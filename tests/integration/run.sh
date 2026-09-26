#!/usr/bin/env bash
#
# WordPress integration smoke test for WebHookARM.
#
# Installs WordPress into a temporary directory against an EMPTY MySQL
# database, activates the plugin behind a stub ARMember, and drives it through
# wp-admin (real options.php saves), WP-CLI, and WP-Cron against local mock
# receivers. Needs php (mysqli, curl, openssl), wp-cli, curl, jq, openssl.
#
# Environment: WP_VERSION (default latest), DB_HOST, DB_NAME, DB_USER, DB_PASS.
# The work directory is deleted after a passing run unless KEEP_WORK is set.
# Outbound DNS must work: WordPress resolves script.google.com before cURL is
# redirected to the local mocks.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
HERE="$ROOT/tests/integration"
WORK="$(mktemp -d)"
WP_DIR="$WORK/wp"
LOG="$WORK/requests.jsonl"
JAR="$WORK/cookies.txt"
SITE="http://127.0.0.1:8080"
SECRET="integration-secret-0123456789"
# display_errors=stderr keeps PHP deprecations from WP-CLI itself out of values read back.
WP="php -d display_errors=stderr -d memory_limit=512M $(command -v wp) --path=$WP_DIR --quiet"
FAILURES=0

: "${WP_VERSION:=latest}"
: "${DB_HOST:=127.0.0.1:3306}"
: "${DB_NAME:=wordpress}"
: "${DB_USER:=root}"
: "${DB_PASS:=root}"

SERVER_PIDS=()

# php -S with workers forks children, so stop those as well as the parent.
cleanup() {
    # The ${arr[@]+...} form avoids "unbound variable" under set -u on bash < 4.4.
    for pid in ${SERVER_PIDS[@]+"${SERVER_PIDS[@]}"}; do
        pkill -P "$pid" 2>/dev/null || true
        kill "$pid" 2>/dev/null || true
    done

    if [ "${FAILURES:-1}" -eq 0 ] && [ -z "${KEEP_WORK:-}" ]; then
        rm -rf "$WORK"
    fi
}
trap cleanup EXIT

diagnostics() {
    echo "--- recorded requests"; cat "$LOG" 2>/dev/null || true
    echo "--- debug.log"; cat "$WP_DIR/wp-content/debug.log" 2>/dev/null || true
    echo "--- wp server log"; tail -50 "$WORK/wp-server.log" 2>/dev/null || true
}

fail() {
    echo "not ok - $*" >&2
    FAILURES=$((FAILURES + 1))
}

pass() {
    echo "ok - $*"
}

assert_eq() {
    if [ "$1" = "$2" ]; then pass "$3"; else fail "$3 (expected '$2', got '$1')"; fi
}

assert_contains() {
    case "$1" in *"$2"*) pass "$3" ;; *) fail "$3 (missing '$2')" ;; esac
}

# --- Queries --------------------------------------------------------------

option() {
    $WP option get "$1" 2>/dev/null || true
}

count_options() {
    OPTION_PREFIX="$1" $WP eval 'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(getenv("OPTION_PREFIX")) . "%"));'
}

# Queue rows only: the prefix followed by a UUID. bono_arm_webhook_delivery_stats
# shares the prefix and must not be counted.
count_queued() {
    QUEUE_REGEX="^bono_arm_webhook_$1_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\$" \
        $WP eval 'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name REGEXP %s", getenv("QUEUE_REGEX")));'
}

stats_state() {
    $WP eval '$s = get_option("bono_arm_webhook_delivery_stats"); echo is_array($s) ? "kept" : "gone";'
}

count_events() {
    $WP cron event list --hook="$1" --format=count 2>/dev/null || echo 0
}

last_outcome() {
    $WP eval '$l = get_option("bono_arm_webhook_last_delivery"); echo is_array($l) ? $l["outcome"] . " " . $l["status"] : "none";'
}

requests() {
    jq -c --arg s "$1" 'select(.server == $s)' "$LOG"
}

count_requests() {
    requests "$1" | wc -l | tr -d ' '
}

last_request() {
    requests "$1" | tail -1
}

valid_signature() {
    php -r '
        $e = json_decode($argv[1], true);
        $h = $e["headers"];
        $expected = "sha256=" . hash_hmac("sha256", $h["x-webhookarm-delivery"] . "." . $h["x-webhookarm-timestamp"] . "." . $e["body"], $argv[2]);
        echo hash_equals($expected, $h["x-webhookarm-signature"]) ? "valid" : "invalid";
    ' "$1" "$2"
}

# --- Actions --------------------------------------------------------------

queue_update() {
    $WP eval 'do_action("arm_update_profile_external", 1, array("first_name" => "Jane", "user_pass" => "hunter2", "nested" => array("api_key" => "k", "city" => "Los Angeles")));'
}

run_cron() {
    $WP cron event run --due-now >/dev/null 2>&1 || true
}

set_url() {
    $WP option update bono_arm_webhook_url "$1" >/dev/null
}

settings_page() {
    curl -s -b "$JAR" "$SITE/wp-admin/options-general.php?page=webhookarm"
}

# Nonces in page order: settings form, test delivery form, resend form.
page_nonce() {
    settings_page | grep -o 'name="_wpnonce" value="[0-9a-f]*"' | sed -n "${1}p" | sed 's/.*value="\([0-9a-f]*\)"/\1/'
}

# Mirrors a browser submit of the enable, URL, and secret fields; pass extra
# fields (such as the allowlist) as additional curl arguments.
save_settings() {
    local url="$1" secret="$2"
    shift 2
    curl -s -o /dev/null -w '%{http_code}' -b "$JAR" \
        --data-urlencode "option_page=bono_arm_webhook" \
        --data-urlencode "action=update" \
        --data-urlencode "_wpnonce=$(page_nonce 1)" \
        --data-urlencode "bono_arm_webhook_profileupdates_enable=yes" \
        --data-urlencode "bono_arm_webhook_url=$url" \
        --data-urlencode "bono_arm_webhook_secret=$secret" \
        "$@" \
        "$SITE/wp-admin/options.php"
}

resend_failed() {
    curl -s -o /dev/null -D - -b "$JAR" \
        --data-urlencode "action=bono_arm_webhook_resend_failed" \
        --data-urlencode "_wpnonce=$(page_nonce 3)" \
        "$SITE/wp-admin/admin-post.php" | tr -d '\r' | sed -n 's/^[Ll]ocation: //p'
}

send_test_delivery() {
    curl -s -o /dev/null -D - -b "$JAR" \
        --data-urlencode "action=bono_arm_webhook_test" \
        --data-urlencode "_wpnonce=$(page_nonce 2)" \
        "$SITE/wp-admin/admin-post.php" | tr -d '\r' | sed -n 's/^[Ll]ocation: //p'
}

wait_for() {
    for _ in $(seq 1 50); do
        curl -sk -o /dev/null "$1" && return 0
        sleep 0.2
    done
    echo "Timed out waiting for $1" >&2
    exit 1
}

# --- Setup ----------------------------------------------------------------

echo "# WordPress $WP_VERSION, $(php -r 'echo "PHP " . PHP_VERSION;'), work dir $WORK"

openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=script.googleusercontent.com" \
    -keyout "$WORK/key.pem" -out "$WORK/cert.pem" 2>/dev/null

$WP core download --version="$WP_VERSION"
$WP config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --skip-check --extra-php <<'PHP'
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('DISABLE_WP_CRON', true);
PHP
$WP core install --url="$SITE" --title="WebHookARM integration" --admin_user=admin \
    --admin_password=password --admin_email=admin@example.com --skip-email

mkdir -p "$WP_DIR/wp-content/plugins/armember-membership" "$WP_DIR/wp-content/mu-plugins"
cp "$HERE/armember-stub.php" "$WP_DIR/wp-content/plugins/armember-membership/armember-membership.php"
cp "$HERE/test-environment.php" "$WP_DIR/wp-content/mu-plugins/"
rsync -a --exclude .git --exclude .github --exclude tests --exclude .review --exclude dist \
    "$ROOT/" "$WP_DIR/wp-content/plugins/WebHookARM/"

: > "$LOG"
for port in 8080 8081 8443; do
    if curl -sk -o /dev/null --max-time 2 "http://127.0.0.1:$port/"; then
        echo "Port $port is already in use; stop the process holding it first." >&2
        exit 1
    fi
done

PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t "$WP_DIR" >"$WORK/wp-server.log" 2>&1 &
SERVER_PIDS+=($!)
MOCK_LOG="$LOG" php -S 127.0.0.1:8081 "$HERE/mock-receiver.php" >"$WORK/receiver.log" 2>&1 &
SERVER_PIDS+=($!)
MOCK_LOG="$LOG" php "$HERE/tls-echo.php" "$WORK/cert.pem" "$WORK/key.pem" >"$WORK/echo.log" 2>&1 &
SERVER_PIDS+=($!)
wait_for "$SITE/wp-login.php"
wait_for "http://127.0.0.1:8081/"
wait_for "https://127.0.0.1:8443/"

# --- Activation and settings screen ----------------------------------------

$WP plugin activate armember-membership WebHookARM >/dev/null
assert_eq "$($WP plugin is-active WebHookARM && echo active)" "active" "plugin activates behind its ARMember dependency"

curl -s -o /dev/null -c "$JAR" -b "wordpress_test_cookie=WP%20Cookie%20check" \
    --data-urlencode "log=admin" --data-urlencode "pwd=password" --data-urlencode "testcookie=1" \
    "$SITE/wp-login.php"

page="$(settings_page)"
assert_contains "$page" "WebHookARM Settings" "settings page renders for an administrator"
assert_contains "$page" "Delivery status" "settings page shows the delivery status card"
assert_contains "$page" "assets/admin.js" "settings page enqueues its script"
assert_contains "$page" "ARMember premium is not active" "settings page warns when only ARMember Lite is active"

mkdir -p "$WP_DIR/wp-content/plugins/armember"
printf '%s\n' '<?php' '/**' ' * Plugin Name: ARMember premium (integration test stub)' ' */' "define('MEMBERSHIP_DIR_NAME', 'armember');" \
    > "$WP_DIR/wp-content/plugins/armember/armember.php"
$WP plugin activate armember >/dev/null
case "$(settings_page)" in
    *"ARMember premium is not active"*) fail "the premium warning is gone once ARMember premium is active" ;;
    *) pass "the premium warning is gone once ARMember premium is active" ;;
esac

autoload="$($WP eval 'global $wpdb; echo $wpdb->get_var("SELECT autoload FROM {$wpdb->options} WHERE option_name = \"bono_arm_webhook_installed_version\"");')"
case "$autoload" in on|yes) pass "installed version is recorded as autoloaded" ;; *) fail "installed version autoload is '$autoload'" ;; esac
assert_eq "$(count_events bono_arm_webhook_cleanup_deliveries)" "1" "daily cleanup is scheduled from wp-admin"

# --- Settings saved through options.php ------------------------------------

assert_eq "$(save_settings "http://127.0.0.1:8081/hook" "$SECRET")" "302" "options.php accepts the settings form"
assert_eq "$(option bono_arm_webhook_url)" "http://127.0.0.1:8081/hook" "webhook URL is saved"
assert_eq "$(option bono_arm_webhook_secret)" "$SECRET" "secret is saved"
assert_eq "$(option bono_arm_webhook_profileupdates_enable)" "yes" "delivery is enabled"

save_settings "http://127.0.0.1:8081/hook" "" >/dev/null
assert_eq "$(option bono_arm_webhook_secret)" "$SECRET" "an empty secret field keeps the saved secret"

save_settings "http://127.0.0.1:8081/hook" "too-short" >/dev/null
assert_eq "$(option bono_arm_webhook_secret)" "$SECRET" "a short secret is rejected"

# --- Custom receiver: queue, cron, signature, redaction --------------------

: > "$LOG"
queue_update
assert_eq "$(count_queued delivery)" "1" "a profile update is queued as one option"
assert_eq "$(count_events bono_arm_webhook_process_delivery)" "1" "a profile update schedules one cron event"
run_cron

post="$(last_request receiver)"
body="$(jq -r '.body' <<<"$post")"
assert_eq "$(jq -r '.method + " " + .path' <<<"$post")" "POST /hook" "cron delivers the update to the receiver"
assert_eq "$(valid_signature "$post" "$SECRET")" "valid" "the delivery signature verifies over delivery.timestamp.body"
assert_contains "$(jq -r '.query' <<<"$post")" "action=profile_update" "the action query parameter is sent"
assert_eq "$(jq -r '.user_login' <<<"$body")" "admin" "WordPress identity fields are appended"
assert_eq "$(jq -r '.nested.city' <<<"$body")" "Los Angeles" "safe nested fields are forwarded"
assert_eq "$(jq -r 'has("user_pass")' <<<"$body")" "false" "the password field is redacted"
assert_eq "$(jq -r '.nested | has("api_key")' <<<"$body")" "false" "nested credentials are redacted"
assert_eq "$(last_outcome)" "succeeded 200" "the outcome is recorded as succeeded"
assert_eq "$(count_queued delivery)" "0" "a delivered update is removed from the queue"
assert_eq "$(count_queued lock)" "0" "the delivery lock is released"

set_url "http://127.0.0.1:8081/reject"
queue_update
run_cron
assert_eq "$(last_outcome)" "failed 403" "a 403 from the receiver is a permanent failure"
assert_eq "$(count_queued delivery)" "0" "a permanently failed update is removed from the queue"
assert_eq "$(count_queued failed)" "1" "a permanently failed update is kept for resending"

# --- Field allowlist, saved through the settings form ----------------------

save_settings "http://127.0.0.1:8081/hook" "" --data-urlencode $'bono_arm_webhook_field_allowlist=first_name\nnested' >/dev/null
assert_eq "$(option bono_arm_webhook_field_allowlist)" $'first_name\nnested' "the field allowlist is saved"
: > "$LOG"
queue_update
run_cron
assert_eq "$(jq -r '.body | fromjson | keys_unsorted | join(",")' <<<"$(last_request receiver)")" "first_name,nested,user_id,user_login,user_email" "the allowlist sends only the listed fields plus identity fields"
save_settings "http://127.0.0.1:8081/hook" "" --data-urlencode "bono_arm_webhook_field_allowlist=" >/dev/null
assert_eq "$(option bono_arm_webhook_field_allowlist)" "" "an empty allowlist field clears the setting"

# --- The cleanup is scheduled from the queue path too ----------------------

$WP cron event unschedule bono_arm_webhook_cleanup_deliveries >/dev/null 2>&1 || true
queue_update
assert_eq "$(count_events bono_arm_webhook_cleanup_deliveries)" "1" "queueing a delivery schedules the daily cleanup if missing"
run_cron

# --- Apps Script: POST without redirects, then a bodyless GET for the reply --

set_url "http://script.google.com:8081/macros/s/success/exec"
: > "$LOG"
location="$(send_test_delivery)"
assert_contains "$location" "webhookarm_test=200" "Send test delivery reports HTTP 200 through the Apps Script redirect"

: > "$LOG"
queue_update
run_cron
assert_eq "$(last_outcome)" "succeeded 200" "an Apps Script Success reply is recorded as delivered"
assert_eq "$(jq -r '.method + " " + .host' <<<"$(last_request receiver)")" "POST script.google.com:8081" "the delivery is POSTed to the Apps Script URL"
echo_get="$(last_request echo)"
assert_eq "$(jq -r '.method + " " + .path' <<<"$echo_get")" "GET /macros/echo" "the reply is fetched from the echo URL with GET"
assert_eq "$(jq -r '.body' <<<"$echo_get")" "" "the reply fetch carries no body (Google answers 400 otherwise)"
assert_eq "$(count_requests echo)" "1" "the echo URL is fetched exactly once"

set_url "http://script.google.com:8081/macros/s/rejected/exec"
queue_update
run_cron
assert_eq "$(last_outcome)" "failed 422" "an Apps Script Request rejected reply is a permanent failure"

# --- Resending kept failures once the receiver is fixed --------------------

assert_eq "$(count_queued failed)" "2" "both permanent failures are kept"
assert_contains "$(settings_page)" "Resend failed deliveries" "the status card offers to resend kept failures"
set_url "http://127.0.0.1:8081/hook"
: > "$LOG"
assert_contains "$(resend_failed)" "webhookarm_resent=2" "Resend failed deliveries queues both again"
assert_eq "$(count_queued failed)" "0" "resent failures are no longer kept"
run_cron
assert_eq "$(count_requests receiver)" "2" "both resent deliveries reach the fixed receiver"
assert_eq "$(last_outcome)" "succeeded 200" "the resent deliveries succeed"

# --- Kept failures are erased with their user; resend needs a destination ---

member_id="$($WP user create member member@example.com --porcelain)"
set_url "http://127.0.0.1:8081/reject"
MEMBER_ID="$member_id" $WP eval 'do_action("arm_update_profile_external", (int) getenv("MEMBER_ID"), array("first_name" => "Member"));'
run_cron
assert_eq "$(count_queued failed)" "1" "a member's failed delivery is kept"
$WP user delete "$member_id" --yes >/dev/null
assert_eq "$(count_queued failed)" "0" "deleting the user erases their kept payload"

set_url ""
assert_contains "$(resend_failed)" "webhookarm_resent=-1" "resend is refused while no webhook URL is saved"
set_url "http://127.0.0.1:8081/hook"

set_url "http://script.google.com:8081/macros/s/retry/exec"
queue_update
run_cron
assert_eq "$(last_outcome)" "retrying 503" "an Apps Script Retry later reply schedules a retry"
assert_eq "$(count_queued delivery)" "1" "a delivery awaiting retry stays queued"
assert_eq "$(count_events bono_arm_webhook_process_delivery)" "1" "the retry is scheduled"

$WP cron event run bono_arm_webhook_cleanup_deliveries >/dev/null 2>&1 || true
assert_eq "$(count_queued delivery)" "1" "the daily cleanup keeps a delivery awaiting retry"
assert_eq "$(stats_state)" "kept" "the daily cleanup keeps the delivery stats"

# Drop the pending retry so it can't fire during the next steps if the run is slow.
$WP cron event unschedule bono_arm_webhook_process_delivery >/dev/null 2>&1 || true

# --- Upgrade from 2.0.x: a delivery queued as a transient is still sent -----

set_url "http://127.0.0.1:8081/hook"
: > "$LOG"
$WP eval '
    $id = wp_generate_uuid4();
    set_transient("bono_arm_webhook_delivery_" . $id, array("attempt" => 0, "body" => "{\"legacy\":true}", "created_at" => time()), DAY_IN_SECONDS);
    wp_schedule_single_event(time(), "bono_arm_webhook_process_delivery", array($id));
'
run_cron
assert_eq "$(jq -r '.body' <<<"$(last_request receiver)")" '{"legacy":true}' "a delivery queued by 2.0.x as a transient is sent"
assert_eq "$(count_options _transient_bono_arm_webhook_delivery_)" "0" "the legacy transient is removed"

# --- Deactivation purges the queue but keeps settings ----------------------

$WP plugin deactivate WebHookARM >/dev/null
assert_eq "$(count_queued delivery)" "0" "deactivation removes queued deliveries"
assert_eq "$(count_events bono_arm_webhook_process_delivery)" "0" "deactivation unschedules deliveries"
assert_eq "$(count_events bono_arm_webhook_cleanup_deliveries)" "0" "deactivation unschedules the cleanup"
assert_eq "$(option bono_arm_webhook_secret)" "$SECRET" "deactivation keeps the settings"
assert_eq "$(stats_state)" "kept" "deactivation keeps the delivery stats"
$WP plugin activate WebHookARM >/dev/null

# --- Removing the secret through the settings form -------------------------

save_settings "http://127.0.0.1:8081/hook" "" --data-urlencode "bono_arm_webhook_secret_clear=1" >/dev/null
assert_eq "$(option bono_arm_webhook_secret)" "" "the remove-secret checkbox clears the secret"

# --- Uninstall -------------------------------------------------------------

queue_update
$WP plugin uninstall WebHookARM --deactivate >/dev/null
assert_eq "$(count_options bono_arm_webhook_)" "0" "uninstall removes every plugin option"
assert_eq "$(count_events bono_arm_webhook_process_delivery)" "0" "uninstall leaves no delivery events"

# --- No PHP notices from the plugin ----------------------------------------

notices="$(grep -h 'plugins/WebHookARM/' "$WP_DIR/wp-content/debug.log" "$WORK/wp-server.log" 2>/dev/null | grep -E 'PHP (Fatal|Warning|Notice|Deprecated)' || true)"
assert_eq "$notices" "" "the plugin raised no PHP errors, warnings, notices, or deprecations"

if [ "$FAILURES" -gt 0 ]; then
    diagnostics
    echo "$FAILURES check(s) failed" >&2
    exit 1
fi

echo "All integration checks passed."
