<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Services\KoraService;
use App\Support\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Controller handling incoming payment gateway webhook events.
 * Enforces cryptographic HMAC-SHA256 signature verification, deduplication,
 * and transactional state transitions.
 */
class PaymentWebhookController
{
    private KoraService $koraService;

    public function __construct(?KoraService $koraService = null)
    {
        $this->koraService = $koraService ?? new KoraService();
    }

    /**
     * Handle incoming Kora webhook notification.
     * POST /api/v1/webhooks/kora
     */
    public function handleKora(): void
    {
        $rawBody = file_get_contents('php://input') ?: '';
        $signatureHeader = $_SERVER['HTTP_X_KORAPAY_SIGNATURE'] ?? '';

        if (empty($signatureHeader)) {
            // Check getallheaders fallback
            if (function_exists('getallheaders')) {
                $headers = getallheaders();
                $signatureHeader = $headers['x-korapay-signature'] ?? ($headers['X-Korapay-Signature'] ?? '');
            }
        }

        if (empty($signatureHeader)) {
            Response::error('Missing x-korapay-signature header.', 401, 'UNAUTHORIZED_WEBHOOK');
        }

        try {
            $result = $this->koraService->handleWebhook($rawBody, $signatureHeader);

            // Respond 200 OK promptly to notify gateway of receipt
            Response::success($result, 'Webhook processed successfully.', 200);
        } catch (InvalidArgumentException $e) {
            error_log('[Kora Webhook Validation Failure] ' . $e->getMessage());
            Response::error('Invalid webhook signature.', 401, 'INVALID_SIGNATURE');
        } catch (RuntimeException $e) {
            // Configuration or transient failure: a non-2xx response makes Kora retry later.
            error_log('[Kora Webhook Processing Failure] ' . $e->getMessage());
            Response::error('Webhook could not be processed right now.', 503, 'PROCESSING_ERROR');
        } catch (Throwable $e) {
            error_log('[Kora Webhook Critical Error] ' . $e->getMessage());
            Response::error('Internal webhook processing error.', 500, 'INTERNAL_SERVER_ERROR');
        }
    }

    /**
     * Handle incoming Monnify webhook notification.
     * POST /api/v1/webhooks/monnify
     */
    public function handleMonnify(): void
    {
        $rawBody = file_get_contents('php://input') ?: '';
        $signatureHeader = $_SERVER['HTTP_MONNIFY_SIGNATURE'] ?? '';

        if (empty($signatureHeader) && function_exists('getallheaders')) {
            $headers = getallheaders();
            $signatureHeader = $headers['monnify-signature'] ?? ($headers['Monnify-Signature'] ?? '');
        }

        try {
            $monnifyService = new \App\Services\MonnifyService();
            $result = $monnifyService->handleWebhook($rawBody, $signatureHeader);

            Response::success($result, 'Monnify webhook processed successfully.', 200);
        } catch (InvalidArgumentException $e) {
            error_log('[Monnify Webhook Validation Failure] ' . $e->getMessage());
            Response::error('Invalid webhook signature.', 401, 'INVALID_SIGNATURE');
        } catch (RuntimeException $e) {
            // Configuration or transient failure: a non-2xx response makes Monnify retry later.
            error_log('[Monnify Webhook Processing Failure] ' . $e->getMessage());
            Response::error('Webhook could not be processed right now.', 503, 'PROCESSING_ERROR');
        } catch (Throwable $e) {
            error_log('[Monnify Webhook Critical Error] ' . $e->getMessage());
            Response::error('Internal webhook processing error.', 500, 'INTERNAL_SERVER_ERROR');
        }
    }
}

