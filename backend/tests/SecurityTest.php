<?php

declare(strict_types=1);

/**
 * Security: sessions, CSRF, magic links, admin access, guest order lookups, uploads,
 * rate limits, CORS and suspended accounts.
 *
 *   php backend/tests/run.php Security
 */

require __DIR__ . '/support/TestKit.php';

use App\Config\Env;

$t = new TestKit('Security');

/** Cookies from a curl (Netscape format) jar file: name => [value, httpOnly]. */
$cookies = static function (string $jarFile): array {
    $out = [];
    foreach (@file($jarFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $httpOnly = str_starts_with($line, '#HttpOnly_');
        if ($line === '' || ($line[0] === '#' && !$httpOnly)) {
            continue;
        }
        $parts = explode("\t", $httpOnly ? substr($line, 10) : $line);
        if (count($parts) >= 7) {
            $out[$parts[5]] = ['value' => $parts[6], 'http_only' => $httpOnly];
        }
    }
    return $out;
};

/** Multipart image upload with the admin cookie jar. */
$upload = static function (TestKit $t, string $jar, string $csrf, string $file, string $mime, string $name): int {
    $ch = curl_init($t->api . '/admin/upload-image');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => ['image' => new CURLFile($file, $mime, $name)],
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-CSRF-Token: ' . $csrf],
        CURLOPT_COOKIEFILE => $t->cookieFile($jar),
        CURLOPT_COOKIEJAR => $t->cookieFile($jar),
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $status;
};

$customerCookie = (string) (Env::get('SESSION_COOKIE_NAME', 'shopco_session') ?: 'shopco_session');

// -----------------------------------------------------------------------------
$t->section('Health and the retired dev admin token');
$health = $t->http('GET', '/health');
$t->check('health 200', 200, $health['status']);
$t->check('public health says only whether the API and database are up (no versions, names or settings)', ['database', 'status', 'timestamp'], (static function (array $d): array {
    $keys = array_keys($d);
    sort($keys);
    return $keys;
})((array) TestKit::get($health, 'data')));

// -----------------------------------------------------------------------------
$t->section('Client address behind proxies');
$ipFor = static function (string $remote, ?string $forwarded, string $trusted = '127.0.0.1,::1'): string {
    $saved = [$_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null];
    $prop = new ReflectionProperty(Env::class, 'vars');
    $vars = $prop->getValue();
    $prop->setValue(null, array_merge($vars, ['TRUSTED_PROXIES' => $trusted]));
    $_SERVER['REMOTE_ADDR'] = $remote;
    if ($forwarded === null) {
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    } else {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $forwarded;
    }
    $ip = App\Support\RequestContext::clientIp();
    $prop->setValue(null, $vars);
    [$_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']] = $saved;
    return $ip;
};
$t->check('a direct visitor cannot choose their address', '203.0.113.9', $ipFor('203.0.113.9', '1.2.3.4'));
$t->check('through our proxy: the address the proxy saw', '198.51.100.7', $ipFor('127.0.0.1', '198.51.100.7'));
$t->check('a made-up address sent to the proxy is ignored (the proxy appends the real one)', '198.51.100.7', $ipFor('127.0.0.1', '1.2.3.4, 198.51.100.7'));
$t->check('several of our own proxies are skipped', '198.51.100.7', $ipFor('10.0.0.5', '1.2.3.4, 198.51.100.7, 10.0.0.9', '127.0.0.1,10.0.0.0/8'));
$t->check('ranges work for IPv6 too', '2001:db8::1', $ipFor('fd00::2', '2001:db8::1', 'fd00::/8'));
$t->check('an address outside the range is not trusted', '11.0.0.5', $ipFor('11.0.0.5', '198.51.100.7', '10.0.0.0/8'));
$t->check('a malformed entry stops the walk at the proxy', '127.0.0.1', $ipFor('127.0.0.1', 'garbage'));
$t->check('only our own addresses in the header: the proxy itself', '127.0.0.1', $ipFor('127.0.0.1', '::1'));

$t->section('Live-server settings check (System status)');
$warningsWith = static function (array $settings): array {
    $prop = new ReflectionProperty(Env::class, 'vars');
    $vars = $prop->getValue();
    $prop->setValue(null, array_merge($vars, $settings));
    $warnings = App\Controllers\AdminOperationsController::productionConfigWarnings();
    $prop->setValue(null, $vars);
    return $warnings;
};
$good = ['APP_URL' => 'https://shop.example.com', 'FRONTEND_URL' => 'https://shop.example.com', 'CORS_ALLOWED_ORIGINS' => 'https://shop.example.com', 'KORA_SECRET_KEY' => 'sk_live_x', 'MONNIFY_API_KEY' => '', 'MONNIFY_CONTRACT_CODE' => ''];
$t->check('a correctly set live shop has no warnings', [], $warningsWith($good));
$t->ok('an internal APP_URL with Kora on is reported (no payment notifications)', str_contains(implode(' ', $warningsWith(['APP_URL' => 'http://127.0.0.1:8080'] + $good)), 'Kora cannot send'));
$t->ok('a private-network APP_URL is reported', str_contains(implode(' ', $warningsWith(['APP_URL' => 'https://10.0.0.4'] + $good)), 'Kora cannot send'));
$t->ok('an http:// FRONTEND_URL is reported', str_contains(implode(' ', $warningsWith(['FRONTEND_URL' => 'http://shop.example.com'] + $good)), 'FRONTEND_URL'));
$t->ok('localhost in CORS_ALLOWED_ORIGINS is reported', str_contains(implode(' ', $warningsWith(['CORS_ALLOWED_ORIGINS' => 'https://shop.example.com,http://localhost:3000'] + $good)), 'localhost'));
$t->ok('sandbox payment keys are reported', str_contains(implode(' ', $warningsWith(['KORA_SECRET_KEY' => 'sk_test_x'] + $good)), 'sandbox'));

$t->section('Upload size limits');
$t->check('php.ini sizes are read like PHP does', [2097152, 8388608, 524288, 1073741824, 100, 0], array_map([App\Support\UploadFiles::class, 'iniBytes'], ['2M', '8m', '512K', '1G', '100', '']));
$t->check('the limit is shown in megabytes', ['2 MB', '5 MB', '1.5 MB'], array_map([App\Support\UploadFiles::class, 'megabytes'], [2097152, 5242880, 1572864]));
$t->check('old X-Dev-Admin-Token header -> 401', 401, $t->http('GET', '/admin/orders', ['headers' => ['X-Dev-Admin-Token: dev_admin_secret_token_12345']])['status']);
$t->check('Bearer dev token -> 401', 401, $t->http('GET', '/admin/orders', ['headers' => ['Authorization: Bearer dev_admin_secret_token_12345']])['status']);

// -----------------------------------------------------------------------------
$t->section('Customer registration: cookie session only');
$custEmail = $t->uniq('t-sec-customer') . '@example.test';
$t->cleanup(function () use ($t, $custEmail): void {
    $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$custEmail]);
    $t->sql("DELETE FROM `users` WHERE `email` = ?", 's', [$custEmail]);
});
$reg = $t->http('POST', '/auth/register', ['jar' => 'cust', 'json' => ['full_name' => 'Security Customer', 'email' => $custEmail, 'password' => 'CustomerPass123']]);
$t->check('register 201', 201, $reg['status']);
$t->check('register body has no session_token', null, TestKit::get($reg, 'data.session_token'));
$t->ok('register body has csrf_token', (string) TestKit::get($reg, 'data.csrf_token') !== '');
$jar = $cookies($t->cookieFile('cust'));
$t->ok('customer cookie set', isset($jar[$customerCookie]));
$t->ok('customer cookie is HttpOnly', ($jar[$customerCookie]['http_only'] ?? false) === true);
$me = $t->http('GET', '/auth/me', ['jar' => 'cust']);
$t->check('/auth/me with cookie 200', 200, $me['status']);
$csrf = (string) TestKit::get($me, 'data.csrf_token');
$t->check('PUT profile without CSRF -> 403', 403, $t->http('PUT', '/customer/profile', ['jar' => 'cust', 'json' => ['phone' => '08000000000']])['status']);
$t->check('PUT profile with CSRF -> 200', 200, $t->http('PUT', '/customer/profile', ['jar' => 'cust', 'csrf' => $csrf, 'json' => ['phone' => '08000000000']])['status']);
$t->check('a number as the profile name is not a server error', 200, $t->http('PUT', '/customer/profile', ['jar' => 'cust', 'csrf' => $csrf, 'json' => ['full_name' => 12345]])['status']);
$t->check('a list as an address name gets a clear 422', 422, $t->http('POST', '/customer/addresses', ['jar' => 'cust', 'csrf' => $csrf, 'json' => ['recipient_name' => [], 'phone' => '08000000000', 'street_address' => '1 Road', 'city' => 'Ikeja', 'state' => 'Lagos']])['status']);
$t->check('customer cookie on an admin endpoint -> 401', 401, $t->http('GET', '/admin/orders', ['jar' => 'cust'])['status']);

$t->section('Profile: the phone rule, and sizes the database can hold');
$putProfile = static fn (array $json): array => $t->http('PUT', '/customer/profile', ['jar' => 'cust', 'csrf' => $csrf, 'json' => $json]);
$storedPhone = static fn (): ?string => $t->scalar("SELECT `phone` FROM `users` WHERE `email` = ?", 's', [$custEmail]);
$t->check('profile phone "abc" -> 422', 422, $putProfile(['phone' => 'abc'])['status']);
$t->check('and it is not stored: the good number stays, in the saved format', '+2348000000000', $storedPhone());
$t->check('a list as the phone -> 422', 422, $putProfile(['phone' => ['0803']])['status']);
$t->check('an over-long phone -> 422', 422, $putProfile(['phone' => str_repeat('0', 51)])['status']);
$t->check('an over-long name -> 422', 422, $putProfile(['full_name' => str_repeat('n', 256)])['status']);
$t->check('spaces and dashes are fine', 200, $putProfile(['phone' => '0803 123-4567'])['status']);
$t->check('+234 is fine', 200, $putProfile(['phone' => '+234 803 123 4567'])['status']);
$t->check('the phone is optional: empty clears it', [200, ''], (static function () use ($putProfile, $storedPhone): array {
    return [$putProfile(['phone' => ''])['status'], $storedPhone()];
})());

$t->section('Addresses: an update follows the same rules as a new one');
$customerId = (int) $t->scalar("SELECT `id` FROM `users` WHERE `email` = ?", 's', [$custEmail]);
$newAddress = ['recipient_name' => 'Ada Obi', 'phone' => '08031234567', 'street_address' => '1 Road', 'city' => 'Ikeja', 'state' => 'Lagos'];
$postAddress = static fn (array $json): array => $t->http('POST', '/customer/addresses', ['jar' => 'cust', 'csrf' => $csrf, 'json' => $json]);
$addressA = (int) TestKit::get($postAddress($newAddress), 'data.id');
$addressB = (int) TestKit::get($postAddress(['city' => 'Yaba'] + $newAddress), 'data.id');
$putAddress = static fn (int $id, array $json): array => $t->http('PUT', "/customer/addresses/{$id}", ['jar' => 'cust', 'csrf' => $csrf, 'json' => $json]);
$t->check('an address book phone must be a real Nigerian number', 422, $postAddress(array_merge($newAddress, ['phone' => '1']))['status']);
$cityOf = static fn (int $id): ?string => $t->scalar("SELECT `city` FROM `addresses` WHERE `id` = ?", 'i', [$id]);
$t->check('PUT a blank city -> 422', 422, $putAddress($addressA, ['city' => ''])['status']);
$t->check('PUT a list as the city -> 422', 422, $putAddress($addressA, ['city' => ['x']])['status']);
$t->check('neither is saved (no blank, no "Array")', 'Ikeja', $cityOf($addressA));
$t->check('PUT one good field still works', 200, $putAddress($addressA, ['city' => 'Surulere'])['status']);
$t->check('and is saved', 'Surulere', $cityOf($addressA));
foreach ([['label', 51], ['recipient_name', 256], ['phone', 51], ['street_address', 256], ['city', 101], ['state', 101], ['country', 101], ['postal_code', 21]] as [$field, $size]) {
    $t->check("POST an over-long {$field} -> 422", 422, $postAddress([$field => str_repeat('1', $size)] + $newAddress)['status']);
    $t->check("PUT an over-long {$field} -> 422", 422, $putAddress($addressA, [$field => str_repeat('1', $size)])['status']);
}
// A failure partway must not leave the customer with no default address.
$defaultOf = static fn (): array => array_map('intval', array_column($t->sql("SELECT `id` FROM `addresses` WHERE `user_id` = ? AND `is_default` = 1", 'i', [$customerId]), 'id'));
$t->check('the first address is the default', [$addressA], $defaultOf());
$conn = $t->db->getConnection();
$sqlMode = (string) $conn->query('SELECT @@SESSION.sql_mode')->fetch_row()[0];
$conn->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
$addressRepo = new \App\Repositories\AddressRepository();
foreach ([
    'an update' => static fn () => $addressRepo->update($addressB, $customerId, ['is_default' => 1, 'label' => str_repeat('x', 60)]),
    'a new address' => static fn () => $addressRepo->create($customerId, ['is_default' => 1, 'label' => str_repeat('x', 60)] + $newAddress),
] as $what => $failing) {
    try {
        $failing();
        $t->ok("{$what} too big for the database fails", false);
    } catch (\mysqli_sql_exception) {
        $t->check("{$what} that fails keeps the default address", [$addressA], $defaultOf());
    }
}
$conn->query("SET SESSION sql_mode = '" . $conn->real_escape_string($sqlMode) . "'");
$t->check('a good update moves the default', [$addressB], (static function () use ($putAddress, $addressB, $defaultOf): array {
    $putAddress($addressB, ['is_default' => true]);
    return $defaultOf();
})());

$t->section('Sign-up refuses bad input with a 422');
$register = static fn (array $over): array => $t->http('POST', '/auth/register', ['json' => $over + ['full_name' => 'Bad Input', 'email' => $t->uniq('t-sec-bad') . '@example.test', 'password' => 'GoodPassword123']]);
foreach ([
    'a password with a zero byte' => [['password' => "GoodPass\u{0}word123"], 'password'],
    'an over-long name' => [['full_name' => str_repeat('n', 256)], 'full_name'],
    'an over-long phone' => [['phone' => str_repeat('0', 51)], 'phone'],
    'a phone that is not one' => [['phone' => 'abc'], 'phone'],
    'a list as the name' => [['full_name' => ['x']], 'full_name'],
] as $what => [$over, $field]) {
    $res = $register($over);
    $t->check("{$what} -> 422 on {$field}", [422, true], [$res['status'], TestKit::get($res, "error.details.{$field}") !== null]);
}
$t->check('/auth/me?scope[]=x -> 400, not a server error', 400, $t->http('GET', '/auth/me?scope[]=x')['status']);
$peek = $t->http('GET', '/auth/me?optional=1');
$t->check('a visitor asking whether anyone is signed in gets a plain "no", not an error', [200, null, null], [$peek['status'], TestKit::get($peek, 'data.user'), TestKit::get($peek, 'data.csrf_token')]);
$t->check('and the signed-in session answers the same way as always', $custEmail, TestKit::get($t->http('GET', '/auth/me?optional=1', ['jar' => 'cust']), 'data.user.email'));
$t->check('/auth/logout?scope[]=x -> 400, not a server error', 400, $t->http('POST', '/auth/logout?scope[]=x', ['json' => []])['status']);
$raw = (string) ($jar[$customerCookie]['value'] ?? '');
$t->check('session stored hashed (raw cookie is not a DB id)', 0, (int) $t->scalar("SELECT COUNT(*) FROM `sessions` WHERE `id` = ?", 's', [$raw]));
$t->check('session stored as sha256 of the cookie', 1, (int) $t->scalar("SELECT COUNT(*) FROM `sessions` WHERE `id` = ?", 's', [hash('sha256', $raw)]));

// -----------------------------------------------------------------------------
$t->section('Magic links');
$ml = $t->http('POST', '/auth/customer/magic-link', ['json' => ['email' => $custEmail]]);
$t->check('magic-link request 200', 200, $ml['status']);
$t->check('magic-link response has no token', null, TestKit::get($ml, 'data.magic_token'));
$t->ok('response body never contains a token field', !str_contains($ml['body'], 'token'));
$t->check('outbox payload has no raw token', 0, (int) $t->scalar(
    "SELECT COUNT(*) FROM `email_outbox` WHERE `recipient_email` = ? AND (`payload` LIKE '%\"token\":%' OR `payload` LIKE '%auth/verify%')",
    's',
    [$custEmail]
));

$singleEmail = $t->uniq('t-sec-magic') . '@example.test';
$t->cleanup(function () use ($t, $singleEmail): void {
    $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$singleEmail]);
    $t->sql("DELETE FROM `email_verification_tokens` WHERE `email` = ?", 's', [$singleEmail]);
    $t->sql("DELETE FROM `users` WHERE `email` = ?", 's', [$singleEmail]);
});
$t->http('POST', '/auth/customer/magic-link', ['json' => ['email' => $singleEmail]]);
$token = $t->magicLinkToken($singleEmail);
$t->ok('sign-in link delivered to the (test) mail log', $token !== null);
$t->check('magic link verifies once', 200, $t->http('POST', '/auth/customer/verify', ['jar' => 'magic', 'json' => ['token' => (string) $token]])['status']);
$t->check('magic link cannot be reused', 401, $t->http('POST', '/auth/customer/verify', ['json' => ['token' => (string) $token]])['status']);
$t->check('unknown magic token rejected', 401, $t->http('POST', '/auth/customer/verify', ['json' => ['token' => str_repeat('a', 64)]])['status']);

$t->section('Registering someone else\'s email first gains nothing');
$victimEmail = $t->uniq('t-sec-prehijack') . '@example.test';
$legitEmail = $t->uniq('t-sec-legit') . '@example.test';
$t->cleanup(function () use ($t, $victimEmail, $legitEmail): void {
    foreach ([$victimEmail, $legitEmail] as $e) {
        $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$e]);
        $t->sql("DELETE FROM `email_verification_tokens` WHERE `email` = ?", 's', [$e]);
        $t->sql("DELETE FROM `users` WHERE `email` = ?", 's', [$e]);
    }
});
$t->check('a stranger registers the address with their own password', 201, $t->http('POST', '/auth/register', ['jar' => 'stranger', 'json' => ['full_name' => 'Stranger', 'email' => $victimEmail, 'password' => 'StrangerPass123']])['status']);
$t->http('POST', '/auth/customer/magic-link', ['json' => ['email' => $victimEmail]]);
$ownerVerify = $t->http('POST', '/auth/customer/verify', ['jar' => 'owner', 'json' => ['token' => (string) $t->magicLinkToken($victimEmail)]]);
$t->check('the real owner signs in with the emailed link', 200, $ownerVerify['status']);
$t->check('the response says the earlier password was removed', true, TestKit::get($ownerVerify, 'data.password_removed'));
$t->check("the stranger's session is signed out", 401, $t->http('GET', '/auth/me', ['jar' => 'stranger'])['status']);
$t->ok("the stranger's password no longer works", $t->http('POST', '/auth/login', ['json' => ['email' => $victimEmail, 'password' => 'StrangerPass123']])['status'] !== 200);
$t->check("the owner's own session works", 200, $t->http('GET', '/auth/me', ['jar' => 'owner'])['status']);
$t->check('a normal sign-up: register', 201, $t->http('POST', '/auth/register', ['jar' => 'legit', 'json' => ['full_name' => 'Legit', 'email' => $legitEmail, 'password' => 'LegitPass12345']])['status']);
$t->http('POST', '/auth/customer/magic-link', ['json' => ['email' => $legitEmail]]);
$legitVerify = $t->http('POST', '/auth/customer/verify', ['jar' => 'legit', 'json' => ['token' => (string) $t->magicLinkToken($legitEmail)]]);
$t->check('then confirming in the same browser keeps the password', false, TestKit::get($legitVerify, 'data.password_removed'));
$t->check('and the password still signs in', 200, $t->http('POST', '/auth/login', ['json' => ['email' => $legitEmail, 'password' => 'LegitPass12345']])['status']);

// -----------------------------------------------------------------------------
$t->section('Admin sessions');
$admin = $t->adminSession('sec');
$t->check('magic-link for an admin email: same generic 200', 200, $t->http('POST', '/auth/customer/magic-link', ['json' => ['email' => $admin['email']]])['status']);
$t->check('no magic token issued for an admin', 0, (int) $t->scalar("SELECT COUNT(*) FROM `email_verification_tokens` WHERE `email` = ?", 's', [$admin['email']]));
$adminJar = $cookies($t->cookieFile($admin['jar']));
$t->ok('admin login sets the shopco_admin cookie', isset($adminJar['shopco_admin']));
$t->ok('admin login does not set the customer cookie', !isset($adminJar[$customerCookie]));
$t->ok('admin login returned a CSRF token', $admin['csrf'] !== '');
$t->check('/auth/me?scope=admin 200', 200, $t->http('GET', '/auth/me?scope=admin', ['jar' => $admin['jar']])['status']);
$t->check('/auth/me (customer scope) with the admin cookie -> 401', 401, $t->http('GET', '/auth/me', ['jar' => $admin['jar']])['status']);
$t->check('admin GET /admin/orders 200', 200, $t->http('GET', '/admin/orders?per_page=1', ['jar' => $admin['jar']])['status']);
$t->check('admin GET /admin/account 200 (identity resolved)', 200, $t->http('GET', '/admin/account', ['jar' => $admin['jar']])['status']);
$t->check('admin mutation without CSRF -> 403', 403, $t->http('POST', '/admin/categories', ['jar' => $admin['jar'], 'json' => []])['status']);
$t->check('admin mutation with CSRF passes auth (422 validation)', 422, $t->http('POST', '/admin/categories', ['jar' => $admin['jar'], 'csrf' => $admin['csrf'], 'json' => []])['status']);
$t->check('customer CSRF token does not work for admin', 403, $t->http('POST', '/admin/categories', ['jar' => $admin['jar'], 'csrf' => $csrf, 'json' => []])['status']);

// -----------------------------------------------------------------------------
$t->section('Uploads');
$tmp = sys_get_temp_dir() . '/shopco-sec-' . getmypid();
@mkdir($tmp);
file_put_contents("{$tmp}/x.svg", '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
file_put_contents("{$tmp}/fake.png", "<?php echo 'not an image'; ?>");
$t->check('SVG upload rejected 422', 422, $upload($t, $admin['jar'], $admin['csrf'], "{$tmp}/x.svg", 'image/svg+xml', 'x.svg'));
$t->check('PHP disguised as PNG rejected 422', 422, $upload($t, $admin['jar'], $admin['csrf'], "{$tmp}/fake.png", 'image/png', 'fake.png'));
// A real 1x1 PNG (no GD needed).
file_put_contents("{$tmp}/ok.png", base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
$t->check('upload without CSRF -> 403', 403, $upload($t, $admin['jar'], '', "{$tmp}/ok.png", 'image/png', 'ok.png'));
array_map('unlink', glob("{$tmp}/*") ?: []);
@rmdir($tmp);

// -----------------------------------------------------------------------------
$t->section('Guest order lookup');
$v = $t->variant(3);
$guestEmail = $t->uniq('t-sec-guest') . '@example.test';
$order = $t->http('POST', '/checkout/orders', [
    'headers' => ['Idempotency-Key: sec' . bin2hex(random_bytes(10))],
    'json' => [
        'email' => $guestEmail,
        'items' => [['product_id' => (int) $v['product_id'], 'variant_id' => (int) $v['variant_id'], 'quantity' => 1]],
        'shipping_address' => ['full_name' => 'Security Guest', 'phone' => '08012345678', 'address_line1' => '1 Test Road', 'city' => 'Enugu', 'state' => 'Enugu', 'country' => 'Nigeria'],
    ],
]);
$t->check('guest order created', 201, $order['status']);
$onum = (string) TestKit::get($order, 'data.order_number');
$gtoken = (string) TestKit::get($order, 'data.guest_access_token');
$t->cleanup(function () use ($t, $onum, $guestEmail): void {
    $t->sql("DELETE FROM `orders` WHERE `order_number` = ?", 's', [$onum]);
    $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$guestEmail]);
});

$noProof = $t->http('GET', "/checkout/orders/{$onum}");
$t->check('lookup without proof -> 404', 404, $noProof['status']);
$t->check('lookup with the wrong email -> 404', 404, $t->http('GET', "/checkout/orders/{$onum}", ['headers' => ['X-Order-Email: nobody@example.test']])['status']);
$t->check('phone number no longer authorizes -> 404', 404, $t->http('GET', "/checkout/orders/{$onum}?phone=08012345678")['status']);
$t->check('last 7 phone digits no longer authorize -> 404', 404, $t->http('GET', "/checkout/orders/{$onum}?phone=2345678")['status']);
$withEmail = $t->http('GET', "/checkout/orders/{$onum}", ['headers' => ["X-Order-Email: {$guestEmail}"]]);
$t->check('lookup with the full checkout email -> 200', 200, $withEmail['status']);
$t->check('email lookup is case-insensitive', 200, $t->http('GET', "/checkout/orders/{$onum}", ['headers' => ['X-Order-Email: ' . strtoupper($guestEmail)]])['status']);
$t->check('response hides user_id', false, array_key_exists('user_id', (array) TestKit::get($withEmail, 'data')));
$t->check('response hides the guest token hash', false, array_key_exists('guest_access_token_hash', (array) TestKit::get($withEmail, 'data')));
$t->check('response hides the idempotency key', false, array_key_exists('idempotency_key', (array) TestKit::get($withEmail, 'data')));
$t->check('guest access token opens the order', 200, $t->http('GET', "/checkout/orders/{$onum}", ['headers' => ["X-Guest-Access-Token: {$gtoken}"]])['status']);
$t->check('a wrong guest token -> 404', 404, $t->http('GET', "/checkout/orders/{$onum}", ['headers' => ['X-Guest-Access-Token: ' . str_repeat('0', 64)]])['status']);
$unknown = $t->http('GET', '/checkout/orders/ORD-00000000-NOPE00', ['headers' => ["X-Order-Email: {$guestEmail}"]]);
$t->check('unknown order -> 404', 404, $unknown['status']);
$t->check('unknown and unauthorized orders get the same message', TestKit::get($noProof, 'error.message'), TestKit::get($unknown, 'error.message'));

// Pay and ship it, then try the courier tracking number on its own.
$paid = $t->http('POST', "/admin/orders/{$onum}/confirm-payment", ['jar' => $admin['jar'], 'csrf' => $admin['csrf'], 'json' => ['reference' => 'SEC-' . bin2hex(random_bytes(4))]]);
$t->check('admin records a manual payment', 200, $paid['status']);
$tracking = 'TRK' . strtoupper(bin2hex(random_bytes(5)));
$shipped = $t->http('POST', "/admin/orders/{$onum}/shipment", ['jar' => $admin['jar'], 'csrf' => $admin['csrf'], 'json' => ['carrier' => 'GIG Logistics', 'tracking_number' => $tracking, 'status' => 'in_transit']]);
$t->check('admin ships the order', 200, $shipped['status']);
$t->check('tracking number alone does not open the order -> 404', 404, $t->http('GET', "/checkout/orders/{$tracking}")['status']);
$t->check('tracking number plus the checkout email -> 200', 200, $t->http('GET', "/checkout/orders/{$tracking}", ['headers' => ["X-Order-Email: {$guestEmail}"]])['status']);

$t->section('Payment redirect is locked to the storefront');
$t->check('initialize-payment without proof -> 404', 404, $t->http('POST', "/checkout/orders/{$onum}/initialize-payment", ['json' => ['gateway' => 'kora', 'redirect_url' => 'https://evil.example/steal']])['status']);

// -----------------------------------------------------------------------------
$t->section('Suspended customers');
$suspEmail = $t->uniq('t-sec-suspended') . '@example.test';
$t->cleanup(function () use ($t, $suspEmail): void {
    $t->sql("DELETE FROM `users` WHERE `email` = ?", 's', [$suspEmail]);
});
$t->check('second customer registers', 201, $t->http('POST', '/auth/register', ['jar' => 'susp', 'json' => ['full_name' => 'Suspended Person', 'email' => $suspEmail, 'password' => 'SuspendedPass123']])['status']);
$t->sql("UPDATE `users` SET `status` = 'suspended' WHERE `email` = ?", 's', [$suspEmail]);
$t->check('existing session stops working once suspended', 401, $t->http('GET', '/auth/me', ['jar' => 'susp'])['status']);
$t->check('suspended customer cannot sign in', 403, $t->http('POST', '/auth/login', ['json' => ['email' => $suspEmail, 'password' => 'SuspendedPass123']])['status']);
$t->http('POST', '/auth/customer/magic-link', ['json' => ['email' => $suspEmail]]);
$t->check('suspended customer gets no sign-in link', null, $t->magicLinkToken($suspEmail));

// -----------------------------------------------------------------------------
$t->section('CORS');
$evil = $t->http('GET', '/health', ['headers' => ['Origin: https://evil.example']]);
$t->ok('arbitrary origin is not echoed', ($evil['headers']['access-control-allow-origin'] ?? '') !== 'https://evil.example');
$t->ok('arbitrary origin gets no credentials grant', !isset($evil['headers']['access-control-allow-credentials']));
$allowed = trim(explode(',', (string) Env::get('CORS_ALLOWED_ORIGINS', 'http://localhost:3000'))[0]);
if ($allowed !== '') {
    $ok = $t->http('GET', '/health', ['headers' => ["Origin: {$allowed}"]]);
    $t->check('configured storefront origin is allowed', $allowed, $ok['headers']['access-control-allow-origin'] ?? null);
    $listing = $t->http('GET', '/categories', ['headers' => ["Origin: {$allowed}"]]);
    $vary = array_map('trim', explode(',', strtolower((string) ($listing['headers']['vary'] ?? ''))));
    $t->check('a cacheable answer varies by Origin as well as encoding (a shared cache keeps one per site)', [true, true], [in_array('origin', $vary, true), in_array('accept-encoding', $vary, true)]);
}

// -----------------------------------------------------------------------------
$t->section('First-run setup refuses a password bcrypt cannot take');
$adminIds = array_map('intval', array_column($t->sql("SELECT `id` FROM `users` WHERE `role` IN ('admin', 'super_admin')"), 'id'));
$setupFlag = $t->scalar("SELECT `value` FROM `store_settings` WHERE `key_name` = 'is_setup_completed'");
$setupOpen = static function () use ($t, $adminIds): void {
    $t->sql("UPDATE `users` SET `role` = 'customer' WHERE `id` IN (" . implode(',', $adminIds) . ')');
    $t->sql("UPDATE `store_settings` SET `value` = '0' WHERE `key_name` = 'is_setup_completed'");
};
$setupClosed = static function () use ($t, $adminIds, $setupFlag): void {
    $t->sql("UPDATE `users` SET `role` = 'admin' WHERE `id` IN (" . implode(',', $adminIds) . ')');
    $t->sql("UPDATE `store_settings` SET `value` = ? WHERE `key_name` = 'is_setup_completed'", 's', [(string) $setupFlag]);
};
$t->cleanup($setupClosed);
$setupOpen();
$setup = $t->http('POST', '/setup', ['json' => [
    'name' => 'Owner', 'email' => 'owner@example.test', 'password' => "GoodPass\u{0}word123", 'store_name' => 'Shop',
    'origin_address' => '1 Road', 'origin_city' => 'Ikeja', 'origin_state' => 'Lagos',
]]);
$setupClosed();
$t->check('a password with a zero byte -> 422 on password', [422, true], [$setup['status'], TestKit::get($setup, 'error.details.password') !== null]);

// -----------------------------------------------------------------------------
$t->section('Sign-in: only real addresses reach an account');
$login = static fn (string $email, string $password, array $headers = []): array => $t->http('POST', '/auth/login', ['headers' => $headers, 'json' => ['email' => $email, 'password' => $password]]);
$t->ok('an admin address like admin@shopco.ng passes the check', filter_var('admin@shopco.ng', FILTER_VALIDATE_EMAIL) !== false);
$refused = array_filter(array_column($t->sql('SELECT `email` FROM `users`'), 'email'), static fn (string $e): bool => filter_var(strtolower($e), FILTER_VALIDATE_EMAIL) === false);
$t->check('every address already in the database passes it', [], array_values($refused));
$wrongPassword = $login($admin['email'], 'not-the-password');
$accented = preg_replace('/e/', 'é', $admin['email'], 1);
$t->ok('(the accented address really differs)', $accented !== $admin['email']);
$accentRes = $login($accented, $admin['password']);
$t->check('an accented copy of an admin address, even with the right password -> 401', 401, $accentRes['status']);
$t->check('the answer is the same as for a wrong password', $wrongPassword['json'], $accentRes['json']);
$t->check('a capitalised address is still the same account', 200, $login(strtoupper($admin['email']), $admin['password'])['status']);
$t->check('a zero byte in a password is just a wrong password', 401, $login($admin['email'], "x\u{0}y")['status']);
$t->sql('DELETE FROM `rate_limits`');

// Unknown addresses take as long as a wrong password (the password check always runs).
$timed = static function (callable $emailFor) use ($login): float {
    $times = [];
    foreach ([1, 2, 3] as $i) {
        $start = microtime(true);
        $login($emailFor($i), 'not-the-password');
        $times[] = microtime(true) - $start;
    }
    sort($times);
    return $times[1];
};
$knownTime = $timed(static fn (int $i): string => $custEmail);
$unknownTime = $timed(static fn (int $i): string => "nobody{$i}." . getmypid() . '@example.test');
$t->ok('an unknown email takes about as long as a known one', $unknownTime > $knownTime * 0.5, sprintf('unknown %.0f ms, known %.0f ms', $unknownTime * 1000, $knownTime * 1000));
$t->sql('DELETE FROM `rate_limits`');

// Fifty failures from one connection stop it, whichever addresses it tries.
for ($i = 1; $i <= 50; $i++) {
    $last = $login("not-an-address-{$i}", 'guess')['status'];
}
$t->check('the 50th failure is still an ordinary 401', 401, $last);
$t->check('the next attempt from that connection -> 429, even with the right password', 429, $login($admin['email'], $admin['password'])['status']);
$t->sql('DELETE FROM `rate_limits`');

// -----------------------------------------------------------------------------
$t->section('Rate limits');
// Three requests, as the sign-in tests above cleared the counters.
$t->http('POST', '/auth/customer/magic-link', ['json' => ['email' => $custEmail]]);
$t->http('POST', '/auth/customer/magic-link', ['json' => ['email' => $custEmail]]);
$t->http('POST', '/auth/customer/magic-link', ['json' => ['email' => $custEmail]]);
$t->check('4th magic link for one email within 15 minutes -> 429', 429, $t->http('POST', '/auth/customer/magic-link', ['json' => ['email' => $custEmail]])['status']);
for ($i = 1; $i <= 5; $i++) {
    $t->http('POST', '/auth/login', ['json' => ['email' => $admin['email'], 'password' => "wrong-{$i}"]]);
}
$t->check('6th login after 5 failures -> 429 (even with the right password)', 429, $t->http('POST', '/auth/login', ['json' => ['email' => $admin['email'], 'password' => $admin['password']]])['status']);

// -----------------------------------------------------------------------------
$t->section('Logout');
$t->check('admin logout 200', 200, $t->http('POST', '/auth/logout?scope=admin', ['jar' => $admin['jar'], 'csrf' => $admin['csrf']])['status']);
$t->check('admin session gone after logout', 401, $t->http('GET', '/auth/me?scope=admin', ['jar' => $admin['jar']])['status']);
$t->check('customer logout 200', 200, $t->http('POST', '/auth/logout', ['jar' => 'cust', 'csrf' => $csrf])['status']);
$t->check('customer session gone after logout', 401, $t->http('GET', '/auth/me', ['jar' => 'cust'])['status']);
$t->check('logged-out session row deleted', 0, (int) $t->scalar("SELECT COUNT(*) FROM `sessions` WHERE `id` = ?", 's', [hash('sha256', $raw)]));

// -----------------------------------------------------------------------------
// Query values sent as lists ("?search[]=x") were 500s (TypeError in error.log, 2026-11-06).
$t->section('Query parameters sent as lists');
$errorLog = BASE_PATH . '/storage/logs/error.log';
$logSize = is_file($errorLog) ? (int) filesize($errorLog) : 0;
foreach ([
    '/auth/me?scope[]=admin',
    '/auth/me?optional[]=1',
    '/products?search[]=shirt',
    '/products?category[]=1',
    '/products?sort[]=newest',
    '/products?min_price[]=1',
    '/products?page[]=2',
    '/products?colors[0][]=red',
    '/catalog/facets?category[x]=1',
    '/checkout/orders/ORD-NOPE?email[]=a@b.c',
] as $path) {
    $res = $t->http('GET', $path);
    $t->check("GET {$path} -> 400, not 500", [400, 'BAD_REQUEST'], [$res['status'], TestKit::get($res, 'error.code')]);
}
$t->check('POST /auth/logout?scope[]=admin -> 400', 400, $t->http('POST', '/auth/logout?scope[]=admin')['status']);
$colours = $t->http('GET', '/products?colors[]=black&colors[]=white&per_page=2');
$t->check('a flat colour list still filters (read as "black,white")', 200, $colours['status']);
$t->check('same result as the comma-separated form', TestKit::get($t->http('GET', '/products?colors=black,white&per_page=2'), 'meta.total'), TestKit::get($colours, 'meta.total'));
clearstatcache();
$t->check('nothing new in error.log from these requests', $logSize, is_file($errorLog) ? (int) filesize($errorLog) : 0);

exit($t->finish());
