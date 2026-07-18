<?php
/** Dependency-free unit checks for pure delivery transformations. */

define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);

function apply_filters($hook, $value) {
    return $value;
}

function get_userdata($user_id) {
    return (object) array('user_login' => 'member', 'user_email' => 'member@example.com');
}

require dirname(__DIR__) . '/includes/delivery.php';

function assert_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

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

$payload = bono_arm_webhook_build_payload(42, array('display_name' => 'Jane', 'nonce' => 'remove-me'));
assert_same(42, $payload['user_id'], 'User ID was not normalized.');
assert_same('member', $payload['user_login'], 'User login was not appended.');
assert_same(false, array_key_exists('nonce', $payload), 'Credential-like fields were not removed.');
assert_same(262144, bono_arm_webhook_max_payload_bytes(), 'Default payload limit changed unexpectedly.');

echo "Delivery transformation tests passed.\n";
