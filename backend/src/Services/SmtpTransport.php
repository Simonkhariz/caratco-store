<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use RuntimeException;

/**
 * Minimal SMTP client (no external dependencies).
 *
 * Every server reply is checked against the expected code, so a rejected recipient, failed
 * login or refused message raises an exception instead of being reported as sent. Headers are
 * RFC 2047 encoded and stripped of CR/LF; bodies are base64 encoded (which also makes SMTP
 * dot-stuffing and line-length limits irrelevant).
 */
final class SmtpTransport
{
    private string $host;
    private int $port;
    private string $encryption;
    private string $username;
    private string $password;
    private string $fromAddress;
    private string $fromName;
    private int $timeout;

    /** @var resource|null */
    private $socket = null;

    public function __construct()
    {
        $this->host = trim((string) Env::get('MAIL_HOST', ''));
        $this->port = (int) Env::get('MAIL_PORT', '587');
        $this->encryption = strtolower(trim((string) Env::get('MAIL_ENCRYPTION', 'tls')));
        $this->username = (string) Env::get('MAIL_USERNAME', '');
        $this->password = (string) Env::get('MAIL_PASSWORD', '');
        $this->fromAddress = trim((string) Env::get('MAIL_FROM_ADDRESS', ''));
        $this->fromName = trim((string) Env::get('MAIL_FROM_NAME', ''));
        // Seconds to wait for the connection and for each reply from the mail server. A healthy
        // server answers in well under a second; this bounds how long a stuck one can hold up a
        // send (MAIL_TIMEOUT_SECONDS, 2-60, default 10).
        $this->timeout = max(2, min(60, Env::int('MAIL_TIMEOUT_SECONDS', 10)));
    }

    public static function isConfigured(): bool
    {
        return strtolower(trim((string) Env::get('MAIL_MAILER', 'log'))) === 'smtp'
            && trim((string) Env::get('MAIL_HOST', '')) !== ''
            && filter_var(trim((string) Env::get('MAIL_FROM_ADDRESS', '')), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * @param array<string, string> $extraHeaders e.g. ['List-Unsubscribe' => '<https://…>']
     * @throws RuntimeException when the server refuses any step
     */
    public function send(string $to, string $subject, string $html, string $text, string $fallbackFromName, array $extraHeaders = []): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid recipient address.');
        }
        $fromName = $this->fromName !== '' ? $this->fromName : $fallbackFromName;

        $this->connect();
        try {
            $this->expect(220);
            $this->command('EHLO ' . $this->heloName(), 250);

            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('Could not start TLS with the mail server.');
                }
                $this->command('EHLO ' . $this->heloName(), 250);
            }

            if ($this->username !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($this->username), 334);
                $this->command(base64_encode($this->password), 235, 'Mail server login failed.');
            }

            $this->command('MAIL FROM:<' . $this->fromAddress . '>', 250);
            $this->command('RCPT TO:<' . $to . '>', [250, 251], 'The mail server refused the recipient.');
            $this->command('DATA', 354);

            $this->write($this->buildMessage($to, $subject, $html, $text, $fromName, $extraHeaders) . "\r\n.");
            $this->expect(250, 'The mail server did not accept the message.');

            try {
                $this->command('QUIT', 221);
            } catch (RuntimeException) {
                // The message was accepted; a sloppy QUIT does not change that.
            }
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    private function connect(): void
    {
        $target = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $socket = @stream_socket_client($target, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            throw new RuntimeException("Could not connect to the mail server ({$errno}: {$errstr}).");
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
    }

    /**
     * @param int|list<int> $expected
     */
    private function command(string $line, int|array $expected, ?string $failure = null): string
    {
        $this->write($line);
        return $this->expect($expected, $failure);
    }

    private function write(string $data): void
    {
        if (@fwrite($this->socket, $data . "\r\n") === false) {
            throw new RuntimeException('Lost the connection to the mail server.');
        }
    }

    /**
     * @param int|list<int> $expected
     */
    private function expect(int|array $expected, ?string $failure = null): string
    {
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            // Multi-line replies use "250-"; the last line uses "250 ".
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $meta = stream_get_meta_data($this->socket);
        if ($response === '' || !empty($meta['timed_out'])) {
            throw new RuntimeException('The mail server did not respond.');
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, (array) $expected, true)) {
            // Keep the server's reply (it is safe and useful in the outbox), but never echo credentials.
            $reply = trim(preg_replace('/\s+/', ' ', $response) ?? '');
            throw new RuntimeException(($failure ?? 'Unexpected reply from the mail server.') . " ({$reply})");
        }
        return $response;
    }

    /**
     * @param array<string, string> $extraHeaders
     */
    private function buildMessage(string $to, string $subject, string $html, string $text, string $fromName, array $extraHeaders): string
    {
        $boundary = 'b_' . bin2hex(random_bytes(12));
        $domain = substr(strrchr($this->fromAddress, '@') ?: '@localhost', 1);

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->encodeName($fromName) . ' <' . $this->fromAddress . '>',
            'To: <' . $to . '>',
            ...(($replyTo = MailService::replyToAddress()) !== null ? ['Reply-To: <' . $this->clean($replyTo) . '>'] : []),
            'Subject: ' . $this->encodeText($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        foreach ($extraHeaders as $name => $value) {
            $headers[] = preg_replace('/[^A-Za-z0-9-]/', '', $name) . ': ' . $this->clean($value);
        }

        $part = static fn (string $type, string $body): string => "--{$boundary}\r\n"
            . "Content-Type: {$type}; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n"
            . $part('text/plain', $text)
            . $part('text/html', $html)
            . "--{$boundary}--";
    }

    private function clean(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }

    /** Unstructured header text (Subject): RFC 2047 encoded only when it is not plain ASCII. */
    private function encodeText(string $value): string
    {
        $value = $this->clean($value);
        return preg_match('/[^\x20-\x7E]/', $value) ? mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n") : $value;
    }

    /** Display name in From: quoted, or RFC 2047 encoded when it is not plain ASCII. */
    private function encodeName(string $value): string
    {
        $value = $this->clean($value);
        return preg_match('/[^\x20-\x7E]/', $value) ? mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n") : '"' . addcslashes($value, '"\\') . '"';
    }

    private function heloName(): string
    {
        $name = preg_replace('/[^A-Za-z0-9.-]/', '', (string) gethostname()) ?: 'localhost';
        return $name;
    }
}
