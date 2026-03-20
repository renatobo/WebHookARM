<?php
/**
 * Plugin Name:       WebHookARM
 * Plugin URI:        https://github.com/renatobo/WebHookARM
 * Description:       Send ARMember profile updates to a secure JSON webhook for Google Apps Script, Make.com, or custom integrations.
 * Version:           1.3.0
 * Requires at least: 5.0
 * Requires PHP:      8.0
 * Tested up to:      6.9.4
 * Author:            Renato Bonomini
 * Author URI:        https://github.com/renatobo
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       webhookarm
 * Domain Path:       /languages
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

define('BONO_ARM_WEBHOOK_VERSION', '1.3.0');
define('BONO_ARM_WEBHOOK_OPTION_ENABLE', 'bono_arm_webhook_profileupdates_enable');
define('BONO_ARM_WEBHOOK_OPTION_URL', 'bono_arm_webhook_url');
define('BONO_ARM_WEBHOOK_OPTION_SECRET', 'bono_arm_webhook_secret');

if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    add_action('admin_notices', 'bono_arm_webhook_php_version_notice');
    return;
}

add_action('plugins_loaded', 'bono_arm_webhook_load_textdomain', 5);
add_action('plugins_loaded', 'bono_arm_webhook_bootstrap');
add_action('admin_menu', 'bono_arm_webhook_add_settings_page');
add_action('admin_init', 'bono_arm_webhook_register_settings');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'bono_arm_webhook_add_plugin_action_links');

/**
 * Load plugin translations.
 */
function bono_arm_webhook_load_textdomain() {
    load_plugin_textdomain('webhookarm', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

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
        add_action('arm_update_profile_external', 'bono_arm_webhook_send_to_sheet', 10, 2);
    }
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
 * Return the saved shared secret.
 *
 * @return string
 */
function bono_arm_webhook_get_secret() {
    return (string) get_option(BONO_ARM_WEBHOOK_OPTION_SECRET, '');
}

/**
 * Handle ARMember profile updates and send to webhook.
 *
 * @param int   $user_id   User ID.
 * @param array $form_data ARMember form data array.
 */
function bono_arm_webhook_send_to_sheet($user_id, $form_data) {
    $webhook_url = bono_arm_webhook_get_webhook_url();
    $secret_key = bono_arm_webhook_get_secret();

    if ('' === $webhook_url || '' === $secret_key) {
        return;
    }

    $user = get_userdata((int) $user_id);

    $payload = is_array($form_data) ? $form_data : array();
    $payload['user_id'] = (int) $user_id;
    $payload['user_login'] = $user ? $user->user_login : '';
    $payload['user_email'] = $user ? $user->user_email : '';

    $request_url = add_query_arg(
        array(
            'key' => $secret_key,
            'action' => 'profile_update',
        ),
        $webhook_url
    );

    $response = wp_remote_post(
        $request_url,
        array(
            'method' => 'POST',
            'redirection' => 5,
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Security-Key' => $secret_key,
            ),
            'body' => wp_json_encode($payload),
        )
    );

    if (defined('WP_DEBUG') && WP_DEBUG) {
        if (is_wp_error($response)) {
            error_log(
                sprintf(
                    'WebHookARM webhook failed for user %d: %s',
                    (int) $user_id,
                    $response->get_error_message()
                )
            );
        } else {
            error_log(
                sprintf(
                    'WebHookARM webhook sent for user %d. Response code: %d',
                    (int) $user_id,
                    (int) wp_remote_retrieve_response_code($response)
                )
            );
        }
    }
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

    return $sanitized;
}

/**
 * Sanitize the shared secret while preserving punctuation.
 *
 * @param mixed $value Submitted option value.
 * @return string
 */
function bono_arm_webhook_sanitize_secret($value) {
    if (!is_string($value)) {
        return '';
    }

    return (string) preg_replace('/[\r\n\t]+/', '', trim($value));
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
 * Render settings page.
 */
function bono_arm_webhook_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Sorry, you are not allowed to manage WebHookARM settings.', 'webhookarm'));
    }

    $webhook_enabled = bono_arm_webhook_is_enabled();
    $webhook_url = bono_arm_webhook_get_webhook_url();
    $secret_key = bono_arm_webhook_get_secret();
    $project_url = 'https://github.com/renatobo/WebHookARM';
    $author_url = 'https://github.com/renatobo';
    $git_updater_url = 'https://github.com/afragen/git-updater';
    $banner_url = plugins_url('assets/webhookarm-settings-banner.svg', __FILE__);
    $sample_script_url = plugins_url('assets/webhookarm_appscript.gs', __FILE__);
    $example_request_url = 'https://hooks.example.com/profile-sync?action=profile_update&key=your-secret';
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
                        <?php esc_html_e('HTTP endpoints can be useful for local testing, but production webhook traffic should use HTTPS so the shared secret and profile data are not sent in clear text.', 'webhookarm'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper webhookarm-tabs" role="tablist" aria-label="<?php echo esc_attr__('WebHookARM sections', 'webhookarm'); ?>">
                <a href="#webhook" class="nav-tab webhookarm-tab nav-tab-active" id="webhookarm-tab-webhook" role="tab" aria-controls="webhook" aria-selected="true" data-panel="webhook">
                    <?php esc_html_e('Webhook', 'webhookarm'); ?>
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
                                <small><?php esc_html_e('Use an HTTPS endpoint in production. HTTP can still be useful for local testing.', 'webhookarm'); ?></small>
                            </label>
                            <label class="webhookarm-field">
                                <span><?php esc_html_e('Secret key', 'webhookarm'); ?></span>
                                <input
                                    type="password"
                                    class="regular-text code"
                                    name="<?php echo esc_attr(BONO_ARM_WEBHOOK_OPTION_SECRET); ?>"
                                    value="<?php echo esc_attr($secret_key); ?>"
                                    autocomplete="off"
                                    placeholder="<?php echo esc_attr__('shared-secret', 'webhookarm'); ?>"
                                />
                                <small>
                                    <?php
                                    echo wp_kses(
                                        __('Sent as both the <code>key</code> query parameter and the <code>X-Security-Key</code> header.', 'webhookarm'),
                                        array('code' => array())
                                    );
                                    ?>
                                </small>
                            </label>
                        </div>

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
                                <button class="button button-secondary button-small" onclick="webhookarmCopy('webhookarm-example-request'); return false;"><?php esc_html_e('Copy', 'webhookarm'); ?></button>
                            </div>
                            <div class="webhookarm-example">
                                <strong><?php esc_html_e('Bundled Apps Script sample', 'webhookarm'); ?></strong>
                                <code id="webhookarm-apps-script-url"><?php echo esc_html($sample_script_url); ?></code>
                                <button class="button button-secondary button-small" onclick="webhookarmCopy('webhookarm-apps-script-url'); return false;"><?php esc_html_e('Copy', 'webhookarm'); ?></button>
                            </div>
                        </div>
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
                                <span><code>X-Security-Key: your-secret</code></span>
                            </div>
                            <div class="webhookarm-code-card">
                                <strong><?php esc_html_e('Query parameters', 'webhookarm'); ?></strong>
                                <span><code>key=your-secret</code></span>
                                <span><code>action=profile_update</code></span>
                            </div>
                        </div>

                        <pre><?php echo esc_html($payload_example); ?></pre>

                        <p class="webhookarm-note">
                            <?php esc_html_e('ARMember field keys vary by site and form configuration. The plugin forwards them as-is and only guarantees the appended WordPress identity fields listed above.', 'webhookarm'); ?>
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
                            <li><?php echo wp_kses(__('Set <code>AUTH_SECRET</code> and <code>SHEET_NAME</code> in Script properties.', 'webhookarm'), array('code' => array())); ?></li>
                            <li><?php esc_html_e('Deploy the project as a Web App and paste that URL into the Webhook tab.', 'webhookarm'); ?></li>
                            <li><?php esc_html_e('Validate the secret from either the query string or the request header before writing rows.', 'webhookarm'); ?></li>
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
                            <li><?php echo wp_kses(__('Validate the shared secret from <code>key</code> or <code>X-Security-Key</code>.', 'webhookarm'), array('code' => array())); ?></li>
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
                        <div class="webhookarm-grid webhookarm-grid-two">
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
                            <div class="webhookarm-code-card">
                                <strong><?php esc_html_e('Automated releases', 'webhookarm'); ?></strong>
                                <span>
                                    <?php
                                    echo wp_kses(
                                        __('Pushing a new version to <code>main</code> tags <code>vX.Y.Z</code>, builds a release zip, and publishes the GitHub release automatically.', 'webhookarm'),
                                        array('code' => array())
                                    );
                                    ?>
                                </span>
                            </div>
                        </div>

                        <p class="webhookarm-note">
                            <?php
                            echo wp_kses(
                                __('Keep the plugin header version and <code>readme.txt</code> stable tag in sync before release. The repository automation uses those values as the source of truth.', 'webhookarm'),
                                array('code' => array())
                            );
                            ?>
                        </p>
                    </div>
                </section>

                <div class="webhookarm-footer">
                    <?php submit_button(__('Save settings', 'webhookarm'), 'primary', 'submit', false); ?>
                </div>
            </form>
        </div>

        <style>
            .webhookarm-admin {
                max-width: 1120px;
                margin-top: 18px;
            }

            .webhookarm-hero {
                margin: 0 0 16px;
                border: 1px solid #c8ccd0;
                background: #f6f7f7;
                display: block;
                max-width: 750px;
                width: fit-content;
            }

            .webhookarm-hero-image {
                display: block;
                width: min(100%, 750px);
                height: auto;
            }

            .webhookarm-headline {
                margin: 8px 0 20px;
            }

            .webhookarm-headline h1 {
                margin: 0 0 8px;
                font-size: 42px;
                line-height: 1.1;
                color: #0f172a;
                font-weight: 400;
            }

            .webhookarm-intro,
            .webhookarm-panel-header p,
            .webhookarm-note,
            .webhookarm-switch-row p,
            .webhookarm-field small {
                margin: 0;
                color: #475569;
                font-size: 14px;
                line-height: 1.65;
            }

            .webhookarm-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                margin: 16px 0 10px;
            }

            .webhookarm-meta a,
            .webhookarm-meta span {
                display: inline-flex;
                align-items: center;
                min-height: 36px;
                padding: 0 14px;
                background: #f6f7f7;
                border: 1px solid #c3c4c7;
                color: #0f172a;
                text-decoration: none;
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
            }

            .webhookarm-meta a:hover {
                border-color: #2271b1;
                color: #2271b1;
            }

            .webhookarm-intro {
                margin-bottom: 20px;
                max-width: 76ch;
            }

            .webhookarm-intro-secondary {
                margin-top: -8px;
            }

            .webhookarm-tabs {
                margin: 24px 0 0;
            }

            .webhookarm-tabs .webhookarm-tab {
                display: inline-block;
                float: none;
            }

            .webhookarm-tabs .webhookarm-tab:focus {
                box-shadow: 0 0 0 1px #2271b1;
            }

            .webhookarm-shell {
                display: grid;
                gap: 18px;
                padding-top: 20px;
            }

            .webhookarm-panel {
                display: grid;
                gap: 18px;
            }

            .webhookarm-panel[hidden] {
                display: none;
            }

            .webhookarm-panel-header h2,
            .webhookarm-switch-row h3,
            .webhookarm-field span {
                margin: 0 0 8px;
                color: #0f172a;
            }

            .webhookarm-card {
                padding: 22px;
                border: 1px solid #c3c4c7;
                background: #ffffff;
            }

            .webhookarm-card-accent {
                border-left: 4px solid #72aee6;
                background: #f6f7f7;
            }

            .webhookarm-switch-row {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 18px;
                margin-bottom: 18px;
            }

            .webhookarm-toggle {
                display: inline-flex;
                gap: 10px;
                align-items: center;
                background: #ffffff;
                border: 1px solid #c3c4c7;
                padding: 12px 14px;
                font-weight: 600;
                color: #0f172a;
            }

            .webhookarm-field-grid,
            .webhookarm-grid,
            .webhookarm-example-grid {
                display: grid;
                gap: 14px;
            }

            .webhookarm-field-grid,
            .webhookarm-grid-two,
            .webhookarm-example-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .webhookarm-grid-three {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                margin-top: 18px;
            }

            .webhookarm-field {
                display: grid;
                gap: 8px;
            }

            .webhookarm-field input {
                width: 100%;
                max-width: none;
            }

            .webhookarm-code-card,
            .webhookarm-example {
                display: grid;
                gap: 8px;
                padding: 14px;
                border: 1px solid #dcdcde;
                background: #ffffff;
            }

            .webhookarm-example .button {
                width: fit-content;
            }

            .webhookarm-footer {
                display: flex;
                justify-content: flex-start;
            }

            .webhookarm-steps {
                margin: 0;
                padding-left: 18px;
                color: #1e293b;
            }

            .webhookarm-steps li + li {
                margin-top: 8px;
            }

            code,
            pre {
                background: #f1f1f1;
                border-radius: 4px;
            }

            code {
                padding: 2px 6px;
            }

            pre {
                padding: 12px;
                overflow-x: auto;
                margin: 18px 0;
            }

            @media (max-width: 960px) {
                .webhookarm-field-grid,
                .webhookarm-grid-two,
                .webhookarm-grid-three,
                .webhookarm-example-grid,
                .webhookarm-switch-row {
                    grid-template-columns: 1fr;
                    display: grid;
                }

                .webhookarm-switch-row {
                    justify-content: stretch;
                }
            }
        </style>
        <script>
        function webhookarmCopy(elementId) {
            const source = document.getElementById(elementId);

            if (!source || !navigator.clipboard) {
                return;
            }

            navigator.clipboard.writeText(source.textContent);
        }

        document.addEventListener('DOMContentLoaded', function () {
            const tabs = document.querySelectorAll('.webhookarm-tab');
            const panels = document.querySelectorAll('.webhookarm-panel');

            function activateTab(targetPanel, updateHash) {
                let hasMatch = false;

                tabs.forEach(function (item) {
                    const isTarget = item.getAttribute('data-panel') === targetPanel;
                    item.classList.toggle('nav-tab-active', isTarget);
                    item.setAttribute('aria-selected', isTarget ? 'true' : 'false');
                    hasMatch = hasMatch || isTarget;
                });

                panels.forEach(function (panel) {
                    const isTarget = panel.getAttribute('data-panel') === targetPanel;
                    panel.classList.toggle('is-active', isTarget);
                    panel.hidden = !isTarget;
                });

                if (hasMatch && updateHash) {
                    window.location.hash = targetPanel;
                }
            }

            tabs.forEach(function (tab) {
                tab.addEventListener('click', function (event) {
                    event.preventDefault();
                    activateTab(tab.getAttribute('data-panel'), true);
                });
            });

            const initialPanel = window.location.hash ? window.location.hash.replace('#', '') : 'webhook';
            activateTab(initialPanel, false);

            window.addEventListener('hashchange', function () {
                const hashPanel = window.location.hash ? window.location.hash.replace('#', '') : 'webhook';
                activateTab(hashPanel, false);
            });
        });
        </script>
    </div>
    <?php
}
