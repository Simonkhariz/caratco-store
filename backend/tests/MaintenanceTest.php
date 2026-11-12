<?php

declare(strict_types=1);

/**
 * Database clean-up (admin → Data & storage): only data the store no longer needs is removed;
 * paid orders and anything waiting for the admin are kept.
 *
 *   php backend/tests/run.php Maintenance
 */

require __DIR__ . '/support/TestKit.php';

use App\Services\DatabaseCleanup;
use App\Services\FulfillmentService;

$t = new TestKit('Maintenance');

/** Place a guest order for one unit and return its id. */
$placeOrder = static function () use ($t): ?int {
    $variant = $t->variant(2);
    if ($variant === null) {
        return null;
    }
    $res = $t->http('POST', '/checkout/orders', [
        'headers' => ['Idempotency-Key: ' . bin2hex(random_bytes(16))],
        'json' => [
            'email' => $t->uniq('clean') . '@example.test',
            'items' => [['product_id' => (int) $variant['product_id'], 'variant_id' => (int) $variant['variant_id'], 'quantity' => 1]],
            'shipping_address' => ['full_name' => 'Clean Buyer', 'phone' => '08000000006', 'address_line1' => '6 Test Way', 'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria'],
        ],
    ]);
    if ($res['status'] !== 201) {
        return null;
    }
    $oid = (int) $t->scalar("SELECT `id` FROM `orders` WHERE `order_number` = ?", 's', [(string) TestKit::get($res, 'data.order_number')]);
    $t->cleanup(function () use ($t, $oid): void {
        foreach (['refunds', 'shipments', 'payments', 'order_status_history', 'order_items'] as $table) {
            $t->sql("DELETE FROM `{$table}` WHERE `order_id` = ?", 'i', [$oid]);
        }
        $t->sql("DELETE FROM `orders` WHERE `id` = ?", 'i', [$oid]);
    });
    return $oid;
};

$exists = static fn (int $id): bool => (int) $t->scalar("SELECT COUNT(*) FROM `orders` WHERE `id` = ?", 'i', [$id]) === 1;

// -----------------------------------------------------------------------------
$t->section('Test data');
$abandoned = $placeOrder();   // cancelled 40 days ago, never paid      -> removed
$latePaid = $placeOrder();    // cancelled 40 days ago, but money came in -> kept
$recent = $placeOrder();      // cancelled 5 days ago, never paid       -> kept (inside 30 days)
$flagged = $placeOrder();     // cancelled 40 days ago, admin must look  -> kept
$pending = $placeOrder();     // still awaiting payment                  -> kept
$t->ok('five test orders placed', !in_array(null, [$abandoned, $latePaid, $recent, $flagged, $pending], true));

foreach ([$abandoned => 40, $latePaid => 40, $recent => 5, $flagged => 40] as $id => $days) {
    // Give the reserved unit back, as a real cancellation does, so later suites keep their stock.
    $t->sql(
        "UPDATE `product_variants` v JOIN `order_items` oi ON oi.variant_id = v.id
         SET v.stock_quantity = v.stock_quantity + oi.quantity WHERE oi.order_id = ?",
        'i',
        [$id]
    );
    $t->sql("UPDATE `orders` SET `order_status` = 'cancelled', `expires_at` = NULL, `updated_at` = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE `id` = ?", 'ii', [$days, $id]);
}
$t->sql("UPDATE `orders` SET `requires_attention` = 1, `attention_reason` = 'test' WHERE `id` = ?", 'i', [$flagged]);
$t->sql(
    "INSERT INTO `payments` (`order_id`, `provider`, `provider_reference`, `amount`, `currency`, `status`, `verified_at`)
     SELECT `id`, 'kora', ?, `grand_total`, 'NGN', 'successful', NOW() FROM `orders` WHERE `id` = ?",
    'si',
    ['CLEAN-' . bin2hex(random_bytes(4)), $latePaid]
);
// The abandoned order had a checkout that was opened but never paid.
$t->sql("INSERT INTO `payments` (`order_id`, `provider`, `provider_reference`, `amount`, `currency`, `status`) SELECT `id`, 'monnify', ?, `grand_total`, 'NGN', 'cancelled' FROM `orders` WHERE `id` = ?", 'si', ['CLEAN-' . bin2hex(random_bytes(4)), $abandoned]);

// The customer said they sent the transfer, then the order expired: the money may be in the bank.
$claimed = $placeOrder();
$t->sql("UPDATE `orders` SET `customer_marked_paid_at` = NOW(), `expires_at` = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE `id` = ?", 'i', [$claimed]);
(new FulfillmentService($t->db))->expireOrder($claimed);
$t->sql("UPDATE `orders` SET `updated_at` = DATE_SUB(NOW(), INTERVAL 40 DAY) WHERE `id` = ?", 'i', [$claimed]);
// Part of the money came in on a closed attempt, and the flag was dealt with.
$partPaid = $placeOrder();
$t->sql(
    "UPDATE `product_variants` v JOIN `order_items` oi ON oi.variant_id = v.id
     SET v.stock_quantity = v.stock_quantity + oi.quantity WHERE oi.order_id = ?",
    'i',
    [$partPaid]
);
$t->sql("UPDATE `orders` SET `order_status` = 'cancelled', `expires_at` = NULL, `updated_at` = DATE_SUB(NOW(), INTERVAL 40 DAY) WHERE `id` = ?", 'i', [$partPaid]);
$t->sql("INSERT INTO `payments` (`order_id`, `provider`, `provider_reference`, `amount`, `amount_received`, `currency`, `status`) SELECT `id`, 'kora', ?, `grand_total`, 100, 'NGN', 'cancelled' FROM `orders` WHERE `id` = ?", 'si', ['CLEAN-' . bin2hex(random_bytes(4)), $partPaid]);

$oldToken = (int) $t->db->insert(
    "INSERT INTO `email_verification_tokens` (`email`, `token_hash`, `type`, `expires_at`, `used_at`)
     VALUES (?, ?, 'magic_link', DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY))",
    'ss',
    [$t->uniq('token') . '@example.test', hash('sha256', bin2hex(random_bytes(16)))]
);

// -----------------------------------------------------------------------------
$t->section('Review');
$asOf = date('Y-m-d H:i:s');
$snapshot = (new DatabaseCleanup($t->db))->snapshot($asOf);
$t->ok('review lists the abandoned order', in_array((string) $abandoned, $snapshot['ids']['unpaid_cancelled_orders'] ?? [], true), json_encode($snapshot['counts']));
$t->ok('reviewing removes nothing', $exists($abandoned));
$t->ok('an expired claim to have paid is not listed as never paid', !in_array((string) $claimed, $snapshot['ids']['unpaid_cancelled_orders'] ?? [], true));
$t->ok('an order that received part of the money is not listed either', !in_array((string) $partPaid, $snapshot['ids']['unpaid_cancelled_orders'] ?? [], true));

// -----------------------------------------------------------------------------
$t->section('Clean-up');
$removed = (new DatabaseCleanup($t->db))->execute($asOf, $snapshot['fingerprint']);
$t->ok('never-paid order cancelled over 30 days ago is removed', !$exists($abandoned), json_encode($removed));
$t->check('its items and closed payment attempts go with it', 0, (int) $t->scalar(
    "SELECT (SELECT COUNT(*) FROM `order_items` WHERE `order_id` = ?) + (SELECT COUNT(*) FROM `payments` WHERE `order_id` = ?)",
    'ii',
    [$abandoned, $abandoned]
));
$t->ok('cancelled order that received money is kept', $exists($latePaid));
$t->ok('recently cancelled order is kept', $exists($recent));
$t->ok('order flagged for the admin is kept', $exists($flagged));
$t->ok('order still awaiting payment is kept', $exists($pending));
$t->ok('the expired claim is kept', $exists($claimed));
$t->ok('the part-paid order is kept', $exists($partPaid));
(new FulfillmentService($t->db))->resolveAttention($claimed, 'Checked the bank: nothing arrived', null);
$t->sql("UPDATE `orders` SET `updated_at` = DATE_SUB(NOW(), INTERVAL 40 DAY) WHERE `id` = ?", 'i', [$claimed]);
$t->ok('once the admin resolves the claim it can be reviewed for clean-up', in_array((string) $claimed, (new DatabaseCleanup($t->db))->snapshot(date('Y-m-d H:i:s'))['ids']['unpaid_cancelled_orders'] ?? [], true));
$t->check('used sign-in link older than a day is removed', 0, (int) $t->scalar("SELECT COUNT(*) FROM `email_verification_tokens` WHERE `id` = ?", 'i', [$oldToken]));

exit($t->finish());
