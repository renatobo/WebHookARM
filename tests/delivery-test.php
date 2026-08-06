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

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    return true;
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    return true;
}

function plugin_basename($file) {
    return basename(dirname($file)) . '/' . basename($file);
}

require dirname(__DIR__) . '/includes/delivery.php';
require dirname(__DIR__) . '/webhookarm.php';

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

$delivery_id = '11111111-2222-4333-8444-555555555555';
$signature = bono_arm_webhook_sign($delivery_id, '1700000000', '{"a":1}', 'secret-of-sixteen');
assert_same(
    hash_hmac('sha256', $delivery_id . '.1700000000.{"a":1}', 'secret-of-sixteen'),
    $signature,
    'Signature is no longer computed over delivery id, timestamp, and body.'
);
assert_same(
    false,
    $signature === bono_arm_webhook_sign('99999999-2222-4333-8444-555555555555', '1700000000', '{"a":1}', 'secret-of-sixteen'),
    'Signature does not change when the delivery id changes.'
);

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

echo "Delivery transformation tests passed.\n";
