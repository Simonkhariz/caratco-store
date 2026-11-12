<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Database;
use App\Support\RateLimiter;
use App\Support\RequestContext;
use InvalidArgumentException;
use RuntimeException;

/**
 * Monnify hosted-checkout integration.
 *
 * - Credentials are read only from backend environment variables and never sent to the browser.
 * - Each checkout attempt gets its own payment row and reference.
 * - Webhooks must carry a valid `monnify-signature` (HMAC-SHA512 of the raw body, keyed with
 *   the client secret); unsigned or mis-signed webhooks are rejected.
 * - All state changes go through PaymentFinalizer, which locks rows, de-duplicates and checks
 *   the amount and currency against the order.
 */
class MonnifyService
{
    private Database $db;
    private string $baseUrl;
    private string $apiKey;
    private string $secretKey;
    private string $contractCode;
    private PaymentFinalizer $finalizer;
    private PaymentAttempts $attempts;

    /**
     * Optional custom HTTP executor for tests (same contract as KoraService).
     *
     * @var (callable(string, string, array, ?string, int): array)|null
     */
    private $httpExecutor = null;

    public function __construct(?Database $db = null, ?callable $httpExecutor = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->httpExecutor = $httpExecutor;
        $this->finalizer = new PaymentFinalizer($this->db);
        $this->attempts = new PaymentAttempts($this->db);

        $this->baseUrl = rtrim(Env::get('MONNIFY_API_BASE_URL', 'https://sandbox.monnify.com'), '/');
        $this->apiKey = trim(Env::get('MONNIFY_API_KEY', ''));
        $this->secretKey = trim(Env::get('MONNIFY_SECRET_KEY', ''));
        $this->contractCode = trim(Env::get('MONNIFY_CONTRACT_CODE', ''));
    }

    /**
     * Get an OAuth2 bearer token from Monnify.
     */
    public function getAccessToken(): string
    {
        if ($this->apiKey === '' || $this->secretKey === '') {
            throw new RuntimeException('Monnify payments are not configured on this store yet.');
        }

        $result = $this->executeHttpRequest(
            'POST',
            "{$this->baseUrl}/api/v1/auth/login",
            [
                'Authorization: Basic ' . base64_encode("{$this->apiKey}:{$this->secretKey}"),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            '',
            15
        );

        if ($result['error'] !== null || $result['status_code'] < 200 || $result['status_code'] >= 300) {
            throw new RuntimeException("Could not authenticate with Monnify (HTTP {$result['status_code']}).");
        }

        $json = json_decode($result['body'], true);
        $token = $json['responseBody']['accessToken'] ?? null;
        if (!$token) {
            throw new RuntimeException("Monnify did not return an access token.");
        }

        return $token;
    }

    /**
     * Initialize a Monnify hosted checkout for an order and return its checkout URL.
     */
    public function initializeCheckout(string|int $orderIdOrNumber, ?string $redirectUrl = null): array
    {
        if ($this->contractCode === '') {
            error_log('[Monnify] Not fully configured: set MONNIFY_CONTRACT_CODE in backend/.env (Monnify Dashboard > Developers > API Keys & Contracts).');
            throw new RuntimeException('Monnify payments are not configured on this store yet.');
        }

        $order = $this->loadOrder($orderIdOrNumber);
        $this->attempts->assertPayable($order);

        $orderNumber = $order['order_number'];
        $amount = (float) $order['grand_total'];
        $currency = strtoupper(trim($order['currency'] ?? 'NGN'));

        $addressSnapshot = is_string($order['shipping_address_snapshot'])
            ? json_decode($order['shipping_address_snapshot'], true)
            : ($order['shipping_address_snapshot'] ?? []);
        $customerName = trim((string) ($addressSnapshot['full_name'] ?? '')) ?: 'Customer';
        $customerEmail = trim((string) $order['guest_email']);
        if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid customer email is required to initialize payment.');
        }

        $providerReference = 'MNFY-' . $orderNumber . '-' . strtoupper(bin2hex(random_bytes(4)));
        $paymentId = $this->attempts->open($order, 'monnify', $providerReference, PaymentAttempts::gatewayWindowMinutes());

        $accessToken = $this->getAccessToken();
        $result = $this->executeHttpRequest(
            'POST',
            "{$this->baseUrl}/api/v1/merchant/transactions/init-transaction",
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            json_encode([
                'amount' => $amount,
                'customerName' => $customerName,
                'customerEmail' => $customerEmail,
                'paymentReference' => $providerReference,
                'paymentDescription' => "Order #{$orderNumber}",
                'currencyCode' => $currency,
                'contractCode' => $this->contractCode,
                'redirectUrl' => $redirectUrl,
                'paymentMethods' => ['CARD', 'ACCOUNT_TRANSFER'],
            ]),
            20
        );

        $json = json_decode($result['body'], true);
        $checkoutUrl = is_array($json) && !empty($json['requestSuccessful'])
            ? ($json['responseBody']['checkoutUrl'] ?? null)
            : null;

        if ($result['error'] !== null || $checkoutUrl === null) {
            $this->attempts->recordMetadata($paymentId, [
                'error' => 'INIT_FAILED',
                'http_status' => $result['status_code'],
                'provider_message' => is_array($json) ? ($json['responseMessage'] ?? null) : null,
                'timestamp' => date('Y-m-d H:i:s'),
                'order_recoverable' => true,
            ]);
            throw new RuntimeException('Monnify could not start the checkout right now. Your order is saved; please try again shortly or choose another payment method.');
        }

        $this->attempts->recordMetadata($paymentId, [
            'initialization_status' => 'initialized',
            'transaction_reference' => $json['responseBody']['transactionReference'] ?? null,
            'initialized_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'order_number' => $orderNumber,
            'provider' => 'monnify',
            'provider_reference' => $providerReference,
            'checkout_url' => $checkoutUrl,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'pending',
            'expires_in_minutes' => PaymentAttempts::gatewayWindowMinutes(),
        ];
    }

    /**
     * Server-to-server status query (GET /api/v2/merchant/transactions/query) used when the
     * customer returns from checkout. Gateway errors leave the payment pending.
     */
    public function verifyTransaction(string $providerReference): array
    {
        $ref = trim($providerReference);
        if ($ref === '') {
            throw new InvalidArgumentException('Transaction reference is required for Monnify verification.');
        }

        $payment = $this->loadPaymentWithOrder($ref);
        if ($payment['status'] !== 'pending') {
            return $this->statusResponse($payment, 'local_database');
        }

        try {
            $token = $this->getAccessToken();
            $result = $this->executeHttpRequest(
                'GET',
                "{$this->baseUrl}/api/v2/merchant/transactions/query?paymentReference=" . urlencode($ref),
                [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json',
                ],
                null,
                15
            );

            $json = json_decode($result['body'], true);
            if ($result['error'] === null && $result['status_code'] >= 200 && $result['status_code'] < 300
                && is_array($json) && is_array($json['responseBody'] ?? null)) {
                $this->applyStatus($ref, $json['responseBody'], 'query');
            }
        } catch (RuntimeException $e) {
            error_log('[Monnify Query] ' . $e->getMessage());
        }

        return $this->statusResponse($this->loadPaymentWithOrder($ref), 'monnify_query');
    }

    /**
     * Handle a Monnify webhook. The signature is mandatory.
     */
    public function handleWebhook(string $rawBody, string $signatureHeader): array
    {
        if ($this->secretKey === '') {
            throw new RuntimeException('Monnify webhook received but MONNIFY_SECRET_KEY is not configured.');
        }

        $signature = strtolower(trim($signatureHeader));
        $computed = hash_hmac('sha512', $rawBody, $this->secretKey);
        if ($signature === '' || !hash_equals($computed, $signature)) {
            // A short record only (never the body), and only so many per connection: unsigned
            // requests must not be able to fill the database. Signed ones never reach this limit.
            RateLimiter::attempt(KoraService::BAD_SIGNATURE_BUCKET, RequestContext::clientIp(), KoraService::BAD_SIGNATURE_LIMIT, KoraService::BAD_SIGNATURE_WINDOW);
            $this->recordWebhookEvent('invalid-signature:' . substr(hash('sha256', $rawBody), 0, 32), 'unknown', 0, 'failed', [
                'error' => $signature === '' ? 'MISSING_SIGNATURE' : 'SIGNATURE_VERIFICATION_FAILED',
            ]);
            throw new InvalidArgumentException('Invalid or missing Monnify webhook signature.');
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Malformed webhook JSON body.');
        }

        $eventType = (string) ($payload['eventType'] ?? 'unknown');
        $eventData = is_array($payload['eventData'] ?? null) ? $payload['eventData'] : [];
        $reference = trim((string) ($eventData['paymentReference'] ?? ''));
        $status = strtoupper(trim((string) ($eventData['paymentStatus'] ?? '')));
        $eventReference = mb_substr(
            ($eventData['transactionReference'] ?? $reference) . ':' . $eventType . ':' . $status,
            0,
            100
        );

        $existing = $this->db->fetchOne(
            "SELECT `processing_result` FROM `payment_webhook_events` WHERE `provider` = 'monnify' AND `event_reference` = ?",
            's',
            [$eventReference]
        );
        if ($existing !== null && in_array($existing['processing_result'], ['processed', 'ignored_duplicate'], true)) {
            return ['status' => 'ignored_duplicate', 'event_reference' => $eventReference];
        }

        if ($reference === '') {
            $this->recordWebhookEvent($eventReference, $eventType, 1, 'ignored_duplicate', ['message' => 'No paymentReference (not a collection event)']);
            return ['status' => 'ignored', 'message' => 'Event has no payment reference.'];
        }

        $result = $this->applyStatus($reference, $eventData, 'webhook');

        $this->recordWebhookEvent($eventReference, $eventType, 1, KoraService::processingResultFor($result['outcome']), [
            'reference' => $reference,
            'payment_status' => $status,
            'outcome' => $result['outcome'],
            'order_number' => $result['order_number'] ?? null,
            'amount_paid' => $eventData['amountPaid'] ?? null,
        ]);

        return ['status' => $result['outcome']] + $result;
    }

    /**
     * Apply a Monnify transaction status (from a signed webhook or a server-side query).
     *
     * @param array $body Monnify transaction object (eventData or responseBody)
     */
    private function applyStatus(string $reference, array $body, string $source): array
    {
        $status = strtoupper(trim((string) ($body['paymentStatus'] ?? '')));
        // Monnify collections are NGN-only; the field name differs between webhook and query payloads.
        $currency = (string) ($body['currency'] ?? ($body['currencyCode'] ?? 'NGN'));
        $safePayload = array_intersect_key($body, array_flip([
            'transactionReference', 'paymentReference', 'amountPaid', 'totalPayable', 'paymentStatus',
            'paymentMethod', 'paidOn', 'currency', 'currencyCode',
        ]));

        return match ($status) {
            // The finalizer compares amountPaid with the order total: PARTIALLY_PAID becomes a
            // flagged underpayment (never marked paid), OVERPAID is paid and flagged for refund.
            'PAID', 'OVERPAID', 'PARTIALLY_PAID' => $this->finalizer->finalizeSuccess($reference, [
                'amount' => $body['amountPaid'] ?? 0,
                'currency' => $currency,
                'method' => strtolower((string) ($body['paymentMethod'] ?? '')),
                'payload' => $safePayload + ['source' => $source],
                'label' => 'Monnify',
            ]),
            'FAILED', 'EXPIRED', 'ABANDONED', 'CANCELLED', 'REVERSED' => $this->finalizer->finalizeFailure(
                $reference,
                "Monnify reported {$status}",
                $safePayload + ['source' => $source],
                'Monnify'
            ),
            default => ['outcome' => PaymentFinalizer::OUTCOME_IGNORED, 'provider_reference' => $reference, 'message' => "Status {$status} needs no action"],
        };
    }

    private function recordWebhookEvent(string $eventReference, string $eventType, int $signatureValid, string $processingResult, array $metadata): void
    {
        try {
            $this->db->execute(
                "INSERT INTO `payment_webhook_events` (`provider`, `event_reference`, `event_type`, `signature_valid`, `processing_result`, `payload_metadata`)
                 VALUES ('monnify', ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE `signature_valid` = VALUES(`signature_valid`), `processing_result` = VALUES(`processing_result`),
                                         `payload_metadata` = VALUES(`payload_metadata`)",
                'ssiss',
                [mb_substr($eventReference, 0, 100), mb_substr($eventType, 0, 100), $signatureValid, $processingResult, json_encode($metadata)]
            );
        } catch (\Throwable $e) {
            error_log('[Monnify Webhook Log Failure] ' . $e->getMessage());
        }
    }

    private function loadPaymentWithOrder(string $ref): array
    {
        $payment = $this->db->fetchOne(
            "SELECT p.*, o.order_number, o.order_status, o.grand_total, o.currency AS order_currency
             FROM `payments` p
             JOIN `orders` o ON p.order_id = o.id
             WHERE p.provider_reference = ? AND p.provider = 'monnify'",
            's',
            [$ref]
        );

        if ($payment === null) {
            throw new InvalidArgumentException("No Monnify payment found matching reference '{$ref}'.");
        }

        return $payment;
    }

    private function statusResponse(array $payment, string $source): array
    {
        $status = match ($payment['status']) {
            'successful', 'partially_refunded', 'refunded' => 'successful',
            'failed', 'cancelled' => 'failed',
            default => 'pending',
        };

        return [
            'order_number' => $payment['order_number'],
            'provider_reference' => $payment['provider_reference'],
            'provider' => 'monnify',
            'status' => $status,
            'order_status' => $payment['order_status'],
            'amount' => (float) $payment['amount'],
            'currency' => $payment['currency'] ?: ($payment['order_currency'] ?: 'NGN'),
            'verified_at' => $payment['verified_at'],
            'source' => $source,
        ];
    }

    private function loadOrder(string|int $orderIdOrNumber): array
    {
        if (is_numeric($orderIdOrNumber)) {
            $order = $this->db->fetchOne("SELECT * FROM `orders` WHERE `id` = ?", 'i', [(int) $orderIdOrNumber]);
        } else {
            $order = $this->db->fetchOne("SELECT * FROM `orders` WHERE `order_number` = ?", 's', [(string) $orderIdOrNumber]);
        }

        if ($order === null) {
            throw new InvalidArgumentException("Order '{$orderIdOrNumber}' not found.");
        }

        return $order;
    }

    /**
     * @return array{status_code: int, body: string, error: ?string}
     */
    private function executeHttpRequest(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        if ($this->httpExecutor !== null) {
            return ($this->httpExecutor)($method, $url, $headers, $body, $timeout);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'status_code' => $statusCode,
            'body' => (string) $response,
            'error' => $error !== '' ? $error : null,
        ];
    }
}
