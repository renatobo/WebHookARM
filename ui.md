# WebHookARM UI Notes

## Settings Header

- Use the banner image at `assets/webhookarm-settings-banner.svg` at the top of the settings page.
- Render the banner near its authored width instead of stretching it across the full admin container.
  - cap the hero near `750px`
  - allow it to shrink on narrow screens without growing beyond the native size
- Below the banner, keep the compact metadata row with:
  - `Plugin Repository`
  - current plugin version
  - author GitHub link
  - single-button link: `Updates via Git Updater`

## Settings Intro Copy

- Keep the page title `WebHookARM Settings`.
- Keep the intro focused on:
  - ARMember profile update delivery
  - payload review
  - receiver setup for Apps Script or Make.com
- Keep the secondary security note directly below the intro:
  - recommend HTTPS in production
  - validate the shared secret on the receiver
  - avoid exposing request data in logs outside local debugging

## Tabs

- Use native WordPress tab markup:
  - `nav-tab-wrapper`
  - `nav-tab`
  - `nav-tab-active`
- Tabs should remain in this order:
  - `Webhook`
  - `Payload`
  - `Apps Script`
  - `Make.com`
  - `Updates`
- Tabs are in-page panels, not separate admin pages.
- Switching tabs should:
  - show only the active panel
  - hide inactive panels with the `hidden` attribute
  - update the URL hash
  - restore the active tab from the URL hash on load

## Panel Layout

- Keep the layout WordPress-admin friendly, not app-like.
- Prefer flat cards, subtle borders, and native admin spacing.
- Keep the form controls on the `Webhook` tab, followed by the `Delivery status` card with the last outcome and the `Send test delivery` button.
- The test button submits a separate form outside the settings form (via the `form` attribute), so it never saves settings.
- Styles and scripts live in `assets/admin.css` and `assets/admin.js`, enqueued only on the settings screen. Scope any generic selector under `.webhookarm-admin`.
- Keep `Payload`, `Apps Script`, `Make.com`, and `Updates` as separate tabs.

## Maintenance

- Keep the plugin asset filenames aligned with the release/update UI:
  - `assets/icon.svg`
  - `assets/icon-128x128.png`
  - `assets/icon-256x256.png`
  - `assets/webhookarm-settings-banner.svg`
- When cutting a release, keep these version references synchronized:
  - `webhookarm.php` plugin header `Version`
  - `webhookarm.php` constant `BONO_ARM_WEBHOOK_VERSION`
  - `readme.txt` `Stable tag`
- Keep docs aligned with the current settings tabs and release automation.
