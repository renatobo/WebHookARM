<?php
/**
 * Plugin Name: WebHookARM integration test environment
 * Description: Must-use plugin for CI only. Points outbound requests at the local mock receivers.
 */

// The mock receivers speak plain HTTP on 127.0.0.1.
add_filter('bono_arm_webhook_allow_insecure_url', '__return_true');

add_filter(
    'http_request_host_is_external',
    static function ($external, $host) {
        return in_array($host, array('127.0.0.1', 'script.google.com', 'script.googleusercontent.com'), true) ? true : $external;
    },
    10,
    2
);

add_filter(
    'http_allowed_safe_ports',
    static function ($ports) {
        return array_merge((array) $ports, array(8081, 8443));
    }
);

// tls-echo.php uses a self-signed certificate.
add_filter('https_ssl_verify', '__return_false');

// Resolve the Google hosts to the local mocks without touching /etc/hosts.
add_action(
    'http_api_curl',
    static function ($handle) {
        curl_setopt(
            $handle,
            CURLOPT_RESOLVE,
            array('script.google.com:8081:127.0.0.1', 'script.googleusercontent.com:8443:127.0.0.1')
        );
    }
);
