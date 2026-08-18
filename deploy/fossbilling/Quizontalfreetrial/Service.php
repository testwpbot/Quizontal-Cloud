<?php

declare(strict_types=1);

namespace Box\Mod\Quizontalfreetrial;

use FOSSBilling\Config;
use FOSSBilling\InformationException;

/**
 * Quizontal Cloud — seven-day starter hosting trial.
 *
 * The wizard is deliberately server-driven: every step re-validates the whole
 * state held in the PHP session, so a customer cannot skip the email code,
 * reuse another person's verified email, or replay the provisioning call.
 *
 * One trial per customer is enforced on four independent axes — normalized
 * email, WhatsApp number, domain and client account — with UNIQUE database
 * keys as the final backstop. Trial rows are never deleted, so a terminated
 * trial still blocks a second signup.
 */
class Service implements \FOSSBilling\InjectionAwareInterface
{
    /** Wizard state lives here for the duration of the visitor's session. */
    private const SESSION_KEY = 'qc_free_trial_wizard';

    /** Verification codes are 8 characters, mixed case, digits and symbols. */
    private const CODE_LENGTH = 8;

    private const STATUS_PENDING = 'pending';
    private const STATUS_PROVISIONING = 'provisioning';
    private const STATUS_ACTIVE = 'active';
    private const STATUS_SUSPENDED = 'suspended';
    private const STATUS_TERMINATED = 'terminated';
    private const STATUS_FAILED = 'failed';

    /** Never hand a trial account one of these — they are ours or reserved. */
    private const BLOCKED_DOMAIN_SUFFIXES = ['localhost', 'local', 'test', 'invalid', 'example', 'internal', 'lan'];

    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    /* =====================================================================
     * Module plumbing
     * ===================================================================== */

    public function getModulePermissions(): array
    {
        return [
            'view' => ['type' => 'bool', 'display_name' => 'View free trials', 'description' => 'View the free trial register.'],
            'manage' => ['type' => 'bool', 'display_name' => 'Manage free trials', 'description' => 'Extend, terminate and reset free trials.'],
            'manage_settings' => [],
        ];
    }

    public function install(): bool
    {
        $this->di['db']->exec("CREATE TABLE IF NOT EXISTS `quizontal_free_trial` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `client_id` BIGINT UNSIGNED NULL,
            `order_id` BIGINT UNSIGNED NULL,
            `service_id` BIGINT UNSIGNED NULL,
            `email` VARCHAR(191) NOT NULL,
            `email_key` VARCHAR(191) NOT NULL,
            `whatsapp` VARCHAR(32) NOT NULL,
            `domain` VARCHAR(191) NOT NULL,
            `first_name` VARCHAR(100) NOT NULL,
            `last_name` VARCHAR(100) NULL,
            `status` ENUM('pending','provisioning','active','suspended','terminated','failed') NOT NULL DEFAULT 'pending',
            `product_id` BIGINT UNSIGNED NULL,
            `starts_at` DATETIME NULL,
            `expires_at` DATETIME NULL,
            `reminded_at` DATETIME NULL,
            `suspended_at` DATETIME NULL,
            `terminated_at` DATETIME NULL,
            `ip` VARCHAR(45) NULL,
            `last_error` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `quizontal_free_trial_email_unique` (`email_key`),
            UNIQUE KEY `quizontal_free_trial_whatsapp_unique` (`whatsapp`),
            UNIQUE KEY `quizontal_free_trial_domain_unique` (`domain`),
            KEY `quizontal_free_trial_client_index` (`client_id`),
            KEY `quizontal_free_trial_status_index` (`status`),
            KEY `quizontal_free_trial_expiry_index` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->di['db']->exec("CREATE TABLE IF NOT EXISTS `quizontal_free_trial_code` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `email_key` VARCHAR(191) NOT NULL,
            `email` VARCHAR(191) NOT NULL,
            `code_hash` VARCHAR(255) NOT NULL,
            `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `sent_count` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            `session_id` VARCHAR(191) NULL,
            `ip` VARCHAR(45) NULL,
            `expires_at` DATETIME NOT NULL,
            `verified_at` DATETIME NULL,
            `window_started_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `quizontal_free_trial_code_email_unique` (`email_key`),
            KEY `quizontal_free_trial_code_ip_index` (`ip`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        return true;
    }

    public function uninstall(): bool
    {
        // The trial register is the one-trial-per-customer record. Deleting it
        // would silently hand every past customer a second free trial, so the
        // tables are deliberately retained.
        return true;
    }

    public function getConfig(): array
    {
        $config = (array) $this->di['mod_config']('quizontalfreetrial');

        $merged = array_merge([
            'enabled' => true,
            'product_id' => 98,
            'trial_days' => 7,
            'grace_days' => 7,
            'reminder_days_before' => 2,
            'code_ttl_minutes' => 15,
            'code_resend_seconds' => 60,
            'code_max_attempts' => 6,
            'code_max_sends_per_hour' => 5,
            'ip_max_trials_per_day' => 3,
            'default_country_code' => '94',
            'terms_note' => 'One free trial per customer. No payment details required. Your account is suspended when the trial ends unless you upgrade.',
        ], $config);

        // Configuration arrives from the admin form as strings.
        foreach (['product_id', 'trial_days', 'grace_days', 'reminder_days_before', 'code_ttl_minutes', 'code_resend_seconds', 'code_max_attempts', 'code_max_sends_per_hour', 'ip_max_trials_per_day'] as $key) {
            $merged[$key] = (int) $merged[$key];
        }
        $merged['enabled'] = (bool) $merged['enabled'];
        $merged['trial_days'] = max(1, min(90, $merged['trial_days']));
        $merged['grace_days'] = max(0, min(90, $merged['grace_days']));
        $merged['default_country_code'] = preg_replace('/\D/', '', (string) $merged['default_country_code']) ?: '94';

        return $merged;
    }

    /* =====================================================================
     * Wizard state
     * ===================================================================== */

    /**
     * Public snapshot of the wizard for the page and for every API answer, so
     * the browser never has to keep authoritative state of its own.
     */
    public function state(): array
    {
        $config = $this->getConfig();
        $state = $this->readState();
        $client = $this->loggedInClient();

        if ($client instanceof \Model_Client) {
            $state['email'] = (string) $client->email;
            $state['first_name'] = (string) $client->first_name;
            $state['last_name'] = (string) $client->last_name;
            $state['needs_account'] = false;
        }

        // Verification is never taken from the session. It is read from the
        // database every time: client.email_approved for a signed-in customer,
        // otherwise a verified code row for the address being signed up.
        $emailVerified = $this->emailIsVerified($client, (string) ($state['email'] ?? ''));

        $step = 'email';
        if ($emailVerified) {
            $step = 'whatsapp';
        }
        if ($emailVerified && !empty($state['whatsapp'])) {
            $step = 'domain';
        }
        if ($emailVerified && !empty($state['domain'])) {
            $step = empty($state['needs_account']) || !empty($state['first_name']) ? 'review' : 'account';
        }
        if (!empty($state['completed_order_id'])) {
            $step = 'done';
        }

        // Tell a customer who cannot have a trial straight away rather than
        // letting them fill in four steps and fail at the last one.
        $blockedReason = null;
        if ($step !== 'done' && $client instanceof \Model_Client) {
            try {
                $this->assertClientEligible($client);
            } catch (InformationException $exception) {
                $blockedReason = $exception->getMessage();
            }
        }

        return [
            'enabled' => $config['enabled'],
            'step' => $step,
            'blocked_reason' => $blockedReason,
            'logged_in' => $client instanceof \Model_Client,
            'email' => $state['email'] ?? '',
            'email_verified' => $emailVerified,
            'whatsapp' => $state['whatsapp'] ?? '',
            'domain' => $state['domain'] ?? '',
            'first_name' => $state['first_name'] ?? '',
            'last_name' => $state['last_name'] ?? '',
            'needs_account' => (bool) ($state['needs_account'] ?? true),
            'completed_order_id' => $state['completed_order_id'] ?? null,
            'trial_days' => $config['trial_days'],
            'terms_note' => $config['terms_note'],
            'product' => $this->publicProductSummary(),
        ];
    }

    private function readState(): array
    {
        $state = $this->di['session']->get(self::SESSION_KEY);

        return is_array($state) ? $state : [];
    }

    private function writeState(array $state): void
    {
        $this->di['session']->set(self::SESSION_KEY, $state);
    }

    private function patchState(array $patch): array
    {
        $state = array_merge($this->readState(), $patch);
        $this->writeState($state);

        return $state;
    }

    public function resetWizard(): array
    {
        $this->di['session']->delete(self::SESSION_KEY);

        return $this->state();
    }

    /* =====================================================================
     * Step 1 & 2 — secure email code
     * ===================================================================== */

    public function requestCode(string $email): array
    {
        $this->assertEnabled();
        $client = $this->loggedInClient();

        if ($client instanceof \Model_Client) {
            if ($this->emailIsVerified($client)) {
                // Already proven in the database — nothing to send.
                return $this->state();
            }
            // A signed-in customer verifies their own account address; the one
            // typed in the form is ignored so it cannot be used to claim
            // somebody else's mailbox.
            $email = (string) $client->email;
        }

        $email = $this->sanitizeEmail($email);
        $key = $this->emailKey($email);

        // Refuse before mailing anything: an address that already owns a trial,
        // or (for guests) already has an account, must not receive a code.
        if ($client instanceof \Model_Client) {
            $this->assertClientEligible($client);
        } else {
            $this->assertEmailEligible($email, $key);
        }
        $this->assertIpBudget();

        $config = $this->getConfig();
        $now = time();
        $row = $this->di['db']->findOne('quizontal_free_trial_code', 'email_key = ?', [$key]);

        if ($row === null) {
            $row = $this->di['db']->dispense('quizontal_free_trial_code');
            $row->email_key = $key;
            $row->sent_count = 0;
            $row->window_started_at = date('Y-m-d H:i:s', $now);
            $row->created_at = date('Y-m-d H:i:s', $now);
        }

        // Rolling one-hour send window.
        if (strtotime((string) $row->window_started_at) < $now - 3600) {
            $row->window_started_at = date('Y-m-d H:i:s', $now);
            $row->sent_count = 0;
        }
        if ((int) $row->sent_count >= $config['code_max_sends_per_hour']) {
            throw new InformationException('Too many verification codes were requested for this email address. Please try again in an hour.');
        }
        if ($row->updated_at && strtotime((string) $row->updated_at) > $now - $config['code_resend_seconds']) {
            throw new InformationException('A verification code was just sent. Please wait a moment before requesting another one.');
        }

        $code = $this->generateCode();
        $row->email = $email;
        $row->code_hash = $this->di['password']->hashIt($code);
        $row->attempts = 0;
        $row->verified_at = null;
        $row->sent_count = (int) $row->sent_count + 1;
        $row->session_id = $this->sessionFingerprint();
        $row->ip = $this->clientIp();
        $row->expires_at = date('Y-m-d H:i:s', $now + ($config['code_ttl_minutes'] * 60));
        $row->updated_at = date('Y-m-d H:i:s', $now);
        $this->di['db']->store($row);

        $this->sendCodeEmail($email, $code, $config['code_ttl_minutes']);

        // Remember the pending address so the browser cannot verify a code that
        // was issued to a different mailbox in another tab. Verification itself
        // is not tracked here — it lives in the database.
        $this->patchState([
            'pending_email' => $email,
            'email' => $email,
        ]);

        return array_merge($this->state(), ['resend_after' => $config['code_resend_seconds']]);
    }

    public function verifyCode(string $email, string $code): array
    {
        $this->assertEnabled();
        $client = $this->loggedInClient();

        if ($client instanceof \Model_Client) {
            if ($this->emailIsVerified($client)) {
                return $this->state();
            }
            $email = (string) $client->email;
        }

        $email = $this->sanitizeEmail($email);
        $key = $this->emailKey($email);
        $state = $this->readState();

        if (!$client instanceof \Model_Client && ($state['pending_email'] ?? null) !== $email) {
            throw new InformationException('Please request a verification code for this email address first.');
        }

        $config = $this->getConfig();
        $row = $this->di['db']->findOne('quizontal_free_trial_code', 'email_key = ?', [$key]);
        if ($row === null) {
            throw new InformationException('Please request a verification code first.');
        }
        if (strtotime((string) $row->expires_at) < time()) {
            throw new InformationException('That verification code has expired. Please request a new one.');
        }
        if ((int) $row->attempts >= $config['code_max_attempts']) {
            throw new InformationException('Too many incorrect attempts. Please request a new verification code.');
        }
        if ($row->session_id !== $this->sessionFingerprint()) {
            throw new InformationException('This verification code belongs to a different browser session. Please request a new one.');
        }

        // Only surrounding whitespace is forgiven — the comparison is exact and
        // case sensitive, because case is part of the code's entropy.
        $code = trim($code);
        $row->attempts = (int) $row->attempts + 1;
        $row->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($row);

        if (strlen($code) !== self::CODE_LENGTH || !$this->di['password']->verify($code, (string) $row->code_hash)) {
            $left = max(0, $config['code_max_attempts'] - (int) $row->attempts);
            throw new InformationException($left > 0
                ? sprintf('That code is not correct. %d attempt%s left.', $left, $left === 1 ? '' : 's')
                : 'That code is not correct. Please request a new verification code.');
        }

        // Re-run eligibility: someone else may have claimed the address while
        // this code was in flight.
        if ($client instanceof \Model_Client) {
            $this->assertClientEligible($client);
        } else {
            $this->assertEmailEligible($email, $key);
        }

        $row->verified_at = date('Y-m-d H:i:s');
        $this->di['db']->store($row);

        // Promote the proof onto the account itself when there is one, so the
        // customer is never asked for the same address again.
        if ($client instanceof \Model_Client) {
            $client->email_approved = 1;
            $this->di['db']->store($client);
        }

        $this->patchState([
            'email' => $email,
            'needs_account' => !$client instanceof \Model_Client,
        ]);

        return $this->state();
    }

    /* =====================================================================
     * Step 3 — WhatsApp validation
     * ===================================================================== */

    public function setWhatsapp(string $number): array
    {
        $this->assertEnabled();
        $this->assertEmailStep();

        $normalized = $this->normalizeWhatsapp($number);
        $existing = $this->di['db']->findOne('quizontal_free_trial', 'whatsapp = ?', [$normalized]);
        if (!$this->isRetryableRow($existing)) {
            throw new InformationException('This WhatsApp number has already been used for a Quizontal Cloud free trial.');
        }

        $this->patchState(['whatsapp' => $normalized]);

        return $this->state();
    }

    /**
     * Accepts the shapes Sri Lankan customers actually type — 0771234567,
     * 771234567, 94771234567, +94 77 123 4567 — and stores one E.164 value so
     * the duplicate check cannot be defeated by formatting.
     */
    public function normalizeWhatsapp(string $number): string
    {
        $config = $this->getConfig();
        $raw = trim($number);
        $hasPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if ($digits === '') {
            throw new InformationException('Please enter your WhatsApp number.');
        }

        $cc = $config['default_country_code'];
        if (!$hasPlus) {
            if (str_starts_with($digits, '00')) {
                $digits = substr($digits, 2);
            } elseif (str_starts_with($digits, '0')) {
                // National format: drop the trunk zero and prepend the country.
                $digits = $cc . ltrim($digits, '0');
            } elseif (!str_starts_with($digits, $cc) && strlen($digits) <= 10) {
                $digits = $cc . $digits;
            }
        }

        if (strlen($digits) < 8 || strlen($digits) > 15) {
            throw new InformationException('That WhatsApp number does not look right. Use the international format, for example +94 77 123 4567.');
        }
        if (str_starts_with($digits, '0')) {
            throw new InformationException('Please include your country code, for example +94 77 123 4567.');
        }
        // Sri Lankan mobile numbers are +94 7X XXX XXXX. Catch landlines early
        // rather than after provisioning.
        if (str_starts_with($digits, '94') && (strlen($digits) !== 11 || $digits[2] !== '7')) {
            throw new InformationException('Please enter a Sri Lankan WhatsApp mobile number, for example +94 77 123 4567.');
        }

        return '+' . $digits;
    }

    /* =====================================================================
     * Step 4 — existing domain
     * ===================================================================== */

    public function setDomain(string $domain): array
    {
        $this->assertEnabled();
        $this->assertEmailStep();
        if (empty($this->readState()['whatsapp'])) {
            throw new InformationException('Please confirm your WhatsApp number first.');
        }

        $normalized = $this->normalizeDomain($domain);
        $this->assertDomainAvailable($normalized);

        [$sld, $tld] = $this->splitDomain($normalized);
        $this->patchState(['domain' => $normalized, 'sld' => $sld, 'tld' => $tld]);

        return $this->state();
    }

    /**
     * Strips the things people paste — scheme, www, path, trailing dot — and
     * validates the remainder as a registrable host name.
     */
    public function normalizeDomain(string $domain): string
    {
        $value = strtolower(trim($domain));
        $value = preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $value) ?? '';
        $value = explode('/', $value)[0];
        $value = explode('?', $value)[0];
        $value = explode('@', $value)[0];
        $value = explode(':', $value)[0];
        $value = trim($value, '.');
        if (str_starts_with($value, 'www.')) {
            $value = substr($value, 4);
        }

        if ($value === '') {
            throw new InformationException('Please enter the domain you want to use.');
        }
        if (strlen($value) > 253) {
            throw new InformationException('That domain name is too long.');
        }
        if (!str_contains($value, '.')) {
            throw new InformationException('Please enter a full domain name, for example yoursite.lk.');
        }

        $labels = explode('.', $value);
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                throw new InformationException('That domain name is not valid. Please check it and try again.');
            }
            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
                throw new InformationException('Domain names may only contain letters, numbers and hyphens.');
            }
        }

        $tld = end($labels);
        if (!preg_match('/^(xn--[a-z0-9-]{2,}|[a-z]{2,})$/', $tld)) {
            throw new InformationException('That domain extension is not valid.');
        }
        if (in_array($tld, self::BLOCKED_DOMAIN_SUFFIXES, true)) {
            throw new InformationException('Please use a real registered domain name for your trial.');
        }
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            throw new InformationException('Please enter a domain name, not an IP address.');
        }

        return $value;
    }

    private function splitDomain(string $domain): array
    {
        $position = strpos($domain, '.');

        return [substr($domain, 0, $position), substr($domain, $position)];
    }

    /**
     * The domain must not already be hosted here, sitting in someone's order,
     * or attached to another trial — otherwise DirectAdmin rejects the account
     * creation halfway through the wizard.
     */
    private function assertDomainAvailable(string $domain): void
    {
        $friendly = 'That domain is already set up on Quizontal Cloud. Please use a different domain or contact support.';

        $trial = $this->di['db']->findOne('quizontal_free_trial', 'domain = ?', [$domain]);
        if (!$this->isRetryableRow($trial)) {
            throw new InformationException('This domain has already been used for a Quizontal Cloud free trial.');
        }

        // Reject anything on or under our own service domains.
        foreach ($this->ownDomains() as $own) {
            if ($domain === $own || str_ends_with($domain, '.' . $own)) {
                throw new InformationException('Please use your own domain name for the trial.');
            }
        }

        [$sld, $tld] = $this->splitDomain($domain);
        $hosting = $this->di['db']->findOne('ServiceHosting', 'sld = ? AND tld = ?', [$sld, $tld]);
        if ($hosting !== null) {
            throw new InformationException($friendly);
        }

        // Pending/active orders keep their domain in the JSON config blob.
        $statement = $this->di['pdo']->prepare(
            "SELECT COUNT(*) FROM client_order
             WHERE service_type = 'hosting'
               AND status IN ('pending_setup','active','suspended')
               AND config LIKE :needle"
        );
        $statement->execute([':needle' => '%"' . $sld . '"%']);
        if ((int) $statement->fetchColumn() > 0) {
            // LIKE can only narrow the candidates; confirm exactly.
            $orders = $this->di['db']->find('ClientOrder', "service_type = 'hosting' AND status IN ('pending_setup','active','suspended')");
            foreach ($orders as $order) {
                $config = json_decode((string) $order->config, true) ?: [];
                $candidate = strtolower(trim((string) ($config['sld'] ?? '')) . trim((string) ($config['tld'] ?? '')));
                if ($candidate === $domain) {
                    throw new InformationException($friendly);
                }
            }
        }
    }

    private function ownDomains(): array
    {
        $domains = [];
        $candidates = [
            (string) Config::getProperty('url', ''),
            (string) ($this->getConfig()['storefront_url'] ?? ''),
        ];

        foreach ($candidates as $url) {
            if ($url === '') {
                continue;
            }
            if (!str_contains($url, '//')) {
                $url = 'https://' . ltrim($url, '/');
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== '') {
                $domains[] = preg_replace('/^www\./', '', $host);
            }
        }

        return array_values(array_unique(array_filter($domains)));
    }

    /* =====================================================================
     * Step 5 — account details for brand-new customers
     * ===================================================================== */

    public function setAccount(string $firstName, string $lastName, string $password, string $passwordConfirm): array
    {
        $this->assertEnabled();
        $this->assertEmailStep();

        if ($this->loggedInClient() instanceof \Model_Client) {
            return $this->state();
        }

        $firstName = trim(strip_tags($firstName));
        $lastName = trim(strip_tags($lastName));
        if (mb_strlen($firstName) < 2) {
            throw new InformationException('Please enter your first name.');
        }
        if (mb_strlen($firstName) > 60 || mb_strlen($lastName) > 60) {
            throw new InformationException('Please shorten your name.');
        }
        if ($password !== $passwordConfirm) {
            throw new InformationException('The two passwords do not match.');
        }
        // Delegates to the strength policy configured for the installation.
        $this->di['validator']->isPasswordStrong($password);

        $this->patchState([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'password' => $password,
            'needs_account' => true,
        ]);

        return $this->state();
    }

    /* =====================================================================
     * Step 6 — final review
     * ===================================================================== */

    public function review(): array
    {
        $this->assertEnabled();
        $state = $this->assertReadyToProvision();
        $config = $this->getConfig();
        $product = $this->trialProduct();
        $expires = strtotime('+' . $config['trial_days'] . ' days');

        return [
            'email' => $state['email'],
            'whatsapp' => $state['whatsapp'],
            'domain' => $state['domain'],
            'name' => trim(($state['first_name'] ?? '') . ' ' . ($state['last_name'] ?? '')),
            'plan' => (string) $product->title,
            'plan_description' => (string) $product->description,
            'trial_days' => $config['trial_days'],
            'grace_days' => $config['grace_days'],
            'price' => 'Free',
            'starts_on' => date('j M Y'),
            'ends_on' => date('j M Y', $expires),
            'terms_note' => $config['terms_note'],
        ];
    }

    /* =====================================================================
     * Step 7 — provisioning
     * ===================================================================== */

    /**
     * Creates (or reuses) the client, writes the trial register row, then lets
     * FOSSBilling create and activate the hosting order, which is what calls
     * DirectAdmin. Returns the service-details URL the browser redirects to.
     */
    public function provision(): array
    {
        $this->assertEnabled();
        $state = $this->assertReadyToProvision();
        $config = $this->getConfig();

        if (!empty($state['completed_order_id'])) {
            // Double submit / refresh during the loader — return the same URL.
            return $this->provisionResult((int) $state['completed_order_id']);
        }

        $product = $this->trialProduct();
        $client = $this->loggedInClient();
        $email = $client instanceof \Model_Client ? (string) $client->email : (string) $state['email'];
        $emailKey = $this->emailKey($email);

        $this->assertIpBudget();
        if ($client instanceof \Model_Client) {
            $this->assertClientEligible($client);
        } else {
            $this->assertEmailEligible($email, $emailKey);
        }

        // Claim the trial slot BEFORE provisioning. The UNIQUE keys make this
        // the atomic gate: two concurrent submissions cannot both get past it.
        // A previous failed attempt by this same visitor is written over rather
        // than colliding, so a server hiccup never costs them their one trial.
        $trial = $this->reusableFailedRow($emailKey, (string) $state['whatsapp'], (string) $state['domain'])
            ?? $this->di['db']->dispense('quizontal_free_trial');

        $trial->client_id = $client instanceof \Model_Client ? (int) $client->id : null;
        $trial->email = $email;
        $trial->email_key = $emailKey;
        $trial->whatsapp = (string) $state['whatsapp'];
        $trial->domain = (string) $state['domain'];
        $trial->first_name = (string) ($state['first_name'] ?? '');
        $trial->last_name = (string) ($state['last_name'] ?? '');
        $trial->status = self::STATUS_PROVISIONING;
        $trial->product_id = (int) $product->id;
        $trial->ip = $this->clientIp();
        $trial->last_error = null;
        $trial->created_at = $trial->created_at ?: date('Y-m-d H:i:s');
        $trial->updated_at = date('Y-m-d H:i:s');

        try {
            $this->di['db']->store($trial);
        } catch (\Throwable $exception) {
            // A UNIQUE key rejected the insert — someone else holds these details.
            throw new InformationException('A free trial has already been claimed with these details.');
        }

        try {
            if (!$client instanceof \Model_Client) {
                $client = $this->createTrialClient($state, $email);
                $trial->client_id = (int) $client->id;
                $this->di['db']->store($trial);
                // Sign in as soon as the account exists. If provisioning fails
                // after this point, the retry runs as an authenticated customer
                // instead of tripping the "email already registered" guard.
                $this->signIn($client);
                // The plaintext password has served its purpose; do not leave
                // it sitting in the session store.
                $this->patchState(['password' => null]);
            }

            $orderId = $this->createTrialOrder($client, $product, $state);
            $order = $this->di['db']->getExistingModelById('ClientOrder', $orderId, 'Trial order not found.');

            // Activation is what reaches DirectAdmin. Let it throw: we want the
            // real reason recorded and shown, not a silent failed_setup order.
            $this->di['mod_service']('order')->activateOrder($order);

            $order = $this->di['db']->getExistingModelById('ClientOrder', $orderId, 'Trial order not found.');
            $order->expires_at = date('Y-m-d H:i:s', strtotime('+' . $config['trial_days'] . ' days'));
            $order->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($order);

            $trial->order_id = (int) $order->id;
            $trial->service_id = $order->service_id ? (int) $order->service_id : null;
            $trial->status = self::STATUS_ACTIVE;
            $trial->starts_at = date('Y-m-d H:i:s');
            $trial->expires_at = (string) $order->expires_at;
            $trial->last_error = null;
            $trial->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($trial);
        } catch (\Throwable $exception) {
            // Keep the row so the admin register shows what went wrong, marked
            // failed so this visitor can retry straight over it.
            $trial->status = self::STATUS_FAILED;
            $trial->last_error = mb_substr($exception->getMessage(), 0, 2000);
            $trial->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($trial);

            $this->di['logger']->err('Free trial provisioning failed for %s: %s', $email, $exception->getMessage());

            throw new InformationException($this->friendlyProvisioningError($exception));
        }

        $this->signIn($client);
        $this->patchState(['completed_order_id' => (int) $trial->order_id, 'password' => null]);
        $this->sendTrialReadyEmail($trial);

        $this->di['logger']->info('Free trial #%s activated for client #%s on %s', $trial->id, $client->id, $trial->domain);

        return $this->provisionResult((int) $trial->order_id);
    }

    private function provisionResult(int $orderId): array
    {
        return [
            'order_id' => $orderId,
            // The normal service details page — trials are ordinary services.
            'redirect_url' => $this->di['url']->link('order/service/manage/' . $orderId),
        ];
    }

    private function createTrialClient(array $state, string $email): \Model_Client
    {
        $clientService = $this->di['mod_service']('client');
        if ($clientService->clientAlreadyExists($email)) {
            throw new InformationException('This email address is already registered. Please sign in and start your trial from the client area.');
        }

        $password = (string) ($state['password'] ?? '');
        if ($password === '') {
            throw new InformationException('Please choose a password for your new account.');
        }

        $client = $clientService->guestCreateClient([
            'email' => $email,
            'first_name' => (string) ($state['first_name'] ?? ''),
            'last_name' => (string) ($state['last_name'] ?? ''),
            'password' => $password,
            'phone' => (string) ($state['whatsapp'] ?? ''),
            'notes' => 'Created through the Quizontal Cloud free trial wizard.',
        ]);

        if (!$client instanceof \Model_Client) {
            throw new InformationException('We could not create your account. Please try again.');
        }

        // The wizard only reaches this point after a one-time code was sent to
        // this address and typed back correctly, which is a stronger proof than
        // clicking a confirmation link. Record it on the account so an
        // installation with "require email confirmation" enabled does not ask
        // the customer to prove the same address a second time.
        $client->email_approved = 1;
        $this->di['db']->store($client);

        return $client;
    }

    /**
     * Builds a zero-priced, invoice-free hosting order for the trial product.
     * Server and hosting-plan IDs come from the product's own configuration,
     * exactly like a paid order placed through the cart.
     */
    private function createTrialOrder(\Model_Client $client, \Model_Product $product, array $state): int
    {
        $productConfig = json_decode((string) $product->config, true) ?: [];
        if (empty($productConfig['server_id']) || empty($productConfig['hosting_plan_id'])) {
            throw new InformationException('The free trial plan is not fully configured yet. Please contact support.');
        }

        $orderConfig = array_merge($productConfig, [
            'sld' => $state['sld'],
            'tld' => $state['tld'],
            'domain' => [
                'action' => 'owndomain',
                'owndomain_sld' => $state['sld'],
                'owndomain_tld' => $state['tld'],
            ],
            'qc_free_trial' => true,
        ]);

        $orderId = $this->di['mod_service']('order')->createOrder($client, $product, [
            'config' => $orderConfig,
            'title' => sprintf('%s — %d-day free trial', (string) $product->title, $this->getConfig()['trial_days']),
            'price' => 0,
            'quantity' => 1,
            'invoice_option' => 'no-invoice',
            // Activated explicitly below so provisioning errors surface here
            // instead of being swallowed into a failed_setup order.
            'activate' => false,
            'notes' => sprintf('Quizontal Cloud free trial for %s.', (string) $state['domain']),
        ]);

        return (int) $orderId;
    }

    private function signIn(\Model_Client $client): void
    {
        try {
            $this->di['session']->set('client_id', (int) $client->id);
        } catch (\Throwable $exception) {
            $this->di['logger']->err('Free trial auto sign-in failed: %s', $exception->getMessage());
        }
    }

    /**
     * DirectAdmin and FOSSBilling speak in operator language. Customers get a
     * short sentence; the full message is kept in the trial row and the log.
     */
    private function friendlyProvisioningError(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'domain already exists') || str_contains($message, 'already exists')) {
            return 'That domain is already set up on our hosting platform. Please use a different domain or contact support.';
        }
        if (str_contains($message, 'connect') || str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'We could not reach the hosting server just now. Please try again in a few minutes.';
        }
        if (str_contains($message, 'not fully configured') || str_contains($message, 'hosting plan') || str_contains($message, 'server from order')) {
            return 'The free trial plan is not fully configured yet. Please contact support so we can switch it on for you.';
        }
        if ($exception instanceof InformationException) {
            return $exception->getMessage();
        }

        return 'We could not finish setting up your trial. Nothing was charged and your trial is still available — please try again or contact support.';
    }

    /* =====================================================================
     * Eligibility — one trial per customer
     * ===================================================================== */

    private function assertEnabled(): void
    {
        if (!$this->getConfig()['enabled']) {
            throw new InformationException('The Quizontal Cloud free trial is not accepting new signups right now.');
        }
    }

    private function assertEmailStep(): void
    {
        if (!$this->emailIsVerified($this->loggedInClient(), (string) ($this->readState()['email'] ?? ''))) {
            throw new InformationException('Please verify your email address first.');
        }
    }

    /**
     * The single source of truth for "is this address verified", read from the
     * database on every call — never from the wizard session.
     *
     * A signed-in customer counts as verified when `client.email_approved` is
     * set. If it is not (an account imported or created before this module, or
     * an installation that never required confirmation), they still have to
     * pass the code step, exactly like a guest.
     */
    private function emailIsVerified(?\Model_Client $client, string $email = ''): bool
    {
        if ($client instanceof \Model_Client) {
            if ((int) $client->email_approved === 1) {
                return true;
            }
            $email = (string) $client->email;
        }

        if ($email === '') {
            return false;
        }

        // `verified_at` is cleared whenever a new code is issued, so a stale
        // row can never satisfy this.
        $row = $this->di['db']->findOne(
            'quizontal_free_trial_code',
            'email_key = ? AND verified_at IS NOT NULL',
            [$this->emailKey($email)]
        );

        return $row !== null;
    }

    private function assertReadyToProvision(): array
    {
        $state = $this->readState();
        $client = $this->loggedInClient();

        if (!$client instanceof \Model_Client) {
            if (empty($state['email'])) {
                throw new InformationException('Please verify your email address first.');
            }
            if (empty($state['first_name']) || empty($state['password'])) {
                throw new InformationException('Please complete your account details first.');
            }
        } else {
            $state['email'] = (string) $client->email;
            $state['first_name'] = (string) $client->first_name;
            $state['last_name'] = (string) $client->last_name;
        }

        // Verification is proven against the database for guests and signed-in
        // customers alike; nothing here trusts the session for that fact.
        if (!$this->emailIsVerified($client, (string) ($state['email'] ?? ''))) {
            throw new InformationException('Please verify your email address first.');
        }
        if (empty($state['whatsapp'])) {
            throw new InformationException('Please confirm your WhatsApp number first.');
        }
        if (empty($state['domain']) || empty($state['sld']) || empty($state['tld'])) {
            throw new InformationException('Please enter the domain you want to use first.');
        }

        // The domain may have been taken since the customer typed it in.
        $this->assertDomainAvailable((string) $state['domain']);

        return $state;
    }

    private function assertEmailEligible(string $email, string $emailKey): void
    {
        $this->assertNoTrialForEmailKey($emailKey);

        if ($this->di['mod_service']('client')->clientAlreadyExists($email)) {
            throw new InformationException('This email address already has a Quizontal Cloud account. Please sign in first, then start your free trial.');
        }
    }

    private function assertNoTrialForEmailKey(string $emailKey): void
    {
        $existing = $this->di['db']->findOne('quizontal_free_trial', 'email_key = ?', [$emailKey]);
        // Found by email key, so the row is definitionally this same address.
        if (!$this->isRetryableRow($existing, $emailKey)) {
            throw new InformationException('This email address has already used its Quizontal Cloud free trial.');
        }
    }

    /**
     * Signed-in customers skip the "already registered" check — of course they
     * are registered; that is how they got here.
     */
    private function assertClientEligible(\Model_Client $client): void
    {
        $existing = $this->di['db']->findOne('quizontal_free_trial', 'client_id = ?', [(int) $client->id]);
        // Found by client ID, so the row is definitionally this same customer.
        if ($existing !== null && !$this->isRetryableRow($existing, (string) $existing->email_key)) {
            throw new InformationException('Your account has already used its Quizontal Cloud free trial.');
        }

        $this->assertNoTrialForEmailKey($this->emailKey((string) $client->email));
    }

    /**
     * Blunt but effective throttle against one person farming trials from a
     * single connection with disposable addresses.
     */
    private function assertIpBudget(): void
    {
        $ip = $this->clientIp();
        if ($ip === '') {
            return;
        }

        $limit = $this->getConfig()['ip_max_trials_per_day'];
        if ($limit < 1) {
            return;
        }

        $statement = $this->di['pdo']->prepare(
            'SELECT COUNT(*) FROM quizontal_free_trial WHERE ip = :ip AND created_at >= :since'
        );
        $statement->execute([':ip' => $ip, ':since' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
        if ((int) $statement->fetchColumn() >= $limit) {
            throw new InformationException('Too many free trials have been started from this connection today. Please contact support if you need another one.');
        }
    }

    /**
     * A failed attempt must not burn the customer's one trial: the row is kept
     * for the admin register, but the same visitor is allowed to retry over it.
     * Anything belonging to somebody else, or any non-failed row, blocks.
     *
     * `$ownerEmailKey` names the identity being checked. Pass it whenever the
     * row was looked up by something that already proves ownership (the email
     * key itself, or the signed-in client), because the wizard session may be
     * empty — a customer retrying tomorrow starts with a fresh session.
     */
    private function isRetryableRow($row, ?string $ownerEmailKey = null): bool
    {
        if ($row === null) {
            return true;
        }
        if ((string) $row->status !== self::STATUS_FAILED) {
            return false;
        }
        if ($ownerEmailKey !== null) {
            return (string) $row->email_key === $ownerEmailKey;
        }

        $client = $this->loggedInClient();
        if ($client instanceof \Model_Client) {
            return $row->client_id === null || (int) $row->client_id === (int) $client->id;
        }

        $currentKey = $this->currentEmailKey();

        return $currentKey !== '' && (string) $row->email_key === $currentKey;
    }

    private function currentEmailKey(): string
    {
        $client = $this->loggedInClient();
        if ($client instanceof \Model_Client) {
            return $this->emailKey((string) $client->email);
        }

        $email = (string) ($this->readState()['email'] ?? '');

        return $email === '' ? '' : $this->emailKey($email);
    }

    /**
     * Returns the single failed row this visitor may write over, so a retry
     * updates it in place instead of colliding with its UNIQUE keys.
     */
    private function reusableFailedRow(string $emailKey, string $whatsapp, string $domain)
    {
        $rows = $this->di['db']->find(
            'quizontal_free_trial',
            'email_key = ? OR whatsapp = ? OR domain = ?',
            [$emailKey, $whatsapp, $domain]
        );

        // More than one row means the details now span two different people.
        if (count($rows) !== 1) {
            return null;
        }

        $row = reset($rows);
        if ((string) $row->status !== self::STATUS_FAILED || (string) $row->email_key !== $emailKey) {
            return null;
        }

        return $row;
    }

    /* =====================================================================
     * Lifecycle — reminder, suspension, termination
     * ===================================================================== */

    public static function onBeforeAdminCronRun(\Box_Event $event): void
    {
        try {
            $event->getDi()['mod_service']('quizontalfreetrial')->runLifecycle();
        } catch (\Throwable $exception) {
            error_log('Free trial lifecycle failed: ' . $exception->getMessage());
        }
    }

    /**
     * Idempotent: every transition is guarded by its own timestamp column, so
     * running the cron more often than needed changes nothing.
     */
    public function runLifecycle(): array
    {
        $config = $this->getConfig();
        $summary = ['reminded' => 0, 'suspended' => 0, 'terminated' => 0];
        $now = time();

        $active = $this->di['db']->find('quizontal_free_trial', "status = ? AND expires_at IS NOT NULL", [self::STATUS_ACTIVE]);
        foreach ($active as $trial) {
            $expiresAt = strtotime((string) $trial->expires_at);

            if ($expiresAt <= $now) {
                if ($this->suspendTrial($trial)) {
                    ++$summary['suspended'];
                }
                continue;
            }

            $remindFrom = strtotime('-' . $config['reminder_days_before'] . ' days', $expiresAt);
            if ($trial->reminded_at === null && $now >= $remindFrom) {
                $this->safely(fn () => $this->sendLifecycleEmail($trial, 'mod_quizontalfreetrial_reminder'));
                $trial->reminded_at = date('Y-m-d H:i:s');
                $trial->updated_at = date('Y-m-d H:i:s');
                $this->di['db']->store($trial);
                ++$summary['reminded'];
            }
        }

        if ($config['grace_days'] >= 0) {
            $suspended = $this->di['db']->find('quizontal_free_trial', 'status = ? AND suspended_at IS NOT NULL', [self::STATUS_SUSPENDED]);
            foreach ($suspended as $trial) {
                $deadline = strtotime('+' . $config['grace_days'] . ' days', strtotime((string) $trial->suspended_at));
                if ($now >= $deadline && $this->terminateTrial((int) $trial->id)) {
                    ++$summary['terminated'];
                }
            }
        }

        return $summary;
    }

    private function suspendTrial($trial): bool
    {
        $order = $trial->order_id ? $this->di['db']->load('ClientOrder', (int) $trial->order_id) : null;

        if ($order instanceof \Model_ClientOrder && $order->status === \Model_ClientOrder::STATUS_ACTIVE) {
            $this->safely(fn () => $this->di['mod_service']('order')->suspendFromOrder($order, 'Free trial ended'));
        }

        $trial->status = self::STATUS_SUSPENDED;
        $trial->suspended_at = date('Y-m-d H:i:s');
        $trial->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($trial);

        $this->safely(fn () => $this->sendLifecycleEmail($trial, 'mod_quizontalfreetrial_expired'));
        $this->di['logger']->info('Free trial #%s suspended at end of term', $trial->id);

        return true;
    }

    public function terminateTrial(int $id): bool
    {
        $trial = $this->di['db']->load('quizontal_free_trial', $id);
        if ($trial === null || (string) $trial->status === self::STATUS_TERMINATED) {
            return false;
        }

        $order = $trial->order_id ? $this->di['db']->load('ClientOrder', (int) $trial->order_id) : null;
        if ($order instanceof \Model_ClientOrder && !in_array($order->status, [\Model_ClientOrder::STATUS_CANCELED, \Model_ClientOrder::STATUS_FAILED_SETUP], true)) {
            // Cancelling the order is what removes the DirectAdmin account.
            $this->safely(fn () => $this->di['mod_service']('order')->cancelFromOrder($order, 'Free trial expired without upgrade'));
        }

        $trial->status = self::STATUS_TERMINATED;
        $trial->terminated_at = date('Y-m-d H:i:s');
        $trial->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($trial);

        $this->safely(fn () => $this->sendLifecycleEmail($trial, 'mod_quizontalfreetrial_terminated'));
        $this->di['logger']->info('Free trial #%s terminated', $trial->id);

        return true;
    }

    /**
     * Gives a customer more trial time without letting them start a new trial.
     */
    public function extendTrial(int $id, int $days): array
    {
        $days = max(1, min(90, $days));
        $trial = $this->di['db']->getExistingModelById('quizontal_free_trial', $id, 'Free trial not found.');
        if ((string) $trial->status === self::STATUS_TERMINATED) {
            throw new InformationException('This trial was already terminated and cannot be extended.');
        }

        $from = max(time(), strtotime((string) $trial->expires_at));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $days . ' days', $from));

        $order = $trial->order_id ? $this->di['db']->load('ClientOrder', (int) $trial->order_id) : null;
        if ($order instanceof \Model_ClientOrder) {
            if ($order->status === \Model_ClientOrder::STATUS_SUSPENDED) {
                $this->safely(fn () => $this->di['mod_service']('order')->unsuspendFromOrder($order));
                $order = $this->di['db']->load('ClientOrder', (int) $trial->order_id);
            }
            $order->expires_at = $expiresAt;
            $order->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($order);
        }

        $trial->status = self::STATUS_ACTIVE;
        $trial->expires_at = $expiresAt;
        $trial->suspended_at = null;
        $trial->reminded_at = null;
        $trial->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($trial);

        return $this->toApiArray($trial);
    }

    /* =====================================================================
     * Email
     * ===================================================================== */

    private function sendCodeEmail(string $email, string $code, int $ttlMinutes): void
    {
        $sent = $this->di['mod_service']('email')->sendTemplate([
            'to' => $email,
            'to_name' => 'Quizontal Cloud customer',
            'code' => 'mod_quizontalfreetrial_code',
            'verification_code' => $code,
            'expires_minutes' => $ttlMinutes,
            'trial_days' => $this->getConfig()['trial_days'],
            // Verification codes are useless once the queue gets round to them.
            'send_now' => true,
            'throw_exceptions' => true,
        ]);

        if ($sent === false) {
            // The template row is disabled — the customer would wait forever
            // for a code that was never going to be sent.
            throw new InformationException('We could not send your verification code right now. Please contact support.');
        }
    }

    private function sendTrialReadyEmail($trial): void
    {
        $this->safely(function () use ($trial): void {
            $this->di['mod_service']('email')->sendTemplate([
                'to_client' => (int) $trial->client_id,
                'code' => 'mod_quizontalfreetrial_ready',
                'trial' => $this->toApiArray($trial),
                'service_url' => $this->di['url']->link('order/service/manage/' . (int) $trial->order_id),
                'send_now' => true,
            ]);
        });
    }

    private function sendLifecycleEmail($trial, string $templateCode): void
    {
        if (!$trial->client_id) {
            return;
        }

        $this->di['mod_service']('email')->sendTemplate([
            'to_client' => (int) $trial->client_id,
            'code' => $templateCode,
            'trial' => $this->toApiArray($trial),
            'service_url' => $trial->order_id ? $this->di['url']->link('order/service/manage/' . (int) $trial->order_id) : $this->di['url']->link('order'),
            'upgrade_url' => $this->di['url']->link('order'),
            'grace_days' => $this->getConfig()['grace_days'],
        ]);
    }

    /* =====================================================================
     * Administration
     * ===================================================================== */

    public function search(array $data): array
    {
        $sql = 'SELECT t.*, CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, "")) AS client_name
                FROM quizontal_free_trial t
                LEFT JOIN client c ON c.id = t.client_id
                WHERE 1';
        $params = [];

        if (!empty($data['status'])) {
            $sql .= ' AND t.status = :status';
            $params[':status'] = (string) $data['status'];
        }
        if (!empty($data['client_id'])) {
            $sql .= ' AND t.client_id = :client_id';
            $params[':client_id'] = (int) $data['client_id'];
        }
        if (!empty($data['search'])) {
            $sql .= ' AND (t.email LIKE :search OR t.domain LIKE :search OR t.whatsapp LIKE :search)';
            $params[':search'] = '%' . (string) $data['search'] . '%';
        }

        $sql .= ' ORDER BY t.id DESC';

        return $this->di['pager']->getPaginatedResultSet($sql, $params, (int) ($data['per_page'] ?? 30), (int) ($data['page'] ?? 1));
    }

    public function get(int $id): array
    {
        $trial = $this->di['db']->getExistingModelById('quizontal_free_trial', $id, 'Free trial not found.');

        return $this->toApiArray($trial);
    }

    public function toApiArray($trial): array
    {
        $expiresAt = $trial->expires_at ? strtotime((string) $trial->expires_at) : null;

        return [
            'id' => (int) $trial->id,
            'client_id' => $trial->client_id ? (int) $trial->client_id : null,
            'order_id' => $trial->order_id ? (int) $trial->order_id : null,
            'email' => (string) $trial->email,
            'whatsapp' => (string) $trial->whatsapp,
            'domain' => (string) $trial->domain,
            'name' => trim((string) $trial->first_name . ' ' . (string) $trial->last_name),
            'status' => (string) $trial->status,
            'starts_at' => $trial->starts_at,
            'expires_at' => $trial->expires_at,
            'suspended_at' => $trial->suspended_at,
            'terminated_at' => $trial->terminated_at,
            'days_left' => $expiresAt === null ? null : (int) ceil(($expiresAt - time()) / 86400),
            'last_error' => $trial->last_error,
            'created_at' => $trial->created_at,
        ];
    }

    public function stats(): array
    {
        $counts = ['active' => 0, 'suspended' => 0, 'terminated' => 0, 'failed' => 0, 'total' => 0];
        $rows = $this->di['pdo']->query('SELECT status, COUNT(*) AS total FROM quizontal_free_trial GROUP BY status')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
            $counts['total'] += (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Surfaced in admin settings so a misconfigured product is obvious before
     * the first customer hits the wizard rather than after.
     */
    public function diagnose(): array
    {
        $config = $this->getConfig();
        $result = [
            'enabled' => $config['enabled'],
            'product_id' => $config['product_id'],
            'product_found' => false,
            'product_title' => null,
            'product_type' => null,
            'product_enabled' => false,
            'server_configured' => false,
            'hosting_plan_configured' => false,
            'server_manager' => null,
            'ready' => false,
            'problems' => [],
        ];

        $product = $this->di['db']->load('Product', $config['product_id']);
        if (!$product instanceof \Model_Product) {
            $result['problems'][] = sprintf('Product #%d does not exist.', $config['product_id']);

            return $result;
        }

        $productConfig = json_decode((string) $product->config, true) ?: [];
        $result['product_found'] = true;
        $result['product_title'] = (string) $product->title;
        $result['product_type'] = (string) $product->type;
        $result['product_enabled'] = $product->status === \Model_Product::STATUS_ENABLED;
        $result['server_configured'] = !empty($productConfig['server_id']);
        $result['hosting_plan_configured'] = !empty($productConfig['hosting_plan_id']);

        if ($result['product_type'] !== 'hosting') {
            $result['problems'][] = sprintf('Product #%d is a "%s" product; the free trial needs a hosting product.', $config['product_id'], $result['product_type']);
        }
        if (!$result['product_enabled']) {
            $result['problems'][] = 'The trial product is disabled in the catalog.';
        }
        if (!$result['server_configured']) {
            $result['problems'][] = 'No hosting server is selected on the product configuration tab.';
        }
        if (!$result['hosting_plan_configured']) {
            $result['problems'][] = 'No hosting plan is selected on the product configuration tab.';
        }

        if ($result['server_configured']) {
            $server = $this->di['db']->load('ServiceHostingServer', (int) $productConfig['server_id']);
            if ($server === null) {
                $result['problems'][] = 'The hosting server selected on the product no longer exists.';
            } else {
                $result['server_manager'] = (string) $server->manager;
                if (strtolower((string) $server->manager) !== 'directadmin') {
                    $result['problems'][] = sprintf('The selected server uses the "%s" manager, not DirectAdmin.', $server->manager);
                }
            }
        }

        $result['ready'] = $result['problems'] === [];

        return $result;
    }

    /* =====================================================================
     * Helpers
     * ===================================================================== */

    private function trialProduct(): \Model_Product
    {
        $config = $this->getConfig();
        $product = $this->di['db']->load('Product', $config['product_id']);

        if (!$product instanceof \Model_Product) {
            throw new InformationException('The free trial plan is not available right now. Please contact support.');
        }
        if ($product->type !== 'hosting' || $product->status !== \Model_Product::STATUS_ENABLED) {
            throw new InformationException('The free trial plan is not available right now. Please contact support.');
        }

        return $product;
    }

    private function publicProductSummary(): array
    {
        try {
            $product = $this->trialProduct();
        } catch (\Throwable) {
            return ['available' => false, 'title' => 'Starter hosting', 'description' => ''];
        }

        return [
            'available' => true,
            'id' => (int) $product->id,
            'title' => (string) $product->title,
            'description' => (string) $product->description,
        ];
    }

    private function loggedInClient(): ?\Model_Client
    {
        $clientId = (int) ($this->di['session']->get('client_id') ?? 0);
        if ($clientId < 1) {
            return null;
        }
        $client = $this->di['db']->load('Client', $clientId);

        return $client instanceof \Model_Client ? $client : null;
    }

    private function sanitizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            throw new InformationException('Please enter a valid email address.');
        }

        return $email;
    }

    /**
     * Collapses the aliases that make "one per customer" meaningless:
     * plus-tags everywhere, and dots on Google-hosted mailboxes.
     */
    public function emailKey(string $email): string
    {
        $email = strtolower(trim($email));
        if (!str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $local = explode('+', $local)[0];
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local . '@' . $domain;
    }

    /**
     * Eight characters mixing upper case, lower case, digits and symbols, with
     * at least one of each class guaranteed.
     *
     * Visually ambiguous characters (I/l/1, O/o/0) are left out so nobody
     * mistypes a code read off a phone screen, and the symbol set deliberately
     * avoids < > & " ' so the code survives HTML email and JSON untouched.
     */
    private function generateCode(): string
    {
        $classes = [
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'abcdefghijkmnpqrstuvwxyz',
            '23456789',
            '!@#$%*?+=',
        ];

        $characters = [];
        foreach ($classes as $class) {
            $characters[] = $class[random_int(0, strlen($class) - 1)];
        }

        $pool = implode('', $classes);
        for ($i = count($characters); $i < self::CODE_LENGTH; ++$i) {
            $characters[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        // Fisher-Yates, so the guaranteed characters are not always first.
        for ($i = count($characters) - 1; $i > 0; --$i) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    private function sessionFingerprint(): string
    {
        $id = (string) $this->di['session']->getId();

        return $id === '' ? '' : hash('sha256', $id);
    }

    private function clientIp(): string
    {
        try {
            return (string) $this->di['request']->getClientIp();
        } catch (\Throwable) {
            return '';
        }
    }

    /** Best-effort side effects must never break the customer-facing flow. */
    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            error_log('Free trial: ' . $exception->getMessage());
        }
    }
}
