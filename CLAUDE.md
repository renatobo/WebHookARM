# WebHookARM

WordPress plugin. Sends ARMember profile updates to a signed JSON webhook.
Requires PHP 8.0+, WordPress 7.0+. No Composer, no build step for the PHP itself.

## Verify changes

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
php tests/delivery-test.php   # no WordPress needed; stubs the WP functions it uses
bash tests/integration/run.sh # real WordPress; needs an empty MySQL DB, wp-cli, jq (see the script header)
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

- `webhookarm.php` — bootstrap, option definitions, settings UI, upgrade notice, test delivery
- `assets/admin.css`, `assets/admin.js` — settings screen assets, enqueued on that screen only
- `includes/delivery.php` — payload build and redaction, WP-Cron queue, HMAC signing, send and retry
- `assets/webhookarm_appscript.gs` — sample Apps Script receiver, must stay in sync with the signing code
- `uninstall.php` — option, queue, legacy transient, and cron cleanup on every site

Delivery is asynchronous. `arm_update_profile_external` stores the body in a
non-autoloaded `bono_arm_webhook_delivery_<uuid>` option and schedules one cron event;
`bono_arm_webhook_process_delivery` sends it and retries at 60/300/900 seconds, giving
up after 4 attempts or a non-retryable 4xx. A 2xx whose body is `Request rejected`
counts as a 4xx and `Retry later` as a 503, because Apps Script cannot set a status.
Versions before 2.1 queued in transients; `bono_arm_webhook_get_delivery()` migrates them.

## Gotchas

- Option keys are the upgrade key. Never rename `bono_arm_webhook_*` options.
- The signed string lives in two places, `bono_arm_webhook_sign()` and the `.gs`
  receiver. Changing one breaks the other. The `.gs` runs on Google's side, so
  updating the plugin does not update anyone's deployed script.
- Apps Script cannot set an HTTP status code. A rejected delivery still answers 200;
  the plugin only catches it when the body is exactly the sample's `Request rejected`.
  A modified script replying anything else is recorded as successful. Never conclude
  delivery works by looking at the WordPress side alone.
- Never let WordPress follow the Apps Script redirect. Apps Script answers the POST with a
  302 to `script.googleusercontent.com`; WordPress turns a 302 into a GET but Requests
  re-sends the JSON body, and Google answers a GET with a body with 400. The POST goes
  out with `redirection => 0` and `bono_arm_webhook_fetch_apps_script_reply()` fetches
  the reply with a bodyless `wp_safe_remote_get()`.
- `tests/integration/run.sh` (CI job `Integration`) installs real WordPress, saves settings
  through `options.php`, and delivers through WP-Cron to local mocks. `tls-echo.php` answers a
  GET with a body with 400, like Google, so letting WordPress follow the Apps Script redirect
  fails the run. Outbound requests reach the mocks through the must-use plugin
  `test-environment.php`; never ship it.
- `bono_arm_webhook_delivery_stats` shares the queue prefix `bono_arm_webhook_delivery_`.
  Anything that sweeps the queue by LIKE must filter with `bono_arm_webhook_is_queue_key()`,
  except `uninstall.php`, which deletes everything, stats included, and can't call plugin code.
- `tests/delivery-test.php` also stubs `register_deactivation_hook`, options, cron, and
  HTTP. A new WordPress call in the delivery path needs a stub there.
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
