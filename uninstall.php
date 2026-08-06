<?php
/**
 * Uninstall script for WebHookARM plugin.
 * This will remove all plugin options from the database when the plugin is deleted.
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Delete plugin options.
delete_option( 'bono_arm_webhook_profileupdates_enable' );
delete_option( 'bono_arm_webhook_url' );
delete_option( 'bono_arm_webhook_secret' );

delete_option( 'bono_arm_webhook_installed_version' );
delete_option( 'bono_arm_webhook_receiver_upgrade_notice' );

// legacy options
delete_option( 'bono_arm_webhook_enable' );

/*
 * Drop any queued deliveries still scheduled for retry. Each event carries its
 * own delivery UUID as an argument, so wp_clear_scheduled_hook() without args
 * would match none of them; wp_unschedule_hook() clears the hook regardless of
 * arguments. Keys are hardcoded because plugin constants are not defined here.
 */
wp_unschedule_hook( 'bono_arm_webhook_process_delivery' );

/*
 * Queued deliveries hold profile data, so remove the transients rather than
 * leaving them to expire in the database after the plugin is gone. Transient
 * names are not enumerable through the options API, so read the keys directly,
 * delete each one properly, then sweep any orphaned value or timeout rows. With
 * a persistent object cache the entries live outside wp_options and expire on
 * their own within a day.
 */
global $wpdb;

$delivery_like = $wpdb->esc_like( '_transient_bono_arm_webhook_delivery_' ) . '%';
$timeout_like  = $wpdb->esc_like( '_transient_timeout_bono_arm_webhook_delivery_' ) . '%';

$delivery_keys = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $delivery_like
    )
);

foreach ( $delivery_keys as $option_name ) {
    delete_transient( substr( $option_name, strlen( '_transient_' ) ) );
}

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $delivery_like,
        $timeout_like
    )
);

// You can add additional cleanup here if needed, such as removing custom database tables