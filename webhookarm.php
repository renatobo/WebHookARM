<?php
/**
 * Plugin Name: WebHookARM
 * Plugin URI: https://github.com/renatobo/WebHookARM
 * Description: WebHookARM is a lightweight WordPress plugin that listens for ARMember profile update events and securely sends the user data to a webhook endpoint (Google Apps Script Web App or Make.com). 
 * Version: 1.0
 * Author: Renato Bonomini
 * Author URI: https://github.com/renatobo
 * License: GPLv2 or later
 */

defined('ABSPATH') or die('No script kiddies please!');

define('BONO_ARM_WEBHOOK_VERSION', '1.0');

// Conditionally register the webhook
add_action('plugins_loaded', function () {
    if (get_option('bono_arm_webhook_profileupdates_enable') === 'yes') {
        add_action('arm_update_profile_external', 'bono_arm_webhook_send_to_sheet', 10, 2);
    }
});

/**
 * Handle ARMember profile updates and send to webhook
 *
 * @param int   $user_id   User ID.
 * @param array $form_data ARMember form data array.
 */
function bono_arm_webhook_send_to_sheet($user_id, $form_data) {
    $webhook_url = get_option('bono_arm_webhook_url');
    $secret_key  = get_option('bono_arm_webhook_secret');
    if (!$webhook_url || !$secret_key) {
        return;
    }

    // Fetch WP user details
    $user = get_userdata($user_id);

    // Build payload from form data and WP user info
    $payload = is_array($form_data) ? $form_data : array();
    $payload['user_id']    = $user_id;
    $payload['user_login'] = $user ? $user->user_login : '';
    $payload['user_email'] = $user ? $user->user_email : '';

    // Append the secret key and action as query parameters
    $request_url = add_query_arg(
        array(
            'key'    => $secret_key,
            'action' => 'profile_update'
        ),
        $webhook_url
    );

    // Send POST and follow redirects
    $response = wp_remote_post($request_url, array(
        'method'      => 'POST',
        'redirection' => 5,
        'headers'     => array(
            'Content-Type'   => 'application/json',
            'X-Security-Key' => $secret_key
        ),
        'body'        => wp_json_encode($payload),
        'timeout'     => 10
    ));

    // Log execution for debugging if WP_DEBUG enabled
    if (defined('WP_DEBUG') && WP_DEBUG) {
        if (is_wp_error($response)) {
            error_log("WebHookARM error sending webhook for payload: " . wp_json_encode($payload) . " - " . $response->get_error_message());
        } else {
            $status = wp_remote_retrieve_response_code($response);
            error_log("WebHookARM webhook sent for payload: " . wp_json_encode($payload) . ". Response code: $status");
        }
    }
}

// Add admin settings page
add_action('admin_menu', function () {
    add_options_page(
        'ARMember to WebHook',
        'ARMember to WebHook',
        'manage_options',
        'webhookarm',
        'bono_arm_webhook_settings_page'
    );
});

/**
 * Render settings page.
 */
function bono_arm_webhook_settings_page() {
    ?>
    <div class="wrap">
        <h2>ARMember to WebHook Settings</h2>
        <p>
            This plugin listens for ARMember profile updates and sends them to a webhook endpoint.<br />
            It can deliver the data to a <strong>Google Sheet via Apps Script</strong> or to any endpoint such as <strong>Make.com</strong>.<br />
            Use a secure Web App URL and secret key (configured in Apps Script properties or in Make.com HTTP module).<br />
            <strong>Version:</strong> <?php echo BONO_ARM_WEBHOOK_VERSION; ?>
        </p>
        <form method="post" action="options.php">
            <?php
                settings_fields('bono_arm_webhook');
                do_settings_sections('bono_arm_webhook');
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Webhook URL</th>
                    <td>
                        <input type="text" name="bono_arm_webhook_url"
                               value="<?php echo esc_url(get_option('bono_arm_webhook_url')); ?>"
                               size="80" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Secret Key</th>
                    <td>
                        <input type="password" name="bono_arm_webhook_secret"
                               value="<?php echo esc_attr(get_option('bono_arm_webhook_secret')); ?>"
                               size="50" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Enable webhook for profile updates</th>
                    <td>
                        <select name="bono_arm_webhook_profileupdates_enable">
                            <option value="no"  <?php selected(get_option('bono_arm_webhook_profileupdates_enable'), 'no'); ?>>No</option>
                            <option value="yes" <?php selected(get_option('bono_arm_webhook_profileupdates_enable'), 'yes'); ?>>Yes</option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <p>
            <a href="https://github.com/renatobo/WebHookARM" target="_blank" rel="noopener noreferrer">
                Plugin GitHub Repository
            </a> |
            <a href="https://github.com/renatobo" target="_blank" rel="noopener noreferrer">
                Author: Renato Bonomini
            </a>
        </p>
        <p>
            Example Google Apps Script: 
            <a href="<?php echo esc_url(content_url('plugins/WebHookARM/webhookarm_appscript.gs')); ?>"
               target="_blank" rel="noopener noreferrer">
                <?php echo esc_html(content_url('plugins/WebHookARM/webhookarm_appscript.gs')); ?>
            </a>
        </p>
    </div>
    <?php
}

// Register plugin settings
add_action('admin_init', function () {
    register_setting('bono_arm_webhook', 'bono_arm_webhook_profileupdates_enable');
    register_setting('bono_arm_webhook', 'bono_arm_webhook_url');
    register_setting('bono_arm_webhook', 'bono_arm_webhook_secret');
});
