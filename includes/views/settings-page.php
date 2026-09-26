<?php
/**
 * Settings page markup, rendered by bono_arm_webhook_settings_page().
 *
 * @package WebHookARM
 *
 * @var bool   $webhook_enabled
 * @var string $webhook_url
 * @var string $secret_key
 * @var bool   $secret_from_constant
 * @var bool   $has_stored_secret
 * @var bool   $premium_active
 * @var string $field_allowlist
 * @var string $project_url
 * @var string $author_url
 * @var string $git_updater_url
 * @var string $banner_url
 * @var string $sample_script_url
 * @var string $example_request_url
 * @var string $payload_example
 */

/** @psalm-suppress ParadoxicalCondition */
if (!defined('ABSPATH')) {
    exit;
}
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

            <?php if (!$premium_active) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <strong><?php esc_html_e('ARMember premium is not active.', 'webhookarm'); ?></strong>
                        <?php esc_html_e('Only ARMember premium sends profile updates to WebHookARM. With ARMember Lite alone, no profile update is ever delivered, although the settings and Send test delivery still work.', 'webhookarm'); ?>
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

                        <label class="webhookarm-field webhookarm-field-allowlist">
                            <span><?php esc_html_e('Send only these fields (optional)', 'webhookarm'); ?></span>
                            <textarea
                                class="large-text code"
                                rows="4"
                                name="<?php echo esc_attr(BONO_ARM_WEBHOOK_OPTION_FIELD_ALLOWLIST); ?>"
                                placeholder="<?php echo esc_attr("first_name\nlast_name\nphone"); ?>"
                            ><?php echo esc_textarea($field_allowlist); ?></textarea>
                            <small>
                                <?php echo wp_kses(__('One ARMember field key per line. Leave empty to send every field except credential-like ones. <code>user_id</code>, <code>user_login</code>, and <code>user_email</code> are always sent. Only top-level keys are matched.', 'webhookarm'), array('code' => array())); ?>
                            </small>
                        </label>

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
                        <?php if (!$premium_active) : ?>
                            <p class="webhookarm-note"><strong><?php esc_html_e('No profile updates will be queued: ARMember premium is not active.', 'webhookarm'); ?></strong></p>
                        <?php endif; ?>
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
                            <?php echo wp_kses(__('To send only specific fields, list them under <strong>Send only these fields</strong> on the Webhook tab. Developers can reshape the payload further with the <code>bono_arm_webhook_payload</code> filter, or change which keys are redacted with <code>bono_arm_webhook_redaction_pattern</code>. Delivery outcomes fire <code>bono_arm_webhook_delivery_succeeded</code> and <code>bono_arm_webhook_delivery_failed</code>.', 'webhookarm'), array('code' => array(), 'strong' => array())); ?>
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
