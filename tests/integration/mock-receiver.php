<?php
/**
 * Router for `php -S`: a webhook receiver that records every request.
 *
 * /macros/s/{reply}/exec emulates a Google Apps Script web app: it answers the
 * POST with a 302 to the echo URL served by tls-echo.php, which holds the reply.
 * /reject answers 403. Anything else answers 200 "Accepted".
 */

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

file_put_contents(
    (string) getenv('MOCK_LOG'),
    json_encode(
        array(
            'server' => 'receiver',
            'method' => $_SERVER['REQUEST_METHOD'],
            'host' => isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '',
            'path' => $path,
            'query' => isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '',
            'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
            'body' => file_get_contents('php://input'),
        )
    ) . "\n",
    FILE_APPEND | LOCK_EX
);

if (preg_match('#^/macros/s/([a-z]+)/exec$#', $path, $match)) {
    header('Location: https://script.googleusercontent.com:8443/macros/echo?reply=' . $match[1], true, 302);
    exit;
}

if ('/reject' === $path) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

echo 'Accepted';
