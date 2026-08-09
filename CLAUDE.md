# WebHookARM

WordPress plugin. Sends ARMember profile updates to a signed JSON webhook.
Requires PHP 8.0+, WordPress 7.0+. No Composer, no build step for the PHP itself.

## Verify changes

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
php tests/delivery-test.php   # no WordPress needed; stubs the WP functions it uses
./build.sh                    # writes dist/WebHookARM-<version>.zip

# Psalm gates merges but the project has no Composer manifest. Install it
# outside the repo and point it at the config:
psalm -c psalm.xml --root=. --no-cache
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
- Psalm runs in CI only. `.claude/settings.json` is gitignored, so any local hook is
  per-machine; never assume a clean local run means a clean CI run.

## Release

Push to `main`. `update-stable-tag.yml` tags `v<Stable tag>`, then calls
`package-plugin.yml` via `workflow_call` to build and upload the asset Git Updater
consumes.

That call is deliberate, not a convenience. A tag pushed with the default
`GITHUB_TOKEN` cannot trigger the `push: tags` event, so `package-plugin.yml` never
fires on its own from an automated tag. v2.0.0 tagged with no release because of
this. If a release goes missing, the fallback is deleting the remote tag and
re-pushing it from a local clone, which fires the `push` trigger normally.

Bump `Version` in `webhookarm.php`, `Stable tag` plus the changelog and upgrade notice
in `readme.txt`, and note anything user-visible in `README.md`.

## Non-goals

- No breaking changes to existing option keys.
- No sensitive data in production logs. Diagnostics are gated behind `WP_DEBUG` and
  carry delivery ids and status codes only.
