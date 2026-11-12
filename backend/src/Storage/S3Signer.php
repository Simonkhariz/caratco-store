<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * AWS Signature Version 4 for S3-compatible APIs (Cloudflare R2, Backblaze B2, AWS S3, AIStor,
 * Garage): https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html
 * Checked against AWS's published test suite (tests/S3StorageTest.php).
 */
final class S3Signer
{
    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
        private readonly string $service = 's3',
    ) {
    }

    /**
     * The Authorization header for a request. $headers are the headers that will be sent and
     * signed (names in any case; host and x-amz-date must be among them).
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    public function authorization(string $method, string $path, array $query, array $headers, string $payloadHash, string $amzDate): string
    {
        $date = substr($amzDate, 0, 8);
        $canonicalHeaders = [];
        foreach ($headers as $name => $value) {
            $canonicalHeaders[strtolower(trim((string) $name))] = trim((string) preg_replace('/\s+/', ' ', (string) $value));
        }
        ksort($canonicalHeaders, SORT_STRING);
        $signedHeaders = implode(';', array_keys($canonicalHeaders));
        $headerBlock = '';
        foreach ($canonicalHeaders as $name => $value) {
            $headerBlock .= "{$name}:{$value}\n";
        }

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            self::canonicalPath($path),
            self::canonicalQuery($query),
            $headerBlock,
            $signedHeaders,
            $payloadHash,
        ]);
        $scope = "{$date}/{$this->region}/{$this->service}/aws4_request";
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);

        $key = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);
        $signature = hash_hmac('sha256', $stringToSign, $key);

        return "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
    }

    /** Each path segment URI-encoded (RFC 3986), slashes kept. */
    public static function canonicalPath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }
        return implode('/', array_map(static fn (string $s): string => rawurlencode($s), explode('/', $path)));
    }

    /** @param array<string, string> $query sorted by name, then value, both URI-encoded */
    public static function canonicalQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[] = [rawurlencode((string) $name), rawurlencode((string) $value)];
        }
        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return implode('&', array_map(static fn (array $p): string => "{$p[0]}={$p[1]}", $pairs));
    }
}
