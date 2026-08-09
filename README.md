# WebHookARM

[![WordPress](https://img.shields.io/badge/WordPress-Plugin-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![ARMember](https://img.shields.io/badge/ARMember-Required-ff6f00)](https://www.armemberplugin.com/)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D%208.0-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![WP Tested](https://img.shields.io/badge/WP%20Tested-7.0.2-21759B)](https://wordpress.org/)
[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL%20v2%20or%20later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Send ARMember profile updates to a secure JSON webhook for Google Apps Script, Make.com, or custom integrations.

## Features

- Hooks into ARMember `arm_update_profile_external` profile update event
- Sends payload as `application/json` via `POST`
- Signs requests with timestamped HMAC-SHA256 authentication
- Queues delivery outside the profile request and retries transient failures
- Redacts credential-like fields and caps serialized payloads at 256 KiB
- Configurable from a tabbed WordPress admin screen: **Settings -> ARMember WebHook**
- Git Updater-compatible release assets published automatically from GitHub Actions

## Requirements

- WordPress 7.0+
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
   - `WA_AUTH_SECRET`
   - `WA_SHEET_NAME`
4. Deploy as a **Web App**:
   - **Execute as**: `Me`
   - **Who has access**: `Anyone`
5. Copy the Web App URL into plugin settings.

### Make.com

1. Create an HTTP/Webhook scenario module.
2. Receive a `POST` with `application/json` body.
3. Validate the HMAC signature using your shared secret over `<delivery id>.<timestamp>.<raw request body>`.
4. Process/store incoming fields as needed.

## Request Format

WebHookARM sends a `POST` request with:

- Query params:
  - `action=profile_update`
  - `delivery=<uuid>`
  - `signature=<hmac>`
  - `timestamp=<unix timestamp>`
- Headers:
  - `Content-Type: application/json`
  - `X-WebhookARM-Delivery: <uuid>`
  - `X-WebhookARM-Signature: sha256=<hmac>`
  - `X-WebhookARM-Timestamp: <unix timestamp>`
- JSON body:

```json
{
  "user_id": 123,
  "user_login": "johndoe",
  "user_email": "john@example.com"
}
```

ARMember form fields are included in the same payload when available.

Credential-like keys (passwords, tokens, nonces, authentication secrets, and payment-card fields) are removed recursively before queueing. Developers can further restrict or reshape the payload with the `bono_arm_webhook_payload` filter. Queued payloads expire after one day; successful and permanently failed deliveries are removed immediately.

Delivery uses WP-Cron with retry delays of 1, 5, and 15 minutes for transient failures. Sites that disable WordPress's request-driven cron must invoke `wp-cron.php` from a system scheduler.

## Security

- Use a strong secret key.
- Always validate the secret at the receiving endpoint.
- Use HTTPS for the webhook URL.
- Avoid logging sensitive data in production.
- Treat the delivery UUID as an idempotency key so retried requests are not processed twice.

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

Latest planned release: `2.0.0`

- Major security and reliability release introducing asynchronous retries, signed requests, bounded payload handling, and a hardened Apps Script receiver.

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
