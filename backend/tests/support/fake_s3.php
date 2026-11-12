<?php

declare(strict_types=1);

/**
 * Stand-in for an S3-compatible API (path style: /<bucket>/<key>) while S3StorageTest runs
 * (`php -S` router). Objects are kept in FAKE_S3_DIR. Every request must carry a valid
 * Signature V4 for FAKE_S3_ACCESS_KEY / FAKE_S3_SECRET (checked with the shop's signer, which
 * the test first checks against AWS's own test suite) and a matching x-amz-content-sha256.
 * Supports PUT/GET/HEAD/DELETE object, HEAD bucket and ListObjectsV2 with paging
 * (FAKE_S3_PAGE keys per page). Writing a mode to "<dir>/mode" forces errors: 403, 500, slow.
 */

define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/src/autoload.php';

use App\Storage\S3Signer;

$dir = (string) getenv('FAKE_S3_DIR');
$bucket = (string) getenv('FAKE_S3_BUCKET');
$access = (string) getenv('FAKE_S3_ACCESS_KEY');
$secret = (string) getenv('FAKE_S3_SECRET');
$page = max(1, (int) (getenv('FAKE_S3_PAGE') ?: 1000));
$mode = trim((string) @file_get_contents("{$dir}/mode")) ?: 'ok';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
parse_str((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? ''), $query);
$body = (string) file_get_contents('php://input');
$headers = [];
foreach ($_SERVER as $name => $value) {
    if (str_starts_with($name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = (string) $value;
    } elseif ($name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH') {
        $headers[strtolower(str_replace('_', '-', $name))] = (string) $value;
    }
}
file_put_contents("{$dir}/requests.log", json_encode(['method' => $method, 'path' => $path, 'query' => $query]) . "\n", FILE_APPEND | LOCK_EX);

$xmlError = static function (int $status, string $code, string $message): never {
    http_response_code($status);
    header('Content-Type: application/xml');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Error><Code>{$code}</Code><Message>{$message}</Message></Error>";
    exit;
};

if ($mode === 'slow') {
    sleep(40);
}
if ($mode === '500') {
    $xmlError(500, 'InternalError', 'We encountered an internal error.');
}

// Signature V4 check, the way S3 does it.
$auth = $headers['authorization'] ?? '';
if (!preg_match('#^AWS4-HMAC-SHA256 Credential=([^/]+)/(\d{8})/([^/]+)/s3/aws4_request, SignedHeaders=([a-z0-9;-]+), Signature=([0-9a-f]{64})$#', $auth, $m)) {
    $xmlError(403, 'AccessDenied', 'Missing or malformed authorization.');
}
[, $givenKey, , $region, $signedList, $signature] = $m;
if ($givenKey !== $access || $mode === '403') {
    $xmlError(403, 'InvalidAccessKeyId', 'The access key does not exist.');
}
$payloadHash = $headers['x-amz-content-sha256'] ?? '';
if ($payloadHash !== hash('sha256', $body)) {
    $xmlError(400, 'XAmzContentSHA256Mismatch', 'The content hash does not match the body.');
}
$signed = [];
foreach (explode(';', $signedList) as $name) {
    $signed[$name] = $name === 'host' ? ($headers['host'] ?? '') : ($headers[$name] ?? '');
}
$expected = (new S3Signer($access, $secret, $region))->authorization($method, $path, array_map('strval', $query), $signed, $payloadHash, $headers['x-amz-date'] ?? '');
if (!hash_equals($expected, $auth)) {
    $xmlError(403, 'SignatureDoesNotMatch', 'The request signature we calculated does not match.');
}

// Routing: /<bucket> or /<bucket>/<key>.
if (!str_starts_with($path, "/{$bucket}")) {
    $xmlError(404, 'NoSuchBucket', 'The specified bucket does not exist.');
}
$key = ltrim(substr($path, strlen("/{$bucket}")), '/');
$file = static fn (string $k): string => "{$dir}/objects/" . rawurlencode($k);
@mkdir("{$dir}/objects", 0700, true);

if ($key === '') {
    if ($method === 'HEAD') {
        http_response_code(200);
        exit;
    }
    if ($method === 'GET' && ($query['list-type'] ?? '') === '2') {
        $prefix = (string) ($query['prefix'] ?? '');
        $keys = [];
        foreach (glob("{$dir}/objects/*") ?: [] as $f) {
            if (str_ends_with($f, '.meta')) {
                continue;
            }
            $k = rawurldecode(basename($f));
            if (str_starts_with($k, $prefix)) {
                $keys[] = $k;
            }
        }
        sort($keys, SORT_STRING);
        $start = isset($query['continuation-token']) ? (int) base64_decode((string) $query['continuation-token']) : 0;
        $slice = array_slice($keys, $start, $page);
        $truncated = $start + $page < count($keys);
        header('Content-Type: application/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult><Name>' . htmlspecialchars($bucket) . '</Name>';
        foreach ($slice as $k) {
            echo '<Contents><Key>' . htmlspecialchars($k) . '</Key><Size>' . filesize($file($k)) . '</Size><LastModified>' . gmdate('Y-m-d\TH:i:s.000\Z', filemtime($file($k))) . '</LastModified></Contents>';
        }
        echo '<IsTruncated>' . ($truncated ? 'true' : 'false') . '</IsTruncated>';
        if ($truncated) {
            echo '<NextContinuationToken>' . base64_encode((string) ($start + $page)) . '</NextContinuationToken>';
        }
        echo '</ListBucketResult>';
        exit;
    }
    $xmlError(400, 'InvalidRequest', 'Unsupported bucket request.');
}

switch ($method) {
    case 'PUT':
        file_put_contents($file($key), $body);
        file_put_contents($file($key) . '.meta', json_encode(['content_type' => $headers['content-type'] ?? '', 'cache_control' => $headers['cache-control'] ?? '']));
        header('ETag: "' . md5($body) . '"');
        http_response_code(200);
        exit;
    case 'GET':
    case 'HEAD':
        if (!is_file($file($key))) {
            $method === 'HEAD' ? http_response_code(404) : $xmlError(404, 'NoSuchKey', 'The specified key does not exist.');
            exit;
        }
        $meta = json_decode((string) @file_get_contents($file($key) . '.meta'), true) ?: [];
        header('Content-Type: ' . ($meta['content_type'] ?? 'application/octet-stream'));
        if (($meta['cache_control'] ?? '') !== '') {
            header('Cache-Control: ' . $meta['cache_control']);
        }
        header('ETag: "' . md5_file($file($key)) . '"');
        header('Content-Length: ' . filesize($file($key)));
        http_response_code(200);
        if ($method === 'GET') {
            readfile($file($key));
        }
        exit;
    case 'DELETE':
        @unlink($file($key));
        @unlink($file($key) . '.meta');
        http_response_code(204);
        exit;
}
$xmlError(405, 'MethodNotAllowed', 'Method not allowed.');
