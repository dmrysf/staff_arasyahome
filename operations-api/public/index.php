<?php

declare(strict_types=1);

use Arasya\Operations\Application\Container;
use Arasya\Operations\Bootstrap\RuntimeLocator;

ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    require __DIR__ . '/RuntimeLocator.php';
    $override = getenv('ARASYA_API_RELEASE_ROOT');
    $home = getenv('HOME');
    $releaseRoot = RuntimeLocator::locate(
        $override === false ? null : (string) $override,
        dirname(__DIR__),
        $home === false ? null : (string) $home,
        __DIR__,
    );
    require $releaseRoot . '/bootstrap.php';
    $container = new Container();
    $container->kernel()->handle($container->requestFactory()->fromGlobals())->send();
} catch (Throwable) {
    $requestId = bin2hex(random_bytes(12));
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Resource-Policy: cross-origin');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header("X-Request-ID: {$requestId}");
    echo json_encode(['error' => ['code' => 'CONFIGURATION_ERROR', 'message' => 'The service is not configured.', 'requestId' => $requestId]], JSON_UNESCAPED_SLASHES);
}
