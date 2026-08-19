<?php

declare(strict_types=1);

use Arasya\Operations\Application\Container;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require dirname(__DIR__) . '/bootstrap.php';

try {
    $container = new Container();
    $container->kernel()->handle($container->requestFactory()->fromGlobals())->send();
} catch (Throwable) {
    $requestId = bin2hex(random_bytes(12));
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header("X-Request-ID: {$requestId}");
    echo json_encode(['error' => ['code' => 'CONFIGURATION_ERROR', 'message' => 'The service is not configured.', 'requestId' => $requestId]], JSON_UNESCAPED_SLASHES);
}

