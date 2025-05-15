=== WebHookARM ===
Contributors: renatobonomini
Tags: ARMember, webhook, google sheets, apps script, automation, profile update
Requires at least: 5.0
Tested up to: 6.8.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight WordPress plugin that listens for ARMember profile update events and securely sends the user data as JSON to a Google Apps Script Web App or any endpoint (e.g., Make.com) to append it to a Google Sheet.

== Installation ==

1. Upload the plugin folder `WebHookARM` to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Settings > ARMember to WebHook**.
4. Configure your settings:
   - **Webhook URL**: Your Google Apps Script Web App URL or endpoint URL.
   - **Secret Key**: Must match `AUTH_SECRET` (Apps Script) or your endpoint config.
   - **Enable Webhook**: Toggle on/off.

5. (Optional) To enable automatic updates from GitHub:
   - Install the <a href="https://github.com/afragen/github-updater" target="_blank">GitHub Updater plugin</a>.
   - Make sure this plugin includes the line "GitHub Plugin URI" in its header (already included).
   - Updates will now appear like any other plugin in your WordPress dashboard.

== Configuration ==

For Google Apps Script:
1. Open your target Google Sheet.
2. Go to **Extensions > Apps Script** and paste the provided script.
3. In **Project Settings**, add Script Properties:
   - `AUTH_SECRET` — your secure token.
   - `SHEET_NAME` — name of the sheet tab.
4. Deploy the script as a Web App:
   - **Execute as**: Me
   - **Who has access**: Anyone

For Make.com:
- Use an HTTP module to `POST` to your endpoint URL.
- Add `key=YOUR_SECRET` and `action=profile_update` as query parameters.
- Set body type to `application/json`.

== Frequently Asked Questions ==

= Does this work without ARMember? =
No. This plugin specifically hooks into ARMember's `arm_update_profile_external` event.

= How do I configure the Google Sheet? =
Create a sheet tab matching `SHEET_NAME` and add a header row with:
| Timestamp | User ID | User Login | User Email | Raw JSON Payload |

= How do I enable automatic updates? =
Install the GitHub Updater plugin (https://github.com/afragen/github-updater) to receive plugin updates directly from GitHub. 
Once installed, it will automatically check for new versions of WebHookARM and allow one-click updates from your WordPress dashboard.

= How do I get support? =
Open an issue at https://github.com/renatobo/WebHookARM or contact Renato Bonomini.

== Changelog ==

= 1.0 =
* Initial release.

== Upgrade Notice ==

= 1.0 =
First release.
