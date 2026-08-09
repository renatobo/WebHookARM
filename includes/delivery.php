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
    $stored = set_transient(
        BONO_ARM_WEBHOOK_DELIVERY_PREFIX . $delivery_id,
        array(
            'attempt' => 0,
            'body' => $body,
            'created_at' => time(),
        ),
        DAY_IN_SECONDS
    );

    if (!$stored) {
        bono_arm_webhook_log('Delivery could not be persisted.');
        return;
    }

    $scheduled = wp_schedule_single_event(time(), BONO_ARM_WEBHOOK_DELIVERY_HOOK, array($delivery_id), true);

    if (is_wp_error($scheduled)) {
        delete_transient(BONO_ARM_WEBHOOK_DELIVERY_PREFIX . $delivery_id);
        bono_arm_webhook_log('Delivery could not be scheduled: ' . $scheduled->get_error_message());
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
     * @param array<string, mixed> $payload Outbound payload after credential redaction.
     * @param int                  $user_id WordPress user ID.
     * @param mixed                $form_data Original ARMember data.
     */
    $payload = apply_filters('bono_arm_webhook_payload', $payload, $user_id, $form_data);

    return is_array($payload) ? $payload : array();
}

/**
 * Recursively remove fields whose names indicate credentials or payment secrets.
 *
 * @param array<string|int, mixed> $data Input data.
 * @return array<string|int, mixed>
 */
function bono_arm_webhook_redact_payload($data) {
    $redacted = array();
    $blocked_pattern = '/(?:pass(?:word)?|pwd|secret|token|nonce|auth|credit.?card|card.?number|cvv|cvc)/i';

    foreach ($data as $key => $value) {
        if (is_string($key) && preg_match($blocked_pattern, $key)) {
            continue;
        }

        $redacted[$key] = is_array($value) ? bono_arm_webhook_redact_payload($value) : $value;
    }

    return $redacted;
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

    $transient_key = BONO_ARM_WEBHOOK_DELIVERY_PREFIX . $delivery_id;
    $delivery = get_transient($transient_key);

    if (!is_array($delivery) || !isset($delivery['body'], $delivery['attempt'])) {
        return;
    }

    $response = bono_arm_webhook_send_request((string) $delivery['body'], $delivery_id);
    $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

    if ($status >= 200 && $status < 300) {
        delete_transient($transient_key);
        bono_arm_webhook_log(sprintf('Delivery %s succeeded with HTTP %d.', $delivery_id, $status));
        return;
    }

    $delivery['attempt'] = (int) $delivery['attempt'] + 1;

    if ($delivery['attempt'] >= 4 || ($status >= 400 && $status < 500 && 408 !== $status && 429 !== $status)) {
        delete_transient($transient_key);
        bono_arm_webhook_log(sprintf('Delivery %s permanently failed after %d attempt(s), HTTP %d.', $delivery_id, $delivery['attempt'], $status));
        return;
    }

    if (!set_transient($transient_key, $delivery, DAY_IN_SECONDS)) {
        bono_arm_webhook_log(sprintf('Delivery %s retry state could not be persisted.', $delivery_id));
        return;
    }

    $delays = array(60, 300, 900);
    $delay = $delays[max(0, min($delivery['attempt'] - 1, count($delays) - 1))];
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

    bono_arm_webhook_log(sprintf('Delivery %s scheduled for retry %d.', $delivery_id, $delivery['attempt'] + 1));
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
    $request_url = add_query_arg(
        array(
            'action' => 'profile_update',
            'delivery' => $delivery_id,
            'signature' => $signature,
            'timestamp' => $timestamp,
        ),
        bono_arm_webhook_get_webhook_url()
    );

    return wp_safe_remote_post(
        $request_url,
        array(
            'redirection' => 3,
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-WebhookARM-Delivery' => $delivery_id,
                'X-WebhookARM-Signature' => 'sha256=' . $signature,
                'X-WebhookARM-Timestamp' => $timestamp,
            ),
            'body' => $body,
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
