<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Pictures on the server's own disk, in the folder served at /uploads (backend/public/uploads, or
 * UPLOAD_DIR). Every key is checked (StorageKey) and every path is confirmed to stay inside the
 * folder before anything is read, written or removed.
 */
final class LocalStorage implements ObjectStorage
{
    public function __construct(private readonly string $root)
    {
    }

    public function name(): string
    {
        return 'local';
    }

    public function root(): string
    {
        return $this->root;
    }

    public function put(string $key, string $sourcePath, string $contentType, string $cacheControl): void
    {
        StorageKey::assertValid($key);
        if (!is_file($sourcePath)) {
            throw new StorageError('The file to store does not exist.');
        }
        $target = $this->root . '/' . $key;
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new StorageError('The uploads folder could not be created.');
        }
        // Uploaded files are moved; anything else is copied, then the copy is swapped in, so a
        // reader never sees half a file.
        $moved = is_uploaded_file($sourcePath) ? @move_uploaded_file($sourcePath, $target) : false;
        if (!$moved) {
            $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.part';
            if (!@copy($sourcePath, $tmp) || !@rename($tmp, $target)) {
                @unlink($tmp);
                throw new StorageError('The file could not be saved in the uploads folder.');
            }
        }
        @chmod($target, 0644);
    }

    public function read(string $key): ?string
    {
        $path = $this->existingPath($key);
        if ($path === null) {
            return null;
        }
        $data = @file_get_contents($path);
        return $data === false ? null : $data;
    }

    public function exists(string $key): bool
    {
        return $this->existingPath($key) !== null;
    }

    public function delete(string $key): bool
    {
        $path = $this->existingPath($key);
        return $path !== null && @unlink($path);
    }

    public function list(string $prefix = ''): iterable
    {
        if (!is_dir($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $key = str_replace('\\', '/', substr($file->getPathname(), strlen($this->root) + 1));
            if (!StorageKey::isValid($key) || ($prefix !== '' && !str_starts_with($key, $prefix))) {
                continue; // hidden files (.gitkeep) and anything that is not a valid key
            }
            yield ['key' => $key, 'size' => (int) $file->getSize(), 'modified' => (int) $file->getMTime()];
        }
    }

    public function url(string $key): string
    {
        StorageKey::assertValid($key);
        return '/uploads/' . $key;
    }

    public function writable(): bool
    {
        return is_dir($this->root) && is_writable($this->root);
    }

    /** The absolute path for a valid key whose file exists inside the folder, else null. */
    private function existingPath(string $key): ?string
    {
        if (!StorageKey::isValid($key)) {
            return null;
        }
        $root = realpath($this->root);
        $full = realpath($this->root . '/' . $key);
        if ($root === false || $full === false || !is_file($full)) {
            return null;
        }
        $root = str_replace('\\', '/', $root) . '/';
        return str_starts_with(str_replace('\\', '/', $full), $root) ? $full : null;
    }
}
