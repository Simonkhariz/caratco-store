<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * A .gz file that is written or fails loudly. A full disk must never leave a cut-off backup that
 * looks fine, so every write is checked, and the finished file is read back and counted: PHP's
 * gzclose() reports success even when its final flush could not be written.
 */
final class GzipFile
{
    /** @var resource */
    private $handle;
    private int $bytes = 0;

    public function __construct(private readonly string $path)
    {
        $handle = @gzopen($path, 'wb6');
        if ($handle === false) {
            throw new RuntimeException("Could not create {$path}.");
        }
        $this->handle = $handle;
    }

    public function write(string $data): void
    {
        $written = gzwrite($this->handle, $data);
        if ($written === false || $written < strlen($data)) {
            throw new RuntimeException("Could not write to {$this->path} (is the disk full?).");
        }
        $this->bytes += strlen($data);
    }

    public function close(): void
    {
        if (!gzclose($this->handle) || $this->readBack() !== $this->bytes) {
            throw new RuntimeException("Could not finish {$this->path} (is the disk full?).");
        }
    }

    private function readBack(): int
    {
        $in = @gzopen($this->path, 'rb');
        if ($in === false) {
            return -1;
        }
        $total = 0;
        while (!gzeof($in)) {
            $chunk = gzread($in, 1 << 20);
            if ($chunk === false) {
                gzclose($in);
                return -1;
            }
            $total += strlen($chunk);
        }
        gzclose($in);
        return $total;
    }
}
