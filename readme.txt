=== WebHookARM ===
Contributors: renatobonomini
Tags: armember, webhook, google sheets, apps script, make, automation, profile update
Requires at least: 5.0
Tested up to: 6.8.1
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WebHookARM listens for ARMember profile updates and sends user data as JSON to a secure webhook endpoint (Google Apps Script, Make.com, or custom API).

== Description ==

WebHookARM is a lightweight WordPress plugin for ARMember-based sites.

When a user updates their profile, the plugin hooks into `arm_update_profile_external` and sends a JSON `POST` payload to your configured webhook URL.

Key capabilities:

* ARMember profile update trigger
* JSON webhook delivery (`application/json`)
* Shared secret passed as both query parameter and header
* Admin settings page under **Settings > ARMember WebHook**
* Optional update flow through GitHub Updater

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

Install the GitHub Updater plugin: https://github.com/afragen/github-updater

= Where do I report security issues? =

See `SECURITY.md` in this repository: https://github.com/renatobo/WebHookARM

== Changelog ==

= 1.1.0 =
* Added enable/disable gate for profile update webhook registration.
* Improved settings flow and documentation.
* Updated readme content and compatibility metadata.

= 1.0.0 =
* Initial public release.

== Upgrade Notice ==

= 1.1.0 =
Recommended update for current configuration flow and latest documented setup.
