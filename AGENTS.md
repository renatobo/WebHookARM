# AGENTS.md

## Project

WebHookARM is a WordPress plugin that sends ARMember profile update data to a webhook endpoint.

Main files:
- `webhookarm.php` (plugin logic + settings page)
- `readme.txt` (WordPress.org readme format)
- `README.md` (GitHub readme)
- `assets/webhookarm_appscript.gs` (sample Apps Script receiver)

## Expectations for Changes

- Keep plugin metadata in sync across:
  - `webhookarm.php`
  - `readme.txt`
  - `README.md`
- Preserve WordPress coding patterns and compatibility with PHP 8.0+.
- Keep security behavior intact:
  - secret key validation support
  - HTTPS webhook usage guidance
  - no sensitive data exposure in production logs

## Versioning

When releasing:
- Update `Version` in `webhookarm.php`
- Update `Stable tag` and changelog in `readme.txt`
- Reflect notable changes in `README.md`

## Validation Checklist

Before finishing changes:
- Confirm no PHP syntax errors in edited files.
- Confirm `readme.txt` remains valid WordPress readme format.
- Confirm docs match actual behavior in `webhookarm.php`.

## Non-Goals

- Do not introduce breaking changes to existing option keys:
  - `bono_arm_webhook_profileupdates_enable`
  - `bono_arm_webhook_url`
  - `bono_arm_webhook_secret`
