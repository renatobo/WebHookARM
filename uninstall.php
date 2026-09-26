<?php
/**
 * Uninstall script for WebHookARM plugin.
 * This will remove all plugin options from the database when the plugin is deleted.
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/*
 * Keys are hardcoded because plugin constants are not defined here. A closure
 * rather than a named function avoids colliding with anything already loaded.
 */
$bono_arm_webhook_cleanup = static function () {
    // Read from $GLOBALS rather than `global $wpdb;` so this works under any
    // include scope.
    $wpdb = $GLOBALS['wpdb'];

    foreach ( array(
        'bono_arm_webhook_profileupdates_enable',
        'bono_arm_webhook_url',
        'bono_arm_webhook_secret',
        'bono_arm_webhook_field_allowlist',
        'bono_arm_webhook_installed_version',
        'bono_arm_webhook_receiver_upgrade_notice',
        'bono_arm_webhook_last_delivery',
        'bono_arm_webhook_delivery_stats',
        // legacy options
        'bono_arm_webhook_enable',
    ) as $option ) {
        delete_option( $option );
    }

    /*
     * Each queued event carries its own delivery UUID as an argument, so
     * wp_clear_scheduled_hook() without args would match none of them;
     * wp_unschedule_hook() clears the hook regardless of arguments.
     */
    wp_unschedule_hook( 'bono_arm_webhook_process_delivery' );
    wp_unschedule_hook( 'bono_arm_webhook_cleanup_deliveries' );

    /*
     * Queued deliveries hold profile data. Since 2.1 they are non-autoloaded
     * options; earlier versions used transients, which may still be present.
     * Delete each through the API so object caches are invalidated, then sweep
     * any orphaned transient value or timeout rows.
     */
    foreach ( array( 'bono_arm_webhook_delivery_', 'bono_arm_webhook_lock_' ) as $prefix ) {
        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( $prefix ) . '%'
            )
        );

        foreach ( (array) $names as $option_name ) {
            delete_option( $option_name );
        }
    }

    $delivery_like = $wpdb->esc_like( '_transient_bono_arm_webhook_delivery_' ) . '%';
    $timeout_like  = $wpdb->esc_like( '_transient_timeout_bono_arm_webhook_delivery_' ) . '%';

    $delivery_keys = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $delivery_like
        )
    );

    foreach ( (array) $delivery_keys as $option_name ) {
        delete_transient( substr( $option_name, strlen( '_transient_' ) ) );
    }

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $delivery_like,
            $timeout_like
        )
    );
};

// Options, cron, and queued deliveries are per site, so clean every site.
if ( is_multisite() ) {
    foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $bono_arm_webhook_site_id ) {
        switch_to_blog( (int) $bono_arm_webhook_site_id );
        $bono_arm_webhook_cleanup();
        restore_current_blog();
    }
} else {
    $bono_arm_webhook_cleanup();
}
