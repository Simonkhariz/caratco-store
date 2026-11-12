<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Database;
use App\Support\Input;
use App\Support\RateLimiter;
use App\Support\RequestContext;
use InvalidArgumentException;
use RuntimeException;

/**
 * Isolated payment service integrating Kora (Korapay) Hosted Checkout,
 * Server-Side Authoritative Transaction Verification, and Cryptographic Webhook Handling.
 *
 * Enforces:
 * - Credentials loaded exclusively from backend environment variables
 * - A new, unique provider reference (and payment row) per checkout attempt
 * - Backend-calculated amount & currency enforcement (never browser values)
 * - Safe response recording with recursive redaction of sensitive credentials/card data
 * - HMAC SHA-256 signature verification over the raw bytes of Kora's payload `data` object
 * - All state changes go through PaymentFinalizer (locking, idempotency, amount checks)
 * - Orders remain pending on initialization (never marked paid until verified)
 */
class KoraService
{
    /** Webhooks with a wrong signature recorded per connection, shared with Monnify. */
    public const BAD_SIGNATURE_BUCKET = 'webhook_bad_signature';
    public const BAD_SIGNATURE_LIMIT = 30;
    public const BAD_SIGNATURE_WINDOW = 600;

    private Database $db;
    private string $baseUrl;
    private string $secretKey;
    private string $publicKey;
    private string $webhookSecret;
    private string $notificationUrl;
    private string $defaultRedirectUrl;
    private PaymentFinalizer $finalizer;
    private PaymentAttempts $attempts;

    /**
     * Optional custom HTTP executor for mock testing.
     * Signature: function(string $method, string $url, array $headers, ?string $body, int $timeout): array
     * Returns: ['status_code' => int, 'body' => string, 'error' => ?string]
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

        // 1. Strictly read credentials from backend environment
        $this->baseUrl = rtrim(Env::get('KORA_API_BASE_URL', 'https://api.korapay.com/merchant/api/v1'), '/');
        $this->secretKey = trim(Env::get('KORA_SECRET_KEY', ''));
        $this->publicKey = trim(Env::get('KORA_PUBLIC_KEY', ''));
        $this->webhookSecret = trim(Env::get('KORA_WEBHOOK_SECRET', ''));

        $appUrl = rtrim(Env::get('APP_URL', 'http://localhost:8000'), '/');
        $this->notificationUrl = "{$appUrl}/api/v1/webhooks/kora";

        $this->defaultRedirectUrl = Env::frontendUrl() . '/checkout/callback';
    }

    /**
     * Initialize a hosted checkout session with Kora for a pending order.
     *
     * @param string|int $orderIdOrNumber Numeric ID or string order_number
     * @param string|null $customRedirectUrl Customer return URL (validated by the controller)
     * @return array Safe public checkout payload (checkout_url, provider_reference, etc.)
     */
    public function initializeCheckout(string|int $orderIdOrNumber, ?string $customRedirectUrl = null): array
    {
        if ($this->secretKey === '') {
            throw new RuntimeException('Kora payments are not configured on this store yet.');
        }

        $order = $this->loadOrder($orderIdOrNumber);
        $this->attempts->assertPayable($order);

        $orderNumber = $order['order_number'];
        $amount = (float) $order['grand_total'];
        $currency = strtoupper(trim($order['currency'] ?? 'NGN'));

        if ($amount <= 0.0) {
            throw new InvalidArgumentException("Invalid order amount for order '{$orderNumber}'. Amount must be greater than zero.");
        }
        if ($currency !== 'NGN') {
            throw new InvalidArgumentException("Unsupported payment currency '{$currency}'. Kora domestic gateway requires NGN.");
        }

        $addressSnapshot = is_string($order['shipping_address_snapshot'])
            ? json_decode($order['shipping_address_snapshot'], true)
            : ($order['shipping_address_snapshot'] ?? []);

        $customerName = trim((string) ($addressSnapshot['full_name'] ?? '')) ?: 'Customer';
        $customerEmail = trim((string) $order['guest_email']);
        if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Valid customer email is required to initialize payment.");
        }

        // A fresh reference and payment row per attempt; older references stay valid for late webhooks.
        $providerReference = $this->generateUniqueReference($orderNumber);
        $paymentId = $this->attempts->open($order, 'kora', $providerReference, PaymentAttempts::gatewayWindowMinutes());

        $payload = [
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $providerReference,
            'customer' => [
                'name' => $customerName,
                'email' => $customerEmail,
            ],
            'notification_url' => $this->notificationUrl,
            'redirect_url' => $customRedirectUrl ?: $this->defaultRedirectUrl,
            'channels' => ['card', 'bank_transfer'],
            'narration' => "Payment for order {$orderNumber}",
            'metadata' => [
                'order_id' => (int) $order['id'],
                'order_number' => $orderNumber,
            ],
        ];

        $httpResult = $this->executeHttpRequest(
            'POST',
            "{$this->baseUrl}/charges/initialize",
            [
                'Authorization: Bearer ' . $this->secretKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            json_encode($payload),
            15
        );

        if ($httpResult['error'] !== null || $httpResult['status_code'] === 0) {
            $this->recordPaymentAttempt($paymentId, [
                'error' => 'GATEWAY_TIMEOUT',
                'error_description' => $httpResult['error'] ?? 'Connection to payment gateway timed out.',
                'timestamp' => date('Y-m-d H:i:s'),
                'order_recoverable' => true,
            ]);
            throw new RuntimeException("Payment gateway request timed out. Your order has been preserved as pending; please try again shortly.");
        }

        $statusCode = $httpResult['status_code'];
        $responseJson = json_decode($httpResult['body'], true);

        if (!is_array($responseJson)) {
            $this->recordPaymentAttempt($paymentId, [
                'error' => 'MALFORMED_GATEWAY_RESPONSE',
                'http_status' => $statusCode,
                'timestamp' => date('Y-m-d H:i:s'),
                'order_recoverable' => true,
            ]);
            throw new RuntimeException("Received an unexpected response from the payment gateway. Your order remains pending.");
        }

        $isSuccess = ($statusCode >= 200 && $statusCode < 300) && (!isset($responseJson['status']) || $responseJson['status'] === true);
        if (!$isSuccess) {
            $providerMessage = Input::text($responseJson['message'] ?? null, 'Payment provider rejected checkout initialization.');
            $this->recordPaymentAttempt($paymentId, [
                'error' => 'PROVIDER_REJECTED',
                'http_status' => $statusCode,
                'provider_message' => $providerMessage,
                'provider_payload' => $this->redactSensitiveFields($responseJson),
                'timestamp' => date('Y-m-d H:i:s'),
                'order_recoverable' => true,
            ]);
            // The provider's words are for the store (log and payment record), not the customer.
            error_log("[Kora Initialization Rejected] HTTP {$statusCode}: {$providerMessage}");
            throw new RuntimeException('The payment provider could not start the checkout. Your order remains pending.');
        }

        $data = $responseJson['data'] ?? [];
        $checkoutUrl = $data['checkout_url'] ?? null;
        if (empty($checkoutUrl)) {
            $this->recordPaymentAttempt($paymentId, [
                'error' => 'MISSING_CHECKOUT_URL',
                'response' => $this->redactSensitiveFields($responseJson),
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
            throw new RuntimeException("Payment provider did not return a valid hosted checkout URL.");
        }

        // Order and payment stay 'pending' at initialization.
        $this->recordPaymentAttempt($paymentId, [
            'initialization_status' => 'initialized',
            'checkout_url' => $checkoutUrl,
            'response_data' => $this->redactSensitiveFields($responseJson),
            'initialized_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'order_number' => $orderNumber,
            'provider' => 'kora',
            'provider_reference' => $providerReference,
            'checkout_url' => $checkoutUrl,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'pending',
            'expires_in_minutes' => PaymentAttempts::gatewayWindowMinutes(),
        ];
    }

    /**
     * Verify a Kora webhook signature: HMAC-SHA256 of the payload's `data` object using the
     * secret key. The exact bytes Kora sent are checked first; re-encoded forms are accepted
     * as a fallback for senders that serialize identically to PHP.
     */
    public function verifyWebhookSignature(string $rawBody, string $signatureHeader): bool
    {
        return $this->verifiedWebhookData($rawBody, $signatureHeader) !== null;
    }

    /**
     * The `data` member the signature covers, decoded from exactly the text that was checked, or
     * null if the signature is wrong. A body can repeat "data": the signature may cover the first
     * while json_decode keeps the last, so the payload's own decoded value must never be trusted.
     *
     * @return array<mixed>|null
     */
    private function verifiedWebhookData(string $rawBody, string $signatureHeader): ?array
    {
        $signature = strtolower(trim($signatureHeader));
        if ($signature === '' || trim($rawBody) === '') {
            return null;
        }

        $signingKey = $this->secretKey !== '' ? $this->secretKey : $this->webhookSecret;
        if ($signingKey === '') {
            return null;
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || !isset($payload['data'])) {
            return null;
        }

        $candidates = array_filter([
            self::extractRawJsonMember($rawBody, 'data'),
            json_encode($payload['data'], JSON_UNESCAPED_SLASHES),
            json_encode($payload['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        foreach (array_unique($candidates) as $candidate) {
            if (hash_equals(hash_hmac('sha256', $candidate, $signingKey), $signature)) {
                $data = json_decode($candidate, true);
                return is_array($data) ? $data : [];
            }
        }

        return null;
    }

    /**
     * Process an incoming Kora webhook notification.
     * Invalid signatures throw InvalidArgumentException (401). Every outcome is recorded in
     * payment_webhook_events; a rejection as a short record, never its body, up to a limit per
     * connection (429 beyond it), so unsigned requests can't fill the database.
     */
    public function handleWebhook(string $rawBody, string $signatureHeader): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException("Malformed webhook payload. Expected valid JSON.");
        }

        $eventType = Input::text($payload['event'] ?? null, 'unknown');

        $data = $this->verifiedWebhookData($rawBody, $signatureHeader);
        if ($data === null) {
            RateLimiter::attempt(self::BAD_SIGNATURE_BUCKET, RequestContext::clientIp(), self::BAD_SIGNATURE_LIMIT, self::BAD_SIGNATURE_WINDOW);
            $unsigned = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $this->recordWebhookEvent('invalid-signature:' . substr(hash('sha256', $rawBody), 0, 32), $eventType, 0, 'failed', [
                'error' => 'SIGNATURE_VERIFICATION_FAILED',
                'reference' => mb_substr(Input::text($unsigned['reference'] ?? null), 0, 100),
            ]);
            throw new InvalidArgumentException("Webhook signature verification failed.");
        }

        // From here on, only what the signature covers.
        $payload['data'] = $data;
        $reference = Input::text($data['reference'] ?? null);
        $eventReference = !empty($payload['event_id'])
            ? Input::text($payload['event_id'])
            : "{$eventType}:{$reference}:" . Input::text($data['status'] ?? null, 'unknown');

        return $this->processPaymentEvent($eventType, $data, $eventReference, $payload);
    }

    /**
     * Apply a verified Kora payment event through PaymentFinalizer and record its outcome.
     */
    public function processPaymentEvent(string $eventType, array $data, string $eventReference, array $fullPayload): array
    {
        $existing = $this->db->fetchOne(
            "SELECT `processing_result` FROM `payment_webhook_events` WHERE `provider` = 'kora' AND `event_reference` = ?",
            's',
            [$eventReference]
        );
        if ($existing !== null && in_array($existing['processing_result'], ['processed', 'ignored_duplicate'], true)) {
            return [
                'status' => 'ignored_duplicate',
                'message' => 'Event already processed.',
                'event_reference' => $eventReference,
            ];
        }

        $reference = trim((string) ($data['reference'] ?? ''));
        if ($reference === '') {
            $this->recordWebhookEvent($eventReference, $eventType, 1, 'failed', ['error' => 'Missing reference in payload']);
            return ['status' => 'failed', 'message' => 'Missing transaction reference in payment event data.'];
        }

        $providerStatus = strtolower(trim((string) ($data['status'] ?? '')));
        $method = (string) ($data['payment_method'] ?? ($data['channel'] ?? ''));
        $safePayload = $this->redactSensitiveFields($fullPayload);

        if ($eventType === 'charge.success' && in_array($providerStatus, ['success', 'successful'], true)) {
            $result = $this->finalizer->finalizeSuccess($reference, [
                'amount' => $data['amount'] ?? 0,
                'currency' => (string) ($data['currency'] ?? ''),
                'method' => $method,
                'payload' => $safePayload,
                'label' => 'Kora',
            ]);
        } elseif ($eventType === 'charge.failed' || $providerStatus === 'failed') {
            $reason = (string) ($data['reason'] ?? ($data['message'] ?? 'Transaction failed or was declined.'));
            $result = $this->finalizer->finalizeFailure($reference, $reason, $safePayload, 'Kora');
        } else {
            $this->recordWebhookEvent($eventReference, $eventType, 1, 'ignored_duplicate', [
                'message' => "Ignored event type '{$eventType}' with status '{$providerStatus}'",
                'reference' => $reference,
            ]);
            return ['status' => 'ignored', 'message' => "Event '{$eventType}' ignored."];
        }

        $this->recordWebhookEvent($eventReference, $eventType, 1, self::processingResultFor($result['outcome']), [
            'reference' => $reference,
            'outcome' => $result['outcome'],
            'order_number' => $result['order_number'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
            'message' => $result['message'] ?? null,
        ]);

        return ['status' => $result['outcome']] + $result;
    }

    /**
     * Synchronously verify a transaction against Kora's verification API.
     * Endpoint: GET {base}/charges/:reference
     */
    public function verifyTransaction(string $providerReference): array
    {
        $ref = trim($providerReference);
        if ($ref === '') {
            throw new InvalidArgumentException("Transaction reference is required for verification.");
        }

        $payment = $this->loadPaymentWithOrder($ref);

        if ($payment['status'] !== 'pending') {
            return $this->statusResponse($payment, 'local_database');
        }

        $httpResult = $this->executeHttpRequest(
            'GET',
            "{$this->baseUrl}/charges/" . urlencode($ref),
            [
                'Authorization: Bearer ' . $this->secretKey,
                'Accept: application/json',
            ],
            null,
            15
        );

        if ($httpResult['error'] !== null || $httpResult['status_code'] === 0) {
            throw new RuntimeException("Verification request to payment gateway timed out. Please retry shortly.");
        }

        $responseJson = json_decode($httpResult['body'], true);
        if (!is_array($responseJson)) {
            throw new RuntimeException("Received an unexpected response from the payment gateway verification endpoint.");
        }

        $statusCode = $httpResult['status_code'];
        $isSuccess = ($statusCode >= 200 && $statusCode < 300) && (!isset($responseJson['status']) || $responseJson['status'] === true);
        if (!$isSuccess) {
            $msg = $responseJson['message'] ?? 'Could not retrieve transaction from payment gateway.';
            throw new RuntimeException("Payment gateway verification failed: {$msg}");
        }

        $data = is_array($responseJson['data'] ?? null) ? $responseJson['data'] : [];
        $providerStatus = strtolower(trim((string) ($data['status'] ?? '')));
        $data['reference'] = $data['reference'] ?? $ref;

        if (in_array($providerStatus, ['success', 'successful'], true)) {
            $this->processPaymentEvent('charge.success', $data, "query:{$ref}:success", $responseJson);
        } elseif ($providerStatus === 'failed') {
            $this->processPaymentEvent('charge.failed', $data, "query:{$ref}:failed", $responseJson);
        }

        return $this->statusResponse($this->loadPaymentWithOrder($ref), 'kora_api_verified');
    }

    /**
     * Persist a webhook/verification event with per-provider upsert semantics.
     * Runs outside any payment transaction, so rejected and failed events are never rolled back.
     */
    public function recordWebhookEvent(string $eventReference, string $eventType, int $signatureValid, string $processingResult, array $metadata): void
    {
        try {
            $this->db->execute(
                "INSERT INTO `payment_webhook_events` (`provider`, `event_reference`, `event_type`, `signature_valid`, `processing_result`, `payload_metadata`)
                 VALUES ('kora', ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE `signature_valid` = VALUES(`signature_valid`), `processing_result` = VALUES(`processing_result`),
                                         `payload_metadata` = VALUES(`payload_metadata`)",
                'ssiss',
                [mb_substr($eventReference, 0, 100), mb_substr($eventType, 0, 100), $signatureValid, $processingResult, json_encode($this->redactSensitiveFields($metadata))]
            );
        } catch (\Throwable $e) {
            error_log('[Kora Webhook Log Failure] ' . $e->getMessage());
        }
    }

    /**
     * Load order by numeric ID or alphanumeric order number.
     */
    public function loadOrder(string|int $orderIdOrNumber): array
    {
        if (is_numeric($orderIdOrNumber)) {
            $order = $this->db->fetchOne("SELECT * FROM `orders` WHERE `id` = ?", 'i', [(int) $orderIdOrNumber]);
        } else {
            $order = $this->db->fetchOne("SELECT * FROM `orders` WHERE `order_number` = ?", 's', [trim((string) $orderIdOrNumber)]);
        }

        if ($order === null) {
            throw new InvalidArgumentException("Order '{$orderIdOrNumber}' was not found.");
        }

        return $order;
    }

    /**
     * Map a PaymentFinalizer outcome onto payment_webhook_events.processing_result.
     */
    public static function processingResultFor(string $outcome): string
    {
        return match ($outcome) {
            PaymentFinalizer::OUTCOME_ALREADY_PROCESSED, PaymentFinalizer::OUTCOME_IGNORED => 'ignored_duplicate',
            PaymentFinalizer::OUTCOME_UNMATCHED => 'unmatched',
            PaymentFinalizer::OUTCOME_REJECTED => 'failed',
            default => 'processed',
        };
    }

    /**
     * Extract the raw JSON text of a top-level member (e.g. "data") exactly as received.
     */
    public static function extractRawJsonMember(string $json, string $key): ?string
    {
        $len = strlen($json);
        $depth = 0;
        $i = 0;

        while ($i < $len) {
            $ch = $json[$i];

            if ($ch === '"') {
                $start = $i;
                $i = self::skipString($json, $i);
                if ($depth === 1) {
                    $j = $i;
                    while ($j < $len && ctype_space($json[$j])) {
                        $j++;
                    }
                    if ($j < $len && $json[$j] === ':' && json_decode(substr($json, $start, $i - $start)) === $key) {
                        $j++;
                        while ($j < $len && ctype_space($json[$j])) {
                            $j++;
                        }
                        $end = self::skipValue($json, $j);
                        return $end > $j ? substr($json, $j, $end - $j) : null;
                    }
                }
                continue;
            }

            if ($ch === '{' || $ch === '[') {
                $depth++;
            } elseif ($ch === '}' || $ch === ']') {
                $depth--;
            }
            $i++;
        }

        return null;
    }

    /**
     * Return the index just past the JSON string starting at $i (which must be a quote).
     */
    private static function skipString(string $json, int $i): int
    {
        $len = strlen($json);
        $i++;
        while ($i < $len) {
            if ($json[$i] === '\\') {
                $i += 2;
                continue;
            }
            if ($json[$i] === '"') {
                return $i + 1;
            }
            $i++;
        }
        return $len;
    }

    /**
     * Return the index just past the JSON value starting at $i.
     */
    private static function skipValue(string $json, int $i): int
    {
        $len = strlen($json);
        if ($i >= $len) {
            return $i;
        }
        if ($json[$i] === '"') {
            return self::skipString($json, $i);
        }
        if ($json[$i] === '{' || $json[$i] === '[') {
            $depth = 0;
            while ($i < $len) {
                $ch = $json[$i];
                if ($ch === '"') {
                    $i = self::skipString($json, $i);
                    continue;
                }
                if ($ch === '{' || $ch === '[') {
                    $depth++;
                } elseif ($ch === '}' || $ch === ']') {
                    $depth--;
                    if ($depth === 0) {
                        return $i + 1;
                    }
                }
                $i++;
            }
            return $len;
        }
        while ($i < $len && !in_array($json[$i], [',', '}', ']'], true)) {
            $i++;
        }
        return $i;
    }

    private function loadPaymentWithOrder(string $ref): array
    {
        $payment = $this->db->fetchOne(
            "SELECT p.*, o.order_number, o.order_status, o.grand_total, o.currency AS order_currency
             FROM `payments` p
             JOIN `orders` o ON p.order_id = o.id
             WHERE p.provider_reference = ?",
            's',
            [$ref]
        );

        if ($payment === null) {
            throw new InvalidArgumentException("No payment found matching provider reference '{$ref}'.");
        }

        return $payment;
    }

    /**
     * Customer-facing verification payload built from the authoritative database state.
     */
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
            'provider' => 'kora',
            'status' => $status,
            'order_status' => $payment['order_status'],
            'amount' => (float) $payment['amount'],
            'currency' => $payment['currency'] ?: ($payment['order_currency'] ?: 'NGN'),
            'verified_at' => $payment['verified_at'],
            'source' => $source,
        ];
    }

    /**
     * Record sanitized payment response metadata into the payments table.
     */
    private function recordPaymentAttempt(int $paymentId, array $metadata): void
    {
        $this->attempts->recordMetadata($paymentId, $this->redactSensitiveFields($metadata));
    }

    /**
     * Recursively redacts sensitive credentials, authorization codes, and card details.
     */
    public function redactSensitiveFields(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $sensitiveKeys = [
            'secret',
            'secret_key',
            'authorization_code',
            'access_code',
            'token',
            'card_number',
            'pan',
            'cvv',
            'cvv2',
            'pin',
            'password',
            'private_key',
            'encryption_key',
            'x-korapay-signature',
        ];

        $cleaned = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSensitive = false;

            foreach ($sensitiveKeys as $pattern) {
                if ($lowerKey === $pattern || str_contains($lowerKey, $pattern)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $cleaned[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $cleaned[$key] = $this->redactSensitiveFields($value);
            } else {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
    }

    /**
     * Generate an unambiguous, unique provider reference.
     * Format: PAY-ORD-YYYYMMDD-XXXXXX-XXXXXXXX
     */
    private function generateUniqueReference(string $orderNumber): string
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $cleanOrderNumber = preg_replace('/[^A-Za-z0-9\-]/', '', $orderNumber);
        return "PAY-{$cleanOrderNumber}-{$suffix}";
    }

    /**
     * Execute an HTTP request either via the injected executor (unit testing) or native cURL.
     *
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
            'error' => ($error !== '') ? $error : null,
        ];
    }
}
