# WebHookARM

WordPress plugin. Sends ARMember profile updates to a signed JSON webhook.
Requires PHP 8.0+, WordPress 6.5+. No Composer, no build step for the PHP itself.

## Verify changes

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
php tests/delivery-test.php   # no WordPress needed; stubs the WP functions it uses
./build.sh                    # writes WebHookARM-<version>.zip to $PWD
```

CI additionally enforces two exact-string metadata matches, and fails the build on either:

- `Version:` in `webhookarm.php` == `Stable tag:` in `readme.txt`
- `Tested up to:` in `webhookarm.php` == `Tested up to:` in `readme.txt`

Keep `README.md` in sync with behavior changes too; it is the GitHub-facing copy of
`readme.txt`.

## Architecture

- `webhookarm.php` — bootstrap, option definitions, settings UI, upgrade notice
- `includes/delivery.php` — payload build and redaction, WP-Cron queue, HMAC signing, send and retry
- `assets/webhookarm_appscript.gs` — sample Apps Script receiver, must stay in sync with the signing code
- `uninstall.php` — option, transient, and cron cleanup

Delivery is asynchronous. `arm_update_profile_external` stores the body in a transient
and schedules one cron event; `bono_arm_webhook_process_delivery` sends it and retries
at 60/300/900 seconds, giving up after 4 attempts or a non-retryable 4xx.

## Gotchas

- Option keys are the upgrade key. Never rename `bono_arm_webhook_*` options.
- The signed string lives in two places, `bono_arm_webhook_sign()` and the `.gs`
  receiver. Changing one breaks the other. The `.gs` runs on Google's side, so
  updating the plugin does not update anyone's deployed script.
- Apps Script cannot set an HTTP status code. A rejected delivery still answers 200
  and the plugin records it as successful. Never conclude delivery works by looking
  at the WordPress side alone.
- `uninstall.php` runs without the plugin's constants loaded. Hardcode key strings there.
- `tests/delivery-test.php` loads `webhookarm.php` behind hand-written stubs. Adding a
  WordPress call at file load time breaks the test run until a stub is added.
- `build.sh` derives the zip name from `basename $PWD`, so the checkout directory must
  stay `WebHookARM`. New top-level directories need an rsync `--exclude` or they ship.
- The psalm PostToolUse hook in `.claude/settings.json` is wrapped in `2>/dev/null || true`
  and silently no-ops when psalm is not installed locally. Real analysis happens in CI only.

## Release

Push to `main`, then `update-stable-tag.yml` tags `v<Stable tag>` and
`package-plugin.yml` builds and uploads the release asset that Git Updater consumes.
Bump `Version` in `webhookarm.php`, `Stable tag` plus the changelog and upgrade notice
in `readme.txt`, and note anything user-visible in `README.md`.

## Non-goals

- No breaking changes to existing option keys.
- No sensitive data in production logs. Diagnostics are gated behind `WP_DEBUG` and
  carry delivery ids and status codes only.
