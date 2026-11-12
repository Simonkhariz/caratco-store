<?php

declare(strict_types=1);

/**
 * Reporting, exports, email outbox endpoints and the audit log.
 *
 * Builds its own data in the test database: a paid order with a completed partial refund and a
 * paid-then-cancelled order (refund owed), then checks that the analytics report, the overview
 * and the CSV exports agree with the database.
 *
 *   php backend/tests/run.php Reporting
 */

require __DIR__ . '/support/TestKit.php';

$t = new TestKit('Reporting');
$admin = $t->adminSession('reporting');
$adminId = $admin['id'];
$A = static fn (string $method, string $path, array $opts = []): array
    => $t->http($method, $path, $opts + ['jar' => $admin['jar'], 'csrf' => $admin['csrf']]);

/** Place a guest order for one unit and return [order_number, grand_total, order_id, email, guest_token]. */
$placeOrder = static function () use ($t): ?array {
    $variant = $t->variant(2);
    if ($variant === null) {
        return null;
    }
    $email = $t->uniq('rep') . '@example.test';
    $res = $t->http('POST', '/checkout/orders', [
        'headers' => ['Idempotency-Key: ' . bin2hex(random_bytes(16))],
        'json' => [
            'email' => $email,
            'items' => [['product_id' => (int) $variant['product_id'], 'variant_id' => (int) $variant['variant_id'], 'quantity' => 1]],
            'shipping_address' => ['full_name' => 'Report Buyer', 'phone' => '08000000005', 'address_line1' => '5 Test Close', 'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria'],
        ],
    ]);
    if ($res['status'] !== 201) {
        return null;
    }
    $onum = (string) TestKit::get($res, 'data.order_number');
    $oid = (int) $t->scalar("SELECT `id` FROM `orders` WHERE `order_number` = ?", 's', [$onum]);
    $t->cleanup(function () use ($t, $oid, $email): void {
        foreach (['refunds', 'shipments', 'payments', 'order_status_history', 'order_items'] as $table) {
            $t->sql("DELETE FROM `{$table}` WHERE `order_id` = ?", 'i', [$oid]);
        }
        $t->sql("DELETE FROM `orders` WHERE `id` = ?", 'i', [$oid]);
        $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$email]);
    });
    return [$onum, (float) TestKit::get($res, 'data.grand_total'), $oid, $email, (string) TestKit::get($res, 'data.guest_access_token')];
};

// -----------------------------------------------------------------------------
$t->section('Audit');
$t->check('admin sign-in is audited', 1, (int) $t->scalar("SELECT COUNT(*) FROM `admin_audit_logs` WHERE `admin_id` = ? AND `action` = 'admin.login'", 'i', [$adminId]));
$t->check('no upper-case legacy action names', 0, (int) $t->scalar("SELECT COUNT(*) FROM `admin_audit_logs` WHERE BINARY `action` <> BINARY LOWER(`action`)"));

// -----------------------------------------------------------------------------
$t->section('Test data: paid order with partial refund, paid-then-cancelled order');
$paid = $placeOrder();
$cancelled = $placeOrder();
$t->ok('orders placed', $paid !== null && $cancelled !== null);
if ($paid !== null && $cancelled !== null) {
    [$paidNum, $paidTotal] = $paid;
    [$cancNum] = $cancelled;
    $t->check('paid order confirmed', 200, $A('POST', "/admin/orders/{$paidNum}/confirm-payment", ['json' => ['reference' => 'BANK-' . bin2hex(random_bytes(4))]])['status']);
    $t->check('partial refund completed', 201, $A('POST', '/admin/refunds', ['json' => ['order_number' => $paidNum, 'amount' => round($paidTotal / 4, 2), 'reason' => 'Report test', 'status' => 'completed', 'restock' => false]])['status']);
    $t->check('second order confirmed', 200, $A('POST', "/admin/orders/{$cancNum}/confirm-payment", ['json' => ['reference' => 'BANK-' . bin2hex(random_bytes(4))]])['status']);
    $t->check('second order cancelled after payment', 200, $A('PATCH', "/admin/orders/{$cancNum}/status", ['json' => ['status' => 'cancelled', 'comment' => 'Report test']])['status']);
}

// -----------------------------------------------------------------------------
$t->section('Analytics (all time) agrees with the database');
$report = $A('GET', '/admin/analytics/report?range=all');
$s = (array) TestKit::get($report, 'data.summary');
$dbGross = (float) $t->scalar("SELECT COALESCE(SUM(COALESCE(`amount_received`, `amount`)), 0) FROM `payments` WHERE `status` IN ('successful', 'partially_refunded', 'refunded') AND `verified_at` IS NOT NULL");
$dbRefunds = (float) $t->scalar("SELECT COALESCE(SUM(`amount`), 0) FROM `refunds` WHERE `status` = 'completed'");
$t->ok('there is revenue to check', $dbGross > 0);
$t->check('gross sales = verified payments', round($dbGross, 2), round((float) ($s['gross_sales'] ?? -1), 2));
$t->check('refunds = completed refunds', round($dbRefunds, 2), round((float) ($s['total_refunded'] ?? -1), 2));
$t->ok('net = gross − refunded − owed', abs((float) ($s['net_sales'] ?? 0) - max(0, (float) ($s['gross_sales'] ?? 0) - (float) ($s['total_refunded'] ?? 0) - (float) ($s['owed_refunds'] ?? 0))) < 0.01);
$data = (array) TestKit::get($report, 'data');
$t->ok('daily sales add up to gross', abs(array_sum(array_column($data['daily_breakdown'] ?? [], 'sales')) - (float) ($s['gross_sales'] ?? 0)) < 0.01);
$t->ok('daily refunds add up to refunded', abs(array_sum(array_column($data['daily_breakdown'] ?? [], 'refunds')) - (float) ($s['total_refunded'] ?? 0)) < 0.01);
$t->ok('gateway volumes add up to gross', abs(array_sum(array_column($data['gateway_breakdown'] ?? [], 'total_volume')) - (float) ($s['gross_sales'] ?? 0)) < 0.01);
$owedNumbers = array_column($data['owed_refunds'] ?? [], 'order_number');
if ($cancelled !== null) {
    $t->ok('cancelled-after-payment order is listed as owed', in_array($cancelled[0], $owedNumbers, true));
}
$t->ok('owed list total matches summary', abs(array_sum(array_map('floatval', array_column($data['owed_refunds'] ?? [], 'owed'))) - (float) ($s['owed_refunds'] ?? 0)) < 0.01);
$t->check('7-day range has exactly 7 days', 7, count((array) TestKit::get($A('GET', '/admin/analytics/report?range=7days'), 'data.daily_breakdown')));
$t->check('custom range includes both end dates', 3, count((array) TestKit::get($A('GET', '/admin/analytics/report?start_date=2026-10-01&end_date=2026-10-03'), 'data.daily_breakdown')));
$t->check('bad custom date refused', 422, $A('GET', '/admin/analytics/report?start_date=2026-13-01&end_date=2026-10-03')['status']);
$t->check('start after end refused', 422, $A('GET', '/admin/analytics/report?start_date=2026-10-05&end_date=2026-10-03')['status']);
$overviewGross = (float) TestKit::get($A('GET', '/admin/dashboard/overview?period=30'), 'data.kpis.gross_sales');
$reportGross = (float) TestKit::get($A('GET', '/admin/analytics/report?range=30days'), 'data.summary.gross_sales');
$t->ok('overview and report agree on 30-day gross', abs($overviewGross - $reportGross) < 0.01, "overview {$overviewGross} vs report {$reportGross}");

// -----------------------------------------------------------------------------
$t->section('Net sales mean the same on the overview and in the report');
$overviewKpis = (array) TestKit::get($A('GET', '/admin/dashboard/overview?period=30'), 'data.kpis');
$report30 = (array) TestKit::get($A('GET', '/admin/analytics/report?range=30days'), 'data.summary');
$t->ok('a paid order was cancelled with no refund', (float) ($report30['owed_refunds'] ?? 0) > 0);
$t->check('the overview subtracts refunds owed too', round((float) $report30['net_sales'], 2), round((float) ($overviewKpis['net_sales'] ?? -1), 2));
$t->check('and shows how much is owed', round((float) $report30['owed_refunds'], 2), round((float) ($overviewKpis['owed_refunds'] ?? -1), 2));

// -----------------------------------------------------------------------------
$t->section('Refunds show on the sales chart, and waiting ones are listed apart');
$trend = (array) TestKit::get($A('GET', '/admin/dashboard/overview?period=30'), 'data.trend');
$t->ok('every day of the chart carries a refunds figure', $trend !== [] && !in_array(false, array_map(static fn (array $day): bool => array_key_exists('refunds', $day), $trend), true));
$t->ok('the chart\'s refunds add up to the refunded total', abs(array_sum(array_map('floatval', array_column($trend, 'refunds'))) - (float) ($overviewKpis['total_refunded'] ?? -1)) < 0.01);
$t->ok('today\'s bar shows the refund completed today', (float) (end($trend)['refunds'] ?? 0) > 0);
$pendingBefore = (float) ($overviewKpis['pending_refunds'] ?? -1);
if ($paidNum !== null) {
    $waiting = $A('POST', '/admin/refunds', ['json' => ['order_number' => $paidNum, 'amount' => 50, 'reason' => 'Waiting refund', 'status' => 'pending', 'restock' => false]]);
    $t->check('a refund recorded as pending', 201, $waiting['status']);
    $after = (array) TestKit::get($A('GET', '/admin/dashboard/overview?period=30'), 'data.kpis');
    $t->check('is shown as pending on the overview', round($pendingBefore + 50, 2), round((float) ($after['pending_refunds'] ?? -1), 2));
    $t->check('and does not come off sales until it is completed', [$overviewKpis['net_sales'], $overviewKpis['total_refunded']], [$after['net_sales'], $after['total_refunded']]);
    $t->check('the report shows the same pending amount', round((float) $after['pending_refunds'], 2), round((float) TestKit::get($A('GET', '/admin/analytics/report?range=30days'), 'data.summary.pending_refunds'), 2));
    $done = $A('PATCH', '/admin/refunds/' . (int) TestKit::get($waiting, 'data.id'), ['json' => ['status' => 'completed']]);
    $t->check('once completed', 200, $done['status']);
    $final = (array) TestKit::get($A('GET', '/admin/dashboard/overview?period=30'), 'data');
    $t->check('it leaves pending and joins refunded', [round($pendingBefore, 2), round((float) $overviewKpis['total_refunded'] + 50, 2)], [round((float) $final['kpis']['pending_refunds'], 2), round((float) $final['kpis']['total_refunded'], 2)]);
    $t->ok('and the chart shows it on its day', abs(array_sum(array_map('floatval', array_column($final['trend'], 'refunds'))) - (float) $final['kpis']['total_refunded']) < 0.01);
}

// -----------------------------------------------------------------------------
$t->section('Product and category sales leave out cancelled and refunded orders');
if ($cancelled !== null) {
    $paidLines = "FROM `order_items` oi JOIN `orders` o ON o.id = oi.order_id
                  WHERE o.order_status NOT IN ('cancelled', 'refunded')
                    AND EXISTS (SELECT 1 FROM `payments` p WHERE p.order_id = o.id AND p.status IN ('successful', 'partially_refunded', 'refunded') AND p.verified_at IS NOT NULL)";
    $cancelledProduct = (int) $t->scalar("SELECT `product_id` FROM `order_items` WHERE `order_id` = ?", 'i', [$cancelled[2]]);
    $expectedProduct = (float) $t->scalar("SELECT COALESCE(SUM(oi.line_total), 0) {$paidLines} AND oi.product_id = ?", 'i', [$cancelledProduct]);
    $productRow = array_values(array_filter((array) ($data['top_products'] ?? []), static fn (array $p): bool => (int) $p['product_id'] === $cancelledProduct))[0] ?? [];
    $t->check('report: product sales exclude the cancelled order', round($expectedProduct, 2), round((float) ($productRow['revenue'] ?? -1), 2));
    $t->check('report: category sales add up to the same orders', round((float) $t->scalar("SELECT COALESCE(SUM(oi.line_total), 0) {$paidLines}"), 2), round(array_sum(array_map('floatval', array_column((array) ($data['category_breakdown'] ?? []), 'revenue'))), 2));
    $overviewTop = array_values(array_filter((array) TestKit::get($A('GET', '/admin/dashboard/overview?period=30'), 'data.top_products'), static fn (array $p): bool => (int) $p['id'] === $cancelledProduct))[0] ?? [];
    $t->check('overview: product sales exclude the cancelled order', round($expectedProduct, 2), round((float) ($overviewTop['total_revenue'] ?? -1), 2));
    $t->ok('the report says these are before discounts', str_contains((string) ($data['formulas']['category_breakdown'] ?? ''), 'before discounts') && str_contains((string) ($data['formulas']['top_products'] ?? ''), 'before discounts'));
    $t->ok('and so does the CSV', str_contains($A('GET', '/admin/analytics/export?range=all')['body'], 'Item sales before discounts (NGN)'));
}

// -----------------------------------------------------------------------------
$t->section('CSV exports');
$csv = $A('GET', '/admin/analytics/export?range=all');
$t->check('analytics CSV downloads', 'text/csv; charset=UTF-8', $csv['headers']['content-type'] ?? null);
$t->ok('analytics CSV has the net sales line', (bool) preg_match('/^"Net sales \(NGN\)",/m', $csv['body']));
$countRows = static function (string $body): int {
    $f = fopen('php://memory', 'r+');
    fwrite($f, $body);
    rewind($f);
    $n = -1;
    while (fgetcsv($f) !== false) {
        $n++;
    }
    fclose($f);
    return $n;
};
$orders = $A('GET', '/admin/orders/export');
$t->check('orders CSV downloads', 200, $orders['status']);
$t->check('orders CSV defaults to all dates', (int) $t->scalar("SELECT COUNT(*) FROM `orders`"), $countRows($orders['body']));
$t->check('orders CSV honours status=attention', (int) $t->scalar("SELECT COUNT(*) FROM `orders` WHERE `requires_attention` = 1"), $countRows($A('GET', '/admin/orders/export?status=attention')['body']));
if ($paid !== null) {
    $t->check('orders CSV honours search', 1, $countRows($A('GET', '/admin/orders/export?search=' . urlencode($paid[0]))['body']));
}
// The order list's special filters ("Customer says paid", "Awaiting WhatsApp payment") and its
// tracking-number search export the same orders the list shows.
$waOrder = $placeOrder();
if ($waOrder !== null) {
    [$waNum, , , , $waToken] = $waOrder;
    $waAccess = ['headers' => ["X-Guest-Access-Token: {$waToken}"]];
    $t->check('WhatsApp payment opened for the export test', 'whatsapp', TestKit::get(
        $t->http('POST', "/checkout/orders/{$waNum}/initialize-payment", $waAccess + ['json' => ['gateway' => 'whatsapp']]), 'data.provider'
    ));
    $waRows = static fn (string $status): string => $A('GET', '/admin/orders/export?status=' . $status)['body'];
    $t->ok('awaiting-WhatsApp export lists the order', str_contains($waRows('awaiting_whatsapp'), $waNum));
    $t->ok('customer-says-paid export does not list it before the customer marks it paid', !str_contains($waRows('payment_claimed'), $waNum));
    $t->check('customer marks the transfer as paid', 200, $t->http('POST', "/checkout/orders/{$waNum}/payment-sent", $waAccess + ['json' => []])['status']);
    $t->ok('customer-says-paid export lists it', str_contains($waRows('payment_claimed'), $waNum));
    $t->check('customer-says-paid export matches the database', (int) $t->scalar(
        "SELECT COUNT(*) FROM `orders` WHERE `order_status` = 'pending_payment' AND `customer_marked_paid_at` IS NOT NULL"
    ), $countRows($waRows('payment_claimed')));
}
if ($paid !== null) {
    $tracking = 'TRK' . strtoupper(bin2hex(random_bytes(5)));
    $t->check('paid order shipped with a tracking number', 200, $A('POST', "/admin/orders/{$paid[0]}/shipment", ['json' => ['carrier' => 'GIG Logistics', 'tracking_number' => $tracking, 'status' => 'in_transit']])['status']);
    $byTracking = $A('GET', '/admin/orders/export?search=' . $tracking)['body'];
    $t->ok('orders CSV search finds the order by its tracking number', $countRows($byTracking) === 1 && str_contains($byTracking, $paid[0]));
}
$t->check('exports need an admin session', 401, $t->http('GET', '/admin/orders/export')['status']);

// -----------------------------------------------------------------------------
$t->section('Outbox');
$t->check('process endpoint answers', true, TestKit::get($A('POST', '/admin/notifications/outbox/process'), 'success'));
$t->ok('outbox list shows transport', array_key_exists('transport', (array) TestKit::get($A('GET', '/admin/notifications/outbox?per_page=1'), 'data.items.0')));
$cust = $t->uniq('magic') . '@example.test';
$t->cleanup(function () use ($t, $cust): void {
    $t->sql("DELETE FROM `email_verification_tokens` WHERE `email` = ?", 's', [$cust]);
    $t->sql("DELETE FROM `email_outbox` WHERE `recipient_email` = ?", 's', [$cust]);
    $t->sql("DELETE FROM `users` WHERE `email` = ?", 's', [$cust]);
});
$t->http('POST', '/auth/customer/magic-link', ['json' => ['email' => $cust]]);
$magic = $t->scalar("SELECT `id` FROM `email_outbox` WHERE `recipient_email` = ? AND `template` = 'magic_link' ORDER BY `id` DESC LIMIT 1", 's', [$cust]);
$t->ok('sign-in email recorded in the outbox', $magic !== null);
if ($magic !== null) {
    $t->check('sign-in link cannot be re-sent', 422, $A('POST', "/admin/notifications/outbox/{$magic}/retry")['status']);
    $t->ok('sign-in token never stored', !str_contains((string) $t->scalar("SELECT `payload` FROM `email_outbox` WHERE `id` = ?", 'i', [(int) $magic]), 'token='));
}
$t->check('legacy broadcasts never pending', 0, (int) $t->scalar("SELECT COUNT(*) FROM `email_outbox` WHERE `template` = 'marketing_broadcast' AND `status` = 'pending' AND JSON_EXTRACT(`payload`, '$.unsubscribe_url') IS NULL"));
$t->ok('system status reports oldest queued email', array_key_exists('oldest_due_minutes', (array) TestKit::get($A('GET', '/admin/system-status'), 'data.mail')));

// -----------------------------------------------------------------------------
$t->section('Underpaid money sent back is in no sales figure');
$under = $placeOrder();
if ($under !== null) {
    $underRef = 'REP-UNDER-' . bin2hex(random_bytes(4));
    $t->sql("INSERT INTO `payments` (`order_id`, `provider`, `provider_reference`, `amount`, `currency`, `status`) VALUES (?, 'kora', ?, ?, 'NGN', 'pending')", 'isd', [$under[2], $underRef, $under[1]]);
    (new App\Services\PaymentFinalizer($t->db))->finalizeSuccess($underRef, ['amount' => 100, 'currency' => 'NGN', 'label' => 'Kora']);
    $beforeUnder = (array) TestKit::get($A('GET', '/admin/analytics/report?range=30days'), 'data.summary');
    $t->check('the refund is recorded', 201, $A('POST', '/admin/refunds', ['json' => ['order_number' => $under[0], 'amount' => 100, 'reason' => 'Underpaid', 'status' => 'completed', 'restock' => false]])['status']);
    $afterUnder = (array) TestKit::get($A('GET', '/admin/analytics/report?range=30days'), 'data.summary');
    $t->check('net sales and refunds are unchanged', [$beforeUnder['net_sales'], $beforeUnder['total_refunded']], [$afterUnder['net_sales'], $afterUnder['total_refunded']]);
    $t->check('the overview agrees', round((float) $afterUnder['net_sales'], 2), round((float) TestKit::get($A('GET', '/admin/dashboard/overview?period=30'), 'data.kpis.net_sales'), 2));
}

// -----------------------------------------------------------------------------
$t->section('An order is found by phone number however it is typed');
$phoneOrder = $placeOrder();
if ($phoneOrder !== null) {
    $savedPhone = (string) (json_decode((string) $t->scalar('SELECT `shipping_address_snapshot` FROM `orders` WHERE `order_number` = ?', 's', [$phoneOrder[0]]), true)['phone'] ?? '');
    $t->ok('it was saved as +234', str_starts_with($savedPhone, '+234'), $savedPhone);
    $core = substr($savedPhone, 4);
    foreach (['0' . $core, '0' . substr($core, 0, 3) . ' ' . substr($core, 3, 3) . ' ' . substr($core, 6), '+234' . $core, '234 ' . $core, '+234 0' . $core] as $typed) {
        $found = array_column((array) TestKit::get($A('GET', '/admin/orders?per_page=100&search=' . urlencode($typed)), 'data.items'), 'order_number');
        $t->ok("found by '{$typed}'", in_array($phoneOrder[0], $found, true));
    }
    $t->check('a search that is not a phone number still works', $phoneOrder[0], TestKit::get($A('GET', '/admin/orders?search=' . urlencode($phoneOrder[0])), 'data.items.0.order_number'));
    $t->ok('and the CSV export finds it too', str_contains((string) $A('GET', '/admin/orders/export?search=' . urlencode('0' . $core))['body'], $phoneOrder[0]));
}

// -----------------------------------------------------------------------------
$t->section('Sending back an overpayment leaves the sale whole');
$over = $placeOrder();
if ($over !== null) {
    $overRef = 'REP-OVER-' . bin2hex(random_bytes(4));
    $netNow = static fn (): float => (float) TestKit::get($A('GET', '/admin/analytics/report?range=30days'), 'data.summary.net_sales');
    $beforeOver = $netNow();
    $t->sql("INSERT INTO `payments` (`order_id`, `provider`, `provider_reference`, `amount`, `currency`, `status`) VALUES (?, 'kora', ?, ?, 'NGN', 'pending')", 'isd', [$over[2], $overRef, $over[1]]);
    (new App\Services\PaymentFinalizer($t->db))->finalizeSuccess($overRef, ['amount' => (float) $over[1] + 2000, 'currency' => 'NGN', 'label' => 'Kora']);
    $t->ok('all the money received counts until the extra goes back', abs($netNow() - $beforeOver - ((float) $over[1] + 2000)) < 0.01);
    $t->check('the extra is refunded', 201, $A('POST', '/admin/refunds', ['json' => ['order_number' => $over[0], 'amount' => 2000, 'reason' => 'Overpaid', 'status' => 'completed', 'restock' => false]])['status']);
    $t->ok('net sales grew by the order total, no more and no less', abs($netNow() - $beforeOver - (float) $over[1]) < 0.01);
    $t->check('the overview agrees', round($netNow(), 2), round((float) TestKit::get($A('GET', '/admin/dashboard/overview?period=30'), 'data.kpis.net_sales'), 2));
}

// -----------------------------------------------------------------------------
$t->section('Sign-out is audited');
$A('POST', '/auth/logout?scope=admin');
$t->check('admin sign-out is audited', 1, (int) $t->scalar("SELECT COUNT(*) FROM `admin_audit_logs` WHERE `admin_id` = ? AND `action` = 'admin.logout'", 'i', [$adminId]));

exit($t->finish());
