<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Config\Shop;
use App\Database\Database;
use InvalidArgumentException;

/**
 * Renders outbox payloads into email subject / HTML / plain text.
 *
 * Every value is escaped; layouts use tables (email clients ignore flexbox). Store name and
 * contact details come from store_settings; nothing is invented when a setting is empty.
 * Payloads carry everything a template needs (version 2), so an email can be (re)sent later
 * by the outbox worker without touching the order again.
 */
final class MailTemplates
{
    public const VERSION = 2;

    /** Templates the outbox can render (and therefore retry) from their stored payload. */
    public const RENDERABLE = [
        'order_received',
        'payment_verified',
        'shipment_tracking',
        'order_cancelled',
        'refund_processed',
        'marketing_broadcast',
        'payment_claimed',
        'admin_new_order',
        'admin_payment_received',
        'order_status_update',
        'storage_alert',
        'data_cleanup_done',
    ];

    private string $frontendUrl;
    /** @var array{name: string, email: string, phone: string, whatsapp: string} */
    private array $store;
    /** The shop's own colour (config/shop.json, theme.accent) for the wordmark and buttons, and the text on it. */
    private string $accent;
    private string $accentInk;

    public function __construct(?Database $db = null)
    {
        $this->frontendUrl = Env::frontendUrl();
        $this->accent = Shop::colour('accent', '#000000');
        $this->accentInk = Shop::colour('accentInk', '#ffffff');
        $settings = array_column(($db ?? Database::getInstance())->fetchAll(
            "SELECT `key_name`, `value` FROM `store_settings`
             WHERE `key_name` IN ('store_name', 'store_contact_email', 'store_contact_phone', 'store_whatsapp_phone')"
        ), 'value', 'key_name');

        $this->store = [
            'name' => trim((string) ($settings['store_name'] ?? '')) ?: Shop::name(),
            'email' => trim((string) ($settings['store_contact_email'] ?? '')),
            'phone' => trim((string) ($settings['store_contact_phone'] ?? '')),
            'whatsapp' => trim((string) ($settings['store_whatsapp_phone'] ?? '')) ?: trim((string) Env::get('STORE_WHATSAPP_PHONE', '')),
        ];
    }

    public function storeName(): string
    {
        return $this->store['name'];
    }

    /**
     * @param array<string, mixed> $p Payload
     * @return array{subject: string, html: string, text: string}
     */
    public function render(string $template, array $p): array
    {
        return match ($template) {
            'order_received' => $this->orderReceived($p),
            'payment_verified' => $this->paymentVerified($p),
            'shipment_tracking' => $this->shipmentTracking($p),
            'order_cancelled' => $this->orderCancelled($p),
            'refund_processed' => $this->refundProcessed($p),
            'marketing_broadcast' => $this->marketingBroadcast($p),
            'magic_link' => $this->magicLink($p),
            'payment_claimed' => $this->paymentClaimed($p),
            'admin_new_order' => $this->adminNewOrder($p),
            'admin_payment_received' => $this->adminPaymentReceived($p),
            'order_status_update' => $this->orderStatusUpdate($p),
            'storage_alert' => $this->storageAlert($p),
            'admin_confirm_code' => $this->adminConfirmCode($p),
            'data_cleanup_done' => $this->dataCleanupDone($p),
            default => throw new InvalidArgumentException("Unknown email template '{$template}'."),
        };
    }

    // -------------------------------------------------------------------------
    // Templates
    // -------------------------------------------------------------------------

    private function orderReceived(array $p): array
    {
        $num = (string) $p['order_number'];
        $subject = "Order {$num} received – complete your payment";
        $deadline = !empty($p['expires_at']) ? $this->dateTime((string) $p['expires_at']) : null;

        $intro = '<p style="margin:0 0 12px">Hi ' . $this->e($this->firstName($p)) . ',</p>'
            . '<p style="margin:0 0 16px">Thanks for your order <strong>' . $this->e($num) . '</strong>. We have reserved your items'
            . ($deadline ? ' until <strong>' . $this->e($deadline) . '</strong>' : '')
            . '. Your order is confirmed once payment is verified.</p>';

        $html = $this->layout(
            'Order received',
            $intro
            . $this->itemsTable($p)
            . $this->totalsTable($p, 'Total to pay')
            . $this->addressBlock($p)
            . $this->button($this->trackUrl($num), 'View order and pay')
            . '<p style="margin:16px 0 0;color:#666666;font-size:13px">To open your order, use this order number with the email you checked out with.</p>'
        );

        $text = "Hi {$this->firstName($p)},\n\n"
            . "Thanks for your order {$num}. We have reserved your items" . ($deadline ? " until {$deadline}" : '') . ".\n"
            . "Your order is confirmed once payment is verified.\n\n"
            . $this->itemsText($p)
            . $this->totalsText($p, 'Total to pay')
            . "\nView order and pay: {$this->trackUrl($num)}\n"
            . $this->footerText();

        return compact('subject', 'html', 'text');
    }

    private function paymentVerified(array $p): array
    {
        $num = (string) $p['order_number'];
        $subject = "Payment received for order {$num}";

        $rows = [
            ['Amount paid', $this->money($p['amount_paid'] ?? $p['grand_total'], $p['currency'] ?? 'NGN')],
            ['Paid with', (string) ($p['payment_label'] ?? '')],
            ['Payment reference', (string) ($p['payment_reference'] ?? '')],
            ['Confirmed', !empty($p['verified_at']) ? $this->dateTime((string) $p['verified_at']) : ''],
        ];

        $html = $this->layout(
            'Payment received',
            '<p style="margin:0 0 12px">Hi ' . $this->e($this->firstName($p)) . ',</p>'
            . '<p style="margin:0 0 16px">We have received your payment for order <strong>' . $this->e($num) . '</strong>. We are now preparing it for delivery and will email you the tracking details when it ships.</p>'
            . $this->keyValueTable($rows)
            . $this->itemsTable($p)
            . $this->totalsTable($p, 'Total')
            . $this->addressBlock($p)
            . $this->button($this->trackUrl($num), 'Track your order')
        );

        $text = "Hi {$this->firstName($p)},\n\nWe have received your payment for order {$num}.\n\n"
            . $this->keyValueText($rows) . "\n"
            . $this->itemsText($p)
            . $this->totalsText($p, 'Total')
            . "\nTrack your order: {$this->trackUrl($num)}\n"
            . $this->footerText();

        return compact('subject', 'html', 'text');
    }

    private function shipmentTracking(array $p): array
    {
        $num = (string) $p['order_number'];
        $subject = "Order {$num} has shipped";
        $trackingUrl = (string) ($p['tracking_url'] ?? '');
        $trackingUrl = str_starts_with($trackingUrl, 'https://') ? $trackingUrl : '';

        $rows = [
            ['Courier', (string) ($p['carrier'] ?? '')],
            ['Tracking number', (string) ($p['tracking_number'] ?? '')],
            ['Estimated delivery', (string) ($p['estimated_days'] ?? '')],
        ];

        $html = $this->layout(
            'Your order is on its way',
            '<p style="margin:0 0 12px">Hi ' . $this->e($this->firstName($p)) . ',</p>'
            . '<p style="margin:0 0 16px">Order <strong>' . $this->e($num) . '</strong> has been handed to the courier.</p>'
            . $this->keyValueTable($rows)
            . ($trackingUrl !== '' ? $this->button($trackingUrl, 'Track with the courier') : '')
            . '<p style="margin:16px 0 0"><a href="' . $this->e($this->trackUrl($num)) . '" style="color:#000000">See your order</a></p>'
        );

        $text = "Hi {$this->firstName($p)},\n\nOrder {$num} has been handed to the courier.\n\n"
            . $this->keyValueText($rows)
            . ($trackingUrl !== '' ? "Track with the courier: {$trackingUrl}\n" : '')
            . "See your order: {$this->trackUrl($num)}\n"
            . $this->footerText();

        return compact('subject', 'html', 'text');
    }

    private function orderCancelled(array $p): array
    {
        $num = (string) $p['order_number'];
        $subject = "Order {$num} has been cancelled";
        $paid = (bool) ($p['was_paid'] ?? false);
        $reason = trim((string) ($p['reason'] ?? ''));

        $body = $paid
            ? 'Your payment of <strong>' . $this->e($this->money($p['amount_paid'] ?? 0, $p['currency'] ?? 'NGN')) . '</strong> will be refunded. We will email you when the refund has been sent.'
            : 'No payment was taken for this order.';

        $html = $this->layout(
            'Order cancelled',
            '<p style="margin:0 0 12px">Hi ' . $this->e($this->firstName($p)) . ',</p>'
            . '<p style="margin:0 0 12px">Order <strong>' . $this->e($num) . '</strong> has been cancelled.</p>'
            . ($reason !== '' ? '<p style="margin:0 0 12px;color:#666666">Reason: ' . $this->e($reason) . '</p>' : '')
            . '<p style="margin:0 0 16px">' . $body . '</p>'
            . $this->contactLine()
        );

        $text = "Hi {$this->firstName($p)},\n\nOrder {$num} has been cancelled.\n"
            . ($reason !== '' ? "Reason: {$reason}\n" : '')
            . ($paid ? "Your payment of {$this->money($p['amount_paid'] ?? 0, $p['currency'] ?? 'NGN')} will be refunded.\n" : "No payment was taken for this order.\n")
            . $this->footerText();

        return compact('subject', 'html', 'text');
    }

    /**
     * To the store's admins: a customer tapped "Mark as paid" for a WhatsApp transfer.
     * The order is not paid until an admin checks the bank and confirms it.
     */
    private function paymentClaimed(array $p): array
    {
        $num = (string) $p['order_number'];
        $amount = $this->money($p['grand_total'] ?? 0, $p['currency'] ?? 'NGN');
        $subject = "Customer says order {$num} is paid ({$amount})";
        $address = is_array($p['address'] ?? null) ? $p['address'] : [];
        $adminUrl = $this->frontendUrl . '/admin/orders/' . rawurlencode($num);

        $rows = [
            ['Order', $num],
            ['Amount to check for', $amount],
            ['Customer', trim((string) ($p['customer_name'] ?? ''))],
            ['Phone', trim((string) ($address['phone'] ?? ''))],
            ['Marked as paid', !empty($p['claimed_at']) ? $this->dateTime((string) $p['claimed_at']) : ''],
        ];

        $html = $this->layout(
            'A customer says they have paid',
            '<p style="margin:0 0 16px">The customer for order <strong>' . $this->e($num) . '</strong> tapped "Mark as paid" for a WhatsApp transfer. '
            . 'Check that <strong>' . $this->e($amount) . '</strong> has arrived in your account, then confirm the payment in admin with the transfer reference. '
            . 'Nothing is marked paid until you do.</p>'
            . $this->keyValueTable($rows)
            . $this->itemsTable($p)
            . $this->button($adminUrl, 'Open the order in admin')
        );

        $text = "The customer for order {$num} tapped \"Mark as paid\" for a WhatsApp transfer.\n"
            . "Check that {$amount} has arrived in your account, then confirm the payment in admin with the transfer reference.\n"
            . "Nothing is marked paid until you do.\n\n"
            . $this->keyValueText($rows) . "\n"
            . $this->itemsText($p)
            . "\nOpen the order: {$adminUrl}\n"
            . $this->footerText();

        return compact('subject', 'html', 'text');
    }

    /** To the admins: a new order was placed. It is not paid yet. */
    private function adminNewOrder(array $p): array
    {
        $num = (string) $p['order_number'];
        $amount = $this->money($p['grand_total'] ?? 0, $p['currency'] ?? 'NGN');
        $subject = "New order {$num} ({$amount})";
        $address = is_array($p['address'] ?? null) ? $p['address'] : [];
        $adminUrl = $this->frontendUrl . '/admin/orders/' . rawurlencode($num);
        $rows = [
            ['Order', $num],
            ['Total', $amount],
            ['Customer', trim((string) ($p['customer_name'] ?? ''))],
            ['Phone', trim((string) ($address['phone'] ?? ''))],
            ['Deliver to', trim(implode(', ', array_filter([(string) ($address['city'] ?? ''), (string) ($address['state'] ?? '')])))],
        ];
        $html = $this->layout(
            'New order',
            '<p style="margin:0 0 16px">Order <strong>' . $this->e($num) . '</strong> was just placed. '
            . 'It is <strong>not paid yet</strong>: you will get another email when the payment is confirmed.</p>'
            . $this->keyValueTable($rows)
            . $this->itemsTable($p)
            . $this->button($adminUrl, 'Open the order in admin')
        );
        $text = "Order {$num} was just placed. It is not paid yet: you will get another email when the payment is confirmed.\n\n"
            . $this->keyValueText($rows) . "\n"
            . $this->itemsText($p)
            . "\nOpen the order: {$adminUrl}\n"
            . $this->footerText();
        return compact('subject', 'html', 'text');
    }

    /** To the admins: a payment was confirmed; the order is ready to pack. */
    private function adminPaymentReceived(array $p): array
    {
        $num = (string) $p['order_number'];
        $amount = $this->money($p['grand_total'] ?? 0, $p['currency'] ?? 'NGN');
        $subject = "Payment received for order {$num} ({$amount})";
        $address = is_array($p['address'] ?? null) ? $p['address'] : [];
        $adminUrl = $this->frontendUrl . '/admin/orders/' . rawurlencode($num);
        $rows = [
            ['Order', $num],
            ['Amount', $amount],
            ['Paid with', trim((string) ($p['payment_label'] ?? ''))],
            ['Reference', trim((string) ($p['payment_reference'] ?? ''))],
            ['Customer', trim((string) ($p['customer_name'] ?? ''))],
            ['Phone', trim((string) ($address['phone'] ?? ''))],
        ];
        $html = $this->layout(
            'Payment received',
            '<p style="margin:0 0 16px">The payment for order <strong>' . $this->e($num) . '</strong> is confirmed. The order is ready to pack and ship.</p>'
            . $this->keyValueTable($rows)
            . $this->itemsTable($p)
            . $this->button($adminUrl, 'Open the order in admin')
        );
        $text = "The payment for order {$num} is confirmed. The order is ready to pack and ship.\n\n"
            . $this->keyValueText($rows) . "\n"
            . $this->itemsText($p)
            . "\nOpen the order: {$adminUrl}\n"
            . $this->footerText();
        return compact('subject', 'html', 'text');
    }

    /** To the customer: the order is being prepared, or was delivered. */
    private function orderStatusUpdate(array $p): array
    {
        $num = (string) $p['order_number'];
        $delivered = ($p['stage'] ?? '') === 'delivered';
        $subject = $delivered ? "Order {$num} has been delivered" : "Order {$num} is being prepared";
        $heading = $delivered ? 'Your order has been delivered' : 'We are preparing your order';
        $line = $delivered
            ? 'Order <strong>' . $this->e($num) . '</strong> has been delivered. We hope you love it. If anything is wrong, reply to this email or contact us.'
            : 'Order <strong>' . $this->e($num) . '</strong> is being packed. We will email you the tracking details as soon as it ships.';
        $html = $this->layout(
            $heading,
            '<p style="margin:0 0 12px">Hi ' . $this->e($this->firstName($p)) . ',</p>'
            . '<p style="margin:0 0 16px">' . $line . '</p>'
            . $this->itemsTable($p)
            . '<p style="margin:16px 0 0"><a href="' . $this->e($this->trackUrl($num)) . '" style="color:#000000">See your order</a></p>'
            . ($delivered ? $this->contactLine() : '')
        );
        $text = "Hi {$this->firstName($p)},\n\n"
            . strip_tags(str_replace(['<strong>', '</strong>'], '', $line)) . "\n\n"
            . $this->itemsText($p)
            . "\nSee your order: {$this->trackUrl($num)}\n"
            . $this->footerText();
        return compact('subject', 'html', 'text');
    }

    /** To the admins: the database is big or slow, or old data waits for review (daily check). */
    private function storageAlert(array $p): array
    {
        $critical = ($p['level'] ?? '') === 'critical';
        $subject = $critical ? 'Your store database needs attention now' : 'Your store database needs attention';
        $adminUrl = $this->frontendUrl . '/admin/data';
        $issues = is_array($p['issues'] ?? null) ? $p['issues'] : [];

        $list = '';
        $text = '';
        foreach ($issues as $issue) {
            $title = (string) ($issue['title'] ?? '');
            $detail = (string) ($issue['detail'] ?? '');
            $list .= '<li style="margin:0 0 8px"><strong>' . $this->e($title) . '</strong><br>' . $this->e($detail) . '</li>';
            $text .= "- {$title}: {$detail}\n";
        }

        $html = $this->layout(
            $critical ? 'Database problem' : 'Database check',
            '<p style="margin:0 0 12px">The daily check of your store database found:</p>'
            . '<ul style="margin:0 0 16px;padding-left:20px">' . $list . '</ul>'
            . '<p style="margin:0 0 16px">Nothing has been removed. Open Data &amp; storage in admin to review the details; old data is only removed when you confirm a clean-up there.</p>'
            . $this->button($adminUrl, 'Open Data & storage')
        );

        $text = "The daily check of your store database found:\n\n{$text}\nNothing has been removed. Review the details in Data & storage: {$adminUrl}\n" . $this->footerText();

        return compact('subject', 'html', 'text');
    }

    /** To one admin: the code that confirms a data clean-up they asked for. */
    private function adminConfirmCode(array $p): array
    {
        $code = (string) ($p['code'] ?? '');
        $summary = is_array($p['summary'] ?? null) ? $p['summary'] : [];
        $expires = !empty($p['expires_at']) ? $this->dateTime((string) $p['expires_at']) : '';
        // Never the code itself: subjects are listed in admin (Email outbox), where anyone signed in
        // to admin could read it, and the code exists to prove access to this inbox.
        $subject = 'Your clean-up confirmation code';

        $rows = [];
        foreach ($summary as $label => $count) {
            $rows[] = [(string) $label, number_format((int) $count)];
        }

        $html = $this->layout(
            'Confirm the data clean-up',
            '<p style="margin:0 0 12px">Hi ' . $this->e(trim((string) ($p['name'] ?? '')) ?: 'there') . ',</p>'
            . '<p style="margin:0 0 16px">Your admin account asked to permanently delete old data from the store. Type this code in admin to confirm'
            . ($expires !== '' ? ' (it works until <strong>' . $this->e($expires) . '</strong>, once)' : '') . ':</p>'
            . '<p style="display:inline-block;margin:0 0 20px;padding:14px 28px;background:#F0F0F0;border-radius:20px;font-size:30px;font-weight:700;letter-spacing:8px;font-family:Menlo,Consolas,monospace">' . $this->e($code) . '</p>'
            . $this->keyValueTable($rows)
            . '<p style="margin:0 0 16px;color:#FF3333"><strong>If this wasn\'t you, don\'t share the code: change your admin password now.</strong></p>'
        );

        $text = "Your admin account asked to permanently delete old data from the store.\n"
            . "Confirmation code: {$code}" . ($expires !== '' ? " (works until {$expires}, once)" : '') . "\n\n"
            . $this->keyValueText($rows)
            . "\nIf this wasn't you, don't share the code: change your admin password now.\n"
            . $this->footerText();

        return compact('subject', 'html', 'text');
    }

    /** To every admin: a data clean-up has run (a security notice). */
    private function dataCleanupDone(array $p): array
    {
        $removed = is_array($p['removed'] ?? null) ? $p['removed'] : [];
        $subject = 'Old data was removed from your store';
        $rows = [
            ['By', (string) ($p['admin_name'] ?? '')],
            ['When', !empty($p['at']) ? $this->dateTime((string) $p['at']) : ''],
            ['From IP address', (string) ($p['ip'] ?? '')],
        ];
        $removedRows = [];
        foreach ($removed as $label => $count) {
            $removedRows[] = [(string) $label, number_format((int) $count)];
        }

        $html = $this->layout(
            'Data clean-up done',
            '<p style="margin:0 0 16px">A data clean-up was confirmed with a password and an emailed code.</p>'
            . $this->keyValueTable($rows)
            . ($removedRows !== [] ? '<p style="margin:0 0 8px;font-weight:bold">Removed</p>' . $this->keyValueTable($removedRows) : '<p style="margin:0 0 16px">Nothing needed removing.</p>')
            . '<p style="margin:0 0 16px">If you don\'t recognise this, change the admin passwords now and check the audit log.</p>'
            . $this->button($this->frontendUrl . '/admin/audit-logs', 'Open the audit log')
        );

        $text = "A data clean-up was confirmed with a password and an emailed code.\n\n"
            . $this->keyValueText($rows)
            . ($removedRows !== [] ? "\nRemoved:\n" . $this->keyValueText($removedRows) : "\nNothing needed removing.\n")
            . "\nIf you don't recognise this, change the admin passwords now and check the audit log.\n"
            . $this->footerText();

        return compact('subject', 'html', 'text');
    }

    private function refundProcessed(array $p): array
    {
        $num = (string) $p['order_number'];
        $amount = $this->money($p['amount'] ?? 0, $p['currency'] ?? 'NGN');
        $subject = "Refund of {$amount} for order {$num}";
        $reference = trim((string) ($p['refund_reference'] ?? ''));

        $rows = [
            ['Amount refunded', $amount],
            ['Order', $num],
            ['Refund reference', $reference],
        ];

        $html = $this->layout(
            'Refund sent',
            '<p style="margin:0 0 12px">Hi ' . $this->e($this->firstName($p)) . ',</p>'
            . '<p style="margin:0 0 16px">We have sent you a refund. Depending on your bank it can take a few business days to show in your account.</p>'
            . $this->keyValueTable($rows)
            . $this->contactLine()
        );

        $text = "Hi {$this->firstName($p)},\n\nWe have sent you a refund. Depending on your bank it can take a few business days to show in your account.\n\n"
            . $this->keyValueText($rows)
            . $this->footerText();

        return compact('subject', 'html', 'text');
    }

    private function marketingBroadcast(array $p): array
    {
        $subject = (string) $p['subject'];
        $unsubscribe = (string) ($p['unsubscribe_url'] ?? '');
        $content = (string) $p['content'];

        $paragraphs = array_filter(array_map('trim', preg_split("/\R{2,}/", $content) ?: []));
        $htmlBody = '';
        foreach ($paragraphs as $para) {
            $htmlBody .= '<p style="margin:0 0 14px">' . nl2br($this->e($para)) . '</p>';
        }

        $why = ($p['reason'] ?? '') === 'customer'
            ? 'you have an account with ' . $this->e($this->store['name'])
            : 'you subscribed to the ' . $this->e($this->store['name']) . ' newsletter';
        $footer = $unsubscribe !== ''
            ? '<p style="margin:16px 0 0;font-size:12px;color:#666666">You are receiving this because ' . $why . '. <a href="' . $this->e($unsubscribe) . '" style="color:#666666">Unsubscribe</a></p>'
            : '';

        $html = $this->layout($subject, $htmlBody . $this->button($this->frontendUrl . '/category', 'Visit the store') . $footer);
        $text = $content . "\n\nVisit the store: {$this->frontendUrl}/category\n"
            . ($unsubscribe !== '' ? "\nUnsubscribe: {$unsubscribe}\n" : '')
            . $this->footerText();

        return compact('subject', 'html', 'text');
    }

    private function magicLink(array $p): array
    {
        $link = (string) $p['link'];
        $minutes = (int) ($p['ttl_minutes'] ?? 15);
        $subject = "Your sign-in link for {$this->store['name']}";

        $html = $this->layout(
            'Sign in',
            '<p style="margin:0 0 16px">Use the button below to sign in. The link works once and expires in ' . $minutes . ' minutes.</p>'
            . $this->button($link, 'Sign in')
            . '<p style="margin:16px 0 0;font-size:12px;color:#666666">If you did not ask for this, you can ignore this email.</p>'
        );
        $text = "Sign in to {$this->store['name']}:\n{$link}\n\nThe link works once and expires in {$minutes} minutes.\nIf you did not ask for this, you can ignore this email.\n";

        return compact('subject', 'html', 'text');
    }

    // -------------------------------------------------------------------------
    // Building blocks
    // -------------------------------------------------------------------------

    /*
     * The look follows the storefront: white page cards on #F0F0F0, the store's wordmark in its accent colour,
     * bold uppercase headings (Space Grotesk where the mail app loads it, else a heavy Arial),
     * 20px rounded panels with a faint black border, accent pill buttons and red (#FF3333) for
     * discounts and warnings. Tables and inline styles only, because mail apps drop most CSS.
     */
    private const HEADING_FONT = "'Space Grotesk','Arial Black',Arial,Helvetica,sans-serif";
    private const BODY_FONT = "'Helvetica Neue',Helvetica,Arial,sans-serif";

    private function layout(string $heading, string $body): string
    {
        $name = $this->e($this->store['name']);
        $headingE = $this->e($heading);
        $footer = $this->footerHtml();
        $home = $this->e($this->frontendUrl !== '' ? $this->frontendUrl : '#');
        $hf = self::HEADING_FONT;
        $bf = self::BODY_FONT;
        $accent = $this->accent;
        // The grey preview line under the subject in most inboxes: the first sentences, not the greeting.
        $preview = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>'], ' ', $body)), ENT_QUOTES, 'UTF-8')));
        $preview = $this->e(mb_substr((string) preg_replace('/^Hi [^,]{1,60},\s*/u', '', $preview), 0, 140));

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">
<title>{$headingE}</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@700&display=swap" rel="stylesheet">
</head>
<body style="margin:0;padding:0;background:#F0F0F0;font-family:{$bf};color:#000000;-webkit-text-size-adjust:100%">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#F0F0F0">{$preview}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F0F0F0">
  <tr><td align="center" style="padding:32px 12px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px">
      <tr><td style="background:#ffffff;border:1px solid #E6E6E6;border-radius:20px">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
          <tr><td style="padding:24px 28px;border-bottom:1px solid #E6E6E6">
            <a href="{$home}" style="font-family:{$hf};font-size:28px;font-weight:700;letter-spacing:-1px;text-transform:uppercase;color:{$accent};text-decoration:none">{$name}</a>
          </td></tr>
          <tr><td style="padding:28px;font-size:16px;line-height:1.55;color:#000000">
            <h1 style="margin:0 0 18px;font-family:{$hf};font-size:28px;line-height:1.1;font-weight:700;text-transform:uppercase;letter-spacing:-0.5px;color:#000000">{$headingE}</h1>
            {$body}
          </td></tr>
        </table>
      </td></tr>
      <tr><td style="padding:28px 16px 8px;text-align:center;font-size:13px;line-height:1.6;color:#666666">{$footer}</td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
    }

    private function footerHtml(): string
    {
        $hf = self::HEADING_FONT;
        $name = $this->e($this->store['name']);
        $contact = [];
        if ($this->store['email'] !== '') {
            $contact[] = '<a href="mailto:' . $this->e($this->store['email']) . '" style="color:#666666">' . $this->e($this->store['email']) . '</a>';
        }
        if ($this->store['phone'] !== '') {
            $contact[] = $this->e($this->store['phone']);
        }
        if ($this->store['whatsapp'] !== '' && $this->store['whatsapp'] !== $this->store['phone']) {
            $contact[] = 'WhatsApp ' . $this->e($this->store['whatsapp']);
        }
        // The line under the name comes from config/shop.json; a shop without one has no such line.
        $tagline = trim(Shop::tagline());
        return '<div style="font-family:' . $hf . ';font-size:20px;font-weight:700;letter-spacing:-0.5px;text-transform:uppercase;color:' . $this->accent . '">' . $name . '</div>'
            . ($tagline !== '' ? '<div style="margin:6px 0 0">' . $this->e($tagline) . '</div>' : '')
            . ($contact !== [] ? '<div style="margin:8px 0 0">' . implode(' &middot; ', $contact) . '</div>' : '')
            . '<div style="margin:12px 0 0;color:#999999">&copy; ' . date('Y') . ' ' . $name . '. All rights reserved.</div>';
    }

    private function footerText(): string
    {
        $parts = array_filter([$this->store['name'], $this->store['email'], $this->store['phone']]);
        return "\n--\n" . implode(' · ', $parts) . "\n";
    }

    private function contactLine(): string
    {
        $ways = [];
        if ($this->store['email'] !== '') {
            $ways[] = 'email <a href="mailto:' . $this->e($this->store['email']) . '" style="color:#000000">' . $this->e($this->store['email']) . '</a>';
        }
        if ($this->store['whatsapp'] !== '') {
            $ways[] = 'WhatsApp ' . $this->e($this->store['whatsapp']);
        }
        return $ways === [] ? '' : '<p style="margin:16px 0 0;color:#666666">Questions? ' . implode(' or ', $ways) . '.</p>';
    }

    private function button(string $url, string $label): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 0"><tr><td style="background:' . $this->accent . ';border-radius:62px">'
            . '<a href="' . $this->e($url) . '" style="display:inline-block;padding:16px 40px;color:' . $this->accentInk . ';text-decoration:none;font-weight:600;font-size:15px;border-radius:62px">' . $this->e($label) . '</a>'
            . '</td></tr></table>';
    }

    /** A section title inside the email, like the storefront's "Order Summary". */
    private function sectionTitle(string $title): string
    {
        return '<p style="margin:24px 0 10px;font-family:' . self::HEADING_FONT . ';font-size:18px;font-weight:700;color:#000000">' . $this->e($title) . '</p>';
    }

    /** The rounded, faintly bordered panel the storefront uses for the cart and order summary. */
    private function panel(string $inner, bool $grey = false): string
    {
        $style = $grey ? 'background:#F0F0F0;border-radius:20px' : 'background:#ffffff;border:1px solid #E6E6E6;border-radius:20px';
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;' . $style . '"><tr><td style="padding:8px 18px">'
            . $inner . '</td></tr></table>';
    }

    private function itemsTable(array $p): string
    {
        $items = is_array($p['items'] ?? null) ? $p['items'] : [];
        if ($items === []) {
            return '';
        }
        $currency = (string) ($p['currency'] ?? 'NGN');
        $rows = '';
        $last = count($items) - 1;
        foreach (array_values($items) as $i => $item) {
            $options = trim((string) ($item['options'] ?? ''));
            $warranty = trim((string) ($item['warranty'] ?? ''));
            $line = $i === $last ? '' : 'border-bottom:1px solid #E6E6E6;';
            $rows .= '<tr>'
                . '<td style="padding:14px 0;' . $line . 'vertical-align:top"><span style="font-weight:700;font-size:15px">' . $this->e((string) $item['name']) . '</span>'
                . ($options !== '' ? '<br><span style="color:#666666;font-size:13px">' . $this->e($options) . '</span>' : '')
                . ($warranty !== '' ? '<br><span style="color:#666666;font-size:13px">Warranty: ' . $this->e($warranty) . '</span>' : '')
                . '</td>'
                . '<td style="padding:14px 8px;' . $line . 'text-align:center;white-space:nowrap;vertical-align:top;color:#666666;font-size:14px">&times; ' . (int) $item['quantity'] . '</td>'
                . '<td style="padding:14px 0;' . $line . 'text-align:right;white-space:nowrap;vertical-align:top;font-weight:700;font-size:15px">' . $this->e($this->money($item['line_total'], $currency)) . '</td>'
                . '</tr>';
        }
        return $this->sectionTitle('Your items')
            . $this->panel('<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>');
    }

    private function itemsText(array $p): string
    {
        $items = is_array($p['items'] ?? null) ? $p['items'] : [];
        $out = '';
        foreach ($items as $item) {
            $options = trim((string) ($item['options'] ?? ''));
            $warranty = trim((string) ($item['warranty'] ?? ''));
            $out .= '- ' . $item['name'] . ($options !== '' ? " ({$options})" : '') . ' x' . (int) $item['quantity']
                . '  ' . $this->money($item['line_total'], (string) ($p['currency'] ?? 'NGN')) . "\n"
                . ($warranty !== '' ? "  Warranty: {$warranty}\n" : '');
        }
        return $out === '' ? '' : $out . "\n";
    }

    /** @return list<array{0: string, 1: string}> */
    private function totalRows(array $p, string $totalLabel): array
    {
        $c = (string) ($p['currency'] ?? 'NGN');
        $rows = [['Subtotal', $this->money($p['subtotal'] ?? 0, $c)]];
        if ((float) ($p['discount_amount'] ?? 0) > 0) {
            $label = !empty($p['promo_code']) ? "Discount ({$p['promo_code']})" : 'Discount';
            $rows[] = [$label, '−' . $this->money($p['discount_amount'], $c)];
        }
        $delivery = 'Delivery' . (!empty($p['zone_name']) ? " ({$p['zone_name']})" : '');
        $rows[] = [$delivery, $this->money($p['shipping_fee'] ?? 0, $c)];
        $rows[] = [$totalLabel, $this->money($p['grand_total'] ?? 0, $c)];
        return $rows;
    }

    private function totalsTable(array $p, string $totalLabel): string
    {
        $rows = $this->totalRows($p, $totalLabel);
        $html = '';
        $last = count($rows) - 1;
        foreach ($rows as $i => [$label, $value]) {
            if ($i === $last) {
                $cell = 'padding:14px 0 12px;border-top:1px solid #E6E6E6;';
                $html .= '<tr><td style="' . $cell . 'font-size:17px;color:#000000">' . $this->e($label) . '</td>'
                    . '<td style="' . $cell . 'text-align:right;font-size:20px;font-weight:700;color:#000000;white-space:nowrap">' . $this->e($value) . '</td></tr>';
                continue;
            }
            // Discounts are red, as on the storefront's order summary.
            $valueColour = str_starts_with($value, '−') ? '#FF3333' : '#000000';
            $html .= '<tr><td style="padding:8px 0;font-size:15px;color:#666666">' . $this->e($label) . '</td>'
                . '<td style="padding:8px 0;text-align:right;font-size:15px;font-weight:700;color:' . $valueColour . ';white-space:nowrap">' . $this->e($value) . '</td></tr>';
        }
        return $this->sectionTitle('Order summary')
            . $this->panel('<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $html . '</table>');
    }

    private function totalsText(array $p, string $totalLabel): string
    {
        return $this->keyValueText($this->totalRows($p, $totalLabel));
    }

    /** @param list<array{0: string, 1: string}> $rows */
    private function keyValueTable(array $rows): string
    {
        $html = '';
        foreach ($rows as [$label, $value]) {
            if (trim($value) === '') {
                continue;
            }
            $html .= '<tr><td style="padding:7px 12px 7px 0;color:#666666;font-size:14px;vertical-align:top">' . $this->e($label)
                // Short values (order numbers, amounts, times) stay on one line; long ones may wrap.
                . '</td><td style="padding:7px 0;font-weight:700;font-size:14px;text-align:right;' . (mb_strlen($value) <= 22 ? 'white-space:nowrap' : 'word-break:break-word') . '">' . $this->e($value) . '</td></tr>';
        }
        return $html === '' ? '' : $this->panel('<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0">' . $html . '</table>', true);
    }

    /** @param list<array{0: string, 1: string}> $rows */
    private function keyValueText(array $rows): string
    {
        $out = '';
        foreach ($rows as [$label, $value]) {
            if (trim($value) !== '') {
                $out .= "{$label}: {$value}\n";
            }
        }
        return $out;
    }

    private function addressBlock(array $p): string
    {
        $a = is_array($p['address'] ?? null) ? $p['address'] : [];
        $lines = array_filter([
            $a['full_name'] ?? '',
            $a['address_line1'] ?? '',
            $a['address_line2'] ?? '',
            trim(($a['city'] ?? '') . ', ' . ($a['state'] ?? ''), ', '),
            $a['phone'] ?? '',
        ], static fn ($v) => trim((string) $v) !== '');
        if ($lines === []) {
            return '';
        }
        return $this->sectionTitle('Delivering to')
            . '<p style="margin:0;font-size:15px;line-height:1.6;color:#666666">'
            . implode('<br>', array_map(fn ($l) => $this->e((string) $l), $lines)) . '</p>';
    }

    private function trackUrl(string $orderNumber): string
    {
        return $this->frontendUrl . '/track-order?order=' . rawurlencode($orderNumber);
    }

    private function firstName(array $p): string
    {
        $name = trim((string) ($p['customer_name'] ?? ''));
        return $name === '' ? 'there' : explode(' ', $name)[0];
    }

    private function money(mixed $amount, string $currency): string
    {
        $symbol = strtoupper($currency) === 'NGN' ? '₦' : strtoupper($currency) . ' ';
        return $symbol . number_format((float) $amount, 2);
    }

    private function dateTime(string $value): string
    {
        $ts = strtotime($value);
        // A no-break space keeps "1:42 am" together.
        return $ts === false ? $value : date('j M Y, g:i', $ts) . "\u{00A0}" . date('a', $ts);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
