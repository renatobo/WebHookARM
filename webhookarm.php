<?php
/**
 * Plugin Name:       WebHookARM
 * Plugin URI:        https://github.com/renatobo/WebHookARM
 * Description:       Send ARMember profile updates to a secure JSON webhook for Google Apps Script, Make.com, or custom integrations.
 * Version:           2.1.1
 * Requires at least: 7.0
 * Requires PHP:      8.0
 * Requires Plugins:  armember-membership
 * Tested up to:      7.0.2
 * Author:            Renato Bonomini
 * Author URI:        https://github.com/renatobo
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       webhookarm
 *
 * GitHub Plugin URI: https://github.com/renatobo/WebHookARM
 * GitHub Branch:     main
 * Primary Branch:    main
 * Release Asset:     true
 *
 * @package WebHookARM
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BONO_ARM_WEBHOOK_VERSION', '2.1.1');
define('BONO_ARM_WEBHOOK_OPTION_ENABLE', 'bono_arm_webhook_profileupdates_enable');
define('BONO_ARM_WEBHOOK_OPTION_URL', 'bono_arm_webhook_url');
define('BONO_ARM_WEBHOOK_OPTION_SECRET', 'bono_arm_webhook_secret');
define('BONO_ARM_WEBHOOK_OPTION_VERSION', 'bono_arm_webhook_installed_version');
define('BONO_ARM_WEBHOOK_OPTION_UPGRADE_NOTICE', 'bono_arm_webhook_receiver_upgrade_notice');
define('BONO_ARM_WEBHOOK_DELIVERY_HOOK', 'bono_arm_webhook_process_delivery');
define('BONO_ARM_WEBHOOK_DELIVERY_PREFIX', 'bono_arm_webhook_delivery_');
define('BONO_ARM_WEBHOOK_OPTION_LAST_DELIVERY', 'bono_arm_webhook_last_delivery');
define('BONO_ARM_WEBHOOK_OPTION_DELIVERY_STATS', 'bono_arm_webhook_delivery_stats');
define('BONO_ARM_WEBHOOK_LOCK_PREFIX', 'bono_arm_webhook_lock_');
define('BONO_ARM_WEBHOOK_LOCK_TTL', 300);
define('BONO_ARM_WEBHOOK_CLEANUP_HOOK', 'bono_arm_webhook_cleanup_deliveries');

if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    add_action('admin_notices', 'bono_arm_webhook_php_version_notice');
    return;
}

register_deactivation_hook(__FILE__, 'bono_arm_webhook_deactivate');
add_action('plugins_loaded', 'bono_arm_webhook_bootstrap');
add_action('admin_menu', 'bono_arm_webhook_add_settings_page');
add_action('admin_init', 'bono_arm_webhook_handle_upgrade_notice_dismissal', 5);
add_action('admin_init', 'bono_arm_webhook_maybe_flag_receiver_upgrade');
add_action('admin_init', 'bono_arm_webhook_register_settings');
add_action('admin_init', 'bono_arm_webhook_maybe_schedule_cleanup');
add_action('admin_notices', 'bono_arm_webhook_receiver_upgrade_notice');
add_action('admin_enqueue_scripts', 'bono_arm_webhook_enqueue_admin_assets');
add_action('admin_post_bono_arm_webhook_test', 'bono_arm_webhook_handle_test_delivery');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'bono_arm_webhook_add_plugin_action_links');

require_once __DIR__ . '/includes/delivery.php';

/**
 * Show an admin notice when PHP is too old for this plugin.
 */
function bono_arm_webhook_php_version_notice() {
    echo '<div class="notice notice-error"><p>';
    printf(
        '%s %s %s',
        '<strong>' . esc_html__('WebHookARM:', 'webhookarm') . '</strong>',
        esc_html__('This plugin requires PHP 8.0 or higher.', 'webhookarm'),
        sprintf(
            /* translators: %s: Current PHP version. */
            esc_html__('You are running PHP %s. Please upgrade PHP before activating this plugin.', 'webhookarm'),
            esc_html(PHP_VERSION)
        )
    );
    echo '</p></div>';
}

/**
 * Register the ARMember hook only when the integration is enabled.
 */
function bono_arm_webhook_bootstrap() {
    if (bono_arm_webhook_is_enabled()) {
        add_action('arm_update_profile_external', 'bono_arm_webhook_queue_profile_update', 10, 2);
    }

    add_action(BONO_ARM_WEBHOOK_DELIVERY_HOOK, 'bono_arm_webhook_process_delivery');
    add_action(BONO_ARM_WEBHOOK_CLEANUP_HOOK, 'bono_arm_webhook_cleanup_expired_deliveries');
}

/**
 * Schedule the daily sweep of expired deliveries if it is missing.
 *
 * Runs on admin_init because updates do not fire activation hooks.
 */
function bono_arm_webhook_maybe_schedule_cleanup() {
    if (!wp_next_scheduled(BONO_ARM_WEBHOOK_CLEANUP_HOOK)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', BONO_ARM_WEBHOOK_CLEANUP_HOOK);
    }
}

/**
 * Stop queued deliveries and remove the profile data they hold.
 *
 * Without this, queued events fire with no handler once the plugin is inactive
 * and their stored payloads linger. Settings are kept for reactivation.
 */
function bono_arm_webhook_deactivate() {
    wp_unschedule_hook(BONO_ARM_WEBHOOK_DELIVERY_HOOK);
    wp_unschedule_hook(BONO_ARM_WEBHOOK_CLEANUP_HOOK);
    bono_arm_webhook_purge_queue();
}

/**
 * Determine whether webhook delivery is enabled.
 *
 * @return bool
 */
function bono_arm_webhook_is_enabled() {
    return 'yes' === get_option(BONO_ARM_WEBHOOK_OPTION_ENABLE, 'no');
}

/**
 * Return the saved webhook URL.
 *
 * @return string
 */
function bono_arm_webhook_get_webhook_url() {
    return (string) get_option(BONO_ARM_WEBHOOK_OPTION_URL, '');
}

/**
 * Return the shared secret, preferring a WEBHOOKARM_SECRET constant.
 *
 * @return string
 */
function bono_arm_webhook_get_secret() {
    $constant = bono_arm_webhook_secret_from_constant();

    return '' !== $constant ? $constant : bono_arm_webhook_get_stored_secret();
}

/**
 * Return the secret saved in the database, ignoring any constant.
 *
 * @return string
 */
function bono_arm_webhook_get_stored_secret() {
    return (string) get_option(BONO_ARM_WEBHOOK_OPTION_SECRET, '');
}

/**
 * Return the secret defined as WEBHOOKARM_SECRET in wp-config.php, or ''.
 *
 * @return string
 */
function bono_arm_webhook_secret_from_constant() {
    if (!defined('WEBHOOKARM_SECRET')) {
        return '';
    }

    $value = constant('WEBHOOKARM_SECRET');

    return is_string($value) ? trim($value) : '';
}

/**
 * Decide which version to warn about, if any.
 *
 * Kept free of WordPress calls so the branch is covered by the CI unit checks;
 * the decision runs once per upgrade and is otherwise unobservable.
 *
 * @param string $stored         Previously recorded plugin version, '' if none.
 * @param bool   $was_configured Whether a webhook URL or secret already exists.
 * @return string Version to warn about, 'unknown' when it cannot be determined,
 *                or '' when no warning is needed.
 */
function bono_arm_webhook_upgrade_notice_version($stored, $was_configured) {
    if (BONO_ARM_WEBHOOK_VERSION === $stored) {
        return '';
    }

    if ('' === $stored) {
        // Builds before 2.0 did not record a version, so an already configured
        // site without one came from a release whose receiver setup differs.
        return $was_configured ? 'unknown' : '';
    }

    return version_compare($stored, '2.0.0', '<') ? $stored : '';
}

/**
 * Record the running version and flag sites whose receiver needs reconfiguring.
 *
 * Version 2.0 changed the authentication scheme, so any site coming from an
 * earlier build has a receiver that will reject or silently drop deliveries
 * until it is updated. Fresh installs are not flagged.
 */
function bono_arm_webhook_maybe_flag_receiver_upgrade() {
    $stored = (string) get_option(BONO_ARM_WEBHOOK_OPTION_VERSION, '');

    if (BONO_ARM_WEBHOOK_VERSION === $stored) {
        return;
    }

    /*
     * Emptiness of the URL and secret is the only default-immune signal that a
     * site was already delivering webhooks: both getters return '' for an absent
     * option regardless of any registered setting default. Testing other options
     * for presence would report a configured site on every fresh install. The
     * stored secret is used, not the WEBHOOKARM_SECRET constant, which a fresh
     * install can define before it has ever delivered anything.
     */
    $was_configured = '' !== bono_arm_webhook_get_webhook_url()
        || '' !== bono_arm_webhook_get_stored_secret();

    $upgraded_from = bono_arm_webhook_upgrade_notice_version($stored, $was_configured);

    if ('' !== $upgraded_from) {
        update_option(BONO_ARM_WEBHOOK_OPTION_UPGRADE_NOTICE, $upgraded_from, true);
    }

    // Autoloaded because both options are read on every admin request.
    update_option(BONO_ARM_WEBHOOK_OPTION_VERSION, BONO_ARM_WEBHOOK_VERSION, true);
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
        plugins_url('assets/admin.css', __FILE__),
        array(),
        BONO_ARM_WEBHOOK_VERSION
    );
    wp_enqueue_script(
        'webhookarm-admin',
        plugins_url('assets/admin.js', __FILE__),
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
    $banner_url = plugins_url('assets/webhookarm-settings-banner.svg', __FILE__);
    $sample_script_url = plugins_url('assets/webhookarm_appscript.gs', __FILE__);
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
    ?>
    <div class="wrap">
        <div class="webhookarm-admin">
            <div class="webhookarm-hero">
                <img
                    src="<?php echo esc_url($banner_url); ?>"
                    alt="<?php echo esc_attr__('WebHookARM settings banner', 'webhookarm'); ?>"
                    class="webhookarm-hero-image"
                />
            </div>

            <div class="webhookarm-meta">
                <a href="<?php echo esc_url($project_url); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Plugin Repository', 'webhookarm'); ?>
                </a>
                <span>
                    <?php
                    printf(
                        /* translators: %s: Plugin version. */
                        esc_html__('Version %s', 'webhookarm'),
                        esc_html(BONO_ARM_WEBHOOK_VERSION)
                    );
                    ?>
                </span>
                <a href="<?php echo esc_url($author_url); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Renato Bonomini on GitHub', 'webhookarm'); ?>
                </a>
                <a href="<?php echo esc_url($git_updater_url); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Updates via Git Updater', 'webhookarm'); ?>
                </a>
            </div>

            <div class="webhookarm-headline">
                <h1><?php esc_html_e('WebHookARM Settings', 'webhookarm'); ?></h1>
                <p class="webhookarm-intro">
                    <?php esc_html_e('Control the ARMember profile update webhook, review the JSON payload shape, and keep the receiver setup aligned with your Apps Script or Make.com flow.', 'webhookarm'); ?>
                </p>
                <p class="webhookarm-intro webhookarm-intro-secondary">
                    <?php esc_html_e('Use HTTPS in production, validate the shared secret on the receiver, and avoid exposing request data in logs outside of local debugging.', 'webhookarm'); ?>
                </p>
            </div>

            <?php settings_errors('bono_arm_webhook'); ?>
            <?php bono_arm_webhook_render_test_notice(); ?>

            <?php if ($webhook_enabled && ('' === $webhook_url || '' === $secret_key)) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <strong><?php esc_html_e('Webhook delivery is enabled but incomplete.', 'webhookarm'); ?></strong>
                        <?php esc_html_e('Add both the webhook URL and secret key before expecting requests to be delivered.', 'webhookarm'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ('' !== $webhook_url && !bono_arm_webhook_is_https_url($webhook_url)) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <strong><?php esc_html_e('Non-HTTPS webhook URL configured.', 'webhookarm'); ?></strong>
                        <?php esc_html_e('HTTP endpoints can be useful for local testing, but production webhook traffic should use HTTPS so profile data and request signatures are not sent in clear text.', 'webhookarm'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($webhook_enabled && defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <strong><?php esc_html_e('WordPress cron is disabled.', 'webhookarm'); ?></strong>
                        <?php esc_html_e('Webhook delivery is queued through WP-Cron. Confirm that the server invokes wp-cron.php on a regular schedule.', 'webhookarm'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper webhookarm-tabs" role="tablist" aria-label="<?php echo esc_attr__('WebHookARM sections', 'webhookarm'); ?>">
                <a href="#webhook" class="nav-tab webhookarm-tab nav-tab-active" id="webhookarm-tab-webhook" role="tab" aria-controls="webhook" aria-selected="true" data-panel="webhook">
                    <?php esc_html_e('Webhook', 'webhookarm'); ?>
                </a>
                <a href="#upgrade" class="nav-tab webhookarm-tab" id="webhookarm-tab-upgrade" role="tab" aria-controls="upgrade" aria-selected="false" data-panel="upgrade">
                    <?php esc_html_e('Upgrade to 2.0', 'webhookarm'); ?>
                </a>
                <a href="#payload" class="nav-tab webhookarm-tab" id="webhookarm-tab-payload" role="tab" aria-controls="payload" aria-selected="false" data-panel="payload">
                    <?php esc_html_e('Payload', 'webhookarm'); ?>
                </a>
                <a href="#apps-script" class="nav-tab webhookarm-tab" id="webhookarm-tab-apps-script" role="tab" aria-controls="apps-script" aria-selected="false" data-panel="apps-script">
                    <?php esc_html_e('Apps Script', 'webhookarm'); ?>
                </a>
                <a href="#make" class="nav-tab webhookarm-tab" id="webhookarm-tab-make" role="tab" aria-controls="make" aria-selected="false" data-panel="make">
                    <?php esc_html_e('Make.com', 'webhookarm'); ?>
                </a>
                <a href="#updates" class="nav-tab webhookarm-tab" id="webhookarm-tab-updates" role="tab" aria-controls="updates" aria-selected="false" data-panel="updates">
                    <?php esc_html_e('Updates', 'webhookarm'); ?>
                </a>
            </nav>

            <form method="post" action="options.php" class="webhookarm-shell">
                <?php settings_fields('bono_arm_webhook'); ?>
                <?php do_settings_sections('bono_arm_webhook'); ?>

                <section class="webhookarm-panel is-active" id="webhook" data-panel="webhook" role="tabpanel" aria-labelledby="webhookarm-tab-webhook">
                    <div class="webhookarm-panel-header">
                        <div>
                            <h2><?php esc_html_e('Webhook connection', 'webhookarm'); ?></h2>
                            <p>
                                <?php esc_html_e('Configure where ARMember profile updates should be delivered and whether the integration should register the outbound hook.', 'webhookarm'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="webhookarm-card webhookarm-card-accent">
                        <div class="webhookarm-switch-row">
                            <div>
                                <h3><?php esc_html_e('Profile update delivery', 'webhookarm'); ?></h3>
                                <p>
                                    <?php
                                    printf(
                                        wp_kses(
                                            /* translators: %s: ARMember action hook name. */
                                            __('When enabled, WebHookARM listens to <code>%s</code> and sends the update as JSON.', 'webhookarm'),
                                            array('code' => array())
                                        ),
                                        esc_html('arm_update_profile_external')
                                    );
                                    ?>
                                </p>
                            </div>
                            <label class="webhookarm-toggle">
                                <input type="hidden" name="<?php echo esc_attr(BONO_ARM_WEBHOOK_OPTION_ENABLE); ?>" value="no" />
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(BONO_ARM_WEBHOOK_OPTION_ENABLE); ?>"
                                    value="yes"
                                    <?php checked(true, $webhook_enabled, true); ?>
                                />
                                <span><?php esc_html_e('Enable profile update webhook', 'webhookarm'); ?></span>
                            </label>
                        </div>

                        <div class="webhookarm-field-grid">
                            <label class="webhookarm-field">
                                <span><?php esc_html_e('Webhook URL', 'webhookarm'); ?></span>
                                <input
                                    type="url"
                                    class="regular-text code"
                                    name="<?php echo esc_attr(BONO_ARM_WEBHOOK_OPTION_URL); ?>"
                                    value="<?php echo esc_attr($webhook_url); ?>"
                                    placeholder="<?php echo esc_attr__('https://hooks.example.com/profile-sync', 'webhookarm'); ?>"
                                />
                                <small><?php echo wp_kses(__('HTTPS is required. Local HTTP testing needs the <code>bono_arm_webhook_allow_insecure_url</code> filter.', 'webhookarm'), array('code' => array())); ?></small>
                            </label>
                            <label class="webhookarm-field">
                                <span><?php esc_html_e('Secret key', 'webhookarm'); ?></span>
                                <?php if ($secret_from_constant) : ?>
                                    <input
                                        type="password"
                                        class="regular-text code"
                                        value=""
                                        disabled
                                        placeholder="<?php echo esc_attr__('Defined in wp-config.php', 'webhookarm'); ?>"
                                    />
                                    <small>
                                        <?php echo wp_kses(__('The <code>WEBHOOKARM_SECRET</code> constant overrides any saved secret. Remove it from <code>wp-config.php</code> to manage the secret here.', 'webhookarm'), array('code' => array())); ?>
                                    </small>
                                <?php else : ?>
                                    <input
                                        type="password"
                                        class="regular-text code"
                                        name="<?php echo esc_attr(BONO_ARM_WEBHOOK_OPTION_SECRET); ?>"
                                        value=""
                                        autocomplete="new-password"
                                        placeholder="<?php echo esc_attr($has_stored_secret ? __('Secret configured; leave blank to keep it', 'webhookarm') : __('Enter at least 16 characters', 'webhookarm')); ?>"
                                    />
                                    <small>
                                        <?php echo wp_kses(__('Used to sign each request with HMAC-SHA256. The secret itself is never transmitted. You can also define it as <code>WEBHOOKARM_SECRET</code> in <code>wp-config.php</code>.', 'webhookarm'), array('code' => array())); ?>
                                    </small>
                                <?php endif; ?>
                            </label>
                        </div>

                        <?php if (!$secret_from_constant && $has_stored_secret) : ?>
                            <label class="webhookarm-clear-secret">
                                <input type="checkbox" name="bono_arm_webhook_secret_clear" value="1" />
                                <?php esc_html_e('Remove the saved secret. Delivery stops until a new secret is saved.', 'webhookarm'); ?>
                            </label>
                        <?php endif; ?>

                        <div class="webhookarm-grid webhookarm-grid-three">
                            <div class="webhookarm-code-card">
                                <strong><?php esc_html_e('Method', 'webhookarm'); ?></strong>
                                <span>
                                    <?php
                                    echo wp_kses(
                                        __('<code>POST</code> with <code>application/json</code>', 'webhookarm'),
                                        array('code' => array())
                                    );
                                    ?>
                                </span>
                            </div>
                            <div class="webhookarm-code-card">
                                <strong><?php esc_html_e('Action parameter', 'webhookarm'); ?></strong>
                                <span><code>action=profile_update</code></span>
                            </div>
                            <div class="webhookarm-code-card">
                                <strong><?php esc_html_e('WordPress fields', 'webhookarm'); ?></strong>
                                <span><code>user_id</code>, <code>user_login</code>, <code>user_email</code></span>
                            </div>
                        </div>

                        <div class="webhookarm-example-grid">
                            <div class="webhookarm-example">
                                <strong><?php esc_html_e('Example request URL', 'webhookarm'); ?></strong>
                                <code id="webhookarm-example-request"><?php echo esc_html($example_request_url); ?></code>
                                <button type="button" class="button button-secondary button-small" data-copy-target="webhookarm-example-request"><?php esc_html_e('Copy', 'webhookarm'); ?></button>
                            </div>
                            <div class="webhookarm-example">
                                <strong><?php esc_html_e('Bundled Apps Script sample', 'webhookarm'); ?></strong>
                                <code id="webhookarm-apps-script-url"><?php echo esc_html($sample_script_url); ?></code>
                                <button type="button" class="button button-secondary button-small" data-copy-target="webhookarm-apps-script-url"><?php esc_html_e('Copy', 'webhookarm'); ?></button>
                            </div>
                        </div>
                    </div>

                    <div class="webhookarm-card">
                        <h3><?php esc_html_e('Delivery status', 'webhookarm'); ?></h3>
                        <?php bono_arm_webhook_render_last_delivery(); ?>
                        <p class="webhookarm-note">
                            <?php esc_html_e('Google Apps Script answers 200 even when it rejects a request. WebHookARM recognises the bundled sample\'s rejection reply, but always confirm records arrive at the receiver itself.', 'webhookarm'); ?>
                        </p>
                        <p>
                            <button type="submit" form="webhookarm-test-form" class="button button-secondary">
                                <?php esc_html_e('Send test delivery', 'webhookarm'); ?>
                            </button>
                        </p>
                        <p class="webhookarm-note">
                            <?php echo wp_kses(__('Sends a signed request using the <strong>saved</strong> settings, with <code>user_id</code> 0 and <code>webhookarm_test</code> set to true. The bundled Apps Script writes it as a row.', 'webhookarm'), array('code' => array(), 'strong' => array())); ?>
                        </p>
                    </div>
                </section>

                <section class="webhookarm-panel" id="upgrade" data-panel="upgrade" role="tabpanel" aria-labelledby="webhookarm-tab-upgrade" hidden>
                    <div class="webhookarm-panel-header">
                        <div>
                            <h2><?php esc_html_e('Upgrading to version 2.0', 'webhookarm'); ?></h2>
                            <p>
                                <?php esc_html_e('Version 2.0 replaces the 1.x authentication scheme and delivers requests through WP-Cron. Receivers built for 1.x will reject or mishandle every 2.0 request until you update them, so work through this checklist before you rely on the integration.', 'webhookarm'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="webhookarm-card webhookarm-card-accent">
                        <h3><?php esc_html_e('What changed', 'webhookarm'); ?></h3>
                        <ul class="webhookarm-steps">
                            <li><?php echo wp_kses(__('The shared secret is <strong>no longer transmitted</strong>. Version 1.x sent it as <code>?key=</code> and in an <code>X-Security-Key</code> header. Version 2.0 sends an HMAC-SHA256 signature instead.', 'webhookarm'), array('code' => array(), 'strong' => array())); ?></li>
                            <li><?php echo wp_kses(__('Requests carry <code>X-WebhookARM-Signature</code>, <code>X-WebhookARM-Timestamp</code>, and <code>X-WebhookARM-Delivery</code> headers, mirrored as <code>signature</code>, <code>timestamp</code>, and <code>delivery</code> query parameters for receivers such as Google Apps Script that cannot read headers.', 'webhookarm'), array('code' => array())); ?></li>
                            <li><?php echo wp_kses(__('Delivery is queued through WP-Cron with retries after 1, 5, and 15 minutes, so requests no longer arrive during the profile save itself.', 'webhookarm'), array('code' => array())); ?></li>
                            <li><?php echo wp_kses(__('Credential-like fields (<code>password</code>, <code>token</code>, <code>secret</code>, card numbers, and similar) are stripped from the payload recursively, and payloads above 256 KiB are dropped.', 'webhookarm'), array('code' => array())); ?></li>
                            <li><?php esc_html_e('Webhook URLs must use HTTPS, and the secret must be at least 16 characters.', 'webhookarm'); ?></li>
                        </ul>
                    </div>

                    <div class="webhookarm-card">
                        <h3><?php esc_html_e('Upgrade checklist', 'webhookarm'); ?></h3>
                        <ol class="webhookarm-steps">
                            <li>
                                <strong><?php esc_html_e('Turn the webhook off while you migrate.', 'webhookarm'); ?></strong>
                                <?php esc_html_e('Clear the enable checkbox on the Webhook tab and save. Profile updates will not be queued until you turn it back on.', 'webhookarm'); ?>
                            </li>
                            <li>
                                <strong><?php esc_html_e('Re-enter the secret key.', 'webhookarm'); ?></strong>
                                <?php esc_html_e('Newly saved secrets must be at least 16 characters. An existing shorter secret keeps working, but the migration is a good moment to generate a stronger one and set the identical value on both sides.', 'webhookarm'); ?>
                            </li>
                            <li>
                                <strong><?php esc_html_e('Confirm the URL uses HTTPS.', 'webhookarm'); ?></strong>
                                <?php echo wp_kses(__('Non-HTTPS URLs are rejected on save. Local HTTP testing requires the <code>bono_arm_webhook_allow_insecure_url</code> filter.', 'webhookarm'), array('code' => array())); ?>
                            </li>
                            <li>
                                <strong><?php esc_html_e('Update the receiver to validate the new signature.', 'webhookarm'); ?></strong>
                                <?php esc_html_e('This is the step that breaks integrations if you skip it. See the signature contract below.', 'webhookarm'); ?>
                            </li>
                            <li>
                                <strong><?php esc_html_e('Confirm WP-Cron runs.', 'webhookarm'); ?></strong>
                                <?php echo wp_kses(__('If <code>DISABLE_WP_CRON</code> is set, have your server invoke <code>wp-cron.php</code> on a schedule or deliveries will sit in the queue and expire after one day.', 'webhookarm'), array('code' => array())); ?>
                            </li>
                            <li>
                                <strong><?php esc_html_e('Re-enable the webhook and save a test profile change.', 'webhookarm'); ?></strong>
                                <?php esc_html_e('Verify the row or record arrives at the receiver. Do not assume success from the WordPress side alone; see the verification note below.', 'webhookarm'); ?>
                            </li>
                        </ol>
                    </div>

                    <div class="webhookarm-card">
                        <h3><?php esc_html_e('Signature contract', 'webhookarm'); ?></h3>
                        <p class="webhookarm-note">
                            <?php echo wp_kses(__('Compute HMAC-SHA256 over the delivery id, timestamp, and <strong>raw</strong> request body joined by periods, using the shared secret as the key. Compare it against the received value in constant time. Do not re-serialize the JSON before signing; whitespace differences change the digest.', 'webhookarm'), array('strong' => array())); ?>
                        </p>
                        <pre><?php echo esc_html("signed_string = delivery_id + \".\" + timestamp + \".\" + raw_body\nsignature     = lowercase_hex( hmac_sha256( signed_string, secret ) )\n\nheader:  X-WebhookARM-Signature: sha256=<signature>\nquery:   ?signature=<signature>&timestamp=<unix>&delivery=<uuid>&action=profile_update"); ?></pre>
                        <p class="webhookarm-note">
                            <?php echo wp_kses(__('Also reject requests whose <code>timestamp</code> is more than a few minutes from your own clock, and whose <code>delivery</code> is not a lowercase version 4 UUID. Use <code>delivery</code> as an idempotency key so a retried request is not stored twice.', 'webhookarm'), array('code' => array())); ?>
                        </p>
                        <p class="webhookarm-note">
                            <strong><?php esc_html_e('Changed after the first 2.0 pre-release:', 'webhookarm'); ?></strong>
                            <?php echo wp_kses(__('the delivery id is now part of the signed string. Early 2.0 receivers signed only <code>timestamp.raw_body</code> and must be updated.', 'webhookarm'), array('code' => array())); ?>
                        </p>
                    </div>

                    <div class="webhookarm-card">
                        <h3><?php esc_html_e('Google Apps Script users', 'webhookarm'); ?></h3>
                        <p class="webhookarm-note">
                            <?php esc_html_e('The bundled sample script ships inside the plugin, but a deployed Apps Script project lives on Google\'s side. Updating this plugin does not update your deployment.', 'webhookarm'); ?>
                        </p>
                        <ol class="webhookarm-steps">
                            <li><?php esc_html_e('Copy the current sample from the Apps Script tab into your project, replacing the old code.', 'webhookarm'); ?></li>
                            <li><?php echo wp_kses(__('Set the script properties <code>WA_AUTH_SECRET</code> and <code>WA_SHEET_NAME</code>. Earlier guidance named these <code>AUTH_SECRET</code> and <code>SHEET_NAME</code>; the <code>WA_</code> prefix is required now.', 'webhookarm'), array('code' => array())); ?></li>
                            <li><?php esc_html_e('Deploy a new Web App version. Editing the code alone does not update the live endpoint.', 'webhookarm'); ?></li>
                            <li><?php esc_html_e('If the Web App URL changed, paste the new one into the Webhook tab.', 'webhookarm'); ?></li>
                        </ol>
                    </div>

                    <div class="webhookarm-card">
                        <h3><?php esc_html_e('Verifying the upgrade worked', 'webhookarm'); ?></h3>
                        <p class="webhookarm-note">
                            <?php esc_html_e('Check the receiver, not WordPress. Google Apps Script cannot set an HTTP status code, so a rejected request still answers 200 and WebHookARM records it as delivered. A receiver signing the wrong string will silently discard every profile update with no error shown here.', 'webhookarm'); ?>
                        </p>
                        <p class="webhookarm-note">
                            <?php echo wp_kses(__('For custom receivers, return a 4xx status on signature failure so failed deliveries are visible, and enable <code>WP_DEBUG</code> temporarily to see delivery outcomes in the WordPress debug log. Diagnostics contain delivery ids and status codes only, never profile data or the secret.', 'webhookarm'), array('code' => array())); ?>
                        </p>
                    </div>
                </section>

                <section class="webhookarm-panel" id="payload" data-panel="payload" role="tabpanel" aria-labelledby="webhookarm-tab-payload" hidden>
                    <div class="webhookarm-panel-header">
                        <div>
                            <h2><?php esc_html_e('Payload format', 'webhookarm'); ?></h2>
                            <p>
                                <?php esc_html_e('WebHookARM forwards the ARMember form payload and appends core WordPress user identifiers so downstream receivers can match records reliably.', 'webhookarm'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="webhookarm-card">
                        <div class="webhookarm-grid webhookarm-grid-two">
                            <div class="webhookarm-code-card">
                                <strong><?php esc_html_e('Headers', 'webhookarm'); ?></strong>
                                <span><code>Content-Type: application/json</code></span>
                                <span><code>X-WebhookARM-Delivery: uuid</code></span>
                                <span><code>X-WebhookARM-Signature: sha256=hmac</code></span>
                                <span><code>X-WebhookARM-Timestamp: unix-time</code></span>
                            </div>
                            <div class="webhookarm-code-card">
                                <strong><?php esc_html_e('Query parameters', 'webhookarm'); ?></strong>
                                <span><code>action=profile_update</code></span>
                                <span><code>delivery=uuid</code></span>
                                <span><code>signature=hmac</code></span>
                                <span><code>timestamp=unix-time</code></span>
                            </div>
                        </div>

                        <pre><?php echo esc_html($payload_example); ?></pre>

                        <p class="webhookarm-note">
                            <?php esc_html_e('ARMember field keys vary by site and form configuration. The plugin forwards them as-is and only guarantees the appended WordPress identity fields listed above.', 'webhookarm'); ?>
                        </p>
                        <p class="webhookarm-note">
                            <?php echo wp_kses(__('Developers can narrow the payload to an allowlist with the <code>bono_arm_webhook_payload</code> filter, or change which keys are redacted with <code>bono_arm_webhook_redaction_pattern</code>. Delivery outcomes fire <code>bono_arm_webhook_delivery_succeeded</code> and <code>bono_arm_webhook_delivery_failed</code>.', 'webhookarm'), array('code' => array())); ?>
                        </p>
                    </div>
                </section>

                <section class="webhookarm-panel" id="apps-script" data-panel="apps-script" role="tabpanel" aria-labelledby="webhookarm-tab-apps-script" hidden>
                    <div class="webhookarm-panel-header">
                        <div>
                            <h2><?php esc_html_e('Google Apps Script receiver', 'webhookarm'); ?></h2>
                            <p>
                                <?php esc_html_e('Use the bundled Apps Script sample as a starting point for syncing profile updates into Google Sheets.', 'webhookarm'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="webhookarm-card">
                        <ol class="webhookarm-steps">
                            <li><?php esc_html_e('Open your target Google Sheet and go to', 'webhookarm'); ?> <strong><?php esc_html_e('Extensions -> Apps Script', 'webhookarm'); ?></strong>.</li>
                            <li>
                                <?php
                                printf(
                                    wp_kses(
                                        __('Use the bundled sample file at <a href="%s" target="_blank" rel="noopener noreferrer">assets/webhookarm_appscript.gs</a> as your base.', 'webhookarm'),
                                        array(
                                            'a' => array(
                                                'href' => array(),
                                                'target' => array(),
                                                'rel' => array(),
                                            ),
                                        )
                                    ),
                                    esc_url($sample_script_url)
                                );
                                ?>
                            </li>
                            <li><?php echo wp_kses(__('Set <code>WA_AUTH_SECRET</code> and <code>WA_SHEET_NAME</code> in Script properties.', 'webhookarm'), array('code' => array())); ?></li>
                            <li><?php esc_html_e('Deploy the project as a Web App and paste that URL into the Webhook tab.', 'webhookarm'); ?></li>
                            <li><?php esc_html_e('The sample validates the timestamped request signature before writing rows.', 'webhookarm'); ?></li>
                        </ol>
                    </div>
                </section>

                <section class="webhookarm-panel" id="make" data-panel="make" role="tabpanel" aria-labelledby="webhookarm-tab-make" hidden>
                    <div class="webhookarm-panel-header">
                        <div>
                            <h2><?php esc_html_e('Make.com receiver', 'webhookarm'); ?></h2>
                            <p>
                                <?php esc_html_e('Make.com works well when you want to route ARMember updates into CRMs, spreadsheets, or downstream automations without custom code.', 'webhookarm'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="webhookarm-card">
                        <ol class="webhookarm-steps">
                            <li><?php esc_html_e('Create an HTTP webhook or custom webhook scenario entry point.', 'webhookarm'); ?></li>
                            <li><?php echo wp_kses(__('Accept <code>POST</code> requests with an <code>application/json</code> body.', 'webhookarm'), array('code' => array())); ?></li>
                            <li><?php echo wp_kses(__('Validate <code>X-WebhookARM-Signature</code> against <code>delivery.timestamp.raw-body</code>.', 'webhookarm'), array('code' => array())); ?></li>
                            <li><?php echo wp_kses(__('Map ARMember field keys plus <code>user_id</code>, <code>user_login</code>, and <code>user_email</code> into your scenario modules.', 'webhookarm'), array('code' => array())); ?></li>
                            <li><?php esc_html_e('Keep the endpoint on HTTPS and avoid storing raw secrets in logs or history longer than necessary.', 'webhookarm'); ?></li>
                        </ol>
                    </div>
                </section>

                <section class="webhookarm-panel" id="updates" data-panel="updates" role="tabpanel" aria-labelledby="webhookarm-tab-updates" hidden>
                    <div class="webhookarm-panel-header">
                        <div>
                            <h2><?php esc_html_e('Updates and release flow', 'webhookarm'); ?></h2>
                            <p>
                                <?php esc_html_e('This plugin is packaged as a GitHub release asset so Git Updater can install versioned zip builds directly from repository releases.', 'webhookarm'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="webhookarm-card">
                        <div class="webhookarm-grid">
                            <div class="webhookarm-code-card">
                                <strong><?php esc_html_e('Git Updater', 'webhookarm'); ?></strong>
                                <span>
                                    <?php
                                    printf(
                                        wp_kses(
                                            __('Install <a href="%s" target="_blank" rel="noopener noreferrer">Git Updater</a> and keep this repository as the plugin source.', 'webhookarm'),
                                            array(
                                                'a' => array(
                                                    'href' => array(),
                                                    'target' => array(),
                                                    'rel' => array(),
                                                ),
                                            )
                                        ),
                                        esc_url($git_updater_url)
                                    );
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="webhookarm-footer">
                    <?php submit_button(__('Save settings', 'webhookarm'), 'primary', 'submit', false); ?>
                </div>
            </form>

            <form id="webhookarm-test-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="bono_arm_webhook_test" />
                <?php wp_nonce_field('bono_arm_webhook_test'); ?>
            </form>
        </div>
    </div>
    <?php
}
