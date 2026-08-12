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
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'Billing', ':missing' => 'API Key'], 3001);
        }

        if (!empty($options['secret-api-key'])) {
            $this->config['secret-api-key'] = trim((string) $options['secret-api-key']);
        } else {
            throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'Billing', ':missing' => 'Secret API Key'], 3001);
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
        $fqdn = $this->fqdn($domain);

        // The same domain is probed several times within seconds across the
        // purchase path (storefront search -> order-page check -> FOSSBilling
        // re-validates when the item enters the cart). The registrar paces
        // checkDomain though, so replay the fresh verdict instead of paying
        // a live API call every time.
        $cached = $this->availabilityCacheRead($fqdn);
        if ($cached !== null) {
            if (!empty($cached['premium'])) {
                throw new Registrar_Exception('Premium domains cannot be registered through automatic provisioning. Please contact us for assistance.');
            }
            if (isset($cached['available']) && is_bool($cached['available'])) {
                return $cached['available'];
            }
        }

        $data = $this->call('/domain/checkDomain/' . rawurlencode($fqdn));
        $response = $data['response'] ?? [];

        if ($this->truthy($response['isPremium'] ?? $response['premium'] ?? null) || $this->isPremiumPricing($response)) {
            $this->availabilityCacheWrite($fqdn, ['time' => time(), 'premium' => true, 'available' => false]);
            throw new Registrar_Exception('Premium domains cannot be registered through automatic provisioning. Please contact us for assistance.');
        }

        $available = $this->availabilitySaysAvailable($response);
        $this->availabilityCacheWrite($fqdn, ['time' => time(), 'premium' => false, 'available' => $available]);

        return $available;
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
        $details = $this->callQuiet('/domain/get/' . rawurlencode($this->fqdn($domain)));
        if (is_array($details)) {
            throw new Registrar_Exception('This domain is already registered with us and cannot be transferred in.');
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

        $requirements = $this->call('/domain/getRegistrationRequirements/' . rawurlencode($tld));
        if (array_key_exists('apiRegisterable', $requirements) && !$this->truthy($requirements['apiRegisterable'])) {
            $reason = (string) ($requirements['notApiRegisterableReason'] ?? 'this extension has registry rules that prevent API registration');
            throw new Registrar_Exception(sprintf('%s cannot be registered automatically: %s.', strtoupper($tld), $this->neutral($reason)));
        }

        $costCents = $this->quotePriceCents($domain, 'registration');

        $payload = [
            'cost' => $costCents,
            'agreeToTerms' => 'yes',
        ];

        $preview = null;
        $previewError = null;
        try {
            $preview = $this->call('/domain/create/' . rawurlencode($fqdn), $payload + ['dryRun' => true]);
        } catch (Registrar_Exception $exception) {
            // Some refusals arrive as SUCCESS+wouldSucceed=false, others as a
            // hard ERROR ("domain unavailable"). Keep either form for the
            // adoption probe below before surfacing anything to the customer.
            $previewError = $exception;
        }
        $previewFailed = $previewError !== null
            || empty($preview['dryRun'])
            || !$this->truthy($preview['wouldSucceed'] ?? null);
        if ($previewFailed) {
            // Phase-0 manual fulfillment: when prepaid registrar balance is not
            // an option, the store owner buys the domain on the registrar's own
            // website (card checkout) BEFORE marking the store invoice paid.
            // Activation then finds the domain taken — by our own linked
            // account. Detect exactly that case and adopt the domain: the order
            // links the already-owned registration, nothing is charged twice,
            // and the whois sync that follows fills dates/lock/privacy/NS from
            // the account. A domain taken by anyone else still hard-fails.
            if ($this->isDomainInOurAccount($fqdn)) {
                $this->getLog()->info('Porkbun: %s already exists in the linked account (manual purchase) — adopted without charging', $fqdn);
                return true;
            }
            if ($previewError !== null) {
                throw $previewError;
            }
            $message = (string) ($preview['message'] ?? 'the registration preview was refused');
            $this->getLog()->err('Porkbun: registration preview for %s refused: %s', $fqdn, $message);
            throw new Registrar_Exception(sprintf('Registration of %s cannot be completed right now. Please try again or contact support.', $fqdn));
        }

        $result = $this->call('/domain/create/' . rawurlencode($fqdn), $payload, $this->newIdempotencyKey());

        $this->getLog()->info('Porkbun: registered %s (charged %s cents, order %s%s)', $fqdn, $result['cost'] ?? $costCents, $result['orderId'] ?? 'n/a', !empty($result['sandbox']) ? ', sandbox' : '');

        // The domain is already billed at this point, so nameserver failures
        // must not fail the order; staff can re-sync nameservers afterwards.
        $nameservers = $this->nameserversFromDomain($domain);
        if ($nameservers !== []) {
            try {
                $this->call('/domain/updateNs/' . rawurlencode($fqdn), ['ns' => $nameservers]);
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

        $preview = null;
        try {
            $preview = $this->call('/domain/renew/' . rawurlencode($fqdn), ['cost' => $costCents, 'dryRun' => true]);
        } catch (Registrar_Exception $exception) {
            $preview = ['message' => $exception->getMessage()];
        }
        if (empty($preview['dryRun']) || !$this->truthy($preview['wouldSucceed'] ?? null)) {
            $message = (string) ($preview['message'] ?? 'the renewal preview was refused');
            $this->getLog()->err('Porkbun: renewal preview for %s refused: %s', $fqdn, $message);
            throw new Registrar_Exception(sprintf('Renewal of %s cannot be completed right now. Please try again or contact support.', $fqdn));
        }

        $result = $this->call('/domain/renew/' . rawurlencode($fqdn), ['cost' => $costCents], $this->newIdempotencyKey());
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
        $preview = $this->call('/domain/transfer/' . rawurlencode($fqdn), $payload + ['dryRun' => true]);
        if (empty($preview['dryRun']) || !$this->truthy($preview['wouldSucceed'] ?? null)) {
            $message = (string) ($preview['message'] ?? 'the transfer preview was refused');
            $this->getLog()->err('Porkbun: transfer preview for %s refused: %s', $fqdn, $message);
            throw new Registrar_Exception(sprintf('Transfer of %s cannot be started right now. Please try again or contact support.', $fqdn));
        }

        $result = $this->call('/domain/transfer/' . rawurlencode($fqdn), $payload, $this->newIdempotencyKey());
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
        $data = $this->call('/domain/get/' . rawurlencode($this->fqdn($domain)));
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
            $ns = $this->call('/domain/getNs/' . rawurlencode($this->fqdn($domain)));
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

        $this->call('/domain/updateNs/' . rawurlencode($this->fqdn($domain)), ['ns' => $nameservers]);

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

        $this->call('/domain/updateContacts/' . rawurlencode($this->fqdn($domain)), $payload);

        return true;
    }

    /**
     * Porkbun does not expose deletions; domains simply expire when not renewed.
     *
     * @throws Registrar_Exception always
     */
    public function deleteDomain(Registrar_Domain $domain)
    {
        throw new Registrar_Exception('Domains cannot be deleted through the API. Let the domain expire instead (disable auto-renewal in the registrar control panel).');
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

        throw new Registrar_Exception('WHOIS privacy was switched off for this domain at the registrar. Staff can re-enable it in the registrar control panel; it is always free.');
    }

    /**
     * @throws Registrar_Exception always (no API endpoint at Porkbun)
     */
    public function disablePrivacyProtection(Registrar_Domain $domain)
    {
        throw new Registrar_Exception('WHOIS privacy cannot be disabled through the API. Use the registrar control panel if this is really required.');
    }

    /**
     * New Porkbun domains are locked by default; the lock is managed in the
     * Porkbun control panel (no API endpoint exists).
     *
     * @throws Registrar_Exception always
     */
    public function lock(Registrar_Domain $domain)
    {
        throw new Registrar_Exception('Domains are transfer-locked by default and the lock can only be managed in the registrar control panel.');
    }

    /**
     * @throws Registrar_Exception always (no API endpoint at Porkbun)
     */
    public function unlock(Registrar_Domain $domain)
    {
        throw new Registrar_Exception('Unlocking is only possible in the registrar control panel (toggle Registrar Lock off).');
    }

    /**
     * Outbound authorization codes are shown in the Porkbun control panel.
     *
     * @throws Registrar_Exception always (no API endpoint at Porkbun)
     */
    public function getEpp(Registrar_Domain $domain)
    {
        throw new Registrar_Exception('Transfer authorization codes are issued in the registrar control panel. Fetch it there and paste it for the customer.');
    }

    // ---------------------------------------------------------------------
    // DNS zone management (Quizontal Cloud domain manager)
    //
    // Customer-facing record CRUD. Every write carries an Idempotency-Key so
    // browser retries cannot double-apply, and the central call() helper
    // already strips provider branding from any error the customer sees.
    // These are account-level API calls, so they work the same for domains
    // the adapter registered and for domains adopted after a manual purchase.
    // ---------------------------------------------------------------------

    public function supportsDnsRecords(): bool
    {
        return true;
    }

    /**
     * All editable zone records (SOA and default NS are excluded upstream).
     * Names are shortened to the subdomain form ('' for the zone root).
     */
    public function dnsListRecords(string $fqdn): array
    {
        $data = $this->call('/dns/retrieve/' . rawurlencode($fqdn));
        $rows = [];
        foreach ((array) ($data['records'] ?? []) as $row) {
            $rows[] = [
                'id' => (string) ($row['id'] ?? ''),
                'name' => $this->shortenRecordName((string) ($row['name'] ?? ''), $fqdn),
                'type' => strtoupper((string) ($row['type'] ?? '')),
                'content' => (string) ($row['content'] ?? ''),
                'ttl' => (int) ($row['ttl'] ?? 600),
                'prio' => (int) ($row['prio'] ?? 0),
                'notes' => (string) ($row['notes'] ?? ''),
            ];
        }

        return ['records' => $rows, 'cloudflare' => $this->truthy($data['cloudflare'] ?? null)];
    }

    /** @return string the new record id */
    public function dnsCreateRecord(string $fqdn, array $record): string
    {
        $result = $this->call('/dns/create/' . rawurlencode($fqdn), $record, $this->newIdempotencyKey());
        return (string) ($result['id'] ?? '');
    }

    public function dnsEditRecord(string $fqdn, string $recordId, array $record): bool
    {
        $this->call('/dns/edit/' . rawurlencode($fqdn) . '/' . rawurlencode($recordId), $record, $this->newIdempotencyKey());
        return true;
    }

    public function dnsDeleteRecord(string $fqdn, string $recordId): bool
    {
        $this->call('/dns/delete/' . rawurlencode($fqdn) . '/' . rawurlencode($recordId), [], $this->newIdempotencyKey());
        return true;
    }

    /** 'www.example.com' -> 'www'; 'example.com' -> '' (zone root). */
    private function shortenRecordName(string $name, string $fqdn): string
    {
        $name = strtolower(rtrim(trim($name), '.'));
        $fqdn = strtolower($fqdn);
        if ($name === $fqdn) return '';
        $suffix = '.' . $fqdn;
        if (str_ends_with($name, $suffix)) return substr($name, 0, -strlen($suffix));
        return $name;
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

    // -----------------------------------------------------------------
    // Freshness window for availability verdicts. Just long enough for one
    // shopper's search -> order page -> cart-add trip; short enough that a
    // different shopper always gets a fresh verdict.
    // -----------------------------------------------------------------
    private const AVAIL_CACHE_TTL = 45;

    private function availabilityCacheFile(string $fqdn): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'qc-domain-availability'
            . DIRECTORY_SEPARATOR . sha1($fqdn) . '.json';
    }

    /** @return array|null The cached verdict payload, null when missing or stale */
    private function availabilityCacheRead(string $fqdn): ?array
    {
        try {
            $file = $this->availabilityCacheFile($fqdn);
            if (!is_file($file)) {
                return null;
            }
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (!is_array($decoded) || !isset($decoded['time'])) {
                return null;
            }
            if ((time() - (int) $decoded['time']) > self::AVAIL_CACHE_TTL) {
                return null;
            }

            return $decoded;
        } catch (\Throwable) {
            return null; // caching must never break a sale
        }
    }

    private function availabilityCacheWrite(string $fqdn, array $payload): void
    {
        try {
            $file = $this->availabilityCacheFile($fqdn);
            $dir = dirname($file);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return;
            }
            @file_put_contents($file, json_encode($payload), LOCK_EX);
        } catch (\Throwable) {
            // see above — worst case simply falls back to live calls
        }
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
        $data = $this->call('/domain/checkDomain/' . rawurlencode($this->fqdn($domain)), $body);
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
        $data = $this->call('/pricing/get', ['tld' => $tld]);
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
    private function callQuiet(string $path, array $body = []): ?array
    {
        try {
            return $this->call($path, $body);
        } catch (Registrar_Exception) {
            return null;
        }
    }

    /**
     * True only when the domain already lives inside the Porkbun account these
     * API keys belong to. This is what makes manual website purchases adoptable
     * at activation time; it can never mistake a stranger's registration for
     * ours because /domain/get only answers for domains in the account.
     */
    private function isDomainInOurAccount(string $fqdn): bool
    {
        return is_array($this->callQuiet('/domain/get/' . rawurlencode($fqdn)));
    }

    /**
     * Central HTTP call with authentication, test-mode cross-checks, redacted
     * logging and uniform error handling.
     *
     * @throws Registrar_Exception on transport errors, HTTP errors, or API errors
     */
    private function call(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        $apiKey = (string) $this->config['api-key'];
        $secretKey = (string) $this->config['secret-api-key'];

        $sandboxKey = str_starts_with($apiKey, 'pk1_sb_') || str_starts_with($secretKey, 'sk1_sb_');
        $liveKey = str_starts_with($apiKey, 'pk1_') && !$sandboxKey;
        if ($sandboxKey === $liveKey) {
            throw new Registrar_Exception('The API key pair looks inconsistent. Use a matching live pair (pk1_…/sk1_…) or sandbox pair (pk1_sb_…/sk1_sb_…).');
        }
        if ($this->_testMode && !$sandboxKey) {
            throw new Registrar_Exception('Test mode is enabled but live keys are configured. Enter the sandbox keys (pk1_sb_…) or disable Test mode.');
        }
        if (!$this->_testMode && $sandboxKey) {
            throw new Registrar_Exception('Sandbox keys are configured while Test mode is disabled. Enable Test mode for sandbox keys, or enter the live keys.');
        }

        // Every Porkbun v3 endpoint — reads included — accepts POST with a
        // JSON body. Historic docs REQUIRED POST for everything; newer docs
        // merely also allow GET for reads. Sending reads as POST keeps the
        // adapter compatible with both.
        $headers = [
            'X-API-Key' => $apiKey,
            'X-Secret-API-Key' => $secretKey,
            'Content-Type' => 'application/json',
        ];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $options = ['headers' => $headers, 'json' => (object) $body, 'timeout' => 30];

        try {
            $response = $this->getHttpClient()->request('POST', $this->apiUrl . $path, $options);
            $statusCode = $response->getStatusCode();
            $decoded = json_decode($response->getContent(false), true);
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface $e) {
            $this->getLog()->err('Porkbun: transport failure on POST %s: %s', $path, $e->getMessage());
            throw new Registrar_Exception('The domain service could not be reached. Please try again.');
        }

        if (!is_array($decoded)) {
            throw new Registrar_Exception(sprintf('The domain service returned an unexpected response (HTTP %s). Please try again.', $statusCode));
        }

        if ($statusCode >= 400 || ($decoded['status'] ?? '') !== 'SUCCESS') {
            $message = (string) ($decoded['message'] ?? sprintf('HTTP %s', $statusCode));
            $code = (string) ($decoded['code'] ?? '');

            // Upstream pacing (rate limits / cooldowns): the order form retries
            // automatically when it sees the "(retry in Ns)" marker, so give it
            // a calm, brand-free sentence and keep the raw detail in the log.
            $looksLikeCooldown = ($code !== '' && stripos($code, 'RATE') !== false)
                || preg_match('/\bwithin\b.+\bused\b|\brate.?limit/i', $message) === 1;
            if ($looksLikeCooldown) {
                $retry = $decoded['ttlRemaining'] ?? null;
                if (!is_numeric($retry) && preg_match('/(\d+)\s*(?:seconds?|secs?|s)\b/i', $message, $match)) {
                    $retry = $match[1];
                }
                $retry = max(2, (int) ceil((float) ($retry ?: 6)));
                $this->getLog()->err('Porkbun POST %s paced: %s%s', $path, $code !== '' ? '[' . $code . '] ' : '', $message);

                throw new Registrar_Exception(sprintf('Our availability checker is cooling down. (retry in %ds)', $retry));
            }

            $this->getLog()->err('Porkbun POST %s failed: %s%s', $path, $code !== '' ? '[' . $code . '] ' : '', $message);

            throw new Registrar_Exception($this->neutral($message));
        }

        return $decoded;
    }

    /**
     * Error text reaching customers must never name upstream providers or
     * expose internal error codes. Server-side logs keep the full detail.
     */
    private function neutral(string $message): string
    {
        $message = preg_replace('/pork\s*bun|inter-?server/i', 'the domain registrar', $message) ?? $message;
        $message = preg_replace('/\s*\(\d{3,}\)\s*$/', '', $message) ?? $message;
        $message = trim((string) preg_replace('/\s{2,}/', ' ', $message));

        return $message !== '' ? $message : 'The domain service reported an error. Please try again.';
    }
}
