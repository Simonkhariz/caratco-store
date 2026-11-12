<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\JsonBodyMiddleware;
use App\Repositories\StoreSettingsRepository;
use App\Services\AuthService;
use App\Support\Response;

/**
 * Controller managing one-time first-run administrator onboarding.
 */
class SetupController
{
    private AuthService $authService;
    private StoreSettingsRepository $settingsRepo;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->settingsRepo = new StoreSettingsRepository();
    }

    /**
     * Check if first-run setup is completed or open.
     * Only public storefront details are returned; nothing is invented when unset.
     */
    public function getStatus(): void
    {
        $isCompleted = $this->settingsRepo->isSetupCompleted();
        $settings = $this->settingsRepo->getAll();

        Response::success([
            'is_setup_completed' => $isCompleted,
            'store_name' => $settings['store_name'] ?? null,
            'store_origin' => [
                'city' => $settings['store_origin_city'] ?? null,
                'state' => $settings['store_origin_state'] ?? null,
                'address' => $settings['store_origin_address'] ?? null,
            ],
            'contact_email' => $settings['store_contact_email'] ?? null,
        ], 'Store setup status retrieved.');
    }

    /**
     * Perform initial admin onboarding and lock setup.
     */
    public function setupStore(): void
    {
        $result = $this->authService->setupStore(JsonBodyMiddleware::getBody());

        Response::success($result, 'Administrator onboarding completed successfully. Setup is now locked.', 201);
    }
}
