<?php

declare(strict_types=1);

/**
 * Shared helpers for the HTTP/API test suites.
 *
 * Suites are plain PHP CLI scripts run by tests/run.php against a throw-away database
 * (shopco_test) and an API started on API_URL. Each suite:
 *
 *   require __DIR__ . '/support/TestKit.php';
 *   $t = new TestKit('Security');
 *   $t->check('label', $expected, $actual);
 *   ...
 *   exit($t->finish());
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
require_once BASE_PATH . '/src/autoload.php';

use App\Config\Env;
use App\Database\Database;

Env::load(BASE_PATH . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Africa/Lagos') ?: 'Africa/Lagos');

final class TestKit
{
    public string $api;
    public Database $db;
    private int $pass = 0;
    private int $fail = 0;
    /** @var list<callable> */
    private array $cleanups = [];
    private string $cookieDir;

    public function __construct(public string $suite)
    {
        $this->api = rtrim(getenv('API_URL') ?: 'http://127.0.0.1:8000/api/v1', '/');
        $this->db = Database::getInstance();
        $this->cookieDir = sys_get_temp_dir() . '/shopco-tests-' . getmypid();
        @mkdir($this->cookieDir, 0700, true);

        $database = (string) Env::get('DB_DATABASE', '');
        if (getenv('SHOPCO_ENV_FILE') === false && getenv('ALLOW_DEV_DB') !== '1') {
            fwrite(STDERR, "Refusing to run against '{$database}'. Use php backend/tests/run.php (it uses a throw-away shopco_test database),\n"
                . "or set ALLOW_DEV_DB=1 to run this suite against the configured database on purpose.\n");
            exit(2);
        }
        echo "== {$suite} (database {$database}, API {$this->api})\n";
    }

    // -------------------------------------------------------------------------
    // Assertions
    // -------------------------------------------------------------------------

    public function check(string $label, mixed $expected, mixed $actual): bool
    {
        $ok = $expected === $actual;
        if (!$ok && is_numeric($expected) && is_numeric($actual)) {
            $ok = abs((float) $expected - (float) $actual) < 0.005;
        }
        if ($ok) {
            $this->pass++;
            echo "PASS  {$label}\n";
        } else {
            $this->fail++;
            echo "FAIL  {$label} (expected " . $this->show($expected) . ', got ' . $this->show($actual) . ")\n";
        }
        return $ok;
    }

    public function ok(string $label, bool $condition, string $detail = ''): bool
    {
        return $this->check($label . ($condition || $detail === '' ? '' : " [{$detail}]"), true, $condition);
    }

    public function section(string $title): void
    {
        echo "-- {$title}\n";
    }

    /** Register cleanup to run in finish() even when checks fail. */
    public function cleanup(callable $fn): void
    {
        $this->cleanups[] = $fn;
    }

    public function finish(): int
    {
        foreach (array_reverse($this->cleanups) as $fn) {
            try {
                $fn();
            } catch (Throwable $e) {
                echo "WARN  cleanup failed: {$e->getMessage()}\n";
            }
        }
        $this->sql("DELETE FROM `rate_limits`");
        array_map('unlink', glob($this->cookieDir . '/*') ?: []);
        @rmdir($this->cookieDir);
        echo "RESULT: {$this->pass} passed, {$this->fail} failed\n";
        return $this->fail > 0 ? 1 : 0;
    }

    // -------------------------------------------------------------------------
    // HTTP
    // -------------------------------------------------------------------------

    /**
     * Make a request. $path is relative to API_URL ("/products") unless it starts with http.
     *
     * Options: json (array body), body (raw string), headers (array "Name: value"), jar (cookie
     * jar name shared across requests), csrf (token for X-CSRF-Token).
     *
     * @return array{status: int, json: array|null, body: string, headers: array<string, string>}
     */
    public function http(string $method, string $path, array $opts = []): array
    {
        $url = str_starts_with($path, 'http') ? $path : $this->api . $path;
        $headers = array_merge(['Accept: application/json'], $opts['headers'] ?? []);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        if (array_key_exists('json', $opts)) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
        } elseif (array_key_exists('body', $opts)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }
        if (!empty($opts['csrf'])) {
            $headers[] = 'X-CSRF-Token: ' . $opts['csrf'];
        }
        if (!empty($opts['jar'])) {
            $jar = $this->cookieDir . '/' . preg_replace('/\W/', '_', (string) $opts['jar']) . '.txt';
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'json' => null, 'body' => $error, 'headers' => []];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $parsed = [];
        foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $k = strtolower(trim($k));
                // A header sent twice reads as one comma-separated value, as HTTP defines it.
                $parsed[$k] = isset($parsed[$k]) ? $parsed[$k] . ', ' . trim($v) : trim($v);
            }
        }
        $json = json_decode($body, true);
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'body' => $body, 'headers' => $parsed];
    }

    /** Read a dotted path ("data.items.0.id") from a response's JSON. */
    public static function get(array $response, string $path): mixed
    {
        $value = $response['json'];
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    public function cookieFile(string $jar): string
    {
        return $this->cookieDir . '/' . preg_replace('/\W/', '_', $jar) . '.txt';
    }

    /**
     * Start a second API on the same test database, run as another kind of shop: $shop is laid
     * over the fixture shop (SHOP_CONFIG_OVERRIDES, which App\Config\Shop reads only while
     * APP_ENV is "testing") and $env over the test environment. Returns its base URL
     * ("http://127.0.0.1:<port>/api/v1"), or null when it did not start. Stopped in finish().
     *
     * @param array<string, mixed> $shop e.g. ['features' => ['ageCheck' => true]]
     * @param array<string, string> $env e.g. ['ADMIN_COOKIE_NAME' => 'other_admin']
     */
    public function startApi(int $port, array $shop = [], array $env = []): ?string
    {
        $base = json_decode((string) getenv('SHOP_CONFIG_OVERRIDES'), true);
        $config = \App\Config\Shop::merge(is_array($base) ? $base : [], $shop);

        // Values from the env file win over the process environment, so $env goes in a copy of the file.
        $envFile = $this->cookieDir . "/env-{$port}";
        $lines = array_values(array_filter(
            file((string) getenv('SHOPCO_ENV_FILE'), FILE_IGNORE_NEW_LINES) ?: [],
            static fn (string $line): bool => !array_key_exists(trim(strstr($line, '=', true) ?: ''), $env)
        ));
        foreach ($env as $name => $value) {
            $lines[] = "{$name}={$value}";
        }
        file_put_contents($envFile, implode("\n", $lines) . "\n");

        $log = $this->cookieDir . "/api-{$port}.log";
        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', BASE_PATH . '/public'],
            [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
            BASE_PATH,
            array_merge(getenv(), ['SHOPCO_ENV_FILE' => $envFile, 'SHOP_CONFIG_OVERRIDES' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)])
        );
        $this->cleanup(static function () use ($process): void {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        });

        $url = "http://127.0.0.1:{$port}/api/v1";
        for ($i = 0; $i < 50; $i++) {
            usleep(200000);
            if (@file_get_contents("{$url}/health") !== false) {
                return $url;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Database & fixtures
    // -------------------------------------------------------------------------

    /** Run SQL; returns rows for SELECTs (assoc arrays), affected rows otherwise. */
    public function sql(string $query, string $types = '', array $params = []): array|int
    {
        if (preg_match('/^\s*(SELECT|SHOW)/i', $query)) {
            return $this->db->fetchAll($query, $types, $params);
        }
        return $this->db->executeUpdate($query, $types, $params);
    }

    /** First column of the first row (or null). */
    public function scalar(string $query, string $types = '', array $params = []): mixed
    {
        $row = $this->db->fetchOne($query, $types, $params);
        return $row === null ? null : array_values($row)[0];
    }

    public function uniq(string $prefix): string
    {
        return $prefix . '.' . getmypid() . '.' . bin2hex(random_bytes(3));
    }

    /**
     * Create a temporary admin, sign in, and return [id, jar, csrf]. Removed in finish().
     *
     * @return array{id: int, email: string, password: string, jar: string, csrf: string}
     */
    public function adminSession(string $label = 'admin'): array
    {
        $email = $this->uniq("t-{$label}") . '@example.test';
        $password = 'Test-pass-' . bin2hex(random_bytes(6));
        $id = (int) $this->db->insert(
            "INSERT INTO `users` (`uuid`, `email`, `password_hash`, `full_name`, `role`, `status`, `email_verified_at`)
             VALUES (UUID(), ?, ?, ?, 'admin', 'active', NOW())",
            'sss',
            [$email, password_hash($password, PASSWORD_BCRYPT), "Test {$label}"]
        );
        $this->cleanup(function () use ($id): void {
            $this->sql("DELETE FROM `admin_audit_logs` WHERE `admin_id` = ?", 'i', [$id]);
            $this->sql("DELETE FROM `sessions` WHERE `user_id` = ?", 'i', [$id]);
            $this->sql("DELETE FROM `users` WHERE `id` = ?", 'i', [$id]);
        });

        $jar = "admin-{$id}";
        $res = $this->http('POST', '/auth/login', ['json' => ['email' => $email, 'password' => $password], 'jar' => $jar]);
        return ['id' => $id, 'email' => $email, 'password' => $password, 'jar' => $jar, 'csrf' => (string) self::get($res, 'data.csrf_token')];
    }

    /** A published product variant with at least $minStock units. */
    public function variant(int $minStock = 3): ?array
    {
        return $this->db->fetchOne(
            "SELECT p.id AS product_id, p.slug, v.id AS variant_id, v.size, v.color_name, v.stock_quantity,
                    COALESCE(NULLIF(v.price_override, 0), p.base_price) AS price
             FROM `products` p JOIN `product_variants` v ON v.product_id = p.id
             WHERE p.status = 'published' AND v.is_active = 1 AND v.stock_quantity >= ?
             ORDER BY p.id, v.id LIMIT 1",
            'i',
            [$minStock]
        );
    }

    /** The latest sign-in link written to the mail log for an email (log mode). */
    public function magicLinkToken(string $email): ?string
    {
        $path = BASE_PATH . '/' . ltrim((string) (Env::get('MAIL_LOG_PATH', 'storage/logs/mail.log') ?: 'storage/logs/mail.log'), '/');
        $log = @file_get_contents($path);
        if ($log === false) {
            return null;
        }
        $token = null;
        foreach (explode(str_repeat('=', 80), $log) as $entry) {
            if (str_contains($entry, "To: {$email}") && preg_match('#auth/verify\?token=([0-9a-f]+)#', $entry, $m)) {
                $token = $m[1];
            }
        }
        return $token;
    }

    /** The latest admin confirmation code written to the mail log for an email (log mode). */
    public function confirmationCode(string $email): ?string
    {
        $path = BASE_PATH . '/' . ltrim((string) (Env::get('MAIL_LOG_PATH', 'storage/logs/mail.log') ?: 'storage/logs/mail.log'), '/');
        $log = @file_get_contents($path);
        if ($log === false) {
            return null;
        }
        $code = null;
        foreach (explode(str_repeat('=', 80), $log) as $entry) {
            if (str_contains($entry, "To: {$email}") && preg_match('#Confirmation code: (\d{6})#', $entry, $m)) {
                $code = $m[1];
            }
        }
        return $code;
    }

    private function show(mixed $v): string
    {
        return is_string($v) ? "'{$v}'" : var_export($v, true);
    }
}
