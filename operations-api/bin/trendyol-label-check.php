<?php

declare(strict_types=1);

/*
 * Read-only Trendyol common-label capability check (API 2.65.0, docs/trendyol-awb-station.md section 11).
 *
 * It answers one question before the AWB station goes live: does this seller account get an official label for each
 * carrier and destination country it really ships with?
 *
 * On the API server, with the credentials the active API release already has, it:
 *   1. GETs the shipment packages of the last 13 days (Order V2, at most 3 pages);
 *   2. groups them by carrier and destination country;
 *   3. for at most 2 packages per group (24 label calls in total) GETs common-label/query, without and with storeFrontCode;
 *   4. for the first label of each group, GETs the PDF from the Trendyol CDN into memory and reads only its structure.
 *
 * It can never change anything: GET is the only verb in this file, only https://apigw.trendyol.com and
 * https://cdn.dsmcdn.com are reachable, no redirect is followed, the label creation service is not called, no database
 * connection is opened and no file is written. The PDF stays in memory and its text is never read.
 *
 * The report holds aggregates only: never a credential, order number, package id, tracking number, URL, name, address
 * or telephone. An error prints only a code. tests/trendyol-label-check.php proves all of this without a network.
 *
 * Run only with the owner's explicit approval. It works before this release is deployed, and leaves nothing on the server:
 *   ssh <host> '/usr/local/bin/php -d display_errors=0 --' < operations-api/bin/trendyol-label-check.php
 * After a deployment: php bin/trendyol-label-check.php
 */

const CHECK_API_HOST = 'apigw.trendyol.com';
const CHECK_LABEL_HOSTS = ['cdn.dsmcdn.com'];
const CHECK_WINDOW_DAYS = 13;
const CHECK_PAGE_SIZE = 200;
const CHECK_MAX_PAGES = 3;
const CHECK_SAMPLES_PER_GROUP = 2;
const CHECK_MAX_LABEL_CALLS = 24;
const CHECK_MAX_PDF_BYTES = 5242880;
const CHECK_CLOSED = ['Cancelled', 'UnSupplied', 'UnPacked', 'Returned', 'UnDeliveredAndReturned'];
const CHECK_ACTIVE = ['Created', 'Picking', 'Invoiced', 'ReadyToShip'];

/** The only network call of this file: GET, HTTPS, two hosts, no redirect, bounded. */
function check_http_get(string $url, array $headers, int $maxBytes): array
{
    $parts = parse_url($url);
    $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || !in_array($host, [CHECK_API_HOST, ...CHECK_LABEL_HOSTS], true)) {
        throw new RuntimeException('HOST_REFUSED');
    }
    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = "{$name}: {$value}";
    }
    // No 'content' key exists: a request body cannot be sent.
    $context = stream_context_create([
        'http' => ['method' => 'GET', 'header' => implode("\r\n", $lines), 'timeout' => 20, 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
    if ($body === false) {
        throw new RuntimeException('NETWORK_FAILED');
    }
    $status = 0;
    $response = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
            $status = (int) $match[1];
        } elseif (preg_match('/^(Content-Type|Cache-Control|Expires):\s*(.*)$/i', $line, $match) === 1) {
            $response[strtolower($match[1])] = trim($match[2]);
        }
    }
    return ['status' => $status, 'body' => $body, 'headers' => $response];
}

function check_safe_word(mixed $value, int $max = 40): ?string
{
    return is_string($value) && preg_match('/^[\p{L}\p{N} ._&-]{1,' . $max . '}$/uD', trim($value)) === 1 ? trim($value) : null;
}

/** Trendyol's error key or exception class only: one code-like word, never a free-text message that could hold data. */
function check_hint(string $body): ?string
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return null;
    }
    foreach ([$decoded['errors'][0]['key'] ?? null, $decoded['exception'] ?? null, $decoded['error'] ?? null] as $value) {
        if (is_string($value) && preg_match('/^[A-Za-z][A-Za-z.]{1,79}$/D', $value) === 1) {
            return $value;
        }
    }
    return null;
}

function check_allowed_label(string $url): bool
{
    $parts = parse_url($url);
    return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']) && !isset($parts['fragment']) && in_array(strtolower((string) ($parts['host'] ?? '')), CHECK_LABEL_HOSTS, true);
}

/** Structure only: version, pages, page boxes. Compressed object streams are inflated in memory to find the boxes. */
function check_pdf_shape(string $bytes): array
{
    $valid = preg_match('/^%PDF-([12]\.[0-9])/', $bytes, $version) === 1 && str_contains(substr($bytes, -2048), '%%EOF');
    $haystack = $bytes;
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $streams) > 0) {
        foreach ($streams[1] as $stream) {
            if (strlen($stream) < 200000) {
                $inflated = @gzuncompress($stream);
                if (is_string($inflated) && str_contains($inflated, '/Type')) {
                    $haystack .= "\n" . $inflated;
                }
            }
        }
    }
    $boxes = [];
    if (preg_match_all('#/MediaBox\s*\[\s*(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s*\]#', $haystack, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $box) {
            $width = (float) $box[3] - (float) $box[1];
            $height = (float) $box[4] - (float) $box[2];
            $boxes[sprintf('%.1f x %.1f pt (%.0f x %.0f mm)', $width, $height, $width * 25.4 / 72, $height * 25.4 / 72)] = true;
        }
    }
    return [
        'validPdf' => $valid,
        'version' => $version[1] ?? null,
        'bytes' => strlen($bytes),
        'pages' => preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $haystack),
        'pageSizes' => array_keys($boxes),
        'images' => preg_match_all('#/Subtype\s*/Image#', $haystack),
        'rotated' => preg_match('#/Rotate\s+(90|180|270)#', $haystack) === 1,
    ];
}

/**
 * @param callable(string, array, int): array $http
 * @param callable(int): void $pause
 */
function check_run(callable $http, string $base, string $sellerId, array $auth, ?string $configuredStoreFront, int $nowMs, callable $pause): array
{
    $packages = [];
    $pages = 0;
    for ($page = 0; $page < CHECK_MAX_PAGES; $page++) {
        $query = http_build_query(['startDate' => $nowMs - CHECK_WINDOW_DAYS * 86400000, 'endDate' => $nowMs, 'page' => $page, 'size' => CHECK_PAGE_SIZE, 'orderByField' => 'PackageLastModifiedDate', 'orderByDirection' => 'ASC']);
        $response = $http("{$base}/integration/order/sellers/" . rawurlencode($sellerId) . "/v2/orders?{$query}", $auth + ['Accept' => 'application/json'], 8388608);
        if ($response['status'] !== 200) {
            return ['outcome' => 'PACKAGE_LIST_FAILED', 'http' => $response['status']];
        }
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || !is_array($decoded['content'] ?? null)) {
            return ['outcome' => 'PACKAGE_LIST_MALFORMED'];
        }
        $pages++;
        array_push($packages, ...$decoded['content']);
        if ($page + 1 >= (int) ($decoded['totalPages'] ?? 0)) {
            break;
        }
        $pause(3);
    }

    $groups = [];
    $candidates = [];
    foreach ($packages as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $provider = check_safe_word($raw['cargoProviderName'] ?? null) ?? 'UNKNOWN';
        $country = is_string($raw['shipmentAddress']['countryCode'] ?? null) && preg_match('/^[A-Za-z]{2}$/D', $raw['shipmentAddress']['countryCode']) === 1 ? strtoupper($raw['shipmentAddress']['countryCode']) : '??';
        $status = check_safe_word($raw['shipmentPackageStatus'] ?? $raw['status'] ?? null) ?? 'UNKNOWN';
        $tracking = $raw['cargoTrackingNumber'] ?? null;
        $tracking = is_int($tracking) && $tracking > 0 ? (string) $tracking : (is_string($tracking) ? trim($tracking) : '');
        $usable = preg_match('/^[A-Za-z0-9]{6,40}$/D', $tracking) === 1;
        $key = "{$provider}|{$country}";
        $groups[$key] ??= ['provider' => $provider, 'country' => $country, 'packages' => 0, 'withTracking' => 0, 'statuses' => []];
        $groups[$key]['packages']++;
        $groups[$key]['withTracking'] += $usable ? 1 : 0;
        $groups[$key]['statuses'][$status] = ($groups[$key]['statuses'][$status] ?? 0) + 1;
        if ($usable && !in_array($status, CHECK_CLOSED, true)) {
            $candidates[$key][in_array($status, CHECK_ACTIVE, true) ? 'active' : 'other'][] = ['tracking' => $tracking, 'status' => $status];
        }
    }

    $attempts = [];
    $documents = [];
    $calls = 0;
    foreach ($candidates as $key => $sets) {
        [$provider, $country] = explode('|', $key);
        $sample = array_slice([...($sets['active'] ?? []), ...($sets['other'] ?? [])], 0, CHECK_SAMPLES_PER_GROUP);
        foreach ($sample as $package) {
            $seen = [];
            foreach (['none', $country] as $variant) {
                if ($calls >= CHECK_MAX_LABEL_CALLS || ($variant !== 'none' && preg_match('/^[A-Z]{2}$/D', $variant) !== 1)) {
                    continue;
                }
                $calls++;
                $pause(2);
                $headers = $auth + ['Accept' => 'application/json'] + ($variant === 'none' ? [] : ['storeFrontCode' => $variant]);
                $row = ['provider' => $provider, 'country' => $country, 'packageStatus' => $package['status'], 'storeFrontHeader' => $variant];
                try {
                    $response = $http("{$base}/integration/sellers/" . rawurlencode($sellerId) . '/common-label/query?' . http_build_query(['id' => $package['tracking']]), $headers, 1048576);
                } catch (RuntimeException $error) {
                    $attempts[] = $row + ['outcome' => preg_match('/^[A-Z_]+$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'REQUEST_FAILED'];
                    continue;
                }
                $row['http'] = $response['status'];
                $decoded = json_decode($response['body'], true);
                $data = is_array($decoded) && is_array($decoded['data'] ?? null) ? $decoded['data'] : null;
                if ($response['status'] !== 200 || $data === null) {
                    $attempts[] = $row + ['outcome' => 'NO_LABEL', 'hint' => check_hint($response['body'])];
                    continue;
                }
                $urls = [];
                $hosts = [];
                $formats = [];
                foreach ($data as $item) {
                    $format = check_safe_word($item['format'] ?? null, 12) ?? 'UNKNOWN';
                    $formats[$format] = true;
                    $url = is_string($item['label'] ?? null) ? $item['label'] : '';
                    $urls[] = $url;
                    $host = parse_url($url, PHP_URL_HOST);
                    $hosts[is_string($host) && preg_match('/^[a-z0-9.-]{1,100}$/iD', $host) === 1 ? strtolower($host) : 'INVALID'] = true;
                }
                $fingerprint = hash('sha256', implode("\n", $urls));
                $row += ['outcome' => $urls === [] ? 'EMPTY' : 'LABEL', 'labels' => count($data), 'formats' => array_keys($formats), 'hosts' => array_keys($hosts),
                    'urlHasQuery' => $urls !== [] && parse_url($urls[0], PHP_URL_QUERY) !== null, 'sameUrlAsPreviousVariant' => in_array($fingerprint, $seen, true)];
                $seen[] = $fingerprint;
                $attempts[] = $row;
                if ($urls !== [] && !isset($documents[$key]) && ($formats['PDF'] ?? false)) {
                    $documents[$key] = ['provider' => $provider, 'country' => $country];
                    if (!check_allowed_label($urls[0])) {
                        $documents[$key]['outcome'] = 'HOST_NOT_ALLOWLISTED_NOT_DOWNLOADED';
                        continue;
                    }
                    $pause(1);
                    try {
                        $pdf = $http($urls[0], ['Accept' => 'application/pdf', 'User-Agent' => 'Arasya-AWB-Check'], CHECK_MAX_PDF_BYTES);
                        $documents[$key] += ['outcome' => $pdf['status'] === 200 ? 'DOWNLOADED' : 'DOWNLOAD_HTTP_' . $pdf['status'], 'contentType' => check_safe_word(str_replace('/', '-', (string) ($pdf['headers']['content-type'] ?? '')), 60), 'cacheControl' => check_safe_word(str_replace(['=', ','], ' ', (string) ($pdf['headers']['cache-control'] ?? '')), 80), 'expiresHeader' => isset($pdf['headers']['expires'])];
                        if ($pdf['status'] === 200) {
                            $documents[$key] += check_pdf_shape($pdf['body']);
                        }
                        unset($pdf);
                    } catch (RuntimeException) {
                        $documents[$key]['outcome'] = 'DOWNLOAD_FAILED';
                    }
                }
            }
        }
    }

    $verdicts = [];
    foreach ($groups as $key => $group) {
        $rows = array_values(array_filter($attempts, static fn (array $row): bool => $row['provider'] === $group['provider'] && $row['country'] === $group['country']));
        $document = $documents[$key] ?? null;
        $printable = $document !== null && ($document['validPdf'] ?? false) === true;
        $withoutHeader = (bool) array_filter($rows, static fn (array $row): bool => $row['storeFrontHeader'] === 'none' && $row['outcome'] === 'LABEL');
        $testedActive = (bool) array_filter($rows, static fn (array $row): bool => in_array($row['packageStatus'], CHECK_ACTIVE, true));
        $withHeader = (bool) array_filter($rows, static fn (array $row): bool => $row['storeFrontHeader'] !== 'none' && $row['outcome'] === 'LABEL');
        $verdicts[] = ['provider' => $group['provider'], 'country' => $group['country'], 'verdict' => $rows === [] ? 'UNTESTED_NO_ELIGIBLE_PACKAGE' : ($printable ? 'SUPPORTED_PDF' : ($withoutHeader || $withHeader ? 'LABEL_ANSWERED_PDF_NOT_CONFIRMED' : ($testedActive ? 'NOT_SUPPORTED_NO_LABEL' : 'NO_LABEL_ONLY_FINISHED_PACKAGES_TESTED'))), 'worksWithoutStoreFront' => $withoutHeader, 'worksWithStoreFront' => $withHeader, 'testedActivePackage' => $testedActive];
    }
    return ['outcome' => 'COMPLETED', 'windowDays' => CHECK_WINDOW_DAYS, 'listPages' => $pages, 'packages' => count($packages), 'labelCalls' => $calls, 'configuredStoreFront' => $configuredStoreFront,
        'groups' => array_values($groups), 'verdicts' => $verdicts, 'attempts' => $attempts, 'documents' => array_values($documents)];
}

if (defined('ARASYA_LABEL_CHECK_LIBRARY')) {
    return; // The test includes the functions only.
}
ini_set('display_errors', '0');
if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (PHP_VERSION_ID < 80200) {
    fwrite(STDOUT, "PHP_TOO_OLD\n");
    exit(1);
}
try {
    // Inside a deployed release: its own bootstrap. Sent over stdin: the active release of this account.
    $commit = 'local';
    if (str_starts_with(__FILE__, '/') && is_file(dirname(__DIR__) . '/bootstrap.php')) {
        require dirname(__DIR__) . '/bootstrap.php';
    } else {
        $root = (string) getenv('HOME') . '/arasya-operations-api';
        $commit = trim((string) @file_get_contents("{$root}/active-release"));
        if (preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1 || !is_file("{$root}/releases/{$commit}/bootstrap.php")) {
            fwrite(STDOUT, "ACTIVE_RELEASE_NOT_FOUND\n");
            exit(1);
        }
        require "{$root}/releases/{$commit}/bootstrap.php";
    }
    $config = \Arasya\Operations\Config\Config::fromEnvironment();
    $trendyol = $config->trendyol;
    if ($trendyol === null) {
        fwrite(STDOUT, "TRENDYOL_NOT_CONFIGURED\n");
        exit(1);
    }
    if (strtolower((string) parse_url($trendyol->baseUrl, PHP_URL_HOST)) !== CHECK_API_HOST) {
        fwrite(STDOUT, "UNEXPECTED_API_HOST\n");
        exit(1);
    }
    $report = check_run('check_http_get', 'https://' . CHECK_API_HOST, $trendyol->sellerId, [
        'Authorization' => 'Basic ' . base64_encode($trendyol->apiKey . ':' . $trendyol->apiSecret),
        'User-Agent' => $trendyol->sellerId . ' - SelfIntegration',
    ], $config->trendyolStoreFrontCode ?? null, (int) (microtime(true) * 1000), static function (int $seconds): void { sleep($seconds); });
    fwrite(STDOUT, json_encode(['activeRelease' => substr($commit, 0, 7)] + $report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
} catch (Throwable $error) {
    fwrite(STDOUT, 'CHECK_FAILED ' . (preg_match('/^[A-Z_]+$/D', $error->getMessage()) === 1 ? $error->getMessage() : (new ReflectionClass($error))->getShortName()) . "\n");
    exit(1);
}
