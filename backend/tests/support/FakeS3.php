<?php

declare(strict_types=1);

/**
 * Starts the stand-in S3 API (fake_s3.php) with fresh random keys in a throw-away folder, and a
 * test copy of the shop's API on that bucket. Stopped and removed by the suite's cleanup.
 */
final class FakeS3
{
    public readonly string $dir;
    public readonly string $bucket;
    public readonly string $access;
    public readonly string $secret;
    /** @var list<resource> */
    private array $processes = [];

    public function __construct(private readonly TestKit $t, public readonly int $port, int $pageSize = 1000)
    {
        $this->dir = sys_get_temp_dir() . '/shopco-fake-s3-' . getmypid() . '-' . $port;
        @mkdir($this->dir . '/work', 0700, true);
        $this->bucket = 'shopco-media-test';
        $this->access = 'TESTKEY' . strtoupper(bin2hex(random_bytes(4)));
        $this->secret = bin2hex(random_bytes(20));
        $env = ['FAKE_S3_DIR' => $this->dir, 'FAKE_S3_BUCKET' => $this->bucket, 'FAKE_S3_ACCESS_KEY' => $this->access, 'FAKE_S3_SECRET' => $this->secret, 'FAKE_S3_PAGE' => (string) $pageSize];
        $this->processes[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fake_s3.php'], [1 => ['file', "{$this->dir}/server.log", 'w'], 2 => ['file', "{$this->dir}/server.log", 'a']], $pipes, BASE_PATH, array_merge(getenv(), $env));
        $up = false;
        for ($i = 0; $i < 40 && !$up; $i++) {
            usleep(150000);
            $up = @fsockopen('127.0.0.1', $port) !== false;
        }
        $t->check('stand-in S3 API started', true, $up);
        $t->cleanup(fn () => $this->stop());
    }

    public function endpoint(): string
    {
        return "http://127.0.0.1:{$this->port}";
    }

    /** The file holding an object (its full key in the bucket, prefix included). */
    public function objectFile(string $bucketKey): string
    {
        return "{$this->dir}/objects/" . rawurlencode($bucketKey);
    }

    /** How many requests reached the API so far. */
    public function requests(): int
    {
        return count(@file("{$this->dir}/requests.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    }

    /** Force errors: "403", "500", "slow"; "ok" for normal answers. */
    public function mode(string $mode): void
    {
        file_put_contents("{$this->dir}/mode", $mode);
    }

    /** backend/.env lines that point the shop at this bucket. */
    public function envLines(): array
    {
        return ['STORAGE_DRIVER=s3', 'S3_ENDPOINT=' . $this->endpoint(), 'S3_REGION=auto', "S3_BUCKET={$this->bucket}", "S3_ACCESS_KEY_ID={$this->access}", "S3_SECRET_ACCESS_KEY={$this->secret}"];
    }

    /**
     * An env file: the test runner's settings with $extra lines replacing any of the same names.
     *
     * @param list<string> $extra
     */
    public function envFile(string $name, array $extra): string
    {
        $names = array_map(static fn (string $l): string => strstr($l, '=', true) ?: $l, $extra);
        $lines = array_values(array_filter(
            file((string) getenv('SHOPCO_ENV_FILE'), FILE_IGNORE_NEW_LINES) ?: [],
            static fn (string $l): bool => !in_array(strstr($l, '=', true), $names, true)
        ));
        $file = "{$this->dir}/{$name}";
        file_put_contents($file, implode("\n", array_merge($lines, $extra)) . "\n");
        return $file;
    }

    /** Start a test copy of the shop's API with $envFile; returns its address. */
    public function startApi(int $port, string $envFile): string
    {
        $this->processes[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', BASE_PATH . '/public', BASE_PATH . '/dev-router.php'], [1 => ['file', "{$this->dir}/api-{$port}.log", 'w'], 2 => ['file', "{$this->dir}/api-{$port}.log", 'a']], $pipes, BASE_PATH, array_merge(getenv(), ['SHOPCO_ENV_FILE' => $envFile]));
        $base = "http://127.0.0.1:{$port}";
        $up = false;
        for ($i = 0; $i < 50 && !$up; $i++) {
            usleep(200000);
            $up = @file_get_contents("{$base}/api/v1/health") !== false;
        }
        $this->t->check("test API on port {$port} started", true, $up);
        return $base;
    }

    private function stop(): void
    {
        foreach ($this->processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
            }
        }
        usleep(200000);
        if (!is_dir($this->dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->dir);
    }
}
