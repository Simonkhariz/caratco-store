<?php

declare(strict_types=1);

/**
 * Can this hosting account run Shop.co? Upload this one file to the account's web folder, open it
 * in a browser, read the answer, then DELETE IT. It changes nothing and needs no settings.
 */

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

$rows = [];
$add = static function (string $what, ?bool $ok, string $detail) use (&$rows): void {
    $rows[] = [$what, $ok, $detail];
};

$add('PHP 8.2 or newer', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);
$missing = array_values(array_filter(
    ['mysqli', 'curl', 'mbstring', 'openssl', 'json', 'simplexml', 'ctype', 'zlib'],
    static fn (string $e): bool => !extension_loaded($e)
));
$add('PHP extensions', $missing === [], $missing === [] ? 'all present' : 'missing: ' . implode(', ', $missing));

$bytes = static function (string $v): int {
    $n = (int) $v;
    return match (strtolower(substr(trim($v), -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
};
$upload = min($bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size')));
$add('Picture uploads of 5 MB', $upload >= 5 * 1048576, ini_get('upload_max_filesize') . ' upload, ' . ini_get('post_max_size') . ' form (raise them in cPanel: MultiPHP INI Editor)');
// Many shared hosts forbid opcache_get_status() (opcache.restrict_api); PHP's settings tell then.
$opcacheStatus = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
$opcacheOn = is_array($opcacheStatus)
    ? (bool) ($opcacheStatus['opcache_enabled'] ?? false)
    : extension_loaded('Zend OPcache') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
$add('OPcache (speed)', $opcacheOn ?: null, $opcacheOn ? 'on' : 'off (optional; about twice as fast when on: MultiPHP INI Editor, opcache.enable)');

$outbound = static function (string $url): array {
    if (!function_exists('curl_init')) {
        return [false, 'curl is missing'];
    }
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6]);
    curl_exec($c);
    $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $error = curl_error($c);
    return [$code > 0, $code > 0 ? 'reached' : ('blocked: ' . $error)];
};
foreach (['Cloudflare R2 (pictures)' => 'https://r2.cloudflarestorage.com', 'Resend (email)' => 'https://api.resend.com', 'Kora (payments)' => 'https://api.korapay.com', 'Monnify (payments)' => 'https://api.monnify.com'] as $name => $url) {
    [$ok, $detail] = $outbound($url);
    // Cloudflare let the certificate on this bare test address expire (9 Sep 2026); account
    // addresses (<id>.r2.cloudflarestorage.com) are fine. Reaching the expired certificate still
    // proves the host isn't blocking R2. The real bucket is checked in Admin → System status.
    if (!$ok && str_contains($url, 'r2.cloudflarestorage.com') && stripos($detail, 'certificate has expired') !== false) {
        [$ok, $detail] = [null, "reached Cloudflare; its certificate on this test address expired (Cloudflare's side, not the host). After setup, Admin → System status checks the real bucket"];
    }
    $add("This host can reach {$name}", $ok, $detail);
}

$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
$add('Backups with mysqldump', function_exists('proc_open') && !in_array('proc_open', $disabled, true) ? true : null, 'optional; the shop writes the backup itself if not');

// What decides which build to upload. Node.js and the picture library need a reasonably new Linux.
$machine = php_uname('m');
$add('Processor', in_array($machine, ['x86_64', 'amd64'], true) ? true : null, $machine . (in_array($machine, ['aarch64', 'arm64'], true) ? ': build with -Cpu arm64' : ''));
$osRelease = @is_readable('/etc/os-release') ? (string) @file_get_contents('/etc/os-release') : '';
$osName = preg_match('/^PRETTY_NAME="?([^"\n]+)/m', $osRelease, $m) ? $m[1] : php_uname('s');
$oldLinux = (bool) preg_match('/(CloudLinux|CentOS|Red Hat|AlmaLinux|Rocky)[^\n]*\b[67](\.|\b)/i', $osName);
$add('Linux new enough for the picture library', $oldLinux ? false : null, $osName . ($oldLinux ? ': too old; build with -NoImageResizing, or choose another host' : ' (version 8 or newer of CloudLinux/AlmaLinux is fine)'));
$software = (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown');
$add('Web server', null, $software . (stripos($software, 'litespeed') !== false ? ' (fine; the guide covers LiteSpeed)' : ''));
$nodes = array_map(static fn (string $p): string => basename($p), glob('/opt/alt/alt-nodejs*') ?: []);
$add('Node.js versions seen', null, $nodes === [] ? 'not visible from PHP; look in Setup Node.js App' : implode(', ', $nodes) . ' (20 or newer is needed)');
$memory = (string) ini_get('memory_limit');
$add('PHP memory for one request', $memory === '-1' || $bytes($memory) >= 128 * 1048576, $memory);

$jsCheck = isset($_SERVER['HTTP_COOKIE']) && str_contains((string) $_SERVER['HTTP_COOKIE'], '__test=');
$add('No browser check in front of the site', $jsCheck ? false : null, $jsCheck ? 'a __test cookie suggests one: webhooks and the storefront would be refused' : 'not seen; test it from a server too: curl -i <this page>');

?><!doctype html>
<title>Can this host run Shop.co?</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<body style="font:15px system-ui;max-width:760px;margin:24px auto;padding:0 16px">
<h1 style="font-size:20px">Can this host run Shop.co?</h1>
<table style="border-collapse:collapse;width:100%">
<?php foreach ($rows as [$what, $ok, $detail]): ?>
<tr style="border-top:1px solid #ddd">
<td style="padding:6px 8px 6px 0"><?= $ok === null ? 'Check' : ($ok ? 'OK' : '<b style="color:#b00">NO</b>') ?></td>
<td style="padding:6px 8px"><?= htmlspecialchars($what) ?></td>
<td style="padding:6px 0;color:#555"><?= htmlspecialchars($detail) ?></td>
</tr>
<?php endforeach; ?>
</table>
<p>Also look in the control panel for: <b>Setup Node.js App</b> offering Node.js 20.9 or newer, <b>Cron Jobs</b>, <b>MySQL Databases</b>, and <b>Domains</b> or <b>Subdomains</b> (two addresses are needed). phpMyAdmin's first page shows the database version: MySQL 8 or MariaDB 10.4 or newer.</p>
<p><b>Delete this file now.</b></p>
</body>
