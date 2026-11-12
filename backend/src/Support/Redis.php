<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Env;
use RuntimeException;

/**
 * Optional Redis connection, for the public catalogue cache only (docs/REDIS.md). Redis is never
 * the record of anything: orders, stock, payments, sessions, rate limits and emails stay in MySQL,
 * and every use falls back to the file cache when Redis is missing, slow or down.
 *
 * Needs no PHP extension: it speaks Redis's protocol (RESP) over a plain socket, so it works on
 * hosts without phpredis. REDIS_URL turns it on:
 *   redis://:password@127.0.0.1:6379/0      TCP
 *   rediss://:password@host:6380/0          TLS (certificate checked)
 *   unix:///home/user/redis.sock?password=…&db=0   Unix socket (common on cPanel)
 * REDIS_PREFIX (default "shopco:") keeps the shop's keys apart on a shared Redis.
 * REDIS_TIMEOUT_MS (default 150) bounds connecting and every reply.
 * REDIS_RETRY_SECONDS (default 30): after a failure, Redis is skipped (no waiting at all) until
 * then; the marker is a file, so every PHP process on the server sees it.
 */
final class Redis
{
    /** @var resource|null */
    private $socket = null;
    private static ?self $shared = null;
    private static bool $sharedResolved = false;
    private static bool $recovered = false;
    private static ?string $testMarkerDir = null;

    private function __construct(
        private readonly string $address,
        private readonly ?string $password,
        private readonly int $db,
        private readonly float $timeout,
        public readonly string $prefix,
    ) {
    }

    /** Whether REDIS_URL is set (Redis is optional). */
    public static function isConfigured(): bool
    {
        return trim((string) Env::get('REDIS_URL', '')) !== '';
    }

    /**
     * The connection for this request, or null when Redis is not set up or recently failed. The
     * first failure in a while is logged and marks Redis down for REDIS_RETRY_SECONDS.
     */
    public static function shared(): ?self
    {
        if (self::$sharedResolved) {
            return self::$shared;
        }
        self::$sharedResolved = true;
        if (!self::isConfigured() || !DevSwitches::enabled(DevSwitches::REDIS)) {
            return null;
        }
        $marker = self::markerFile();
        $downSince = @filemtime($marker);
        if ($downSince !== false && time() - $downSince < self::retrySeconds()) {
            return null;
        }
        try {
            $redis = self::fromEnv();
            $redis->connect();
        } catch (\Throwable $e) {
            self::markDown($e->getMessage());
            return null;
        }
        if ($downSince !== false) {
            // Back after an outage: entries written before it may predate admin changes made
            // while Redis was away (they went to the file cache), so the cache starts afresh.
            @unlink($marker);
            self::$recovered = true;
        }
        return self::$shared = $redis;
    }

    /** True once per request when Redis has just come back after being marked down. */
    public static function takeRecovered(): bool
    {
        $was = self::$recovered;
        self::$recovered = false;
        return $was;
    }

    /** Stop using Redis for this request and for REDIS_RETRY_SECONDS on this server. */
    public static function markDown(string $reason): void
    {
        $marker = self::markerFile();
        if (!is_file($marker) || time() - (int) @filemtime($marker) >= self::retrySeconds()) {
            error_log('[Redis] unavailable, using the file cache: ' . mb_substr($reason, 0, 200));
        }
        @touch($marker);
        self::$shared?->close();
        self::$shared = null;
        self::$sharedResolved = true;
    }

    /**
     * For System status: set up or not, and whether it answers now (a direct check that ignores
     * the down marker), with the reply time.
     *
     * @return array{configured: bool, switched_off: bool, ok: bool|null, ms: float|null, error: string|null, down_marker: bool}
     */
    public static function status(): array
    {
        if (!self::isConfigured()) {
            return ['configured' => false, 'switched_off' => false, 'ok' => null, 'ms' => null, 'error' => null, 'down_marker' => false];
        }
        // Switched off in admin, Tech switches: no connection is made, so nothing can be measured.
        if (!DevSwitches::enabled(DevSwitches::REDIS)) {
            return ['configured' => true, 'switched_off' => true, 'ok' => null, 'ms' => null, 'error' => null, 'down_marker' => false];
        }
        $started = microtime(true);
        try {
            $redis = self::fromEnv();
            $redis->connect();
            $ok = $redis->command('PING') === 'PONG';
            $redis->close();
            return ['configured' => true, 'switched_off' => false, 'ok' => $ok, 'ms' => round((microtime(true) - $started) * 1000, 1), 'error' => null, 'down_marker' => is_file(self::markerFile())];
        } catch (\Throwable $e) {
            return ['configured' => true, 'switched_off' => false, 'ok' => false, 'ms' => null, 'error' => mb_substr($e->getMessage(), 0, 200), 'down_marker' => is_file(self::markerFile())];
        }
    }

    /** Tests only: where the down marker lives, and a fresh start. */
    public static function resetForTests(?string $markerDir): void
    {
        self::$shared?->close();
        self::$shared = null;
        self::$sharedResolved = false;
        self::$recovered = false;
        self::$testMarkerDir = $markerDir;
    }

    public static function fromEnv(): self
    {
        $url = trim((string) Env::get('REDIS_URL', ''));
        $timeout = max(10, min(5000, Env::int('REDIS_TIMEOUT_MS', 150))) / 1000;
        $prefix = (string) (Env::get('REDIS_PREFIX', 'shopco:') ?? 'shopco:');
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            throw new RuntimeException('REDIS_URL is not a valid address.');
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $scheme = strtolower($parts['scheme']);
        $password = isset($parts['pass']) ? rawurldecode($parts['pass']) : (isset($query['password']) ? (string) $query['password'] : null);
        if ($scheme === 'unix') {
            $address = 'unix://' . ($parts['path'] ?? '');
            $db = (int) ($query['db'] ?? 0);
        } elseif ($scheme === 'redis' || $scheme === 'rediss') {
            $address = ($scheme === 'rediss' ? 'tls://' : 'tcp://') . ($parts['host'] ?? '127.0.0.1') . ':' . (int) ($parts['port'] ?? 6379);
            $db = (int) ltrim((string) ($parts['path'] ?? '0'), '/');
        } else {
            throw new RuntimeException('REDIS_URL must start with redis://, rediss:// or unix://.');
        }
        return new self($address, $password !== '' ? $password : null, $db, $timeout, $prefix);
    }

    public function connect(): void
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $socket = @stream_socket_client($this->address, $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException("Cannot connect to Redis ({$errno}): {$error}");
        }
        $seconds = (int) floor($this->timeout);
        stream_set_timeout($socket, $seconds, (int) round(($this->timeout - $seconds) * 1e6));
        $this->socket = $socket;
        if ($this->password !== null) {
            $this->command('AUTH', $this->password);
        }
        if ($this->db !== 0) {
            $this->command('SELECT', (string) $this->db);
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /**
     * Run one command and return its reply: string, int, null (missing) or a list.
     *
     * @throws RuntimeException on a network problem or a Redis error reply
     */
    public function command(string ...$args): mixed
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Redis is not connected.');
        }
        $payload = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $payload .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }
        for ($written = 0; $written < strlen($payload); $written += $n) {
            $n = @fwrite($this->socket, substr($payload, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException('Writing to Redis failed.');
            }
        }
        return $this->reply();
    }

    private function reply(): mixed
    {
        $line = $this->line();
        $type = $line[0] ?? '';
        $rest = substr($line, 1);
        return match ($type) {
            '+' => $rest,
            '-' => throw new RuntimeException('Redis error: ' . mb_substr($rest, 0, 200)),
            ':' => (int) $rest,
            '$' => (int) $rest < 0 ? null : $this->bytes((int) $rest),
            '*' => (int) $rest < 0 ? null : $this->arrayReply((int) $rest),
            default => throw new RuntimeException('Unexpected reply from Redis.'),
        };
    }

    /** @return list<mixed> */
    private function arrayReply(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->reply();
        }
        return $items;
    }

    private function line(): string
    {
        $line = fgets($this->socket);
        if ($line === false) {
            $meta = stream_get_meta_data($this->socket);
            throw new RuntimeException(!empty($meta['timed_out']) ? 'Redis did not answer in time.' : 'Redis closed the connection.');
        }
        return rtrim($line, "\r\n");
    }

    private function bytes(int $length): string
    {
        $data = '';
        while (strlen($data) < $length + 2) {
            $chunk = fread($this->socket, $length + 2 - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                throw new RuntimeException(!empty($meta['timed_out']) ? 'Redis did not answer in time.' : 'Redis closed the connection.');
            }
            $data .= $chunk;
        }
        return substr($data, 0, $length);
    }

    private static function retrySeconds(): int
    {
        return max(1, Env::int('REDIS_RETRY_SECONDS', 30));
    }

    private static function markerFile(): string
    {
        $dir = self::$testMarkerDir ?? BASE_PATH . '/storage/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/redis.down';
    }
}
