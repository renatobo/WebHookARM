<?php
/**
 * Settings screen, admin notices, and admin request handlers.
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
 * Clear the receiver upgrade notice when an administrator dismisses it.
 */
function bono_arm_webhook_handle_upgrade_notice_dismissal() {
    if (!isset($_GET['bono_arm_webhook_dismiss_upgrade'])) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    check_admin_referer('bono_arm_webhook_dismiss_upgrade');

    // Cleared rather than deleted: an absent option costs a query on every
    // admin request, an autoloaded empty one does not.
    update_option(BONO_ARM_WEBHOOK_OPTION_UPGRADE_NOTICE, '', true);

    $redirect = remove_query_arg(
        array('bono_arm_webhook_dismiss_upgrade', '_wpnonce'),
        wp_unslash(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '')
    );

    wp_safe_redirect('' !== $redirect ? $redirect : admin_url());
    exit;
}

/**
 * Warn administrators upgrading from a build whose receiver setup is incompatible.
 */
function bono_arm_webhook_receiver_upgrade_notice() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $upgraded_from = (string) get_option(BONO_ARM_WEBHOOK_OPTION_UPGRADE_NOTICE, '');

    if ('' === $upgraded_from) {
        return;
    }

    $upgrade_url = admin_url('options-general.php?page=webhookarm#upgrade');
    $dismiss_url = wp_nonce_url(
        add_query_arg('bono_arm_webhook_dismiss_upgrade', '1', $upgrade_url),
        'bono_arm_webhook_dismiss_upgrade'
    );
    ?>
    <div class="notice notice-warning">
        <p>
            <strong><?php esc_html_e('WebHookARM: your receiver must be reconfigured.', 'webhookarm'); ?></strong>
        </p>
        <p>
            <?php
            if ('unknown' === $upgraded_from) {
                printf(
                    /* translators: %s: Current plugin version. */
                    esc_html__('This site was upgraded to WebHookARM %s from an earlier version. Version 2.0 no longer transmits the shared secret and signs each request instead, so Google Apps Script, Make.com, and custom receivers built for the previous version will stop accepting deliveries until you update them.', 'webhookarm'),
                    esc_html(BONO_ARM_WEBHOOK_VERSION)
                );
            } else {
                printf(
                    /* translators: 1: Previous plugin version. 2: Current plugin version. */
                    esc_html__('This site was upgraded from WebHookARM %1$s to %2$s. Version 2.0 no longer transmits the shared secret and signs each request instead, so Google Apps Script, Make.com, and custom receivers built for %1$s will stop accepting deliveries until you update them.', 'webhookarm'),
                    esc_html($upgraded_from),
                    esc_html(BONO_ARM_WEBHOOK_VERSION)
                );
            }
            ?>
        </p>
        <p>
            <?php esc_html_e('A rejected delivery is not always visible in WordPress, so profile updates can be dropped without an error appearing here.', 'webhookarm'); ?>
            <?php echo wp_kses(__('If you were already running a 2.0 pre-release, the change that affects you is narrower: the delivery id is now part of the signed string.', 'webhookarm'), array('code' => array())); ?>
        </p>
        <p>
            <a href="<?php echo esc_url($upgrade_url); ?>" class="button button-primary">
                <?php esc_html_e('Open the upgrade guide', 'webhookarm'); ?>
            </a>
            <a href="<?php echo esc_url($dismiss_url); ?>" class="button button-secondary">
                <?php esc_html_e('I have updated my receiver', 'webhookarm'); ?>
            </a>
        </p>
    </div>
    <?php
}

/**
 * Add the plugin settings page.
 */
function bono_arm_webhook_add_settings_page() {
    add_options_page(
        __('WebHookARM Settings', 'webhookarm'),
        __('ARMember WebHook', 'webhookarm'),
        'manage_options',
        'webhookarm',
        'bono_arm_webhook_settings_page'
    );
}

/**
 * Add a Settings link on the Plugins screen.
 *
 * @param array<int, string> $links Existing action links.
 * @return array<int, string>
 */
function bono_arm_webhook_add_plugin_action_links($links) {
    $settings_url = admin_url('options-general.php?page=webhookarm');

    array_unshift(
        $links,
        sprintf(
            '<a href="%s">%s</a>',
            esc_url($settings_url),
            esc_html__('Settings', 'webhookarm')
        )
    );

    return $links;
}

/**
 * Register plugin settings.
 */
function bono_arm_webhook_register_settings() {
    register_setting(
        'bono_arm_webhook',
        BONO_ARM_WEBHOOK_OPTION_ENABLE,
        array(
            'type' => 'string',
            'sanitize_callback' => 'bono_arm_webhook_sanitize_enabled',
            'default' => 'no',
        )
    );

    register_setting(
        'bono_arm_webhook',
        BONO_ARM_WEBHOOK_OPTION_URL,
        array(
            'type' => 'string',
            'sanitize_callback' => 'bono_arm_webhook_sanitize_url',
            'default' => '',
        )
    );

    register_setting(
        'bono_arm_webhook',
        BONO_ARM_WEBHOOK_OPTION_SECRET,
        array(
            'type' => 'string',
            'sanitize_callback' => 'bono_arm_webhook_sanitize_secret',
            'default' => '',
        )
    );
}

/**
 * Sanitize the enabled flag into the stored yes/no value.
 *
 * @param mixed $value Submitted option value.
 * @return string
 */
function bono_arm_webhook_sanitize_enabled($value) {
    return 'yes' === $value ? 'yes' : 'no';
}

/**
 * Sanitize the webhook URL.
 *
 * @param mixed $value Submitted option value.
 * @return string
 */
function bono_arm_webhook_sanitize_url($value) {
    $url = is_string($value) ? trim($value) : '';

    if ('' === $url) {
        return '';
    }

    $sanitized = esc_url_raw($url, array('http', 'https'));

    if ('' === $sanitized) {
        add_settings_error(
            'bono_arm_webhook',
            'bono_arm_webhook_invalid_url',
            __('Enter a valid webhook URL.', 'webhookarm')
        );

        return bono_arm_webhook_get_webhook_url();
    }

    $allow_insecure = (bool) apply_filters('bono_arm_webhook_allow_insecure_url', false, $sanitized);
    if (!$allow_insecure && !bono_arm_webhook_is_https_url($sanitized)) {
        add_settings_error(
            'bono_arm_webhook',
            'bono_arm_webhook_insecure_url',
            __('Webhook URLs must use HTTPS. Developers may opt in to local HTTP endpoints with the bono_arm_webhook_allow_insecure_url filter.', 'webhookarm')
        );

        return bono_arm_webhook_get_webhook_url();
    }

    return $sanitized;
}

/**
 * Sanitize the shared secret while preserving punctuation.
 *
 * @param mixed $value Submitted option value.
 * @return string
 */
function bono_arm_webhook_sanitize_secret($value) {
    // Fallbacks read the stored option directly so a WEBHOOKARM_SECRET constant
    // is never copied into the database.
    if ('' !== bono_arm_webhook_secret_from_constant()) {
        return bono_arm_webhook_get_stored_secret();
    }

    if (bono_arm_webhook_secret_clear_requested()) {
        return '';
    }

    if (!is_string($value)) {
        return '';
    }

    $secret = (string) preg_replace('/[\r\n\t]+/', '', trim($value));

    if ('' === $secret) {
        return bono_arm_webhook_get_stored_secret();
    }

    if (strlen($secret) < 16) {
        add_settings_error(
            'bono_arm_webhook',
            'bono_arm_webhook_weak_secret',
            __('Use a secret containing at least 16 characters.', 'webhookarm')
        );

        return bono_arm_webhook_get_stored_secret();
    }

    return $secret;
}

/**
 * Whether the settings form asked to remove the saved secret.
 *
 * options.php has already verified the settings nonce before sanitizing.
 *
 * @return bool
 */
function bono_arm_webhook_secret_clear_requested() {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    return isset($_POST['bono_arm_webhook_secret_clear'])
        && '1' === sanitize_text_field(wp_unslash((string) $_POST['bono_arm_webhook_secret_clear']));
}

/**
 * Determine whether a configured webhook uses HTTPS.
 *
 * @param string $url Webhook URL.
 * @return bool
 */
function bono_arm_webhook_is_https_url($url) {
    $scheme = wp_parse_url($url, PHP_URL_SCHEME);

    return is_string($scheme) && 'https' === strtolower($scheme);
}

/**
 * Load the settings screen assets on that screen only.
 *
 * @param string $hook_suffix Current admin page hook.
 */
function bono_arm_webhook_enqueue_admin_assets($hook_suffix) {
    if ('settings_page_webhookarm' !== $hook_suffix) {
        return;
    }

    wp_enqueue_style(
        'webhookarm-admin',
        plugins_url('assets/admin.css', BONO_ARM_WEBHOOK_FILE),
        array(),
        BONO_ARM_WEBHOOK_VERSION
    );
    wp_enqueue_script(
        'webhookarm-admin',
        plugins_url('assets/admin.js', BONO_ARM_WEBHOOK_FILE),
        array(),
        BONO_ARM_WEBHOOK_VERSION,
        array('in_footer' => true)
    );
}

/**
 * Send a test delivery from the settings screen and report the result.
 */
function bono_arm_webhook_handle_test_delivery() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Sorry, you are not allowed to send WebHookARM test deliveries.', 'webhookarm'), '', array('response' => 403));
    }

    check_admin_referer('bono_arm_webhook_test');

    $status = bono_arm_webhook_send_test_delivery();

    wp_safe_redirect(
        add_query_arg(
            array(
                'page' => 'webhookarm',
                'webhookarm_test' => $status,
            ),
            admin_url('options-general.php')
        )
    );
    exit;
}

/**
 * Render the result notice for a test delivery, if one was just sent.
 */
function bono_arm_webhook_render_test_notice() {
    // Display-only: the value is an integer status set by our own redirect.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (!isset($_GET['webhookarm_test'])) {
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $status = (int) sanitize_text_field(wp_unslash((string) $_GET['webhookarm_test']));

    if ($status >= 200 && $status < 300) {
        $class = 'notice-success';
        /* translators: %d: HTTP status code. */
        $message = sprintf(__('Test delivery accepted with HTTP %d. Confirm the test record arrived at the receiver.', 'webhookarm'), $status);
    } elseif (-1 === $status) {
        $class = 'notice-warning';
        $message = __('Save a webhook URL and secret key before sending a test delivery.', 'webhookarm');
    } elseif (0 === $status) {
        $class = 'notice-error';
        $message = __('Test delivery failed: the receiver could not be reached. Check the URL and that it is publicly reachable over HTTPS.', 'webhookarm');
    } elseif (422 === $status) {
        $class = 'notice-error';
        $message = __('Test delivery rejected by the receiver. Check that both sides use the same secret and the receiver signs the delivery id, timestamp, and raw body.', 'webhookarm');
    } else {
        $class = 'notice-error';
        /* translators: %d: HTTP status code. */
        $message = sprintf(__('Test delivery failed with HTTP %d.', 'webhookarm'), $status);
    }

    printf(
        '<div class="notice %1$s inline"><p>%2$s</p></div>',
        esc_attr($class),
        esc_html($message)
    );
}

/**
 * Render the most recent delivery outcome.
 */
function bono_arm_webhook_render_last_delivery() {
    $last = get_option(BONO_ARM_WEBHOOK_OPTION_LAST_DELIVERY, array());

    if (!is_array($last) || !isset($last['outcome'], $last['status'], $last['time'])) {
        echo '<p class="webhookarm-note">' . esc_html__('No delivery has been processed yet.', 'webhookarm') . '</p>';
        return;
    }

    $labels = array(
        'succeeded' => __('Succeeded', 'webhookarm'),
        'failed' => __('Failed permanently', 'webhookarm'),
        'retrying' => __('Failed, retry scheduled', 'webhookarm'),
    );
    $outcome = isset($labels[$last['outcome']]) ? $labels[$last['outcome']] : (string) $last['outcome'];

    printf(
        '<p class="webhookarm-note">%s</p>',
        esc_html(
            sprintf(
                /* translators: 1: Outcome. 2: HTTP status code. 3: Attempt number. 4: Relative time. */
                __('%1$s: HTTP %2$d on attempt %3$d, %4$s ago.', 'webhookarm'),
                $outcome,
                (int) $last['status'],
                isset($last['attempt']) ? (int) $last['attempt'] : 1,
                human_time_diff((int) $last['time'])
            )
        )
    );

    $stats = get_option(BONO_ARM_WEBHOOK_OPTION_DELIVERY_STATS, array());

    if (!is_array($stats) || !isset($stats['since'])) {
        return;
    }

    printf(
        '<p class="webhookarm-note">%s</p>',
        esc_html(
            sprintf(
                /* translators: 1: Date counting started. 2: Successful deliveries. 3: Permanently failed deliveries. */
                __('Since %1$s: %2$d delivered, %3$d failed permanently.', 'webhookarm'),
                wp_date(get_option('date_format'), (int) $stats['since']),
                isset($stats['succeeded']) ? (int) $stats['succeeded'] : 0,
                isset($stats['failed']) ? (int) $stats['failed'] : 0
            )
        )
    );

    // Shown separately so a later success cannot hide it.
    if (isset($stats['last_failure']['status'], $stats['last_failure']['time']) && is_array($stats['last_failure'])) {
        printf(
            '<p class="webhookarm-note"><strong>%s</strong></p>',
            esc_html(
                sprintf(
                    /* translators: 1: HTTP status code. 2: Attempt number. 3: Relative time. */
                    __('Last permanent failure: HTTP %1$d on attempt %2$d, %3$s ago.', 'webhookarm'),
                    (int) $stats['last_failure']['status'],
                    isset($stats['last_failure']['attempt']) ? (int) $stats['last_failure']['attempt'] : 1,
                    human_time_diff((int) $stats['last_failure']['time'])
                )
            )
        );
    }
}

/**
 * Render settings page.
 */
function bono_arm_webhook_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Sorry, you are not allowed to manage WebHookARM settings.', 'webhookarm'));
    }

    $webhook_enabled = bono_arm_webhook_is_enabled();
    $webhook_url = bono_arm_webhook_get_webhook_url();
    $secret_key = bono_arm_webhook_get_secret();
    $secret_from_constant = '' !== bono_arm_webhook_secret_from_constant();
    $has_stored_secret = '' !== bono_arm_webhook_get_stored_secret();
    $project_url = 'https://github.com/renatobo/WebHookARM';
    $author_url = 'https://github.com/renatobo';
    $git_updater_url = 'https://github.com/afragen/git-updater';
    $banner_url = plugins_url('assets/webhookarm-settings-banner.svg', BONO_ARM_WEBHOOK_FILE);
    $sample_script_url = plugins_url('assets/webhookarm_appscript.gs', BONO_ARM_WEBHOOK_FILE);
    $example_request_url = 'https://hooks.example.com/profile-sync?action=profile_update&delivery=uuid&timestamp=unix-time&signature=hmac';
    $payload_example = wp_json_encode(
        array(
            'armember_field_key' => 'Updated value',
            'display_name' => 'Jane Doe',
            'user_id' => 123,
            'user_login' => 'janedoe',
            'user_email' => 'jane@example.com',
        ),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    require __DIR__ . '/views/settings-page.php';
}
