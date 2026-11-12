<?php

declare(strict_types=1);

namespace App\Storage;

use App\Config\Env;

/**
 * Pictures in an S3-compatible bucket (Cloudflare R2, Backblaze B2, AWS S3, AIStor, Garage),
 * using the S3 HTTP API with Signature V4; no SDK.
 *
 * The bucket stays private: the shop links to /uploads/<key> and the API serves those paths
 * from the bucket (MediaController), so nothing needs public bucket access or a provider address
 * in the database. Objects are stored under S3_PREFIX (default "uploads/"), so the same bucket
 * can hold other, private data (backups) without it ever being served as a picture.
 *
 * Settings (backend/.env only, never in the storefront): S3_ENDPOINT (R2:
 * https://<account id>.r2.cloudflarestorage.com), S3_REGION (R2: auto), S3_BUCKET,
 * S3_ACCESS_KEY_ID, S3_SECRET_ACCESS_KEY, optional S3_PREFIX and S3_PATH_STYLE (default true).
 * Give the key access to this one bucket only, with object read and write.
 */
final class S3Storage implements ObjectStorage
{
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 30;
    private const LIST_PAGE = 1000;

    private readonly S3Signer $signer;
    private readonly string $prefix;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $region,
        private readonly string $bucket,
        string $accessKey,
        string $secretKey,
        string $prefix = 'uploads/',
        private readonly bool $pathStyle = true,
        private readonly int $listPage = self::LIST_PAGE,
    ) {
        if (!preg_match('#^https?://[^/\s]+$#', rtrim($endpoint, '/')) || $bucket === '' || $accessKey === '' || $secretKey === '' || $region === '') {
            throw new StorageError('S3 storage needs S3_ENDPOINT, S3_REGION, S3_BUCKET, S3_ACCESS_KEY_ID and S3_SECRET_ACCESS_KEY.');
        }
        $prefix = trim($prefix, '/');
        if ($prefix !== '' && !StorageKey::isValid($prefix)) {
            throw new StorageError('Invalid S3_PREFIX.');
        }
        $this->prefix = $prefix === '' ? '' : $prefix . '/';
        $this->signer = new S3Signer($accessKey, $secretKey, $region);
    }

    /**
     * The bucket from backend/.env. $prefix replaces S3_PREFIX: scripts/backup_to_bucket.php uses
     * "backups/" so backups sit beside the pictures but are never listed or served as pictures.
     */
    public static function fromEnv(?string $prefix = null): self
    {
        return new self(
            rtrim(trim((string) Env::get('S3_ENDPOINT', '')), '/'),
            trim((string) (Env::get('S3_REGION', 'auto') ?: 'auto')),
            trim((string) Env::get('S3_BUCKET', '')),
            trim((string) Env::get('S3_ACCESS_KEY_ID', '')),
            trim((string) Env::get('S3_SECRET_ACCESS_KEY', '')),
            $prefix ?? (string) (Env::get('S3_PREFIX', 'uploads/') ?? 'uploads/'),
            filter_var(Env::get('S3_PATH_STYLE', 'true'), FILTER_VALIDATE_BOOLEAN),
        );
    }

    public function name(): string
    {
        return 's3';
    }

    public function put(string $key, string $sourcePath, string $contentType, string $cacheControl): void
    {
        StorageKey::assertValid($key);
        $body = @file_get_contents($sourcePath);
        if ($body === false) {
            throw new StorageError('The file to store does not exist.');
        }
        $response = $this->request('PUT', $this->prefix . $key, [], $body, [
            'Content-Type' => $contentType,
            'Cache-Control' => $cacheControl,
        ]);
        if ($response['status'] !== 200) {
            throw $this->failure('store', $response);
        }
    }

    public function read(string $key): ?string
    {
        if (!StorageKey::isValid($key)) {
            return null;
        }
        $response = $this->request('GET', $this->prefix . $key);
        if ($response['status'] === 404) {
            return null;
        }
        if ($response['status'] !== 200) {
            throw $this->failure('read', $response);
        }
        return $response['body'];
    }

    public function exists(string $key): bool
    {
        if (!StorageKey::isValid($key)) {
            return false;
        }
        $response = $this->request('HEAD', $this->prefix . $key);
        if ($response['status'] === 200) {
            return true;
        }
        if ($response['status'] === 404) {
            return false;
        }
        throw $this->failure('check', $response);
    }

    /**
     * The stored object's details, or null when it does not exist (used to verify a migration).
     *
     * @return array{size: int, content_type: string, cache_control: string, etag: string}|null
     */
    public function head(string $key): ?array
    {
        StorageKey::assertValid($key);
        $response = $this->request('HEAD', $this->prefix . $key);
        if ($response['status'] === 404) {
            return null;
        }
        if ($response['status'] !== 200) {
            throw $this->failure('check', $response);
        }
        $h = $response['headers'];
        return [
            'size' => (int) ($h['content-length'] ?? -1),
            'content_type' => (string) ($h['content-type'] ?? ''),
            'cache_control' => (string) ($h['cache-control'] ?? ''),
            'etag' => trim((string) ($h['etag'] ?? ''), '"'),
        ];
    }

    public function delete(string $key): bool
    {
        // S3 answers a delete the same way whether or not the object existed, so check first.
        if (!$this->exists($key)) {
            return false;
        }
        $response = $this->request('DELETE', $this->prefix . $key);
        if ($response['status'] !== 204 && $response['status'] !== 200) {
            throw $this->failure('remove', $response);
        }
        return true;
    }

    public function list(string $prefix = ''): iterable
    {
        $token = null;
        do {
            $query = ['list-type' => '2', 'prefix' => $this->prefix . $prefix, 'max-keys' => (string) $this->listPage];
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }
            $response = $this->request('GET', '', $query);
            if ($response['status'] !== 200) {
                throw $this->failure('list', $response);
            }
            $xml = @simplexml_load_string($response['body']);
            if ($xml === false) {
                throw new StorageError('The storage returned an unreadable object list.');
            }
            foreach ($xml->Contents as $object) {
                $key = substr((string) $object->Key, strlen($this->prefix));
                if (StorageKey::isValid($key)) {
                    yield ['key' => $key, 'size' => (int) $object->Size, 'modified' => (int) strtotime((string) $object->LastModified)];
                }
            }
            $token = (string) $xml->IsTruncated === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null && $token !== '');
    }

    public function url(string $key): string
    {
        StorageKey::assertValid($key);
        return '/uploads/' . $key;
    }

    public function writable(): bool
    {
        try {
            return $this->request('HEAD', '')['status'] === 200;
        } catch (StorageError) {
            return false;
        }
    }

    /**
     * One signed request. $objectPath is the object key in the bucket ('' for the bucket itself).
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function request(string $method, string $objectPath, array $query = [], string $body = '', array $headers = []): array
    {
        $parts = parse_url($this->endpoint);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if ($this->pathStyle) {
            $path = '/' . $this->bucket . ($objectPath !== '' ? '/' . $objectPath : '');
        } else {
            $host = $this->bucket . '.' . $host;
            $path = '/' . $objectPath;
        }

        $amzDate = gmdate('Ymd\THis\Z');
        $payloadHash = hash('sha256', $body);
        $signed = $headers + ['Host' => $host, 'X-Amz-Date' => $amzDate, 'X-Amz-Content-Sha256' => $payloadHash];
        $authorization = $this->signer->authorization($method, $path, $query, $signed, $payloadHash, $amzDate);

        $url = "{$scheme}://{$host}" . S3Signer::canonicalPath($path) . ($query !== [] ? '?' . S3Signer::canonicalQuery($query) : '');
        $send = ['Authorization: ' . $authorization];
        foreach ($signed as $name => $value) {
            if (strtolower($name) !== 'host') {
                $send[] = "{$name}: {$value}";
            }
        }

        $received = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $send,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$received): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $received[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            // No "Expect: 100-continue" round trip before the body.
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($send, ['Expect:']));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new StorageError('The picture storage could not be reached: ' . mb_substr($error, 0, 200));
        }
        return ['status' => $status, 'body' => (string) $raw, 'headers' => $received];
    }

    /** @param array{status: int, body: string, headers: array<string, string>} $response */
    private function failure(string $action, array $response): StorageError
    {
        $code = preg_match('#<Code>([^<]{1,80})</Code>#', $response['body'], $m) ? " ({$m[1]})" : '';
        return new StorageError("The picture storage refused to {$action} the file: HTTP {$response['status']}{$code}.");
    }
}
