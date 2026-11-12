<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use InvalidArgumentException;

/**
 * The single place where a payment attempt becomes successful or failed.
 *
 * Every confirmation path (Kora webhook, Kora verify, Monnify webhook, Monnify verify,
 * admin manual confirmation) goes through here so the same rules always apply:
 *  - the payment and order rows are locked FOR UPDATE, so concurrent webhooks/polls serialize;
 *  - it is idempotent: a payment that is already successful is never processed twice,
 *    whatever event key or code path delivers it again;
 *  - amount and currency must match what the order was charged;
 *  - an order only ever moves pending_payment -> paid, never backwards;
 *  - money that arrives for a cancelled/expired order, or a second successful payment for an
 *    already-paid order, is recorded and flagged for the admin instead of changing the order;
 *  - customer emails are sent only after the transaction commits.
 */
class PaymentFinalizer
{
    public const OUTCOME_PAID = 'paid';
    public const OUTCOME_ALREADY_PROCESSED = 'already_processed';
    public const OUTCOME_LATE_PAYMENT = 'late_payment';
    public const OUTCOME_DUPLICATE_PAYMENT = 'duplicate_payment';
    public const OUTCOME_UNDERPAID = 'underpaid';
    public const OUTCOME_REJECTED = 'rejected';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_IGNORED = 'ignored';
    public const OUTCOME_UNMATCHED = 'unmatched';

    private const AMOUNT_TOLERANCE = 0.01;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Apply a provider-confirmed successful payment.
     *
     * @param array{amount: float|int|string, currency: string, method?: ?string, payload?: array, label: string, confirmed_by?: ?int} $receipt
     * @return array<string, mixed>
     */
    public function finalizeSuccess(string $providerReference, array $receipt): array
    {
        $label = $receipt['label'] ?? 'Payment gateway';

        return $this->db->transaction(function (Database $db) use ($providerReference, $receipt, $label): array {
            $payment = $db->fetchOne(
                "SELECT * FROM `payments` WHERE `provider_reference` = ? FOR UPDATE",
                's',
                [$providerReference]
            );
            if ($payment === null) {
                return ['outcome' => self::OUTCOME_UNMATCHED, 'provider_reference' => $providerReference];
            }

            $order = $db->fetchOne("SELECT * FROM `orders` WHERE `id` = ? FOR UPDATE", 'i', [(int) $payment['order_id']]);

            if (in_array($payment['status'], ['successful', 'partially_refunded', 'refunded'], true)) {
                return $this->result(self::OUTCOME_ALREADY_PROCESSED, $order, $payment);
            }

            $expectedAmount = (float) $payment['amount'];
            $expectedCurrency = strtoupper((string) ($payment['currency'] ?: $order['currency']));
            $receivedAmount = round((float) $receipt['amount'], 2);
            $receivedCurrency = strtoupper(trim((string) ($receipt['currency'] ?? '')));

            if ($receivedCurrency === '' || $receivedCurrency !== $expectedCurrency) {
                $this->flagAttention($db, (int) $order['id'], sprintf(
                    '%s payment %s reported currency "%s" but the order is in %s. Not applied; verify with the gateway.',
                    $label,
                    $providerReference,
                    $receivedCurrency ?: 'missing',
                    $expectedCurrency
                ));
                return $this->result(self::OUTCOME_REJECTED, $order, $payment, 'Currency mismatch');
            }

            if ($receivedAmount + self::AMOUNT_TOLERANCE < $expectedAmount) {
                $db->executeUpdate(
                    "UPDATE `payments` SET `amount_received` = ?, `updated_at` = NOW() WHERE `id` = ?",
                    'di',
                    [$receivedAmount, (int) $payment['id']]
                );
                $this->flagAttention($db, (int) $order['id'], sprintf(
                    'Underpaid: %s payment %s received %s %s of %s. Order not marked paid; contact the customer or refund.',
                    $label,
                    $providerReference,
                    $expectedCurrency,
                    number_format($receivedAmount, 2),
                    number_format($expectedAmount, 2)
                ));
                return $this->result(self::OUTCOME_UNDERPAID, $order, $payment, 'Amount received is less than the order total');
            }

            if ($receivedAmount > $expectedAmount + self::AMOUNT_TOLERANCE) {
                $this->flagAttention($db, (int) $order['id'], sprintf(
                    'Overpaid by %s %s on %s payment %s. Refund the difference.',
                    $expectedCurrency,
                    number_format($receivedAmount - $expectedAmount, 2),
                    $label,
                    $providerReference
                ));
            }

            $method = trim((string) ($receipt['method'] ?? '')) ?: null;
            $payloadJson = isset($receipt['payload']) ? json_encode($receipt['payload']) : null;

            $db->executeUpdate(
                "UPDATE `payments`
                 SET `status` = 'successful', `amount_received` = ?, `payment_method` = COALESCE(?, `payment_method`),
                     `provider_payload_redacted` = COALESCE(?, `provider_payload_redacted`), `verified_at` = NOW(), `updated_at` = NOW()
                 WHERE `id` = ?",
                'dssi',
                [$receivedAmount, $method, $payloadJson, (int) $payment['id']]
            );
            $payment['status'] = 'successful';
            $payment['verified_at'] = date('Y-m-d H:i:s');

            $orderId = (int) $order['id'];
            $confirmedBy = isset($receipt['confirmed_by']) ? (int) $receipt['confirmed_by'] : null;

            if ($order['order_status'] === 'pending_payment') {
                $db->executeUpdate(
                    "UPDATE `orders` SET `order_status` = 'paid', `payment_status` = 'successful', `expires_at` = NULL, `updated_at` = NOW() WHERE `id` = ?",
                    'i',
                    [$orderId]
                );
                ProductSales::refreshForOrder($db, $orderId);
                $db->insert(
                    "INSERT INTO `order_status_history` (`order_id`, `previous_status`, `new_status`, `comment`, `changed_by_user_id`)
                     VALUES (?, 'pending_payment', 'paid', ?, ?)",
                    'isi',
                    [$orderId, "Payment confirmed via {$label}. Reference: {$providerReference}", $confirmedBy]
                );

                // Other attempts the customer opened but never paid (e.g. Kora opened, then paid
                // with Monnify) are closed, so they no longer show as pending. Money that still
                // arrives on one of them is recorded and flagged as a duplicate payment below.
                $db->executeUpdate(
                    "UPDATE `payments` SET `status` = 'cancelled', `updated_at` = NOW()
                     WHERE `order_id` = ? AND `status` = 'pending' AND `id` <> ?",
                    'ii',
                    [$orderId, (int) $payment['id']]
                );

                $order['order_status'] = 'paid';
                $order['payment_status'] = 'successful';

                if (!empty($order['user_id'])) {
                    $userId = (int) $order['user_id'];
                    $db->afterCommit(function () use ($orderId, $userId): void {
                        $this->removePaidItemsFromSavedCart($orderId, $userId);
                    });
                }

                // Queued inside this transaction (just before it commits): a paid order always has
                // its receipt in the outbox, and a rolled-back payment never does.
                $mailOrder = $order;
                $db->beforeCommit(static function () use ($mailOrder, $providerReference, $label, $method): void {
                    MailService::getInstance()->sendPaymentVerified($mailOrder, [
                        'reference' => $providerReference,
                        'payment_method' => trim($label . ' ' . ($method ?? '')),
                    ]);
                    MailService::getInstance()->sendPaymentReceivedToAdmins((string) $mailOrder['order_number'], trim($label . ' ' . ($method ?? '')), (string) $providerReference);
                });

                return $this->result(self::OUTCOME_PAID, $order, $payment);
            }

            if ($order['order_status'] === 'cancelled') {
                $db->executeUpdate(
                    "UPDATE `orders` SET `payment_status` = 'successful', `updated_at` = NOW() WHERE `id` = ?",
                    'i',
                    [$orderId]
                );
                $order['payment_status'] = 'successful';
                $this->flagAttention($db, $orderId, sprintf(
                    'Payment received after the order was cancelled/expired (%s %s). Its stock was already released and a cancelled order cannot be reopened: refund the customer, who can place a new order for the items.',
                    $label,
                    $providerReference
                ));
                return $this->result(self::OUTCOME_LATE_PAYMENT, $order, $payment);
            }

            // Order already paid (or further along) through another payment attempt.
            $this->flagAttention($db, $orderId, sprintf(
                'Duplicate payment: %s %s succeeded although the order was already %s. Refund this second payment.',
                $label,
                $providerReference,
                $order['order_status']
            ));
            return $this->result(self::OUTCOME_DUPLICATE_PAYMENT, $order, $payment);
        });
    }

    /**
     * Record a provider-confirmed failed/abandoned payment attempt. The order stays
     * pending (until its payment window expires) so the customer can retry.
     *
     * @return array<string, mixed>
     */
    public function finalizeFailure(string $providerReference, string $reason, array $payload = [], string $label = 'Payment gateway'): array
    {
        return $this->db->transaction(function (Database $db) use ($providerReference, $reason, $payload, $label): array {
            $payment = $db->fetchOne(
                "SELECT * FROM `payments` WHERE `provider_reference` = ? FOR UPDATE",
                's',
                [$providerReference]
            );
            if ($payment === null) {
                return ['outcome' => self::OUTCOME_UNMATCHED, 'provider_reference' => $providerReference];
            }

            $order = $db->fetchOne("SELECT * FROM `orders` WHERE `id` = ? FOR UPDATE", 'i', [(int) $payment['order_id']]);

            if ($payment['status'] !== 'pending') {
                // Out-of-order failure after success, or a repeated failure: nothing to change.
                return $this->result(
                    $payment['status'] === 'failed' ? self::OUTCOME_ALREADY_PROCESSED : self::OUTCOME_IGNORED,
                    $order,
                    $payment
                );
            }

            $db->executeUpdate(
                "UPDATE `payments` SET `status` = 'failed', `provider_payload_redacted` = COALESCE(?, `provider_payload_redacted`), `updated_at` = NOW() WHERE `id` = ?",
                'si',
                [empty($payload) ? null : json_encode($payload), (int) $payment['id']]
            );
            $payment['status'] = 'failed';

            if ($order['order_status'] === 'pending_payment') {
                $db->insert(
                    "INSERT INTO `order_status_history` (`order_id`, `previous_status`, `new_status`, `comment`)
                     VALUES (?, 'pending_payment', 'pending_payment', ?)",
                    'is',
                    [(int) $order['id'], mb_substr("Payment attempt failed via {$label} ({$providerReference}): {$reason}", 0, 1000)]
                );
            }

            return $this->result(self::OUTCOME_FAILED, $order, $payment, $reason);
        });
    }

    /**
     * Admin confirmation of an offline payment (bank transfer / WhatsApp), with the real
     * transfer reference supplied by the admin.
     *
     * @return array<string, mixed>
     */
    public function recordManualPayment(int $orderId, string $reference, ?int $adminId, string $note = ''): array
    {
        $reference = trim($reference);
        if (mb_strlen($reference) < 4 || mb_strlen($reference) > 80) {
            throw new InvalidArgumentException('Enter the bank transfer or receipt reference (4-80 characters) for this payment.');
        }

        return $this->db->transaction(function (Database $db) use ($orderId, $reference, $adminId, $note): array {
            $order = $db->fetchOne("SELECT * FROM `orders` WHERE `id` = ? FOR UPDATE", 'i', [$orderId]);
            if ($order === null) {
                throw new InvalidArgumentException('Order not found.');
            }
            // The customer said they sent a transfer, but the order expired before it was checked:
            // the money is still recorded (finalizeSuccess keeps it as a late payment to refund).
            $claimExpired = $order['order_status'] === 'cancelled'
                && !empty($order['customer_marked_paid_at'])
                && in_array($order['payment_status'], ['pending', 'failed', 'cancelled'], true);
            if ($order['order_status'] !== 'pending_payment' && !$claimExpired) {
                throw new InvalidArgumentException("Only orders awaiting payment can be confirmed manually (this order is '{$order['order_status']}').");
            }

            $providerReference = 'MANUAL-' . $order['order_number'] . '-' . strtoupper(substr(hash('sha256', $reference), 0, 8));
            $duplicate = $db->fetchOne("SELECT `id` FROM `payments` WHERE `provider_reference` = ?", 's', [$providerReference]);
            if ($duplicate !== null) {
                throw new InvalidArgumentException('This payment reference has already been recorded for this order.');
            }

            // One bank transfer can only pay for one order.
            $usedOn = $db->fetchOne(
                "SELECT o.`order_number` FROM `payments` p JOIN `orders` o ON o.`id` = p.`order_id`
                 WHERE p.`provider` IN ('manual', 'whatsapp') AND p.`status` IN ('successful', 'partially_refunded', 'refunded')
                   AND JSON_UNQUOTE(JSON_EXTRACT(p.`provider_payload_redacted`, '$.admin_reference')) = ?
                 LIMIT 1",
                's',
                [$reference]
            );
            if ($usedOn !== null) {
                throw new InvalidArgumentException("This bank reference was already used to confirm order {$usedOn['order_number']}.");
            }

            $details = json_encode([
                'admin_reference' => $reference,
                'note' => $note,
                'confirmed_by' => $adminId,
                'confirmed_at' => date('Y-m-d H:i:s'),
            ]);

            // When the customer chose "Pay via WhatsApp", settle that open attempt instead of
            // adding a second row, so the order shows one payment, not a pending one plus a manual one.
            $whatsappAttempt = $db->fetchOne(
                "SELECT `id`, `provider_reference` FROM `payments`
                 WHERE `order_id` = ? AND `provider` = 'whatsapp' AND `status` = 'pending'
                 ORDER BY `id` DESC LIMIT 1 FOR UPDATE",
                'i',
                [$orderId]
            );

            if ($whatsappAttempt !== null) {
                $providerReference = (string) $whatsappAttempt['provider_reference'];
                $db->executeUpdate(
                    "UPDATE `payments` SET `payment_method` = 'bank_transfer', `provider_payload_redacted` = ?, `updated_at` = NOW() WHERE `id` = ?",
                    'si',
                    [$details, (int) $whatsappAttempt['id']]
                );
            } else {
                $db->insert(
                    "INSERT INTO `payments` (`order_id`, `provider`, `provider_reference`, `amount`, `currency`, `payment_method`, `status`, `provider_payload_redacted`)
                     VALUES (?, 'manual', ?, ?, ?, 'bank_transfer', 'pending', ?)",
                    'isdss',
                    [
                        $orderId,
                        $providerReference,
                        (float) $order['grand_total'],
                        $order['currency'],
                        $details,
                    ]
                );
            }

            return $this->finalizeSuccess($providerReference, [
                'amount' => (float) $order['grand_total'],
                'currency' => $order['currency'],
                'method' => 'bank_transfer',
                'label' => 'manual confirmation (ref ' . $reference . ')',
                'confirmed_by' => $adminId,
            ]);
        });
    }

    /**
     * Take a paid order's items out of the customer's saved account cart, so the cart badge
     * clears on every device even when payment was confirmed later (e.g. a WhatsApp transfer).
     * Runs after the payment commits; a failure here never affects the payment.
     */
    private function removePaidItemsFromSavedCart(int $orderId, int $userId): void
    {
        try {
            $this->db->executeUpdate(
                "UPDATE `cart_items` ci
                 JOIN `carts` c ON c.`id` = ci.`cart_id` AND c.`user_id` = ?
                 JOIN (SELECT `variant_id`, SUM(`quantity`) AS qty FROM `order_items`
                       WHERE `order_id` = ? AND `variant_id` IS NOT NULL GROUP BY `variant_id`) oi
                   ON oi.`variant_id` = ci.`variant_id`
                 SET ci.`quantity` = ci.`quantity` - oi.qty",
                'ii',
                [$userId, $orderId]
            );
            $this->db->executeUpdate(
                "DELETE ci FROM `cart_items` ci JOIN `carts` c ON c.`id` = ci.`cart_id`
                 WHERE c.`user_id` = ? AND ci.`quantity` <= 0",
                'i',
                [$userId]
            );
        } catch (\Throwable $e) {
            error_log('[Saved cart cleanup] ' . $e->getMessage());
        }
    }

    /**
     * Append a note to the order's admin-attention flag.
     */
    private function flagAttention(Database $db, int $orderId, string $reason): void
    {
        // A repeated status check (e.g. an underpaid Monnify payment that stays pending) must not
        // add the same note again: the column keeps 500 characters, so repeats would push later,
        // different alerts out of sight.
        $db->executeUpdate(
            "UPDATE `orders`
             SET `requires_attention` = 1,
                 `attention_reason` = IF(LOCATE(?, COALESCE(`attention_reason`, '')) > 0,
                     `attention_reason`,
                     LEFT(CONCAT_WS(' | ', NULLIF(`attention_reason`, ''), ?), 500)),
                 `updated_at` = NOW()
             WHERE `id` = ?",
            'ssi',
            [$reason, $reason, $orderId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function result(string $outcome, array $order, array $payment, ?string $message = null): array
    {
        return array_filter([
            'outcome' => $outcome,
            'order_number' => $order['order_number'],
            'order_status' => $order['order_status'],
            'payment_status' => $payment['status'],
            'provider' => $payment['provider'],
            'provider_reference' => $payment['provider_reference'],
            'amount' => (float) $payment['amount'],
            'currency' => $payment['currency'] ?: $order['currency'],
            'verified_at' => $payment['verified_at'] ?? null,
            'message' => $message,
        ], static fn ($v) => $v !== null);
    }
}
