<?php
/**
 * Secure, asynchronous webhook delivery.
 *
 * @package WebHookARM
 */

// Psalm analyses this file inline from webhookarm.php, where the same guard has
// already run, so it reads the check as redundant. It is not: the guard still
// protects the file when it is requested directly.
/** @psalm-suppress ParadoxicalCondition */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Queue a profile update without delaying the profile request on network I/O.
 *
 * @param int   $user_id   User ID.
 * @param array $form_data ARMember form data.
 */
function bono_arm_webhook_queue_profile_update($user_id, $form_data) {
    $webhook_url = bono_arm_webhook_get_webhook_url();
    $secret_key = bono_arm_webhook_get_secret();

    if ('' === $webhook_url || '' === $secret_key) {
        return;
    }

    $payload = bono_arm_webhook_build_payload((int) $user_id, $form_data);
    $body = wp_json_encode($payload);

    if (false === $body || strlen($body) > bono_arm_webhook_max_payload_bytes()) {
        bono_arm_webhook_log('Payload rejected because it could not be encoded or exceeded the size limit.');
        return;
    }

    $delivery_id = wp_generate_uuid4();
    $option_key = BONO_ARM_WEBHOOK_DELIVERY_PREFIX . $delivery_id;

    // A non-autoloaded option rather than a transient: a persistent object cache
    // may evict transients under memory pressure, silently dropping the delivery.
    $stored = add_option(
        $option_key,
        array(
            'attempt' => 0,
            'body' => $body,
            'created_at' => time(),
        ),
        '',
        false
    );

    if (!$stored) {
        bono_arm_webhook_log('Delivery could not be persisted.');
        return;
    }

    $scheduled = wp_schedule_single_event(time(), BONO_ARM_WEBHOOK_DELIVERY_HOOK, array($delivery_id), true);

    if (is_wp_error($scheduled)) {
        delete_option($option_key);
        bono_arm_webhook_log('Delivery could not be scheduled: ' . $scheduled->get_error_message());
        return;
    }

    /**
     * Filter whether to spawn WP-Cron immediately after queueing a delivery.
     *
     * Off by default. Low-traffic sites can enable it so a delivery does not
     * wait for the next page load to trigger WP-Cron.
     *
     * @param bool   $spawn       Whether to call spawn_cron().
     * @param string $delivery_id Delivery UUID.
     */
    if (apply_filters('bono_arm_webhook_spawn_cron', false, $delivery_id)) {
        spawn_cron();
    }
}

/**
 * Build and filter the outbound payload.
 *
 * @param int   $user_id   User ID.
 * @param mixed $form_data ARMember form data.
 * @return array<string, mixed>
 */
function bono_arm_webhook_build_payload($user_id, $form_data) {
    $user = get_userdata($user_id);
    $payload = is_array($form_data) ? bono_arm_webhook_redact_payload($form_data) : array();
    $payload['user_id'] = $user_id;
    $payload['user_login'] = $user ? (string) $user->user_login : '';
    $payload['user_email'] = $user ? (string) $user->user_email : '';

    /**
     * Filter the complete outbound profile-update payload.
     *
     * Return an allowlisted subset here to send only specific fields.
     *
     * @param array<string, mixed> $payload Outbound payload after credential redaction.
     * @param int                  $user_id WordPress user ID.
     * @param mixed                $form_data Original ARMember data.
     */
    $payload = apply_filters('bono_arm_webhook_payload', $payload, $user_id, $form_data);

    return is_array($payload) ? $payload : array();
}

/**
 * Return the regular expression matched against payload keys for redaction.
 *
 * @return string
 */
function bono_arm_webhook_redaction_pattern() {
    // ssn is anchored against letters so keys such as "classname" survive.
    $default = '/(?:pass(?:word)?|pwd|secret|token|nonce|auth|credit.?card|card.?number|cvv|cvc'
        . '|(?<![a-z])ssn(?![a-z])|iban|api.?key|private.?key|cc.?num|security.?answer)/i';

    /**
     * Filter the pattern whose matching payload keys are removed before delivery.
     *
     * @param string $pattern PCRE pattern matched against each array key.
     */
    $pattern = apply_filters('bono_arm_webhook_redaction_pattern', $default);

    // A broken override must fail closed, never disable redaction.
    if (!is_string($pattern) || false === @preg_match($pattern, '')) {
        bono_arm_webhook_log('Invalid redaction pattern from filter; using the default.');
        return $default;
    }

    return $pattern;
}

/**
 * Recursively remove fields whose names indicate credentials or payment secrets.
 *
 * @param array<string|int, mixed> $data    Input data.
 * @param string|null              $pattern Redaction pattern, resolved once by the top-level call.
 * @return array<string|int, mixed>
 */
function bono_arm_webhook_redact_payload($data, $pattern = null) {
    $pattern = null === $pattern ? bono_arm_webhook_redaction_pattern() : $pattern;
    $redacted = array();

    foreach ($data as $key => $value) {
        if (is_string($key) && preg_match($pattern, $key)) {
            continue;
        }

        $redacted[$key] = is_array($value) ? bono_arm_webhook_redact_payload($value, $pattern) : $value;
    }

    return $redacted;
}

/**
 * Load a queued delivery, migrating one queued by 2.0.x from its transient.
 *
 * @param string $delivery_id Delivery UUID.
 * @return array<string, mixed>|null
 */
function bono_arm_webhook_get_delivery($delivery_id) {
    $option_key = BONO_ARM_WEBHOOK_DELIVERY_PREFIX . $delivery_id;
    $delivery = get_option($option_key, null);

    if (!is_array($delivery)) {
        // Versions before 2.1 stored the queue in transients under the same name.
        $delivery = get_transient($option_key);

        if (!is_array($delivery)) {
            return null;
        }

        add_option($option_key, $delivery, '', false);
        delete_transient($option_key);
    }

    if (!isset($delivery['body'], $delivery['attempt'])) {
        delete_option($option_key);
        return null;
    }

    return $delivery;
}

/**
 * Take a short-lived per-delivery lock so overlapping cron runs do not send twice.
 *
 * @param string $delivery_id Delivery UUID.
 * @return bool True when the lock was acquired.
 */
function bono_arm_webhook_acquire_lock($delivery_id) {
    $lock_key = BONO_ARM_WEBHOOK_LOCK_PREFIX . $delivery_id;

    if (add_option($lock_key, time(), '', false)) {
        return true;
    }

    // A lock older than the TTL belongs to a process that died mid-send.
    $locked_at = (int) get_option($lock_key, 0);
    if ($locked_at > time() - BONO_ARM_WEBHOOK_LOCK_TTL) {
        return false;
    }

    delete_option($lock_key);

    return add_option($lock_key, time(), '', false);
}

/**
 * Release a per-delivery lock.
 *
 * @param string $delivery_id Delivery UUID.
 */
function bono_arm_webhook_release_lock($delivery_id) {
    delete_option(BONO_ARM_WEBHOOK_LOCK_PREFIX . $delivery_id);
}

/**
 * Process one queued delivery.
 *
 * @param string $delivery_id Delivery UUID.
 */
function bono_arm_webhook_process_delivery($delivery_id) {
    if (!is_string($delivery_id) || !wp_is_uuid($delivery_id)) {
        return;
    }

    $delivery = bono_arm_webhook_get_delivery($delivery_id);

    if (null === $delivery) {
        return;
    }

    $option_key = BONO_ARM_WEBHOOK_DELIVERY_PREFIX . $delivery_id;

    if (isset($delivery['created_at']) && (int) $delivery['created_at'] < time() - DAY_IN_SECONDS) {
        delete_option($option_key);
        bono_arm_webhook_log(sprintf('Delivery %s expired before it could be sent.', $delivery_id));
        return;
    }

    if (!bono_arm_webhook_acquire_lock($delivery_id)) {
        bono_arm_webhook_log(sprintf('Delivery %s is already being processed.', $delivery_id));
        return;
    }

    try {
        bono_arm_webhook_attempt_delivery($delivery_id, $delivery);
    } finally {
        bono_arm_webhook_release_lock($delivery_id);
    }
}

/**
 * Send one attempt and record success, permanent failure, or the next retry.
 *
 * @param string               $delivery_id Delivery UUID.
 * @param array<string, mixed> $delivery    Stored delivery state.
 */
function bono_arm_webhook_attempt_delivery($delivery_id, $delivery) {
    $option_key = BONO_ARM_WEBHOOK_DELIVERY_PREFIX . $delivery_id;
    $response = bono_arm_webhook_send_request((string) $delivery['body'], $delivery_id);
    $status = bono_arm_webhook_effective_status($response);
    $attempt = (int) $delivery['attempt'] + 1;

    if ($status >= 200 && $status < 300) {
        delete_option($option_key);
        bono_arm_webhook_record_outcome($delivery_id, 'succeeded', $status, $attempt);
        bono_arm_webhook_log(sprintf('Delivery %s succeeded with HTTP %d.', $delivery_id, $status));

        /**
         * Fires after a delivery is accepted by the receiver.
         *
         * @param string $delivery_id Delivery UUID.
         * @param int    $status      Effective HTTP status.
         * @param int    $attempt     Attempt number that succeeded.
         */
        do_action('bono_arm_webhook_delivery_succeeded', $delivery_id, $status, $attempt);
        return;
    }

    if ($attempt >= 4 || ($status >= 400 && $status < 500 && 408 !== $status && 429 !== $status)) {
        delete_option($option_key);
        bono_arm_webhook_record_outcome($delivery_id, 'failed', $status, $attempt);
        bono_arm_webhook_log(sprintf('Delivery %s permanently failed after %d attempt(s), HTTP %d.', $delivery_id, $attempt, $status));

        /**
         * Fires after a delivery is abandoned.
         *
         * @param string $delivery_id Delivery UUID.
         * @param int    $status      Effective HTTP status, 0 for a transport error.
         * @param int    $attempt     Number of attempts made.
         */
        do_action('bono_arm_webhook_delivery_failed', $delivery_id, $status, $attempt);
        return;
    }

    $delivery['attempt'] = $attempt;

    if (!update_option($option_key, $delivery, false)) {
        bono_arm_webhook_log(sprintf('Delivery %s retry state could not be persisted.', $delivery_id));
        return;
    }

    $delays = array(60, 300, 900);
    $delay = $delays[max(0, min($attempt - 1, count($delays) - 1))];
    $scheduled = wp_schedule_single_event(
        time() + $delay,
        BONO_ARM_WEBHOOK_DELIVERY_HOOK,
        array($delivery_id),
        true
    );

    if (is_wp_error($scheduled)) {
        bono_arm_webhook_log(sprintf('Delivery %s retry could not be scheduled: %s', $delivery_id, $scheduled->get_error_message()));
        return;
    }

    bono_arm_webhook_record_outcome($delivery_id, 'retrying', $status, $attempt);
    bono_arm_webhook_log(sprintf('Delivery %s scheduled for retry %d.', $delivery_id, $attempt + 1));
}

/**
 * Map a transport result to the status the retry logic acts on.
 *
 * Google Apps Script cannot set an HTTP status code, so the bundled receiver
 * answers 200 with a fixed body instead. "Request rejected" has been its
 * rejection body since 2.0, so already deployed scripts are covered.
 *
 * @param array|WP_Error $response Transport result.
 * @return int 0 for a transport error, 422 for a receiver rejection, 503 for a
 *             receiver asking to retry, otherwise the HTTP status.
 */
function bono_arm_webhook_effective_status($response) {
    if (is_wp_error($response)) {
        bono_arm_webhook_log('Transport error: ' . $response->get_error_code());
        return 0;
    }

    $status = (int) wp_remote_retrieve_response_code($response);

    if ($status >= 200 && $status < 300) {
        $body = trim((string) wp_remote_retrieve_body($response));

        if ('Request rejected' === $body) {
            $status = 422;
        } elseif ('Retry later' === $body) {
            $status = 503;
        }
    }

    /**
     * Filter the status used to decide success, retry, or permanent failure.
     *
     * @param int            $status   Effective HTTP status.
     * @param array|WP_Error $response Transport result.
     */
    return (int) apply_filters('bono_arm_webhook_effective_status', $status, $response);
}

/**
 * Record the most recent delivery outcome and running totals for the settings screen.
 *
 * Holds identifiers and status codes only, never payload data. The last
 * permanent failure is kept apart from the latest outcome so a later success
 * does not hide it.
 *
 * @param string $delivery_id Delivery UUID.
 * @param string $outcome     succeeded, failed, or retrying.
 * @param int    $status      Effective HTTP status.
 * @param int    $attempt     Attempt number.
 */
function bono_arm_webhook_record_outcome($delivery_id, $outcome, $status, $attempt) {
    update_option(
        BONO_ARM_WEBHOOK_OPTION_LAST_DELIVERY,
        array(
            'delivery_id' => $delivery_id,
            'outcome' => $outcome,
            'status' => $status,
            'attempt' => $attempt,
            'time' => time(),
        ),
        false
    );

    if ('retrying' === $outcome) {
        return;
    }

    $stats = get_option(BONO_ARM_WEBHOOK_OPTION_DELIVERY_STATS, array());
    $stats = is_array($stats) ? $stats : array();
    $stats += array('since' => time(), 'succeeded' => 0, 'failed' => 0);
    $stats[$outcome] = (int) $stats[$outcome] + 1;

    if ('failed' === $outcome) {
        $stats['last_failure'] = array(
            'delivery_id' => $delivery_id,
            'status' => $status,
            'attempt' => $attempt,
            'time' => time(),
        );
    }

    update_option(BONO_ARM_WEBHOOK_OPTION_DELIVERY_STATS, $stats, false);
}

/**
 * Send a signed webhook request.
 *
 * @param string $body        JSON request body.
 * @param string $delivery_id Delivery UUID.
 * @return array|WP_Error
 */
function bono_arm_webhook_send_request($body, $delivery_id) {
    $timestamp = (string) time();
    $secret = bono_arm_webhook_get_secret();
    $signature = bono_arm_webhook_sign($delivery_id, $timestamp, $body, $secret);
    $webhook_url = bono_arm_webhook_get_webhook_url();
    $request_url = add_query_arg(
        array(
            'action' => 'profile_update',
            'delivery' => $delivery_id,
            'signature' => $signature,
            'timestamp' => $timestamp,
        ),
        $webhook_url
    );
    $signing_headers = array(
        'Content-Type' => 'application/json',
        'X-WebhookARM-Delivery' => $delivery_id,
        'X-WebhookARM-Signature' => 'sha256=' . $signature,
        'X-WebhookARM-Timestamp' => $timestamp,
    );
    $defaults = array(
        'redirection' => 0,
        'timeout' => 10,
        'headers' => $signing_headers,
        'body' => $body,
    );

    /**
     * Filter the outbound request arguments.
     *
     * Signing headers and the body are re-applied afterwards, so the filter
     * can change timeouts, redirects, or add headers but not break signing.
     * Redirects stay off for Apps Script, whose reply is fetched separately.
     *
     * @param array<string, mixed> $args        wp_safe_remote_post() arguments.
     * @param string               $delivery_id Delivery UUID.
     */
    $args = apply_filters('bono_arm_webhook_request_args', $defaults, $delivery_id);
    $args = is_array($args) ? $args : $defaults;
    $extra_headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : array();
    $args['headers'] = array_merge($extra_headers, $signing_headers);
    $args['body'] = $body;

    $is_apps_script = bono_arm_webhook_is_apps_script_url($webhook_url);

    if ($is_apps_script) {
        $args['redirection'] = 0;
    }

    $response = wp_safe_remote_post($request_url, $args);

    return $is_apps_script ? bono_arm_webhook_fetch_apps_script_reply($response, $args) : $response;
}

/**
 * Whether a webhook URL points at a Google Apps Script web app.
 *
 * @param string $url Webhook URL.
 * @return bool
 */
function bono_arm_webhook_is_apps_script_url($url) {
    $host = wp_parse_url($url, PHP_URL_HOST);

    return is_string($host) && 'script.google.com' === strtolower($host);
}

/**
 * Fetch the reply an Apps Script web app serves after its POST redirect.
 *
 * doPost runs on the POST, which answers 302 to a googleusercontent.com echo
 * URL holding the reply text. WordPress cannot follow that redirect itself:
 * it switches a 302 to GET but Requests re-sends the JSON body, and Google
 * answers a GET with a body with 400. So the POST is sent without redirects
 * and the reply is fetched here with a plain GET.
 *
 * @param array|WP_Error       $response Response to the POST.
 * @param array<string, mixed> $args     Arguments the POST was sent with.
 * @return array|WP_Error The echo URL's response, or the POST response when
 *                        there is no redirect to follow.
 */
function bono_arm_webhook_fetch_apps_script_reply($response, $args) {
    if (is_wp_error($response)) {
        return $response;
    }

    $status = (int) wp_remote_retrieve_response_code($response);

    if (!in_array($status, array(301, 302, 303, 307, 308), true)) {
        return $response;
    }

    $location = wp_remote_retrieve_header($response, 'location');
    $location = is_array($location) ? (string) end($location) : (string) $location;
    $host = wp_parse_url($location, PHP_URL_HOST);
    $scheme = wp_parse_url($location, PHP_URL_SCHEME);

    // Only follow Google's own echo host, so a redirect cannot send the
    // request anywhere else.
    if (
        !is_string($host)
        || 'https' !== strtolower((string) $scheme)
        || !preg_match('/(?:^|\.)googleusercontent\.com$/i', $host)
    ) {
        bono_arm_webhook_log(sprintf('Apps Script redirect to an unexpected host was not followed (HTTP %d).', $status));
        return $response;
    }

    return wp_safe_remote_get(
        $location,
        array(
            'redirection' => 0,
            'timeout' => isset($args['timeout']) ? (int) $args['timeout'] : 10,
        )
    );
}

/**
 * Compute the request signature over the delivery id, timestamp, and raw body.
 *
 * Binding the delivery id into the signed string stops an observed request from
 * being replayed with a different identifier to defeat receiver-side idempotency.
 *
 * @param string $delivery_id Delivery UUID.
 * @param string $timestamp   Unix timestamp as a string.
 * @param string $body        Raw JSON request body.
 * @param string $secret      Shared secret.
 * @return string Lowercase hex HMAC-SHA256.
 */
function bono_arm_webhook_sign($delivery_id, $timestamp, $body, $secret) {
    return hash_hmac('sha256', $delivery_id . '.' . $timestamp . '.' . $body, $secret);
}

/**
 * Send a signed test request synchronously using the saved settings.
 *
 * @return int Effective HTTP status, 0 for a transport error, -1 when the
 *             webhook URL or secret is missing.
 */
function bono_arm_webhook_send_test_delivery() {
    if ('' === bono_arm_webhook_get_webhook_url() || '' === bono_arm_webhook_get_secret()) {
        return -1;
    }

    $body = wp_json_encode(
        array(
            'webhookarm_test' => true,
            'user_id' => 0,
            'user_login' => 'webhookarm-test',
            'user_email' => '',
        )
    );

    if (false === $body) {
        return 0;
    }

    return bono_arm_webhook_effective_status(bono_arm_webhook_send_request($body, wp_generate_uuid4()));
}

/**
 * Delete queued deliveries and locks that outlived the one-day retention.
 *
 * A delivery normally removes itself; leftovers come from a retry that could
 * not be scheduled or a cron event that was lost.
 */
function bono_arm_webhook_cleanup_expired_deliveries() {
    global $wpdb;

    $delivery_names = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500",
            $wpdb->esc_like(BONO_ARM_WEBHOOK_DELIVERY_PREFIX) . '%'
        )
    );

    foreach ((array) $delivery_names as $option_name) {
        if (!bono_arm_webhook_is_queue_key($option_name, BONO_ARM_WEBHOOK_DELIVERY_PREFIX)) {
            continue;
        }

        $delivery = get_option($option_name);

        if (!is_array($delivery) || !isset($delivery['created_at']) || (int) $delivery['created_at'] < time() - DAY_IN_SECONDS) {
            delete_option($option_name);
        }
    }

    $lock_names = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500",
            $wpdb->esc_like(BONO_ARM_WEBHOOK_LOCK_PREFIX) . '%'
        )
    );

    foreach ((array) $lock_names as $option_name) {
        if (!bono_arm_webhook_is_queue_key($option_name, BONO_ARM_WEBHOOK_LOCK_PREFIX)) {
            continue;
        }

        if ((int) get_option($option_name, 0) < time() - BONO_ARM_WEBHOOK_LOCK_TTL) {
            delete_option($option_name);
        }
    }
}

/**
 * Whether an option name is a queue row: the prefix followed by a delivery UUID.
 *
 * The LIKE prefix alone also matches bono_arm_webhook_delivery_stats, which
 * must survive cleanup and deactivation.
 *
 * @param string $option_name Option name.
 * @param string $prefix      Queue prefix.
 * @return bool
 */
function bono_arm_webhook_is_queue_key($option_name, $prefix) {
    return 0 === strpos($option_name, $prefix) && wp_is_uuid(substr($option_name, strlen($prefix)));
}

/**
 * Remove every queued delivery, lock, and legacy delivery transient.
 */
function bono_arm_webhook_purge_queue() {
    global $wpdb;

    foreach (array(BONO_ARM_WEBHOOK_DELIVERY_PREFIX, BONO_ARM_WEBHOOK_LOCK_PREFIX) as $prefix) {
        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            )
        );

        foreach ((array) $names as $option_name) {
            if (bono_arm_webhook_is_queue_key($option_name, $prefix)) {
                delete_option($option_name);
            }
        }
    }

    $legacy_names = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('_transient_' . BONO_ARM_WEBHOOK_DELIVERY_PREFIX) . '%'
        )
    );

    foreach ((array) $legacy_names as $option_name) {
        delete_transient(substr($option_name, strlen('_transient_')));
    }
}

/**
 * Return the maximum serialized payload size.
 *
 * @return int
 */
function bono_arm_webhook_max_payload_bytes() {
    return (int) apply_filters('bono_arm_webhook_max_payload_bytes', 262144);
}

/**
 * Write a redacted diagnostic only when WordPress debugging is enabled.
 *
 * @param string $message Diagnostic message without profile data or secrets.
 */
function bono_arm_webhook_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('WebHookARM: ' . $message);
    }
}
