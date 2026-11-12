<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Repositories\SessionRepository;
use App\Repositories\StoreSettingsRepository;
use App\Repositories\TokenRepository;
use App\Repositories\UserRepository;
use App\Support\Input;
use App\Support\PhoneNumber;
use App\Support\RateLimiter;
use App\Support\RequestContext;
use App\Support\Response;
use App\Support\SessionCookie;

/**
 * Service orchestrating authentication, sessions, security tokens, and customer accounts.
 *
 * Session tokens are only ever delivered as HTTP-only cookies; API responses carry the
 * user profile and the per-session CSRF token, never the session token itself.
 */
class AuthService
{
    /** Failed sign-ins allowed from one connection in 15 minutes, whichever emails it tries. */
    private const LOGIN_IP_LIMIT = 50;

    /** Same cost as real passwords: checked when there is no password, so both take equally long. */
    private const DUMMY_PASSWORD_HASH = '$2y$12$ATSd8EiXKf2SP2G.MdtZkevacF1QwE2x7tSJoq/4oWlfJfD0HGh06';

    private UserRepository $userRepo;
    private SessionRepository $sessionRepo;
    private TokenRepository $tokenRepo;
    private StoreSettingsRepository $settingsRepo;
    private Database $db;

    public function __construct()
    {
        $this->userRepo = new UserRepository();
        $this->sessionRepo = new SessionRepository();
        $this->tokenRepo = new TokenRepository();
        $this->settingsRepo = new StoreSettingsRepository();
        $this->db = Database::getInstance();
    }

    /**
     * One-time protected setup flow for the single initial admin.
     */
    public function setupStore(array $input): array
    {
        RateLimiter::attempt('setup', RequestContext::clientIp(), 10, 3600);

        if ($this->settingsRepo->isSetupCompleted()) {
            Response::error(
                'First-run admin onboarding is already completed. Public setup is permanently closed.',
                403,
                'SETUP_ALREADY_COMPLETED'
            );
        }

        $name = trim((string) ($input['name'] ?? $input['full_name'] ?? ''));
        $email = trim(strtolower((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $storeName = trim((string) ($input['store_name'] ?? ''));
        $originCity = trim((string) ($input['origin_city'] ?? ''));
        $originState = trim((string) ($input['origin_state'] ?? ''));
        $originAddress = trim((string) ($input['origin_address'] ?? ''));
        $contactEmail = trim(strtolower((string) ($input['contact_email'] ?? $email)));
        $contactPhone = trim((string) ($input['contact_phone'] ?? ''));

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Admin full name is required.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }
        if (strlen($password) < 10) {
            $errors['password'] = 'Password must be at least 10 characters long.';
        } elseif (str_contains($password, "\0")) {
            $errors['password'] = 'Password contains a character that cannot be used.';
        }
        if ($storeName === '') {
            $errors['store_name'] = 'Store name is required.';
        }
        if ($originAddress === '' || $originCity === '' || $originState === '') {
            $errors['origin_address'] = 'Store origin address, city and state are required.';
        }
        if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'Contact email must be a valid email address.';
        }

        if (!empty($errors)) {
            Response::error('Setup validation failed.', 422, 'VALIDATION_ERROR', $errors);
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $createdAdminId = 0;

        $this->db->transaction(function (Database $db) use (
            $name, $email, $passwordHash, $storeName, $originCity, $originState, $originAddress, $contactEmail, $contactPhone, &$createdAdminId
        ) {
            // Lock the admin rows so two concurrent setup requests cannot both create an admin.
            $existingAdmin = $db->fetchOne(
                "SELECT `id` FROM `users` WHERE `role` IN ('admin', 'super_admin') LIMIT 1 FOR UPDATE"
            );
            if ($existingAdmin !== null) {
                Response::error(
                    'First-run admin onboarding is already completed. Public setup is permanently closed.',
                    403,
                    'SETUP_ALREADY_COMPLETED'
                );
            }

            $createdAdminId = (int) $db->insert(
                "INSERT INTO `users` (`uuid`, `email`, `password_hash`, `full_name`, `role`, `status`, `email_verified_at`)
                 VALUES (UUID(), ?, ?, ?, 'super_admin', 'active', NOW())",
                'sss',
                [$email, $passwordHash, $name]
            );

            $settings = [
                'store_name' => $storeName,
                'store_origin_city' => $originCity,
                'store_origin_state' => $originState,
                'store_origin_address' => $originAddress,
                'store_contact_email' => $contactEmail,
                'store_contact_phone' => $contactPhone,
                'admin_onboarded_email' => $email,
                'is_setup_completed' => '1',
            ];

            foreach ($settings as $key => $val) {
                $db->execute(
                    "INSERT INTO `store_settings` (`key_name`, `value`) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                    'ss',
                    [$key, $val]
                );
            }

            AuditService::record($createdAdminId, 'store.setup', 'store', 'setup', null, [
                'email' => $email,
                'store_name' => $storeName,
                'origin_city' => $originCity,
                'origin_state' => $originState,
            ]);
        });

        $user = $this->userRepo->findById($createdAdminId);
        return $this->startSession($user, null);
    }

    /**
     * Customer registration. Roles cannot be chosen via public registration.
     */
    public function registerCustomer(array $input): array
    {
        RateLimiter::attempt('register', RequestContext::clientIp(), 10, 3600, 'Too many sign-up attempts. Please try again later.');

        $name = Input::text($input['full_name'] ?? $input['name'] ?? null);
        $email = strtolower(Input::text($input['email'] ?? null));
        $password = is_scalar($input['password'] ?? null) ? (string) $input['password'] : '';
        $phone = isset($input['phone']) ? Input::text($input['phone']) : null;

        $errors = [];
        if ($name === '') {
            $errors['full_name'] = 'Full name is required.';
        } elseif (Input::tooLong($name, 255)) {
            $errors['full_name'] = 'Full name can be at most 255 characters.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters long.';
        } elseif (str_contains($password, "\0")) {
            $errors['password'] = 'Password contains a character that cannot be used.';
        }
        if ($phone !== null && $phone !== '' && !PhoneNumber::isNigerian($phone)) {
            $errors['phone'] = PhoneNumber::NIGERIAN_RULE;
        }

        if (!empty($errors)) {
            Response::error('Registration validation failed.', 422, 'VALIDATION_ERROR', $errors);
        }

        // Saying the address is taken is a known trade-off: the sign-up limit above slows anyone
        // listing addresses, and "use a sign-in link" helps a real customer who forgot.
        $existing = $this->userRepo->findByEmail($email);
        if ($existing !== null) {
            Response::error(
                'An account with this email already exists. Sign in, or use "Email me a sign-in link".',
                409,
                'EMAIL_ALREADY_EXISTS'
            );
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $userId = $this->userRepo->create([
            'email' => $email,
            'password_hash' => $passwordHash,
            'full_name' => $name,
            'phone' => $phone === null ? null : PhoneNumber::stored($phone),
            'role' => 'customer', // strictly customer
            'status' => 'active',
            'email_verified_at' => null,
        ]);

        $user = $this->userRepo->findById($userId);
        return $this->startSession($user, null);
    }

    /**
     * Standard password-based login for customers and administrators.
     * The session cookie scope (customer/admin) follows the account role.
     */
    public function login(string $email, string $password): array
    {
        $email = trim(strtolower($email));
        $ip = RequestContext::clientIp();
        $ipKey = $ip . '|' . $email;

        RateLimiter::guard('login_ip', $ip, self::LOGIN_IP_LIMIT, 900, 'Too many failed sign-in attempts from this connection. Please wait 15 minutes and try again.');

        // The database ignores accents when comparing emails, so "ádmin@..." would load the real
        // admin row under limit counters of its own. Addresses here are plain ASCII (sign-up and
        // setup use this same check), so one that fails it can only be wrong.
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            RateLimiter::hit('login_ip', $ip, 900);
            Response::error('Invalid email or password.', 401, 'INVALID_CREDENTIALS');
        }

        RateLimiter::guard('login_ip_email', $ipKey, 5, 900, 'Too many failed sign-in attempts. Please wait 15 minutes and try again.');
        RateLimiter::guard('login_email', $email, 20, 3600, 'Too many failed sign-in attempts for this account. Please try again later.');

        $user = $this->userRepo->findByEmail($email);
        // Always one password check, so an unknown email takes as long as a wrong password.
        $hash = (string) ($user['password_hash'] ?? '');
        $passwordOk = password_verify($password, $hash !== '' ? $hash : self::DUMMY_PASSWORD_HASH);
        if ($user === null || $hash === '' || !$passwordOk) {
            RateLimiter::hit('login_ip', $ip, 900);
            RateLimiter::hit('login_ip_email', $ipKey, 900);
            RateLimiter::hit('login_email', $email, 3600);
            Response::error('Invalid email or password.', 401, 'INVALID_CREDENTIALS');
        }

        if ($user['status'] === 'suspended') {
            Response::error('Account has been suspended. Please contact customer support.', 403, 'ACCOUNT_SUSPENDED');
        }

        RateLimiter::clear('login_ip_email', $ipKey);

        $scope = SessionCookie::scopeForRole((string) $user['role']);
        $result = $this->startSession($user, SessionCookie::read($scope));
        if ($scope === SessionCookie::SCOPE_ADMIN) {
            AuditService::record((int) $user['id'], 'admin.login', 'admin', (string) $user['id'], null, null);
        }
        return $result;
    }

    /**
     * Passwordless / magic-link sign-in request (customers only).
     * The response is identical whether or not a link was sent, so it cannot be used
     * to discover accounts, and the token is only ever delivered by email.
     */
    public function requestMagicLink(string $email): array
    {
        $email = trim(strtolower($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('A valid email address is required.', 422, 'VALIDATION_ERROR', [
                'email' => 'Please provide a valid email address.',
            ]);
        }

        RateLimiter::attempt('magic_link_ip', RequestContext::clientIp(), 10, 900, 'Too many sign-in link requests. Please wait a few minutes.');
        RateLimiter::attempt('magic_link_email', $email, 3, 900, 'A sign-in link was sent recently. Please check your inbox or wait a few minutes.');

        $user = $this->userRepo->findByEmail($email);
        if ($user === null) {
            // Lightweight guest-first profile; becomes usable only after the emailed link is opened.
            $namePart = ucfirst(explode('@', $email)[0]);
            $userId = $this->userRepo->create([
                'email' => $email,
                'full_name' => $namePart,
                'role' => 'customer',
                'status' => 'unverified',
            ]);
            $this->tokenRepo->create($email, 'magic_link', $userId, 15);
        } elseif ($user['role'] === 'customer' && $user['status'] !== 'suspended') {
            $this->tokenRepo->create($email, 'magic_link', (int) $user['id'], 15);
        }
        // Admin and suspended accounts silently receive nothing.

        return [
            'email' => $email,
            'expires_in_minutes' => 15,
        ];
    }

    /**
     * Verify a magic-link token (single use) and sign the customer in.
     */
    public function verifyMagicLink(string $token): array
    {
        RateLimiter::attempt('magic_verify_ip', RequestContext::clientIp(), 20, 900);

        $tokenRecord = $this->tokenRepo->consume($token, 'magic_link');
        if ($tokenRecord === null) {
            Response::error('This sign-in link is invalid, expired, or has already been used.', 401, 'INVALID_MAGIC_LINK');
        }

        $user = $this->userRepo->findById((int) $tokenRecord['user_id']);
        if ($user === null || $user['role'] !== 'customer') {
            Response::error('This sign-in link is invalid, expired, or has already been used.', 401, 'INVALID_MAGIC_LINK');
        }
        if ($user['status'] === 'suspended') {
            Response::error('Account has been suspended. Please contact customer support.', 403, 'ACCOUNT_SUSPENDED');
        }

        // First proof that this person owns the email. A password chosen before that proof may
        // belong to someone who registered the address first (to read the owner's orders once
        // they verify). It is kept only when the link is opened in the browser that registered
        // (signed in as this same account); otherwise the password and every other session of
        // the account are removed, and the owner can sign in with email links.
        $previousToken = SessionCookie::read(SessionCookie::SCOPE_CUSTOMER);
        $passwordRemoved = false;
        if (empty($user['email_verified_at']) && !empty($user['password_hash'])) {
            $current = $previousToken !== null ? $this->sessionRepo->find($previousToken) : null;
            if ($current === null || (int) $current['user_id'] !== (int) $user['id']) {
                $this->db->execute("UPDATE `users` SET `password_hash` = NULL WHERE `id` = ?", 'i', [(int) $user['id']]);
                $this->sessionRepo->destroyUserSessions((int) $user['id']);
                $passwordRemoved = true;
            }
        }

        $this->userRepo->markEmailVerified((int) $user['id']);
        $user = $this->userRepo->findById((int) $user['id']);

        $result = $this->startSession($user, $previousToken);
        $result['password_removed'] = $passwordRemoved;
        return $result;
    }

    /**
     * Terminate the session for one scope and clear its cookie.
     */
    public function logout(string $scope): void
    {
        $token = SessionCookie::read($scope);
        if ($token !== null) {
            if ($scope === SessionCookie::SCOPE_ADMIN) {
                $session = $this->db->fetchOne("SELECT `user_id` FROM `sessions` WHERE `id` = ?", 's', [hash('sha256', $token)]);
                if (!empty($session['user_id'])) {
                    AuditService::record((int) $session['user_id'], 'admin.logout', 'admin', (string) $session['user_id'], null, null);
                }
            }
            $this->sessionRepo->destroy($token);
        }
        SessionCookie::clear($scope);
    }

    /**
     * Rotate (or create) the session for a user, set its cookie and return the safe payload.
     */
    private function startSession(array $user, ?string $previousToken): array
    {
        $scope = SessionCookie::scopeForRole((string) $user['role']);

        // Orders placed as a guest with this (verified) email now belong to the account, so they
        // appear under "My orders". Unverified emails never claim orders.
        // Plain `=`: the column's collation already ignores case, and LOWER() would stop MySQL
        // using idx_orders_email (every guest order was scanned on each sign-in).
        if ($user['role'] === 'customer' && !empty($user['email_verified_at'])) {
            $this->db->execute(
                "UPDATE `orders` SET `user_id` = ? WHERE `user_id` IS NULL AND `guest_email` = ?",
                'is',
                [(int) $user['id'], strtolower((string) $user['email'])]
            );
        }
        $sessionData = $this->sessionRepo->rotate(
            $previousToken,
            (int) $user['id'],
            RequestContext::clientIp(),
            RequestContext::userAgent(),
            SessionCookie::ttlSeconds($scope)
        );

        SessionCookie::set($scope, $sessionData['token'], strtotime($sessionData['expires_at']));

        return [
            'user' => UserRepository::toSafeUser($user),
            'csrf_token' => $sessionData['csrf_token'],
            'expires_at' => $sessionData['expires_at'],
        ];
    }
}
