# WebHookARM

[![WordPress](https://img.shields.io/badge/WordPress-Plugin-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![ARMember](https://img.shields.io/badge/ARMember-Required-ff6f00)](https://www.armemberplugin.com/)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D%208.0-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![WP Tested](https://img.shields.io/badge/WP%20Tested-6.9.4-21759B)](https://wordpress.org/)
[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL%20v2%20or%20later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Send ARMember profile updates to a secure JSON webhook for Google Apps Script, Make.com, or custom integrations.

## Features

- Hooks into ARMember `arm_update_profile_external` profile update event
- Sends payload as `application/json` via `POST`
- Adds shared secret in both query string and header for flexible receiver validation
- Configurable from a tabbed WordPress admin screen: **Settings -> ARMember WebHook**
- Git Updater-compatible release assets published automatically from GitHub Actions

## Requirements

- WordPress 5.0+
- PHP 8.0+
- ARMember plugin installed and active
- A webhook endpoint URL (Google Apps Script, Make.com, or custom API)

## Quick Start

1. Install and activate the plugin.
2. Open **Settings -> ARMember WebHook**.
3. Configure:
   - **Webhook URL**
   - **Secret Key**
   - **Enable webhook for profile updates** = `Yes`
4. Update an ARMember profile and verify your endpoint receives a `POST` payload.

## Installation

1. Copy the plugin to `/wp-content/plugins/WebHookARM`.
2. Activate **WebHookARM** in **Plugins**.
3. Go to **Settings -> ARMember WebHook**.
4. Save your webhook URL and secret key.

## Endpoint Configuration

### Google Apps Script + Google Sheets

Use the sample script in [`assets/webhookarm_appscript.gs`](assets/webhookarm_appscript.gs).

1. Open your Google Sheet.
2. Go to **Extensions -> Apps Script** and paste/adapt the script.
3. In **Project Settings -> Script properties**, set:
   - `AUTH_SECRET`
   - `SHEET_NAME`
4. Deploy as a **Web App**:
   - **Execute as**: `Me`
   - **Who has access**: `Anyone`
5. Copy the Web App URL into plugin settings.

### Make.com

1. Create an HTTP/Webhook scenario module.
2. Receive a `POST` with `application/json` body.
3. Validate one of:
   - Query param `key=<YOUR_SECRET>`
   - Header `X-Security-Key: <YOUR_SECRET>`
4. Process/store incoming fields as needed.

## Request Format

WebHookARM sends a `POST` request with:

- Query params:
  - `key=<secret>`
  - `action=profile_update`
- Headers:
  - `Content-Type: application/json`
  - `X-Security-Key: <secret>`
- JSON body:

```json
{
  "user_id": 123,
  "user_login": "johndoe",
  "user_email": "john@example.com"
}
```

ARMember form fields are included in the same payload when available.

## Security

- Use a strong secret key.
- Always validate the secret at the receiving endpoint.
- Use HTTPS for the webhook URL.
- Avoid logging sensitive data in production.

For security reporting, see [SECURITY.md](SECURITY.md).

## Automatic Updates (GitHub)

This plugin includes Git Updater-compatible headers and release assets. To receive dashboard updates:

1. Install [Git Updater](https://github.com/afragen/git-updater).
2. Keep this repository configured as your plugin source.
3. Use published GitHub releases as the update source; the repository automation builds the versioned zip asset automatically when a new version is tagged.

## Releases

Releases are generated automatically with GitHub Actions:

1. Update the version in `webhookarm.php` and `readme.txt`.
2. Push the change to `main`.
3. The `update-stable-tag` workflow creates the matching `vX.Y.Z` tag.
4. The `package-plugin` workflow builds the plugin zip and publishes the GitHub release asset.

Release packaging keeps only WordPress runtime files:

- Keeps `README.md`
- Removes all other `.md` files
- Removes `.sh` scripts that are not used by WordPress at runtime

Latest planned release: `1.3.1`

- Maintenance patch release to keep plugin version metadata, packaging, and release files synchronized across the repository.

## Troubleshooting

- No requests arriving: confirm plugin toggle is enabled and ARMember profile update event is firing.
- 401/403 at endpoint: verify secret key and validation logic.
- Invalid payload format: ensure receiver accepts `application/json`.
- Debugging: enable `WP_DEBUG` to inspect webhook send logs.

## FAQ

### Does this work without ARMember?

No. WebHookARM is triggered by ARMember profile update hooks.

### Can I send to something other than Google Sheets?

Yes. Any endpoint that accepts authenticated JSON `POST` requests is supported.

### Where do I get help?

- Open an issue: <https://github.com/renatobo/WebHookARM/issues>
- Repository: <https://github.com/renatobo/WebHookARM>

## Related repositories

- [ARMember Extended API Services (bono_arm_api)](https://github.com/renatobo/bono_arm_api)
- [TelegrARM](https://github.com/renatobo/TelegrARM)

## License

GPLv2 or later. See [LICENSE](LICENSE).
