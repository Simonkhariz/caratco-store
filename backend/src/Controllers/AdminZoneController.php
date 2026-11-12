<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Middleware\JsonBodyMiddleware;
use App\Services\AuditService;
use App\Services\CheckoutService;
use App\Support\Input;
use App\Support\RequestContext;
use App\Support\Response;

/**
 * Admin configuration of delivery zones and fees.
 * A zone covers a list of Nigerian states (or '*' for every state not covered elsewhere).
 */
class AdminZoneController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * GET /api/v1/admin/shipping-zones
     */
    public function index(): void
    {
        $zones = array_map([$this, 'present'], $this->db->fetchAll("SELECT * FROM `shipping_zones` ORDER BY `fee` ASC, `id` ASC"));

        Response::success([
            'zones' => $zones,
            'states' => CheckoutService::NIGERIAN_STATES,
        ], 'Shipping destination zones retrieved.');
    }

    /**
     * GET /api/v1/admin/shipping-zones/{id}
     */
    public function show(array $params): void
    {
        Response::success($this->present($this->find((int) ($params['id'] ?? 0))), 'Shipping zone retrieved.');
    }

    /**
     * POST /api/v1/admin/shipping-zones
     */
    public function create(): void
    {
        $in = $this->input(JsonBodyMiddleware::getBody());

        if ($this->db->fetchOne("SELECT id FROM `shipping_zones` WHERE `code` = ?", 's', [$in['code']]) !== null) {
            Response::error('A shipping zone with this code already exists.', 422, 'VALIDATION_ERROR', ['code' => 'Code already in use.']);
        }

        $id = (int) $this->db->insert(
            "INSERT INTO `shipping_zones` (`name`, `code`, `description`, `states_covered`, `fee`, `items_included`, `extra_item_fee`, `heavy_item_fee`, `estimated_days`, `is_active`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            'ssssdiddsi',
            [$in['name'], $in['code'], $in['description'], json_encode($in['states_covered']), $in['fee'], $in['items_included'], $in['extra_item_fee'], $in['heavy_item_fee'], $in['estimated_days'], $in['is_active']]
        );

        $created = $this->present($this->find($id));
        AuditService::record($this->adminId(), 'shipping_zone.create', 'shipping_zone', (string) $id, null, $created);
        Response::success($created, 'Delivery zone created.', 201);
    }

    /**
     * PUT /api/v1/admin/shipping-zones/{id}
     */
    public function update(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $before = $this->present($this->find($id));
        $in = $this->input(JsonBodyMiddleware::getBody(), $before);

        if ($this->db->fetchOne("SELECT id FROM `shipping_zones` WHERE `code` = ? AND `id` <> ?", 'si', [$in['code'], $id]) !== null) {
            Response::error('A shipping zone with this code already exists.', 422, 'VALIDATION_ERROR', ['code' => 'Code already in use.']);
        }

        $this->db->execute(
            "UPDATE `shipping_zones`
             SET `name` = ?, `code` = ?, `description` = ?, `states_covered` = ?, `fee` = ?, `items_included` = ?, `extra_item_fee` = ?, `heavy_item_fee` = ?, `estimated_days` = ?, `is_active` = ?
             WHERE `id` = ?",
            'ssssdiddsii',
            [$in['name'], $in['code'], $in['description'], json_encode($in['states_covered']), $in['fee'], $in['items_included'], $in['extra_item_fee'], $in['heavy_item_fee'], $in['estimated_days'], $in['is_active'], $id]
        );

        $updated = $this->present($this->find($id));
        AuditService::record($this->adminId(), 'shipping_zone.update', 'shipping_zone', (string) $id, $before, $updated);
        Response::success($updated, 'Delivery zone updated.');
    }

    /**
     * DELETE /api/v1/admin/shipping-zones/{id}
     * Orders keep their own fee and zone snapshot, so deleting a zone never changes past orders.
     */
    public function delete(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $before = $this->present($this->find($id));

        $this->db->execute("DELETE FROM `shipping_zones` WHERE `id` = ?", 'i', [$id]);
        AuditService::record($this->adminId(), 'shipping_zone.delete', 'shipping_zone', (string) $id, $before, null);
        Response::success(['id' => $id], 'Delivery zone deleted.');
    }

    // -------------------------------------------------------------------------

    private function find(int $id): array
    {
        $zone = $this->db->fetchOne("SELECT * FROM `shipping_zones` WHERE `id` = ?", 'i', [$id]);
        if ($zone === null) {
            Response::error('Shipping zone not found.', 404, 'ZONE_NOT_FOUND');
        }
        return $zone;
    }

    private function present(array $zone): array
    {
        $zone['id'] = (int) $zone['id'];
        $zone['states_covered'] = is_string($zone['states_covered'])
            ? (json_decode($zone['states_covered'], true) ?: [])
            : (array) $zone['states_covered'];
        $zone['fee'] = (float) $zone['fee'];
        $zone['items_included'] = max(1, (int) ($zone['items_included'] ?? 1));
        $zone['extra_item_fee'] = (float) ($zone['extra_item_fee'] ?? 0);
        $zone['heavy_item_fee'] = (float) ($zone['heavy_item_fee'] ?? 0);
        $zone['is_active'] = (bool) $zone['is_active'];
        return $zone;
    }

    /**
     * Validate zone input; on update, missing fields keep their current values.
     *
     * @return array{name: string, code: string, description: string, states_covered: list<string>, fee: float, items_included: int, extra_item_fee: float, heavy_item_fee: float, estimated_days: string, is_active: int}
     */
    private function input(array $body, ?array $current = null): array
    {
        $str = static fn (string $key, string $default = ''): string => array_key_exists($key, $body)
            ? Input::text($body[$key])
            : (string) ($current[$key] ?? $default);

        $name = $str('name');
        $code = strtoupper($str('code'));
        $description = $str('description');
        $estimatedDays = $str('estimated_days', '2-4 business days');
        $feeRaw = array_key_exists('fee', $body) ? $body['fee'] : ($current['fee'] ?? null);
        $includedRaw = array_key_exists('items_included', $body) ? $body['items_included'] : ($current['items_included'] ?? 1);
        $extraRaw = array_key_exists('extra_item_fee', $body) ? $body['extra_item_fee'] : ($current['extra_item_fee'] ?? 0);
        // Charged for each heavy item, in shops that deliver them (config/shop.json, features.heavyDelivery).
        $heavyRaw = array_key_exists('heavy_item_fee', $body) ? $body['heavy_item_fee'] : ($current['heavy_item_fee'] ?? 0);
        $statesRaw = array_key_exists('states_covered', $body) ? $body['states_covered'] : ($current['states_covered'] ?? []);
        $isActive = array_key_exists('is_active', $body) ? (int) (bool) $body['is_active'] : (int) ($current['is_active'] ?? 1);

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (mb_strlen($name) > 100) {
            $errors['name'] = 'Keep the name to 100 characters.';
        }
        if (!preg_match('/^[A-Z0-9_-]{2,30}$/', $code)) {
            $errors['code'] = 'Use 2-30 letters, numbers, dashes or underscores.';
        }
        if (!is_numeric($feeRaw) || (float) $feeRaw < 0 || (float) $feeRaw > 1000000) {
            $errors['fee'] = 'Enter a delivery fee from ₦0 to ₦1,000,000.';
        }
        if (!is_numeric($includedRaw) || (int) $includedRaw < 1 || (int) $includedRaw > 1000 || (float) $includedRaw != (int) $includedRaw) {
            $errors['items_included'] = 'Enter how many items the fee covers (1 or more).';
        }
        if (!is_numeric($extraRaw) || (float) $extraRaw < 0 || (float) $extraRaw > 1000000) {
            $errors['extra_item_fee'] = 'Enter ₦0 to ₦1,000,000 for each extra item.';
        }
        if (!is_numeric($heavyRaw) || (float) $heavyRaw < 0 || (float) $heavyRaw > 1000000) {
            $errors['heavy_item_fee'] = 'Enter ₦0 to ₦1,000,000 for each heavy item.';
        }
        if ($estimatedDays === '') {
            $errors['estimated_days'] = 'Give customers a delivery estimate, e.g. "2-4 business days".';
        } elseif (mb_strlen($estimatedDays) > 50) {
            $errors['estimated_days'] = 'Keep the delivery estimate to 50 characters.';
        }

        $states = [];
        if (!is_array($statesRaw) || $statesRaw === []) {
            $errors['states_covered'] = 'Pick at least one state.';
        } else {
            $unknown = [];
            foreach ($statesRaw as $state) {
                $state = Input::text($state);
                if ($state === '*') {
                    $states[] = '*';
                    continue;
                }
                $canonical = CheckoutService::canonicalState($state);
                if ($canonical === null) {
                    $unknown[] = $state;
                } else {
                    $states[] = $canonical;
                }
            }
            if ($unknown !== []) {
                $errors['states_covered'] = 'Not Nigerian states: ' . implode(', ', $unknown) . '.';
            }
            $states = array_values(array_unique($states));
        }

        if ($errors !== []) {
            Response::error('Please fix the highlighted fields.', 422, 'VALIDATION_ERROR', $errors);
        }

        if ($isActive === 1) {
            $conflict = $this->coverageConflict($states, (int) ($current['id'] ?? 0));
            if ($conflict !== null) {
                Response::error($conflict, 422, 'ZONE_OVERLAP', ['states_covered' => $conflict]);
            }
        }

        return [
            'name' => $name,
            'code' => $code,
            'description' => $description,
            'states_covered' => $states,
            'fee' => round((float) $feeRaw, 2),
            'items_included' => (int) $includedRaw,
            'extra_item_fee' => round((float) $extraRaw, 2),
            'heavy_item_fee' => round((float) $heavyRaw, 2),
            'estimated_days' => $estimatedDays,
            'is_active' => $isActive,
        ];
    }

    /**
     * Checkout charges the first active zone that covers a state, so a second one would show as
     * active and never be used. Says which zone already covers these states, or null if none does.
     *
     * @param list<string> $states
     */
    private function coverageConflict(array $states, int $zoneId): ?string
    {
        $canonical = static fn (string $state): string => $state === '*' ? '*' : (CheckoutService::canonicalState($state) ?? $state);
        foreach ($this->db->fetchAll("SELECT `id`, `name`, `states_covered` FROM `shipping_zones` WHERE `is_active` = 1 AND `id` <> ? ORDER BY `id` ASC", 'i', [$zoneId]) as $zone) {
            $covered = array_map(static fn ($s): string => $canonical(trim((string) $s)), (array) json_decode((string) $zone['states_covered'], true));
            $overlap = array_values(array_intersect($states, $covered));
            if ($overlap !== []) {
                $names = implode(', ', array_map(static fn (string $s): string => $s === '*' ? 'All other states' : $s, $overlap));
                return "{$names} " . (count($overlap) === 1 ? 'is' : 'are') . " already covered by the active zone \"{$zone['name']}\". Remove " . (count($overlap) === 1 ? 'it' : 'them') . ' from this zone, or switch that zone off first.';
            }
        }
        return null;
    }

    private function adminId(): ?int
    {
        $admin = RequestContext::getUser();
        return $admin ? (int) $admin['id'] : null;
    }
}
