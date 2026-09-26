# WebHookARM

[![WordPress](https://img.shields.io/badge/WordPress-Plugin-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![ARMember](https://img.shields.io/badge/ARMember-Required-ff6f00)](https://www.armemberplugin.com/)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D%208.0-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![WP Tested](https://img.shields.io/badge/WP%20Tested-7.1.2-21759B)](https://wordpress.org/)
[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL%20v2%20or%20later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Send ARMember profile updates to a secure JSON webhook for Google Apps Script, Make.com, or custom integrations.

## Features

- Hooks into ARMember `arm_update_profile_external` profile update event
- Sends payload as `application/json` via `POST`
- Signs requests with timestamped HMAC-SHA256 authentication
- Queues delivery outside the profile request and retries transient failures
- Redacts credential-like fields and caps serialized payloads at 256 KiB
- Shows the latest delivery outcome, delivered and failed totals, and the last permanent failure, and sends a signed test delivery from the settings screen
- Accepts the shared secret from a `WEBHOOKARM_SECRET` constant in `wp-config.php`
- Optional field allowlist: send only the ARMember fields you list
- Keeps permanently failed deliveries for 7 days and resends them with one click once the receiver is fixed
- Configurable from a tabbed WordPress admin screen: **Settings -> ARMember WebHook**
- Git Updater-compatible release assets published automatically from GitHub Actions

## Requirements

- WordPress 7.0+
- PHP 8.0+
- ARMember premium installed and active. It runs on top of the free ARMember Lite (`armember-membership`), which is also required. Lite alone never sends profile updates, and the settings page warns when premium is missing.
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

Credential-like keys (passwords, tokens, nonces, authentication secrets, payment-card fields, SSNs, IBANs, API and private keys, and security answers) are removed recursively before queueing. Queued payloads expire after one day; successful and permanently failed deliveries are removed immediately.

Delivery uses WP-Cron with retry delays of 1, 5, and 15 minutes for transient failures. Sites that disable WordPress's request-driven cron must invoke `wp-cron.php` from a system scheduler.

Redirects are not followed; receivers must answer the configured URL directly, and a redirect is treated as a failed attempt. Google Apps Script is handled specially: it answers every POST with a 302 to a `script.googleusercontent.com` URL holding its reply, so for `script.google.com` URLs the plugin fetches that reply with a separate, bodyless GET. (WordPress cannot follow that redirect itself: it switches a 302 to GET but still sends the JSON body, and Google answers that with 400.)

### Receiver replies

Google Apps Script cannot set an HTTP status code, so a `2xx` reply is also checked for these bodies:

- `Request rejected`: permanent failure, not retried
- `Retry later`: temporary failure, retried. The bundled sample sends it when its lock is busy or an unexpected service error occurs.

Other receivers should use status codes: `2xx` for success, `408`/`429`/`5xx` to retry, any other `4xx` to stop.

## Developer Hooks

| Hook | Type | Purpose |
|---|---|---|
| `bono_arm_webhook_payload` | filter | Reshape or allowlist the payload |
| `bono_arm_webhook_redaction_pattern` | filter | Change the regex matched against payload keys for redaction |
| `bono_arm_webhook_max_payload_bytes` | filter | Change the 256 KiB payload cap |
| `bono_arm_webhook_request_args` | filter | Adjust timeout, redirects, or add headers (signing headers and body are re-applied) |
| `bono_arm_webhook_effective_status` | filter | Map a receiver reply to the status the retry logic uses |
| `bono_arm_webhook_allow_insecure_url` | filter | Allow an HTTP URL for local testing |
| `bono_arm_webhook_spawn_cron` | filter | Spawn WP-Cron right after queueing (off by default) |
| `bono_arm_webhook_keep_failed_deliveries` | filter | Return false to discard permanently failed deliveries instead of keeping them for 7 days |
| `bono_arm_webhook_delivery_succeeded` | action | Delivery id, status, attempt |
| `bono_arm_webhook_delivery_failed` | action | Delivery id, status, attempts, after the delivery is abandoned |

To send only specific fields without code, list their keys under **Send only these fields** on the Webhook tab, one per line. `user_id`, `user_login`, and `user_email` are always sent, and only top-level keys are matched.

Example allowlist in code:

```php
add_filter('bono_arm_webhook_payload', function ($payload) {
    return array_intersect_key($payload, array_flip(array('first_name', 'last_name', 'user_id', 'user_login', 'user_email')));
});
```

## Security

- Use a strong secret key. To keep it out of the database, define it in `wp-config.php` as `define('WEBHOOKARM_SECRET', '...');`.
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

Latest planned release: `2.2.0`

- Keeps permanently failed deliveries for 7 days and resends them on request.
- Optional "Send only these fields" allowlist.
- Warns when ARMember premium is not active.
- Schedules the daily cleanup from the queue path too.

Previous release: `2.1.3`

- Stops the daily cleanup and deactivation from erasing the Delivery status totals and last failure.

Previous release: `2.1.2`

- Declares compatibility with WordPress 7.1.2. No code changes.

Previous release: `2.1.1`

- Fixes Google Apps Script deliveries being recorded as failed with HTTP 400 by fetching the Apps Script reply with a plain GET instead of letting WordPress follow the redirect.

Previous release: `2.1.0`

- Recognises Apps Script rejection replies instead of recording them as delivered, and lets an updated sample script ask for a retry.
- Moves the delivery queue out of transients so a persistent object cache cannot evict pending deliveries.
- Adds a delivery status panel, a test delivery button, delivery hooks, and a `WEBHOOKARM_SECRET` constant.
- Stops following redirects for receivers other than Google Apps Script.
- Cleans every site on multisite uninstall, and removes queued data on deactivation.

## Troubleshooting

- No requests arriving: confirm plugin toggle is enabled and ARMember profile update event is firing.
- 401/403 at endpoint: verify secret key and validation logic.
- Invalid payload format: ensure receiver accepts `application/json`.
- Check **Delivery status** on the Webhook tab, or use **Send test delivery**.
- After fixing a receiver, use **Resend failed deliveries** on the same card (up to 50 per click). Failed deliveries are kept for 7 days, removed on deactivation, uninstall, or when their user is deleted, and resent under their original delivery ids with their original payloads. A receiver that deduplicates by id skips any it already stored; the bundled Apps Script only remembers ids for 6 hours. A resend can overwrite newer data at a receiver that updates records in place, and it ignores allowlist changes made after the original save.
- Debugging: enable `WP_DEBUG` to inspect webhook send logs.

## FAQ

### Does this work without ARMember?

No. WebHookARM is triggered by ARMember's `arm_update_profile_external` event, which only ARMember premium fires, when a logged-in member saves their profile on the frontend. ARMember Lite alone never fires it, and admin edits, member imports, and bulk actions don't either.

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
