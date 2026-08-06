=== WebHookARM ===
Contributors: renatobonomini
Tags: armember, webhook, google sheets, apps script, make, automation, profile update
Requires at least: 6.5
Tested up to: 7.0.2
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

GitHub Plugin URI: https://github.com/renatobo/WebHookARM
GitHub Branch: main

Send ARMember profile updates to a secure JSON webhook for Google Apps Script, Make.com, or custom integrations.

== Description ==

WebHookARM is a lightweight WordPress plugin for ARMember-based sites.

When a user updates their profile, the plugin hooks into `arm_update_profile_external` and sends a JSON `POST` payload to your configured webhook URL.

Key capabilities:

* ARMember profile update trigger
* JSON webhook delivery (`application/json`)
* Timestamped HMAC-SHA256 request signatures
* Asynchronous delivery with bounded retries
* Tabbed admin settings page under **Settings > ARMember WebHook**
* Git Updater-compatible release assets published automatically from GitHub Actions

The plugin works well with:

* Google Apps Script + Google Sheets
* Make.com scenarios
* Any endpoint that accepts authenticated JSON requests

== Installation ==

1. Upload the `WebHookARM` folder to `/wp-content/plugins/`.
2. Activate the plugin in **Plugins**.
3. Go to **Settings > ARMember WebHook**.
4. Configure:
   * **Webhook URL**
   * **Secret Key**
   * **Enable webhook for profile updates** = `Yes`
5. Save settings.

== Configuration ==

= Google Apps Script + Google Sheets =

1. Open your target Google Sheet.
2. Go to **Extensions > Apps Script**.
3. Use the sample file `assets/webhookarm_appscript.gs` as your base.
4. In **Project Settings > Script properties**, add:
   * `WA_AUTH_SECRET`
   * `WA_SHEET_NAME`
5. Deploy as a **Web App**:
   * **Execute as**: Me
   * **Who has access**: Anyone
6. Copy the Web App URL into plugin settings.

= Make.com =

1. Create an HTTP/Webhook scenario.
2. Accept `POST` requests with `application/json` body.
3. Validate `X-WebhookARM-Signature` over `<delivery id>.<timestamp>.<raw request body>`.
4. Process/store payload values in your scenario.

== Request Format ==

WebHookARM sends:

* Method: `POST`
* Query params:
  * `action=profile_update`
  * `delivery=<uuid>`
  * `signature=<hmac>` (for receivers such as Apps Script that cannot read headers)
  * `timestamp=<unix timestamp>`
* Headers:
  * `Content-Type: application/json`
  * `X-WebhookARM-Delivery: <uuid>`
  * `X-WebhookARM-Signature: sha256=<hmac>`
  * `X-WebhookARM-Timestamp: <unix timestamp>`
* Body: JSON with ARMember form fields plus WordPress user data (`user_id`, `user_login`, `user_email`)

Credential-like keys are removed recursively and payloads are capped at 256 KiB before being queued. Transient failures are retried after 1, 5, and 15 minutes. Queued data expires after one day. Sites with request-driven WP-Cron disabled must invoke `wp-cron.php` from a system scheduler.

== Frequently Asked Questions ==

= Does this work without ARMember? =

No. WebHookARM is triggered by ARMember's `arm_update_profile_external` event.

= Can I use this without Google Sheets? =

Yes. Any endpoint that accepts authenticated JSON `POST` requests can be used.

= Is the secret included in the URL? =

No. URLs contain only a short-lived signature, timestamp, action, and delivery identifier. The shared secret is used to calculate the signature and is never transmitted.

= How do I get plugin updates from GitHub? =

Install the Git Updater plugin: https://github.com/afragen/git-updater

= Where do I report security issues? =

See `SECURITY.md` in this repository: https://github.com/renatobo/WebHookARM

== Changelog ==

= 2.0.0 =
* Replaced synchronous webhook calls with asynchronous WP-Cron delivery and bounded retry handling.
* Added HMAC-SHA256 request authentication over the delivery id, timestamp, and raw body without transmitting the shared secret.
* Added credential-field redaction, payload size limits, safe HTTP requests, HTTPS enforcement, and delivery identifiers.
* Hardened the bundled Apps Script receiver with replay protection, idempotency, concurrency locking, formula-injection protection, and privacy-safe diagnostics.
* Added an "Upgrade to 2.0" settings tab documenting the migration, plus a dismissible admin warning for sites upgrading from a version whose receiver configuration is no longer compatible.
* Added delivery regression tests, stronger CI packaging checks, ARMember dependency metadata, and WordPress 7.0 compatibility metadata.

= 1.3.1 =
* Maintenance patch release to keep plugin version metadata, packaging, and release files synchronized across the repository.

= 1.3.0 =
* Added translation hooks, capability checks, and clearer admin notices around incomplete or non-HTTPS webhook configuration.
* Refreshed compatibility metadata for WordPress 6.9.4 and shortened the plugin summary to satisfy the official readme validator limit.
* Hardened release documentation and security policy details for the current supported release line.

= 1.2.0 =
* Reworked the settings screen to match the tabbed WordPress admin UI used in the eventon-apify plugin.
* Added Git Updater release asset metadata and a Plugins screen Settings shortcut.
* Switched release packaging to an automated GitHub Actions flow that tags, builds, and publishes versioned zip releases.
* Reduced debug logging detail so request payloads are not written to logs.

= 1.1.0 =
* Added enable/disable gate for profile update webhook registration.
* Improved settings flow and documentation.
* Updated readme content and compatibility metadata.

= 1.0.0 =
* Initial public release.

== Upgrade Notice ==

= 2.0.0 =
Major delivery and authentication upgrade. Receivers built for 1.x must be updated before deliveries will be accepted: validate the HMAC signature over the delivery id, timestamp, and raw body, because the shared secret is no longer included in request URLs. Sites upgrading from an earlier version get an admin warning linking to the step-by-step guide on the plugin's "Upgrade to 2.0" tab.

= 1.3.1 =
Maintenance patch release with synchronized version metadata and packaging.

= 1.3.0 =
Recommended update for improved admin hardening, clearer webhook safety guidance, and WordPress 6.9.4 compatibility metadata.

= 1.2.0 =
Recommended update for the new settings UI, Git Updater compatibility, and automated GitHub releases.
