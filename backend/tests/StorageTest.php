<?php

declare(strict_types=1);

/**
 * The storage layer for uploaded pictures (App\Storage, roadmap Prompt 22): the local driver's
 * store/read/list/delete, key and path safety, the URL that never changes with the storage, and
 * how the driver is chosen. Runs in a throw-away folder.
 *
 *   php backend/tests/run.php Storage
 */

require __DIR__ . '/support/TestKit.php';

use App\Config\Env;
use App\Storage\LocalStorage;
use App\Storage\Storage;
use App\Storage\StorageError;
use App\Storage\StorageKey;

$t = new TestKit('Storage');
$root = str_replace('\\', '/', sys_get_temp_dir()) . '/shopco-storage-' . getmypid();
@mkdir($root, 0700, true);
$t->cleanup(function () use ($root): void {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($root);
    @unlink(dirname($root) . '/shopco-storage-outside-' . getmypid() . '.txt');
});
$source = "{$root}/../shopco-storage-src-" . getmypid() . '.webp';
file_put_contents($source, 'picture-bytes-1');
$t->cleanup(fn () => @unlink($source));
$storage = new LocalStorage($root);
$throws = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (StorageError) {
        return true;
    }
};

// -----------------------------------------------------------------------------
$t->section('Store, read, list, delete');
$key = StorageKey::forUpload('webp');
$t->ok('upload keys are server-made: prod_<date>_<time>_<random>.webp', (bool) preg_match('/^prod_\d{8}_\d{6}_[0-9a-f]{8}\.webp$/', $key), $key);
$storage->put($key, $source, 'image/webp', StorageKey::cacheControl($key));
$t->check('stored', true, $storage->exists($key));
$t->check('reads back the same bytes', 'picture-bytes-1', $storage->read($key));
$t->check('the source is kept (copied, not moved, when it is not an upload)', true, is_file($source));
$t->check('public path never names the storage', "/uploads/{$key}", $storage->url($key));
$storage->put('catalog/banner-test.webp', $source, 'image/webp', 'public, max-age=86400');
$t->check('nested keys create their folder', true, is_file("{$root}/catalog/banner-test.webp"));
file_put_contents("{$root}/.gitkeep", '');
$listed = iterator_to_array($storage->list(), false);
$keys = array_column($listed, 'key');
sort($keys);
$t->check('lists every picture, hidden files left out', ['catalog/banner-test.webp', $key], $keys);
$t->check('with size and date', [strlen('picture-bytes-1'), true], [$listed[0]['size'], $listed[0]['modified'] > 0]);
$t->check('prefix filter', ['catalog/banner-test.webp'], array_column(iterator_to_array($storage->list('catalog/'), false), 'key'));
file_put_contents($source, 'picture-bytes-2');
$storage->put($key, $source, 'image/webp', 'x');
$t->check('storing the same key again replaces it', 'picture-bytes-2', $storage->read($key));
$t->check('no temporary files left', [], glob("{$root}/*.part") ?: []);
$t->check('delete removes it', true, $storage->delete($key));
$t->check('and it is gone', [false, null], [$storage->exists($key), $storage->read($key)]);
$t->check('deleting again reports nothing removed', false, $storage->delete($key));
$t->check('storing a missing file is an error', true, $throws(fn () => $storage->put('prod_x.webp', "{$root}/nope.webp", 'image/webp', 'x')));
$t->check('writable folder', true, $storage->writable());
$t->check('a missing folder is not writable', false, (new LocalStorage("{$root}/does-not-exist"))->writable());

// -----------------------------------------------------------------------------
$t->section('Keys and paths are always safe');
file_put_contents(dirname($root) . '/shopco-storage-outside-' . getmypid() . '.txt', 'outside');
$bad = ['', '../shopco-storage-outside-' . getmypid() . '.txt', '/etc/passwd', 'a\\b.webp', 'a//b.webp', '.hidden', 'a/../b.webp', 'catalog/.env', 'x y.webp', 'x;rm.webp', str_repeat('a', 256)];
foreach ($bad as $badKey) {
    $label = $badKey === '' ? '(empty)' : (strlen($badKey) > 40 ? substr($badKey, 0, 12) . '…' : $badKey);
    $t->check("refused: {$label}", [false, true, null, false, false], [
        StorageKey::isValid($badKey),
        $throws(fn () => $storage->put($badKey, $source, 'image/webp', 'x')),
        $storage->read($badKey),
        $storage->exists($badKey),
        $storage->delete($badKey),
    ]);
}
$t->check('nothing outside the folder was touched', 'outside', file_get_contents(dirname($root) . '/shopco-storage-outside-' . getmypid() . '.txt'));
$t->check('no URL for an unsafe key', true, $throws(fn () => $storage->url('../x')));

$t->check('key from a stored path', 'prod_20261022_102734_27f8e528.webp', StorageKey::fromPublicPath('/uploads/prod_20261022_102734_27f8e528.webp'));
$t->check('key from a full URL', 'catalog/banner-formal.webp', StorageKey::fromPublicPath('https://shop.example/uploads/catalog/banner-formal.webp'));
$t->check('no key from an escaping path', null, StorageKey::fromPublicPath('/uploads/../.env'));
$t->check('admin uploads may be kept a year', 'public, max-age=31536000, immutable', StorageKey::cacheControl('prod_20261022_102734_27f8e528.webp'));
$t->check('other pictures for a day', 'public, max-age=86400', StorageKey::cacheControl('catalog/banner-formal.webp'));

// -----------------------------------------------------------------------------
$t->section('Choosing the storage');
$prop = new ReflectionProperty(Env::class, 'vars');
$vars = $prop->getValue();
Storage::useForTests(null);
$default = Storage::uploads();
$t->check('local by default', 'local', $default->name());
$t->check('in the folder from UPLOAD_DIR', Storage::localRoot(), $default instanceof LocalStorage ? $default->root() : null);
$t->ok('which for tests is the throw-away folder', str_ends_with(Storage::localRoot(), 'storage/test-uploads'), Storage::localRoot());
$prop->setValue(null, array_merge($vars, ['STORAGE_DRIVER' => 'ftp']));
Storage::useForTests(null);
$t->check('an unknown driver is a clear error, not a silent fallback', true, $throws(fn () => Storage::uploads()));
$prop->setValue(null, $vars);
Storage::useForTests(null);

exit($t->finish());
