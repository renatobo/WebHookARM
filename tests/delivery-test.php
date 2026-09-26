<?php
/** Dependency-free unit checks for delivery transformations and retry handling. */

define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);

$GLOBALS['test_filters'] = array();
$GLOBALS['test_options'] = array();
$GLOBALS['test_transients'] = array();
$GLOBALS['test_cron'] = array();
$GLOBALS['test_actions'] = array();
$GLOBALS['test_responses'] = array();
$GLOBALS['test_requests'] = array();
$GLOBALS['test_settings_errors'] = array();

class WP_Error {
    private $code;

    public function __construct($code = '', $message = '') {
        $this->code = $code;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return (string) $this->code;
    }
}

function apply_filters($hook, $value) {
    return isset($GLOBALS['test_filters'][$hook]) ? $GLOBALS['test_filters'][$hook] : $value;
}

function do_action($hook, ...$args) {
    $GLOBALS['test_actions'][] = array($hook, $args);
}

function get_userdata($user_id) {
    return (object) array('user_login' => 'member', 'user_email' => 'member@example.com');
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    return true;
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    return true;
}

function register_deactivation_hook($file, $callback) {
    return true;
}

function plugin_basename($file) {
    return basename(dirname($file)) . '/' . basename($file);
}

function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['test_options']) ? $GLOBALS['test_options'][$name] : $default;
}

function add_option($name, $value = '', $deprecated = '', $autoload = null) {
    if (array_key_exists($name, $GLOBALS['test_options'])) {
        return false;
    }
    $GLOBALS['test_options'][$name] = $value;
    return true;
}

function update_option($name, $value, $autoload = null) {
    if (array_key_exists($name, $GLOBALS['test_options']) && $GLOBALS['test_options'][$name] === $value) {
        return false;
    }
    $GLOBALS['test_options'][$name] = $value;
    return true;
}

function delete_option($name) {
    unset($GLOBALS['test_options'][$name]);
    return true;
}

function get_transient($name) {
    return isset($GLOBALS['test_transients'][$name]) ? $GLOBALS['test_transients'][$name] : false;
}

function delete_transient($name) {
    unset($GLOBALS['test_transients'][$name]);
    return true;
}

function wp_schedule_single_event($timestamp, $hook, $args = array(), $wp_error = false) {
    $GLOBALS['test_cron'][] = array($timestamp, $hook, $args);
    return true;
}

function wp_is_uuid($uuid) {
    return is_string($uuid) && 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid);
}

function is_wp_error($thing) {
    return $thing instanceof WP_Error;
}

function add_query_arg($args, $url) {
    return $url . '?' . http_build_query($args);
}

function wp_parse_url($url, $component = -1) {
    return parse_url($url, $component);
}

function wp_safe_remote_post($url, $args) {
    $GLOBALS['test_requests'][] = array($url, $args + array('method' => 'POST'));
    return array_shift($GLOBALS['test_responses']);
}

function wp_safe_remote_get($url, $args = array()) {
    $GLOBALS['test_requests'][] = array($url, $args + array('method' => 'GET'));
    return array_shift($GLOBALS['test_responses']);
}

function wp_remote_retrieve_header($response, $header) {
    return isset($response['headers'][$header]) ? $response['headers'][$header] : '';
}

function wp_remote_retrieve_response_code($response) {
    return $response['response']['code'];
}

function wp_remote_retrieve_body($response) {
    return $response['body'];
}

function __($text, $domain = 'default') {
    return $text;
}

function wp_unslash($value) {
    return $value;
}

function sanitize_text_field($value) {
    return trim((string) $value);
}

function esc_url_raw($url, $protocols = null) {
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return false !== filter_var($url, FILTER_VALIDATE_URL) && in_array($scheme, (array) $protocols, true) ? $url : '';
}

function add_settings_error($setting, $code, $message, $type = 'error') {
    $GLOBALS['test_settings_errors'][] = $code;
}

require dirname(__DIR__) . '/includes/delivery.php';
require dirname(__DIR__) . '/webhookarm.php';

function assert_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function http_response($code, $body = '', $headers = array()) {
    return array('response' => array('code' => $code), 'body' => $body, 'headers' => $headers);
}

/**
 * Reset state, queue one delivery, and run a single processing attempt.
 *
 * @param mixed $response Response the stubbed transport returns.
 * @param int   $attempt  Attempts already made.
 */
function run_attempt($response, $attempt = 0) {
    $GLOBALS['test_options'] = array(
        BONO_ARM_WEBHOOK_OPTION_URL => 'https://hooks.example.com/sync',
        BONO_ARM_WEBHOOK_OPTION_SECRET => 'secret-of-sixteen',
        BONO_ARM_WEBHOOK_DELIVERY_PREFIX . TEST_DELIVERY_ID => array(
            'attempt' => $attempt,
            'body' => '{"a":1}',
            'created_at' => time(),
        ),
    );
    $GLOBALS['test_cron'] = array();
    $GLOBALS['test_actions'] = array();
    $GLOBALS['test_requests'] = array();
    $GLOBALS['test_responses'] = array($response);

    bono_arm_webhook_process_delivery(TEST_DELIVERY_ID);
}

/**
 * Return the first entry a stub recorded, read indirectly so static analysis
 * does not treat the globals as permanently empty.
 *
 * @param string $name  Global holding the recorded calls.
 * @param int    $index Position of the entry.
 * @return array
 */
function first_recorded($name, $index = 0) {
    $entries = $GLOBALS[$name];

    return isset($entries[$index]) && is_array($entries[$index]) ? $entries[$index] : array();
}

function stored_delivery() {
    return get_option(BONO_ARM_WEBHOOK_DELIVERY_PREFIX . TEST_DELIVERY_ID, null);
}

define('TEST_DELIVERY_ID', '11111111-2222-4333-8444-555555555555');

// Redaction.
$redacted = bono_arm_webhook_redact_payload(
    array(
        'display_name' => 'Jane',
        'password' => 'never-send-this',
        'nested' => array('api_token' => 'never-send-this-either', 'city' => 'Los Angeles'),
    )
);

assert_same(false, array_key_exists('password', $redacted), 'Top-level credentials were not removed.');
assert_same(false, array_key_exists('api_token', $redacted['nested']), 'Nested credentials were not removed.');
assert_same('Los Angeles', $redacted['nested']['city'], 'Safe nested values were not preserved.');

$redacted = bono_arm_webhook_redact_payload(
    array(
        'user_ssn' => 'x',
        'iban' => 'x',
        'api_key' => 'x',
        'private-key' => 'x',
        'cc_number' => 'x',
        'security_answer' => 'x',
        'classname' => 'kept',
    )
);
assert_same(array('classname' => 'kept'), $redacted, 'The extended redaction terms did not match as expected.');

$GLOBALS['test_filters']['bono_arm_webhook_redaction_pattern'] = '/^city$/';
$redacted = bono_arm_webhook_redact_payload(array('city' => 'x', 'nested' => array('city' => 'x', 'zip' => '1')));
assert_same(array('nested' => array('zip' => '1')), $redacted, 'A filtered redaction pattern was not applied at every depth.');

$GLOBALS['test_filters']['bono_arm_webhook_redaction_pattern'] = '/unterminated(';
$redacted = bono_arm_webhook_redact_payload(array('password' => 'x', 'city' => 'kept'));
assert_same(array('city' => 'kept'), $redacted, 'An invalid redaction pattern did not fall back to the default.');
unset($GLOBALS['test_filters']['bono_arm_webhook_redaction_pattern']);

// Payload.
$payload = bono_arm_webhook_build_payload(42, array('display_name' => 'Jane', 'nonce' => 'remove-me'));
assert_same(42, $payload['user_id'], 'User ID was not normalized.');
assert_same('member', $payload['user_login'], 'User login was not appended.');
assert_same(false, array_key_exists('nonce', $payload), 'Credential-like fields were not removed.');
assert_same(262144, bono_arm_webhook_max_payload_bytes(), 'Default payload limit changed unexpectedly.');

// Signing.
$signature = bono_arm_webhook_sign(TEST_DELIVERY_ID, '1700000000', '{"a":1}', 'secret-of-sixteen');
assert_same(
    hash_hmac('sha256', TEST_DELIVERY_ID . '.1700000000.{"a":1}', 'secret-of-sixteen'),
    $signature,
    'Signature is no longer computed over delivery id, timestamp, and body.'
);
assert_same(
    false,
    $signature === bono_arm_webhook_sign('99999999-2222-4333-8444-555555555555', '1700000000', '{"a":1}', 'secret-of-sixteen'),
    'Signature does not change when the delivery id changes.'
);

// Upgrade notice.
assert_same(
    'unknown',
    bono_arm_webhook_upgrade_notice_version('', true),
    'A configured site with no recorded version was not flagged for receiver changes.'
);
assert_same(
    '',
    bono_arm_webhook_upgrade_notice_version('', false),
    'A fresh install was incorrectly flagged for receiver changes.'
);
assert_same(
    '1.3.1',
    bono_arm_webhook_upgrade_notice_version('1.3.1', true),
    'An upgrade from 1.x was not flagged for receiver changes.'
);
assert_same(
    '',
    bono_arm_webhook_upgrade_notice_version(BONO_ARM_WEBHOOK_VERSION, true),
    'The current version was flagged as an upgrade.'
);
assert_same(
    '',
    bono_arm_webhook_upgrade_notice_version('2.1.0', true),
    'A newer recorded version was flagged as an upgrade.'
);

// Retry handling.
run_attempt(http_response(200, 'Success'));
assert_same(null, stored_delivery(), 'A 2xx response did not remove the delivery.');
assert_same('bono_arm_webhook_delivery_succeeded', first_recorded('test_actions')[0], 'Success did not fire the succeeded action.');
assert_same(false, array_key_exists(BONO_ARM_WEBHOOK_LOCK_PREFIX . TEST_DELIVERY_ID, $GLOBALS['test_options']), 'The delivery lock was not released.');

run_attempt(http_response(200, "Request rejected\n"));
assert_same(null, stored_delivery(), 'An Apps Script rejection was retried instead of failing permanently.');
assert_same(array('bono_arm_webhook_delivery_failed', array(TEST_DELIVERY_ID, 422, 1)), first_recorded('test_actions'), 'An Apps Script rejection did not fire the failed action with status 422.');

run_attempt(http_response(200, 'Retry later'));
assert_same(1, stored_delivery()['attempt'], 'An Apps Script retry request was not kept for retry.');
assert_same(60, first_recorded('test_cron')[0] - time(), 'The first retry was not scheduled after 60 seconds.');

foreach (array(408, 429, 500) as $retryable) {
    run_attempt(http_response($retryable));
    assert_same(1, stored_delivery()['attempt'], "HTTP $retryable was not retried.");
}

run_attempt(http_response(403));
assert_same(null, stored_delivery(), 'A non-retryable 4xx was retried.');

run_attempt(new WP_Error('http_request_failed'));
assert_same(1, stored_delivery()['attempt'], 'A transport error was not retried.');
assert_same('retrying', get_option(BONO_ARM_WEBHOOK_OPTION_LAST_DELIVERY)['outcome'], 'The retry outcome was not recorded.');
assert_same(0, get_option(BONO_ARM_WEBHOOK_OPTION_LAST_DELIVERY)['status'], 'A transport error was not recorded as status 0.');

run_attempt(http_response(500), 1);
assert_same(300, first_recorded('test_cron')[0] - time(), 'The second retry was not scheduled after 300 seconds.');

run_attempt(http_response(500), 2);
assert_same(900, first_recorded('test_cron')[0] - time(), 'The third retry was not scheduled after 900 seconds.');

run_attempt(http_response(500), 3);
assert_same(null, stored_delivery(), 'The fourth failed attempt did not give up.');
assert_same(array(), $GLOBALS['test_cron'], 'A fifth attempt was scheduled.');

run_attempt(http_response(200));
$GLOBALS['test_options'][BONO_ARM_WEBHOOK_LOCK_PREFIX . TEST_DELIVERY_ID] = time();
$GLOBALS['test_options'][BONO_ARM_WEBHOOK_DELIVERY_PREFIX . TEST_DELIVERY_ID] = array('attempt' => 0, 'body' => '{}', 'created_at' => time());
$GLOBALS['test_requests'] = array();
bono_arm_webhook_process_delivery(TEST_DELIVERY_ID);
assert_same(array(), $GLOBALS['test_requests'], 'A delivery held by another process was sent again.');

$GLOBALS['test_options'][BONO_ARM_WEBHOOK_LOCK_PREFIX . TEST_DELIVERY_ID] = time() - BONO_ARM_WEBHOOK_LOCK_TTL - 1;
$GLOBALS['test_responses'] = array(http_response(200));
bono_arm_webhook_process_delivery(TEST_DELIVERY_ID);
assert_same(1, count($GLOBALS['test_requests']), 'A stale lock blocked delivery.');

run_attempt(http_response(200));
$GLOBALS['test_options'][BONO_ARM_WEBHOOK_DELIVERY_PREFIX . TEST_DELIVERY_ID] = array('attempt' => 0, 'body' => '{}', 'created_at' => time() - DAY_IN_SECONDS - 1);
$GLOBALS['test_requests'] = array();
bono_arm_webhook_process_delivery(TEST_DELIVERY_ID);
assert_same(array(), $GLOBALS['test_requests'], 'An expired delivery was sent.');
assert_same(null, stored_delivery(), 'An expired delivery was not removed.');

// A delivery queued by 2.0.x as a transient is still sent.
run_attempt(http_response(200));
unset($GLOBALS['test_options'][BONO_ARM_WEBHOOK_DELIVERY_PREFIX . TEST_DELIVERY_ID]);
$GLOBALS['test_transients'][BONO_ARM_WEBHOOK_DELIVERY_PREFIX . TEST_DELIVERY_ID] = array('attempt' => 0, 'body' => '{}', 'created_at' => time());
$GLOBALS['test_requests'] = array();
$GLOBALS['test_responses'] = array(http_response(200));
bono_arm_webhook_process_delivery(TEST_DELIVERY_ID);
assert_same(1, count($GLOBALS['test_requests']), 'A legacy transient delivery was not sent.');
assert_same(array(), $GLOBALS['test_transients'], 'A legacy transient delivery was not removed.');

// Request arguments.
run_attempt(http_response(200));
list($url, $args) = first_recorded('test_requests');
assert_same(0, $args['redirection'], 'A non-Apps Script receiver was allowed to redirect.');
assert_same('sha256=' . bono_arm_webhook_sign(TEST_DELIVERY_ID, $args['headers']['X-WebhookARM-Timestamp'], '{"a":1}', 'secret-of-sixteen'), $args['headers']['X-WebhookARM-Signature'], 'The request was not signed.');

// Apps Script: POST without redirects, then fetch the reply with a bodyless GET.
// WordPress would follow the 302 as a GET that still carries the JSON body,
// which Google answers with 400.
define('TEST_ECHO_URL', 'https://script.googleusercontent.com/macros/echo?user_content_key=abc');
$GLOBALS['test_filters']['bono_arm_webhook_request_args'] = array('redirection' => 3);
run_attempt(http_response(200));
$GLOBALS['test_options'][BONO_ARM_WEBHOOK_OPTION_URL] = 'https://script.google.com/macros/s/abc/exec';
$GLOBALS['test_options'][BONO_ARM_WEBHOOK_DELIVERY_PREFIX . TEST_DELIVERY_ID] = array('attempt' => 0, 'body' => '{"a":1}', 'created_at' => time());
$GLOBALS['test_requests'] = array();
$GLOBALS['test_responses'] = array(http_response(302, '', array('location' => TEST_ECHO_URL)), http_response(200, 'Success'));
bono_arm_webhook_process_delivery(TEST_DELIVERY_ID);
unset($GLOBALS['test_filters']['bono_arm_webhook_request_args']);
list($post_url, $post_args) = first_recorded('test_requests');
list($get_url, $get_args) = first_recorded('test_requests', 1);
assert_same(0, $post_args['redirection'], 'The Apps Script POST was allowed to follow redirects.');
assert_same('GET', $get_args['method'], 'The Apps Script reply was not fetched with GET.');
assert_same(TEST_ECHO_URL, $get_url, 'The Apps Script reply was not fetched from the redirect location.');
assert_same(false, array_key_exists('body', $get_args), 'The Apps Script reply fetch carried a body, which Google rejects with 400.');
assert_same(null, stored_delivery(), 'An Apps Script Success reply behind the redirect was not treated as delivered.');

foreach (array('Request rejected' => 422, 'Retry later' => 503) as $reply => $expected) {
    $GLOBALS['test_responses'] = array(http_response(302, '', array('location' => TEST_ECHO_URL)), http_response(200, $reply));
    $response = bono_arm_webhook_send_request('{"a":1}', TEST_DELIVERY_ID);
    assert_same($expected, bono_arm_webhook_effective_status($response), "The Apps Script reply '$reply' behind the redirect was not mapped to $expected.");
}

$GLOBALS['test_requests'] = array();
$GLOBALS['test_responses'] = array(http_response(302, '', array('location' => 'https://evil.example.com/steal')));
$response = bono_arm_webhook_send_request('{"a":1}', TEST_DELIVERY_ID);
assert_same(1, count($GLOBALS['test_requests']), 'An Apps Script redirect to a foreign host was followed.');
assert_same(302, bono_arm_webhook_effective_status($response), 'An unfollowed redirect did not keep its own status.');

$GLOBALS['test_responses'] = array(http_response(302, '', array('location' => TEST_ECHO_URL)), new WP_Error('http_request_failed'));
assert_same(0, bono_arm_webhook_effective_status(bono_arm_webhook_send_request('{"a":1}', TEST_DELIVERY_ID)), 'A failed reply fetch was not treated as a transport error.');

$GLOBALS['test_filters']['bono_arm_webhook_request_args'] = array('timeout' => 30, 'headers' => array('X-Extra' => '1', 'X-WebhookARM-Signature' => 'forged'), 'body' => 'tampered');
run_attempt(http_response(200));
list($url, $args) = first_recorded('test_requests');
assert_same(30, $args['timeout'], 'The request args filter was ignored.');
assert_same('1', $args['headers']['X-Extra'], 'Extra headers from the filter were dropped.');
assert_same(0, strpos($args['headers']['X-WebhookARM-Signature'], 'sha256='), 'The filter overwrote the signature.');
assert_same('{"a":1}', $args['body'], 'The filter overwrote the signed body.');
unset($GLOBALS['test_filters']['bono_arm_webhook_request_args']);

// Delivery stats keep the last permanent failure after a later success.
$GLOBALS['test_options'] = array(
    BONO_ARM_WEBHOOK_OPTION_URL => 'https://hooks.example.com/sync',
    BONO_ARM_WEBHOOK_OPTION_SECRET => 'secret-of-sixteen',
);
foreach (array(http_response(200), http_response(200, 'Request rejected'), http_response(200)) as $response) {
    $GLOBALS['test_options'][BONO_ARM_WEBHOOK_DELIVERY_PREFIX . TEST_DELIVERY_ID] = array('attempt' => 0, 'body' => '{}', 'created_at' => time());
    $GLOBALS['test_responses'] = array($response);
    bono_arm_webhook_process_delivery(TEST_DELIVERY_ID);
}
$stats = get_option(BONO_ARM_WEBHOOK_OPTION_DELIVERY_STATS);
assert_same(2, $stats['succeeded'], 'Successful deliveries were not counted.');
assert_same(1, $stats['failed'], 'Permanent failures were not counted.');
assert_same(422, $stats['last_failure']['status'], 'A later success hid the last permanent failure.');
assert_same('succeeded', get_option(BONO_ARM_WEBHOOK_OPTION_LAST_DELIVERY)['outcome'], 'The latest outcome was not recorded.');

run_attempt(http_response(500));
assert_same(false, get_option(BONO_ARM_WEBHOOK_OPTION_DELIVERY_STATS), 'A retry was counted as a final outcome.');

// Upgrade flag.
$GLOBALS['test_options'] = array();
bono_arm_webhook_maybe_flag_receiver_upgrade();
assert_same(false, get_option(BONO_ARM_WEBHOOK_OPTION_UPGRADE_NOTICE), 'A fresh install was flagged for receiver changes.');
assert_same(BONO_ARM_WEBHOOK_VERSION, get_option(BONO_ARM_WEBHOOK_OPTION_VERSION), 'The running version was not recorded.');

$GLOBALS['test_options'] = array(BONO_ARM_WEBHOOK_OPTION_SECRET => 'stored-secret-value');
bono_arm_webhook_maybe_flag_receiver_upgrade();
assert_same('unknown', get_option(BONO_ARM_WEBHOOK_OPTION_UPGRADE_NOTICE), 'A configured site without a recorded version was not flagged.');

// URL sanitizer.
$GLOBALS['test_options'] = array(BONO_ARM_WEBHOOK_OPTION_URL => 'https://old.example.com/hook');
assert_same('https://new.example.com/hook', bono_arm_webhook_sanitize_url(' https://new.example.com/hook '), 'A valid HTTPS URL was not saved.');
assert_same('https://old.example.com/hook', bono_arm_webhook_sanitize_url('http://new.example.com/hook'), 'An HTTP URL replaced the saved URL.');
assert_same('https://old.example.com/hook', bono_arm_webhook_sanitize_url('not a url'), 'An invalid URL replaced the saved URL.');
assert_same(array('bono_arm_webhook_insecure_url', 'bono_arm_webhook_invalid_url'), $GLOBALS['test_settings_errors'], 'Rejected URLs did not report settings errors.');
$GLOBALS['test_filters']['bono_arm_webhook_allow_insecure_url'] = true;
assert_same('http://localhost/hook', bono_arm_webhook_sanitize_url('http://localhost/hook'), 'The insecure URL filter was ignored.');
unset($GLOBALS['test_filters']['bono_arm_webhook_allow_insecure_url']);
assert_same('', bono_arm_webhook_sanitize_url(''), 'An empty URL did not clear the setting.');

// Secret sanitizer.
$GLOBALS['test_options'] = array(BONO_ARM_WEBHOOK_OPTION_SECRET => 'stored-secret-value');
$GLOBALS['test_settings_errors'] = array();
assert_same('stored-secret-value', bono_arm_webhook_sanitize_secret(''), 'An empty field did not keep the saved secret.');
assert_same('', bono_arm_webhook_sanitize_secret(null), 'A non-string value was not rejected.');
assert_same('stored-secret-value', bono_arm_webhook_sanitize_secret('too-short'), 'A short secret replaced the saved secret.');
assert_same(array('bono_arm_webhook_weak_secret'), $GLOBALS['test_settings_errors'], 'A short secret did not report a settings error.');
assert_same('new-secret-value-here', bono_arm_webhook_sanitize_secret(" new-secret-\nvalue-here "), 'A valid secret was not trimmed and saved.');
$_POST['bono_arm_webhook_secret_clear'] = '1';
assert_same('', bono_arm_webhook_sanitize_secret(''), 'The clear request did not remove the secret.');
unset($_POST['bono_arm_webhook_secret_clear']);

// Secret constant takes precedence and is never written back.
define('WEBHOOKARM_SECRET', 'constant-secret-value');
$GLOBALS['test_options'][BONO_ARM_WEBHOOK_OPTION_SECRET] = 'stored-secret-value';
assert_same('constant-secret-value', bono_arm_webhook_get_secret(), 'The WEBHOOKARM_SECRET constant was not preferred.');
assert_same('stored-secret-value', bono_arm_webhook_sanitize_secret('new-secret-value-here'), 'Saving settings overwrote the stored secret while the constant is defined.');

$GLOBALS['test_options'] = array();
bono_arm_webhook_maybe_flag_receiver_upgrade();
assert_same(false, get_option(BONO_ARM_WEBHOOK_OPTION_UPGRADE_NOTICE), 'A fresh install using WEBHOOKARM_SECRET was flagged as an upgrade.');

echo "Delivery tests passed.\n";
