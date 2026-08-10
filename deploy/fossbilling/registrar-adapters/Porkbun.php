<?php

/**
 * Porkbun domain registrar adapter for FOSSBilling.
 *
 * Developed for Quizontal Cloud (https://github.com/testwpbot/Quizontal-Cloud).
 * Targets the Porkbun API v3: https://porkbun.com/api/json/v3/documentation
 *
 * Design notes
 * ------------
 * - Nothing is hardcoded: API keys and the (optional) API base URL are entered
 *   by the administrator under "Domain registrars" in the FOSSBilling admin
 *   area and stored in FOSSBilling's own database (tld_registrar.config).
 * - Test mode uses FOSSBilling's built-in registrar "Test mode" flag together
 *   with Porkbun sandbox keys (pk1_sb_… / sk1_sb_…). The adapter refuses to mix
 *   sandbox keys with live mode (or live keys with test mode) so a mis-click can
 *   never charge a real card or hit production by accident.
 * - Every billable operation (register / renew / transfer) is first rehearsed
 *   with Porkbun's dryRun flag and only then committed with an Idempotency-Key,
 *   so retries can never register or charge for the same domain twice.
 * - The price Porkbun actually charges must match the quote from checkDomain.
 *   Quotes are carried through from the availability call and never invented by
 *   this adapter.
 */

class Registrar_Adapter_Porkbun extends Registrar_AdapterAbstract
{
    private const DEFAULT_API_URL = 'https://api.porkbun.com/api/json/v3';

    public $config = [
        'api-key' => null,
        'secret-api-key' => null,
        'api-url' => null,
    ];

    private string $apiUrl = self::DEFAULT_API_URL;

    public function __construct($options)
    {
        if (!empty($options['api-key'])) {
            $this->config['api-key'] = trim((string) $options['api-key']);
        } else {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'Porkbun', ':missing' => 'API Key'], 3001);
        }

        if (!empty($options['secret-api-key'])) {
            $this->config['secret-api-key'] = trim((string) $options['secret-api-key']);
        } else {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'Porkbun', ':missing' => 'Secret API Key'], 3001);
        }

        if (!empty($options['api-url'])) {
            $this->apiUrl = rtrim(trim((string) $options['api-url']), '/');
        }
    }

    public static function getConfig()
    {
        return [
            'label' => 'Manages domains on Porkbun via API v3. Keep "Test mode" enabled while using sandbox keys (pk1_sb_… / sk1_sb_…) for free end-to-end testing, then disable it and switch to live keys (pk1_… / sk1_…) for real orders.',
            'form' => [
                'api-key' => [
                    'password', [
                        'label' => 'API Key',
                        'description' => 'Create keys at porkbun.com/account/api. Sandbox keys start with pk1_sb_, live keys with pk1_.',
                        'required' => true,
                    ],
                ],
                'secret-api-key' => [
                    'password', [
                        'label' => 'Secret API Key',
                        'description' => 'The secret that belongs to the API key above (sk1_…).',
                        'required' => true,
                    ],
                ],
                'api-url' => [
                    'text', [
                        'label' => 'API Base URL',
                        'description' => 'Leave empty for the default https://api.porkbun.com/api/json/v3 (the sandbox uses the same URL; only the key changes).',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Availability / transferability
    // ---------------------------------------------------------------------

    /**
     * @return bool True when the domain is available for registration
     *
     * @throws Registrar_Exception on premium names or API errors
     */
    public function isDomainAvailable(Registrar_Domain $domain)
    {
        $data = $this->call('POST', '/domain/checkDomain/' . rawurlencode($this->fqdn($domain)));
        $response = $data['response'] ?? [];

        if ($this->truthy($response['isPremium'] ?? $response['premium'] ?? null) || $this->isPremiumPricing($response)) {
            throw new Registrar_Exception('Premium domains cannot be registered through automatic provisioning. Please contact us for assistance.');
        }

        return $this->availabilitySaysAvailable($response);
    }

    /**
     * A domain can be transferred to Porkbun when a transfer price exists for
     * its TLD (Porkbun omits transfer pricing for API-unsupported TLDs such as
     * .uk) and the domain is not already inside the Porkbun account.
     *
     * @throws Registrar_Exception
     */
    public function isDomaincanBeTransferred(Registrar_Domain $domain)
    {
        $details = $this->callQuiet('GET', '/domain/get/' . rawurlencode($this->fqdn($domain)));
        if (is_array($details)) {
            throw new Registrar_Exception('This domain is already in our Porkbun account and cannot be transferred in.');
        }

        $transferPrice = $this->tldPrice(ltrim((string) $domain->getTld(), '.'), 'transfer');
        if ($transferPrice === null) {
            throw new Registrar_Exception('This extension cannot be transferred to us automatically. Please contact support.');
        }

        return true;
    }

    // ---------------------------------------------------------------------
    // Registration / renewal / transfer
    // ---------------------------------------------------------------------

    /**
     * Registers a domain at Porkbun.
     *
     * Flow: TLD requirements check -> fresh price quote -> dry-run rehearsal
     * -> idempotent commit -> best-effort nameserver assignment.
     *
     * @throws Registrar_Exception
     */
    public function registerDomain(Registrar_Domain $domain)
    {
        $fqdn = $this->fqdn($domain);
        $tld = ltrim((string) $domain->getTld(), '.');

        $requirements = $this->call('GET', '/domain/getRegistrationRequirements/' . rawurlencode($tld));
        if (array_key_exists('apiRegisterable', $requirements) && !$this->truthy($requirements['apiRegisterable'])) {
            $reason = (string) ($requirements['notApiRegisterableReason'] ?? 'this extension has registry rules that prevent API registration');
            throw new Registrar_Exception(sprintf('%s cannot be registered automatically: %s.', strtoupper($tld), $reason));
        }

        $costCents = $this->quotePriceCents($domain, 'registration');

        $payload = [
            'cost' => $costCents,
            'agreeToTerms' => 'yes',
        ];

        $preview = $this->call('POST', '/domain/create/' . rawurlencode($fqdn), $payload + ['dryRun' => true]);
        if (empty($preview['dryRun']) || !$this->truthy($preview['wouldSucceed'] ?? null)) {
            $message = (string) ($preview['message'] ?? 'the provider refused the registration preview');
            throw new Registrar_Exception(sprintf('Registration of %s cannot be completed right now: %s', $fqdn, $message));
        }

        $result = $this->call('POST', '/domain/create/' . rawurlencode($fqdn), $payload, $this->newIdempotencyKey());

        $this->getLog()->info('Porkbun: registered %s (charged %s cents, order %s%s)', $fqdn, $result['cost'] ?? $costCents, $result['orderId'] ?? 'n/a', !empty($result['sandbox']) ? ', sandbox' : '');

        // The domain is already billed at this point, so nameserver failures
        // must not fail the order; staff can re-sync nameservers afterwards.
        $nameservers = $this->nameserversFromDomain($domain);
        if ($nameservers !== []) {
            try {
                $this->call('POST', '/domain/updateNs/' . rawurlencode($fqdn), ['ns' => $nameservers]);
            } catch (Registrar_Exception $e) {
                $this->getLog()->err('Porkbun: nameserver update for the newly registered %s failed: %s', $fqdn, $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Renews a domain (dry-run first, idempotent commit).
     *
     * @throws Registrar_Exception
     */
    public function renewDomain(Registrar_Domain $domain)
    {
        $fqdn = $this->fqdn($domain);
        $costCents = $this->quotePriceCents($domain, 'renewal');

        $preview = $this->call('POST', '/domain/renew/' . rawurlencode($fqdn), ['cost' => $costCents, 'dryRun' => true]);
        if (empty($preview['dryRun']) || !$this->truthy($preview['wouldSucceed'] ?? null)) {
            $message = (string) ($preview['message'] ?? 'the provider refused the renewal preview');
            throw new Registrar_Exception(sprintf('Renewal of %s cannot be completed right now: %s', $fqdn, $message));
        }

        $result = $this->call('POST', '/domain/renew/' . rawurlencode($fqdn), ['cost' => $costCents], $this->newIdempotencyKey());
        $this->getLog()->info('Porkbun: renewed %s until %s (charged %s cents)', $fqdn, $result['expirationDate'] ?? 'unknown', $result['cost'] ?? $costCents);

        return true;
    }

    /**
     * Starts an inbound transfer (5–7 days at the registries).
     *
     * @throws Registrar_Exception
     */
    public function transferDomain(Registrar_Domain $domain)
    {
        $fqdn = $this->fqdn($domain);

        $epp = trim((string) ($domain->getEpp() ?? ''));
        if ($epp === '') {
            throw new Registrar_Exception('The transfer authorization (EPP) code is required to transfer this domain.');
        }

        $costCents = $this->quotePriceCents($domain, 'transfer');

        $payload = ['authCode' => $epp, 'cost' => $costCents];
        $preview = $this->call('POST', '/domain/transfer/' . rawurlencode($fqdn), $payload + ['dryRun' => true]);
        if (empty($preview['dryRun']) || !$this->truthy($preview['wouldSucceed'] ?? null)) {
            $message = (string) ($preview['message'] ?? 'the provider refused the transfer preview');
            throw new Registrar_Exception(sprintf('Transfer of %s cannot be started right now: %s', $fqdn, $message));
        }

        $result = $this->call('POST', '/domain/transfer/' . rawurlencode($fqdn), $payload, $this->newIdempotencyKey());
        $this->getLog()->info('Porkbun: transfer of %s started (transfer %s, charged %s cents)', $fqdn, $result['transferId'] ?? 'n/a', $result['cost'] ?? $costCents);

        return true;
    }

    // ---------------------------------------------------------------------
    // Domain information / management
    // ---------------------------------------------------------------------

    /**
     * @throws Registrar_Exception when the domain is not in the account
     */
    public function getDomainDetails(Registrar_Domain $domain)
    {
        $data = $this->call('GET', '/domain/get/' . rawurlencode($this->fqdn($domain)));
        $info = $data['domain'] ?? [];

        if (!empty($info['expireDate'])) {
            $domain->setExpirationTime(strtotime((string) $info['expireDate']));
        }
        if (!empty($info['createDate'])) {
            $domain->setRegistrationTime(strtotime((string) $info['createDate']));
        }
        $domain->setLocked($this->truthy($info['securityLock'] ?? $info['locked'] ?? null) ? 1 : 0);
        $domain->setPrivacyEnabled($this->truthy($info['whoisPrivacy'] ?? $info['privacy'] ?? null) ? 1 : 0);

        try {
            $ns = $this->call('GET', '/domain/getNs/' . rawurlencode($this->fqdn($domain)));
            $list = array_values(array_filter((array) ($ns['ns'] ?? [])));
            $domain->setNs1($list[0] ?? null);
            $domain->setNs2($list[1] ?? null);
            $domain->setNs3($list[2] ?? null);
            $domain->setNs4($list[3] ?? null);
        } catch (Registrar_Exception $e) {
            $this->getLog()->err('Porkbun: could not read nameservers for %s: %s', $this->fqdn($domain), $e->getMessage());
        }

        return $domain;
    }

    /**
     * @throws Registrar_Exception
     */
    public function modifyNs(Registrar_Domain $domain)
    {
        $nameservers = $this->nameserversFromDomain($domain);
        if ($nameservers === []) {
            throw new Registrar_Exception('At least one nameserver is required.');
        }

        $this->call('POST', '/domain/updateNs/' . rawurlencode($this->fqdn($domain)), ['ns' => $nameservers]);

        return true;
    }

    /**
     * Pushes the registrant contact from FOSSBilling to Porkbun.
     * Other roles are left untouched (Porkbun keeps unspecified roles as-is).
     *
     * @throws Registrar_Exception
     */
    public function modifyContact(Registrar_Domain $domain)
    {
        $contact = $domain->getContactRegistrar();

        $payload = [
            'contacts' => [
                'registrant' => [
                    'firstName' => (string) $contact->getFirstName(),
                    'lastName' => (string) $contact->getLastName(),
                    'organization' => (string) $contact->getCompany(),
                    'address1' => (string) $contact->getAddress1(),
                    'address2' => (string) $contact->getAddress2(),
                    'city' => (string) $contact->getCity(),
                    'state' => (string) $contact->getState(),
                    'postalCode' => (string) $contact->getZip(),
                    'country' => strtoupper((string) $contact->getCountry()),
                    'phone' => (string) $contact->getTel(),
                    'phoneCountryCode' => (string) $contact->getTelCc(),
                    'email' => (string) $contact->getEmail(),
                ],
            ],
        ];

        $this->call('POST', '/domain/updateContacts/' . rawurlencode($this->fqdn($domain)), $payload);

        return true;
    }

    /**
     * Porkbun does not expose deletions; domains simply expire when not renewed.
     *
     * @throws Registrar_Exception always
     */
    public function deleteDomain(Registrar_Domain $domain)
    {
        throw new Registrar_Exception('Porkbun does not delete domains via the API. Let the domain expire instead (disable auto-renewal in the Porkbun control panel).');
    }

    /**
     * WHOIS privacy is free and enabled automatically on registrations. For
     * domains registered before this integration, enabling is confirmed when
     * already on; otherwise staff enable it in the Porkbun panel.
     *
     * @throws Registrar_Exception when the privacy flag is not already enabled
     */
    public function enablePrivacyProtection(Registrar_Domain $domain)
    {
        $current = $this->getDomainDetails($domain);
        if ($current->getPrivacyEnabled()) {
            return true;
        }

        throw new Registrar_Exception('WHOIS privacy was switched off for this domain in the Porkbun control panel. Re-enable it there (Domain Management → Details → WHOIS Privacy); it is always free.');
    }

    /**
     * @throws Registrar_Exception always (no API endpoint at Porkbun)
     */
    public function disablePrivacyProtection(Registrar_Domain $domain)
    {
        throw new Registrar_Exception('Porkbun does not allow disabling WHOIS privacy via the API. Use the Porkbun control panel if this is really required.');
    }

    /**
     * New Porkbun domains are locked by default; the lock is managed in the
     * Porkbun control panel (no API endpoint exists).
     *
     * @throws Registrar_Exception always
     */
    public function lock(Registrar_Domain $domain)
    {
        throw new Registrar_Exception('Domains at Porkbun are transfer-locked by default and the lock can only be managed in the Porkbun control panel (Domain Management → Details).');
    }

    /**
     * @throws Registrar_Exception always (no API endpoint at Porkbun)
     */
    public function unlock(Registrar_Domain $domain)
    {
        throw new Registrar_Exception('Unlocking is only possible in the Porkbun control panel (Domain Management → Details → toggle Registrar Lock off).');
    }

    /**
     * Outbound authorization codes are shown in the Porkbun control panel.
     *
     * @throws Registrar_Exception always (no API endpoint at Porkbun)
     */
    public function getEpp(Registrar_Domain $domain)
    {
        throw new Registrar_Exception('Transfer authorization codes are issued in the Porkbun control panel: Domain Management → Details → Authorization Code. Fetch it there and paste it for the customer.');
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function fqdn(Registrar_Domain $domain): string
    {
        return strtolower($domain->getSld() . $domain->getTld());
    }

    /**
     * @return string[] Non-empty nameservers, lowercased, preserving order
     */
    private function nameserversFromDomain(Registrar_Domain $domain): array
    {
        $nameservers = [];
        foreach ([$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()] as $ns) {
            $ns = strtolower(trim((string) $ns));
            if ($ns !== '' && !in_array($ns, $nameservers, true)) {
                $nameservers[] = $ns;
            }
        }

        return $nameservers;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function availabilitySaysAvailable(array $response): bool
    {
        foreach (['avail', 'available', 'availability'] as $key) {
            if (!array_key_exists($key, $response)) {
                continue;
            }
            $value = $response[$key];
            if (is_bool($value)) {
                return $value;
            }

            return in_array(strtolower(trim((string) $value)), ['yes', 'true', '1', 'available'], true);
        }

        return false;
    }

    private function isPremiumPricing(array $response): bool
    {
        if (isset($response['pricing']) && is_array($response['pricing'])) {
            foreach (['premium', 'isPremium'] as $key) {
                if ($this->truthy($response['pricing'][$key] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns a fresh Porkbun quote (integer cents) for the domain operation.
     * Never trusts local state: Porkbun rejects mismatched cost values anyway,
     * and the dry-run step double-checks the quote before any charge.
     *
     * @throws Registrar_Exception
     */
    private function quotePriceCents(Registrar_Domain $domain, string $type): int
    {
        $body = [];
        if ($type !== 'registration') {
            $body['priceType'] = $type;
        }
        $data = $this->call('POST', '/domain/checkDomain/' . rawurlencode($this->fqdn($domain)), $body);
        $response = (array) ($data['response'] ?? []);

        // The top-level "price" belongs to the response's own type, so only
        // trust it when checkDomain answered for the operation we quoted
        // (otherwise a registration price could be picked for a renewal).
        $responseType = strtolower(trim((string) ($response['type'] ?? '')));
        $candidates = [];
        if ($responseType === '' || $responseType === $type) {
            $candidates[] = $response['price'] ?? null;
        }
        $candidates[] = $response['additional'][$type]['price'] ?? null;
        $candidates[] = $response['pricing'][$type] ?? null;
        $candidates[] = $response[$type]['price'] ?? null;
        $candidates[] = $response[$type . 'Price'] ?? null;
        foreach ($candidates as $raw) {
            $cents = $this->dollarsToCents($raw);
            if ($cents !== null) {
                return $cents;
            }
        }

        if ($type === 'transfer') {
            $cents = $this->dollarsToCents($this->tldPrice(ltrim((string) $domain->getTld(), '.'), 'transfer'));
            if ($cents !== null) {
                return $cents;
            }
        }

        throw new Registrar_Exception(sprintf('Could not determine the current %s price for %s. Please try again.', $type, $this->fqdn($domain)));
    }

    /**
     * Looks up a price component from Porkbun's public price list.
     *
     * @return string|null Dollar amount as string, or null when unavailable
     */
    private function tldPrice(string $tld, string $component): ?string
    {
        $data = $this->call('GET', '/pricing/get', ['tld' => $tld]);
        $pricing = (array) ($data['pricing'] ?? $data['prices'] ?? []);
        $entry = $pricing[$tld] ?? null;
        if (is_array($entry) && isset($entry[$component])) {
            return (string) $entry[$component];
        }

        return null;
    }

    /**
     * Converts a dollar amount ("9.73", 9.73) to integer cents, null when
     * the input does not look like a usable amount.
     */
    private function dollarsToCents(mixed $amount): ?int
    {
        if ($amount === null || is_bool($amount) || is_array($amount)) {
            return null;
        }
        $amount = trim((string) $amount);
        if ($amount === '' || !is_numeric($amount)) {
            return null;
        }

        $cents = (int) round(((float) $amount) * 100);

        return $cents > 0 ? $cents : null;
    }

    private function newIdempotencyKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Performs a call and RETURNS NULL instead of throwing on failure
     * (used for "is this domain in the account?" style probes).
     */
    private function callQuiet(string $method, string $path, array $body = []): ?array
    {
        try {
            return $this->call($method, $path, $body);
        } catch (Registrar_Exception) {
            return null;
        }
    }

    /**
     * Central HTTP call with authentication, test-mode cross-checks, redacted
     * logging and uniform error handling.
     *
     * @throws Registrar_Exception on transport errors, HTTP errors, or API errors
     */
    private function call(string $method, string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        $apiKey = (string) $this->config['api-key'];
        $secretKey = (string) $this->config['secret-api-key'];

        $sandboxKey = str_starts_with($apiKey, 'pk1_sb_') || str_starts_with($secretKey, 'sk1_sb_');
        $liveKey = str_starts_with($apiKey, 'pk1_') && !$sandboxKey;
        if ($sandboxKey === $liveKey) {
            throw new Registrar_Exception('The Porkbun API key pair looks inconsistent. Use a matching live pair (pk1_…/sk1_…) or sandbox pair (pk1_sb_…/sk1_sb_…).');
        }
        if ($this->_testMode && !$sandboxKey) {
            throw new Registrar_Exception('This registrar is in Test mode but live Porkbun keys are configured. Enter the sandbox keys (pk1_sb_…) or disable Test mode.');
        }
        if (!$this->_testMode && $sandboxKey) {
            throw new Registrar_Exception('Sandbox Porkbun keys are configured while Test mode is disabled. Enable Test mode for sandbox keys, or enter the live keys.');
        }

        $headers = [
            'X-API-Key' => $apiKey,
            'X-Secret-API-Key' => $secretKey,
        ];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $options = ['headers' => $headers, 'timeout' => 30];
        if (strtoupper($method) === 'POST') {
            $headers['Content-Type'] = 'application/json';
            $options['json'] = (object) $body;
        } elseif ($body !== []) {
            $options['query'] = $body;
        }

        try {
            $response = $this->getHttpClient()->request(strtoupper($method), $this->apiUrl . $path, $options);
            $statusCode = $response->getStatusCode();
            $decoded = json_decode($response->getContent(false), true);
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface $e) {
            throw new Registrar_Exception('Could not reach Porkbun: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new Registrar_Exception(sprintf('Porkbun returned an unexpected response (HTTP %s).', $statusCode));
        }

        if ($statusCode >= 400 || ($decoded['status'] ?? '') !== 'SUCCESS') {
            $message = (string) ($decoded['message'] ?? sprintf('HTTP %s', $statusCode));
            $code = (string) ($decoded['code'] ?? '');
            if ($code !== '' && stripos($code, 'RATE') !== false && isset($decoded['ttlRemaining'])) {
                $message .= sprintf(' (retry in %ss)', $decoded['ttlRemaining']);
            }
            $this->getLog()->err('Porkbun %s %s failed: %s%s', strtoupper($method), $path, $code !== '' ? '[' . $code . '] ' : '', $message);

            throw new Registrar_Exception('Porkbun: ' . $message);
        }

        return $decoded;
    }
}
