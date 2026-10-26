<?php

declare(strict_types=1);

// Test-only loopback HTTP server for tests/source-access.php (php -S): misbehaving shops. The request path picks the
// behaviour; the transport must never follow a redirect, never accept an oversized answer and give up on a slow one.
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$mode = (string) ($_SERVER['HTTP_X_TEST_MODE'] ?? 'ok');
header('Content-Type: application/json');
if ($mode === 'redirect') {
    http_response_code(302);
    header('Location: http://127.0.0.1:1/wp-json/wc/v3/orders');
    echo '{"redirect":true}';
    return true;
}
if ($mode === 'huge') {
    echo str_repeat('x', 1_048_577);
    return true;
}
if ($mode === 'slow') {
    sleep(4);
    echo '{}';
    return true;
}
echo json_encode(['path' => $path, 'method' => $_SERVER['REQUEST_METHOD'] ?? '', 'host' => $_SERVER['HTTP_HOST'] ?? '', 'body' => file_get_contents('php://input')]);
return true;
