<?php
/**
 * Plugin Name:       WebHookARM
 * Plugin URI:        https://github.com/renatobo/WebHookARM
 * Description:       Send ARMember profile updates to a secure JSON webhook for Google Apps Script, Make.com, or custom integrations.
 * Version:           2.2.0
 * Requires at least: 7.0
 * Requires PHP:      8.0
 * Requires Plugins:  armember-membership
 * Tested up to:      7.1.2
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

define('BONO_ARM_WEBHOOK_VERSION', '2.2.0');
define('BONO_ARM_WEBHOOK_FILE', __FILE__);
define('BONO_ARM_WEBHOOK_OPTION_ENABLE', 'bono_arm_webhook_profileupdates_enable');
define('BONO_ARM_WEBHOOK_OPTION_URL', 'bono_arm_webhook_url');
define('BONO_ARM_WEBHOOK_OPTION_SECRET', 'bono_arm_webhook_secret');
define('BONO_ARM_WEBHOOK_OPTION_FIELD_ALLOWLIST', 'bono_arm_webhook_field_allowlist');
define('BONO_ARM_WEBHOOK_OPTION_VERSION', 'bono_arm_webhook_installed_version');
define('BONO_ARM_WEBHOOK_OPTION_UPGRADE_NOTICE', 'bono_arm_webhook_receiver_upgrade_notice');
define('BONO_ARM_WEBHOOK_DELIVERY_HOOK', 'bono_arm_webhook_process_delivery');
define('BONO_ARM_WEBHOOK_DELIVERY_PREFIX', 'bono_arm_webhook_delivery_');
define('BONO_ARM_WEBHOOK_OPTION_LAST_DELIVERY', 'bono_arm_webhook_last_delivery');
define('BONO_ARM_WEBHOOK_OPTION_DELIVERY_STATS', 'bono_arm_webhook_delivery_stats');
define('BONO_ARM_WEBHOOK_LOCK_PREFIX', 'bono_arm_webhook_lock_');
define('BONO_ARM_WEBHOOK_FAILED_PREFIX', 'bono_arm_webhook_failed_');
define('BONO_ARM_WEBHOOK_FAILED_TTL', 7 * 86400);
define('BONO_ARM_WEBHOOK_LOCK_TTL', 300);
define('BONO_ARM_WEBHOOK_CLEANUP_HOOK', 'bono_arm_webhook_cleanup_deliveries');

if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    add_action('admin_notices', 'bono_arm_webhook_php_version_notice');
    return;
}

register_deactivation_hook(__FILE__, 'bono_arm_webhook_deactivate');
add_action('plugins_loaded', 'bono_arm_webhook_bootstrap');
add_action('delete_user', 'bono_arm_webhook_forget_user');
add_action('admin_menu', 'bono_arm_webhook_add_settings_page');
add_action('admin_init', 'bono_arm_webhook_handle_upgrade_notice_dismissal', 5);
add_action('admin_init', 'bono_arm_webhook_maybe_flag_receiver_upgrade');
add_action('admin_init', 'bono_arm_webhook_register_settings');
add_action('admin_init', 'bono_arm_webhook_maybe_schedule_cleanup');
add_action('admin_notices', 'bono_arm_webhook_receiver_upgrade_notice');
add_action('admin_enqueue_scripts', 'bono_arm_webhook_enqueue_admin_assets');
add_action('admin_post_bono_arm_webhook_test', 'bono_arm_webhook_handle_test_delivery');
add_action('admin_post_bono_arm_webhook_resend_failed', 'bono_arm_webhook_handle_resend_failed');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'bono_arm_webhook_add_plugin_action_links');

require_once __DIR__ . '/includes/delivery.php';
require_once __DIR__ . '/includes/admin.php';

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
 * Runs on admin_init and whenever a delivery is queued, because updates do not
 * fire activation hooks and some sites rarely open wp-admin.
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
 * and their stored payloads, including kept failures, linger. Settings are
 * kept for reactivation. A network deactivation cleans every site.
 *
 * @param bool $network_wide Whether the plugin is being deactivated network-wide.
 */
function bono_arm_webhook_deactivate($network_wide = false) {
    if (!$network_wide || !is_multisite()) {
        bono_arm_webhook_deactivate_site();
        return;
    }

    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $site_id) {
        switch_to_blog((int) $site_id);
        bono_arm_webhook_deactivate_site();
        restore_current_blog();
    }
}

/**
 * Unschedule events and purge queued and kept deliveries on the current site.
 */
function bono_arm_webhook_deactivate_site() {
    wp_unschedule_hook(BONO_ARM_WEBHOOK_DELIVERY_HOOK);
    wp_unschedule_hook(BONO_ARM_WEBHOOK_CLEANUP_HOOK);
    bono_arm_webhook_purge_queue();
}

/**
 * Whether premium ARMember is active.
 *
 * Only premium ARMember fires arm_update_profile_external. ARMember Lite
 * (armember-membership), the declared dependency, never does, so with Lite
 * alone no profile update is ever delivered. Premium defines
 * MEMBERSHIP_DIR_NAME when it loads; Lite does not.
 *
 * @return bool
 */
function bono_arm_webhook_premium_armember_active() {
    return defined('MEMBERSHIP_DIR_NAME');
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
 * Return the payload field allowlist; an empty list means send every field.
 *
 * @return array<int, string>
 */
function bono_arm_webhook_get_field_allowlist() {
    $saved = (string) get_option(BONO_ARM_WEBHOOK_OPTION_FIELD_ALLOWLIST, '');

    return '' === $saved ? array() : explode("\n", $saved);
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
