=== WebHookARM ===
Contributors: renatobonomini
Tags: armember, webhook, google sheets, apps script, make, automation, profile update
Requires at least: 5.0
Tested up to: 6.9.4
Requires PHP: 8.0
Stable tag: 1.3.1
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
* Shared secret passed as both query parameter and header
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
   * `AUTH_SECRET`
   * `SHEET_NAME`
5. Deploy as a **Web App**:
   * **Execute as**: Me
   * **Who has access**: Anyone
6. Copy the Web App URL into plugin settings.

= Make.com =

1. Create an HTTP/Webhook scenario.
2. Accept `POST` requests with `application/json` body.
3. Validate either:
   * Query parameter: `key=<YOUR_SECRET>`
   * Header: `X-Security-Key: <YOUR_SECRET>`
4. Process/store payload values in your scenario.

== Request Format ==

WebHookARM sends:

* Method: `POST`
* Query params:
  * `key=<secret>`
  * `action=profile_update`
* Headers:
  * `Content-Type: application/json`
  * `X-Security-Key: <secret>`
* Body: JSON with ARMember form fields plus WordPress user data (`user_id`, `user_login`, `user_email`)

== Frequently Asked Questions ==

= Does this work without ARMember? =

No. WebHookARM is triggered by ARMember's `arm_update_profile_external` event.

= Can I use this without Google Sheets? =

Yes. Any endpoint that accepts authenticated JSON `POST` requests can be used.

= Why send the secret in query string and header? =

To support different receiver implementations. Your endpoint can validate one or both.

= How do I get plugin updates from GitHub? =

Install the Git Updater plugin: https://github.com/afragen/git-updater

= Where do I report security issues? =

See `SECURITY.md` in this repository: https://github.com/renatobo/WebHookARM

== Changelog ==

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

= 1.3.1 =
Maintenance patch release with synchronized version metadata and packaging.

= 1.3.0 =
Recommended update for improved admin hardening, clearer webhook safety guidance, and WordPress 6.9.4 compatibility metadata.

= 1.2.0 =
Recommended update for the new settings UI, Git Updater compatibility, and automated GitHub releases.
