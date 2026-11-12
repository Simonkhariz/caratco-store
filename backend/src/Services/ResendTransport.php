<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

/**
 * Sends one email through Resend's HTTP API (https://resend.com/docs/api-reference/emails/send-email).
 * A transport only: the outbox, retries and duplicate protection stay in MailService.
 *
 * Settings (backend/.env, server-only): MAIL_MAILER=resend, RESEND_API_KEY, MAIL_FROM_ADDRESS on a
 * domain verified in Resend, optional MAIL_FROM_NAME and MAIL_REPLY_TO. RESEND_API_URL is only for tests.
 *
 * Every send carries an Idempotency-Key. Resend keeps it for 24 hours and answers a repeat with the
 * original result without sending again, so a retry after a lost answer never sends twice. The
 * key includes a fingerprint of the message, because Resend refuses a reused key with a changed body.
 */
final class ResendTransport
{
    public static function isConfigured(): bool
    {
        return strtolower(trim((string) Env::get('MAIL_MAILER', 'log'))) === 'resend'
            && self::apiKey() !== ''
            && filter_var(trim((string) Env::get('MAIL_FROM_ADDRESS', '')), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * @param array<string, string> $extraHeaders e.g. ['List-Unsubscribe' => '<https://…>']
     * @param string|null $idempotencyKey stable for one outbox email (null for sign-in links and codes)
     * @return string Resend's id for the accepted email
     * @throws ResendError when Resend refuses the email or cannot be reached
     */
    public function send(string $to, string $subject, string $html, string $text, string $fallbackFromName, array $extraHeaders = [], ?string $idempotencyKey = null): string
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new ResendError('Invalid recipient address.', 0, true);
        }
        $fromAddress = trim((string) Env::get('MAIL_FROM_ADDRESS', ''));
        $fromName = trim((string) Env::get('MAIL_FROM_NAME', '')) ?: $fallbackFromName;
        $from = $fromName !== '' ? sprintf('%s <%s>', str_replace(['"', '<', '>', "\r", "\n"], '', $fromName), $fromAddress) : $fromAddress;

        $message = ['from' => $from, 'to' => [$to], 'subject' => $subject, 'html' => $html, 'text' => $text];
        $replyTo = MailService::replyToAddress();
        if ($replyTo !== null) {
            $message['reply_to'] = [$replyTo];
        }
        if ($extraHeaders !== []) {
            $message['headers'] = $extraHeaders;
        }
        $body = (string) json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = ['Authorization: Bearer ' . self::apiKey(), 'Content-Type: application/json', 'Accept: application/json'];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . substr($idempotencyKey . '-' . substr(hash('sha256', $body), 0, 16), 0, 256);
        }

        $timeout = max(2, min(60, Env::int('MAIL_TIMEOUT_SECONDS', 10)));
        $ch = curl_init(rtrim((string) (Env::get('RESEND_API_URL', 'https://api.resend.com') ?: 'https://api.resend.com'), '/') . '/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new ResendError('Could not reach Resend: ' . mb_substr($curlError, 0, 200), 0, false);
        }
        $json = json_decode((string) $raw, true);
        if ($status >= 200 && $status < 300 && is_array($json) && !empty($json['id'])) {
            return (string) $json['id'];
        }

        $name = is_array($json) ? (string) ($json['name'] ?? '') : '';
        $detail = is_array($json) ? mb_substr((string) ($json['message'] ?? ''), 0, 300) : '';
        throw new ResendError(
            sprintf('Resend answered HTTP %d%s%s', $status, $name !== '' ? " ({$name})" : '', $detail !== '' ? ": {$detail}" : ''),
            $status,
            self::isPermanent($status),
            // Quota errors are 429s too (daily_quota_exceeded, monthly_quota_exceeded).
            $status === 429 && !str_ends_with($name, '_quota_exceeded')
        );
    }

    /**
     * The email itself is invalid (400 validation, 422 missing fields): retrying cannot help.
     * 401/403 (key or domain settings), 409 (same key still in progress), 429 (rate or quota),
     * 5xx and network errors are worth retrying: they pass or can be fixed in the settings.
     */
    public static function isPermanent(int $status): bool
    {
        return $status === 400 || $status === 422;
    }

    private static function apiKey(): string
    {
        return trim((string) Env::get('RESEND_API_KEY', ''));
    }
}
