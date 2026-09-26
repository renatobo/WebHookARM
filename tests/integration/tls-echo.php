<?php
/**
 * Minimal HTTPS server emulating script.googleusercontent.com/macros/echo.
 *
 * Like Google, it answers a GET that carries a body with 400. That is what
 * WordPress sends if it follows the Apps Script 302 itself, so a regression
 * that lets WordPress follow the redirect fails the integration run.
 *
 * Usage: MOCK_LOG=/path/log php tls-echo.php cert.pem key.pem
 */

$replies = array(
    'success' => 'Success',
    'retry' => 'Retry later',
    'rejected' => 'Request rejected',
);

$context = stream_context_create(
    array(
        'ssl' => array(
            'local_cert' => $argv[1],
            'local_pk' => $argv[2],
            'verify_peer' => false,
            'allow_self_signed' => true,
        ),
    )
);

$server = stream_socket_server('ssl://127.0.0.1:8443', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

if (!$server) {
    fwrite(STDERR, "Could not listen: $errstr\n");
    exit(1);
}

while (true) {
    $connection = @stream_socket_accept($server, -1);

    if (!$connection) {
        continue;
    }

    stream_set_timeout($connection, 5);
    $head = '';

    while (false === strpos($head, "\r\n\r\n")) {
        $chunk = fread($connection, 1);

        if (false === $chunk || '' === $chunk) {
            break;
        }

        $head .= $chunk;
    }

    $lines = explode("\r\n", trim($head));
    $request_line = explode(' ', (string) array_shift($lines));
    $headers = array();

    foreach ($lines as $line) {
        $parts = explode(':', $line, 2);

        if (2 === count($parts)) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }

    $length = isset($headers['content-length']) ? (int) $headers['content-length'] : 0;
    $body = '';

    while (strlen($body) < $length) {
        $chunk = fread($connection, $length - strlen($body));

        if (false === $chunk || '' === $chunk) {
            break;
        }

        $body .= $chunk;
    }

    $method = isset($request_line[0]) ? $request_line[0] : '';
    $target = isset($request_line[1]) ? $request_line[1] : '';
    parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

    file_put_contents(
        (string) getenv('MOCK_LOG'),
        json_encode(
            array(
                'server' => 'echo',
                'method' => $method,
                'host' => isset($headers['host']) ? $headers['host'] : '',
                'path' => (string) parse_url($target, PHP_URL_PATH),
                'query' => (string) parse_url($target, PHP_URL_QUERY),
                'headers' => $headers,
                'body' => $body,
            )
        ) . "\n",
        FILE_APPEND | LOCK_EX
    );

    if ('GET' === $method && '' !== $body) {
        $status = '400 Bad Request';
        $reply = 'Bad Request';
    } else {
        $status = '200 OK';
        $key = isset($query['reply']) ? (string) $query['reply'] : '';
        $reply = isset($replies[$key]) ? $replies[$key] : 'Success';
    }

    fwrite(
        $connection,
        "HTTP/1.1 $status\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($reply) . "\r\nConnection: close\r\n\r\n" . $reply
    );
    fclose($connection);
}
