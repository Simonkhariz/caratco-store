<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Database;

/**
 * Which payment methods customers can choose at checkout.
 *
 * A method is offered only when both switches are on and it is set up:
 *  - the server allows it (PAYMENT_<NAME>_ENABLED in backend/.env, default on),
 *  - the admin has not switched it off in Store settings (store_settings payment_<name>_enabled), and
 *  - it can work: see configured().
 */
final class PaymentMethods
{
    public const ALL = ['monnify', 'kora', 'whatsapp'];

    public static function settingKey(string $method): string
    {
        return "payment_{$method}_enabled";
    }

    public static function serverAllows(string $method): bool
    {
        return in_array($method, self::ALL, true)
            && filter_var(Env::get('PAYMENT_' . strtoupper($method) . '_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Admin switches, keyed by method (missing setting = on).
     *
     * @return array<string, bool>
     */
    public static function adminSwitches(?Database $db = null): array
    {
        $db = $db ?? Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT `key_name`, `value` FROM `store_settings`
             WHERE `key_name` IN ('payment_monnify_enabled', 'payment_kora_enabled', 'payment_whatsapp_enabled')"
        );
        $stored = array_column($rows, 'value', 'key_name');

        $switches = [];
        foreach (self::ALL as $method) {
            $switches[$method] = ($stored[self::settingKey($method)] ?? '1') !== '0';
        }
        return $switches;
    }

    public static function isEnabled(string $method, ?Database $db = null): bool
    {
        return self::serverAllows($method) && (self::adminSwitches($db)[$method] ?? false) && self::configured($method);
    }

    /**
     * Status of every method for the admin screen.
     *
     * @return array<string, array{enabled: bool, admin_on: bool, server_allows: bool}>
     */
    public static function status(?Database $db = null): array
    {
        $switches = self::adminSwitches($db);
        $status = [];
        foreach (self::ALL as $method) {
            $server = self::serverAllows($method);
            $status[$method] = [
                'enabled' => $server && $switches[$method],
                'admin_on' => $switches[$method],
                'server_allows' => $server,
            ];
        }
        return $status;
    }

    /** Store setting naming the gateway "Pay now" uses. */
    public const PRIMARY_KEY = 'payment_primary_gateway';
    public const GATEWAYS = ['monnify', 'kora'];

    /** The gateway the admin chose for "Pay now" (Monnify unless set to Kora). */
    public static function primaryGateway(?Database $db = null): string
    {
        $db = $db ?? Database::getInstance();
        $value = (string) ($db->fetchOne("SELECT `value` FROM `store_settings` WHERE `key_name` = ?", 's', [self::PRIMARY_KEY])['value'] ?? '');
        return in_array($value, self::GATEWAYS, true) ? $value : 'monnify';
    }

    /**
     * The method has what it needs to work: a gateway's keys in backend/.env (the same test admin
     * Settings shows), and for WhatsApp a number to send the customer to.
     */
    public static function configured(string $gateway): bool
    {
        return match ($gateway) {
            'kora' => trim((string) Env::get('KORA_SECRET_KEY', '')) !== '',
            'monnify' => trim((string) Env::get('MONNIFY_API_KEY', '')) !== '' && trim((string) Env::get('MONNIFY_CONTRACT_CODE', '')) !== '',
            'whatsapp' => CheckoutService::supportWhatsAppPhone() !== '',
            default => true,
        };
    }

    /**
     * The gateway behind the storefront's single "Pay now" button: the admin's choice when it is
     * on and set up, otherwise the other gateway when that one is, otherwise none (WhatsApp only).
     * A gateway without keys is never offered: its button could only fail.
     */
    public static function payNowGateway(?Database $db = null): ?string
    {
        $status = self::status($db);
        $usable = static fn (string $g): bool => $status[$g]['enabled'] && self::configured($g);
        $primary = self::primaryGateway($db);
        if ($usable($primary)) {
            return $primary;
        }
        foreach (self::GATEWAYS as $gateway) {
            if ($usable($gateway)) {
                return $gateway;
            }
        }
        return null;
    }
}
