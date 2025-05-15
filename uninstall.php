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

// legacy options
delete_option( 'bono_arm_webhook_enable' );

// You can add additional cleanup here if needed, such as removing custom database tables