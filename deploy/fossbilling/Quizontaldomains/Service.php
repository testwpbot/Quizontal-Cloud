<?php

declare(strict_types=1);

namespace Box\Mod\Quizontaldomains;

use FOSSBilling\InformationException;

/**
 * Customer self-service DNS record management.
 *
 * Every call re-proves order ownership, resolves the TLD registrar adapter
 * through the stock Servicedomain service, and only talks to adapters that
 * implement the DNS surface (currently the Quizontal Porkbun adapter).
 * Record writes are plain zone operations at the registrar — no billing,
 * no balance, idempotency-keyed — and every adapter error reaching this
 * layer is already free of provider branding.
 */
class Service implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    /** Types customers may manage. NS records stay registrar-owned by design. */
    public const RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'ALIAS', 'MX', 'TXT', 'SRV', 'CAA'];

    private const MIN_TTL = 600;   // registrar account minimum
    private const MAX_TTL = 86400;

    /**
     * Welcome-page target shown on freshly sold domains — a host on the
     * store's own network, so a customer never meets the upstream supplier.
     * Overridable without code changes via the extension config key parked_ip.
     */
    private const DEFAULT_PARKED_IP = '216.219.95.93';

    /** Upstream parking IPs — the ONLY whitelisted fingerprints the sweeper may remove. */
    private const PARKING_IPS = ['44.227.65.245', '44.227.76.166'];

    /** Hostname marker of the upstream's parking infrastructure (pixie, fwd, spf...). */
    private const PARKING_HOSTMARKER = 'porkbun.com';

    /** Record types the sweeper may touch. NS is deliberately absent — untouchable. */
    private const SWEEPABLE_TYPES = ['A', 'AAAA', 'ALIAS', 'CNAME', 'MX', 'TXT', 'URL', 'FWD', 'REDIRECT'];

    /** Minimum seconds between automatic branding passes for one order. */
    private const AUTOBRAND_INTERVAL = 3600;

    /**
     * Load the order, prove the logged-in client owns it, and resolve the
     * domain service record plus its FQDN.
     *
     * @return array{0: \Model_ClientOrder, 1: \Model_ServiceDomain, 2: string}
     */
    public function findOwnedDomainService(int $clientId, int $orderId): array
    {
        $order = $this->di['db']->findOne('ClientOrder', 'id = ? AND client_id = ?', [$orderId, $clientId]);
        // Domain orders carry the PRODUCT TYPE ('domain') in service_type;
        // getOrderService() maps it to the ServiceDomain model for us.
        if (!$order instanceof \Model_ClientOrder || !in_array((string) $order->service_type, ['domain', 'servicedomain'], true)) {
            throw new InformationException('Domain service not found.');
        }
        $manageable = [\Model_ClientOrder::STATUS_ACTIVE, \Model_ClientOrder::STATUS_FAILED_RENEW];
        if (!in_array($order->status, $manageable, true)) {
            throw new InformationException('This domain is not in a manageable state.');
        }
        $service = $this->di['mod_service']('Order')->getOrderService($order);
        if (!$service instanceof \Model_ServiceDomain) {
            throw new InformationException('Domain service details were not found.');
        }
        $fqdn = strtolower(trim((string) $service->sld) . trim((string) $service->tld));
        if ($fqdn === '') {
            throw new InformationException('Domain name is missing on the service record.');
        }

        return [$order, $service, $fqdn];
    }

    /**
     * Theme probe: fail-soft capability check used to decide whether the
     * manage page renders the DNS tab at all. Every refusal reason is logged
     * so a hidden tab is never a silent mystery.
     */
    public function supported(int $clientId, int $orderId): array
    {
        try {
            [$order, $service, $fqdn] = $this->findOwnedDomainService($clientId, $orderId);
            $this->adapterFor($service, $order);
            // Self-heals branding + nameserver fields for anything the
            // activation pass could not reach (throttled per order).
            $this->maybeAutoBrand($order, $service);

            return [
                'supported' => true,
                'domain' => $fqdn,
                'registrar_nameservers' => $this->usesRegistrarNameservers($service),
            ];
        } catch (\Throwable $exception) {
            if (isset($this->di['logger'])) {
                $this->di['logger']->info('Quizontaldomains: DNS tab hidden (order #%d, client #%d): %s', $orderId, $clientId, $exception->getMessage());
            }

            return ['supported' => false];
        }
    }

    public function listRecords(int $clientId, int $orderId): array
    {
        [$order, $service, $fqdn] = $this->findOwnedDomainService($clientId, $orderId);
        $adapter = $this->adapterFor($service, $order);
        $result = $this->dnsGuard(fn () => $adapter->dnsListRecords($fqdn));

        // Nameserver rows stay out of the zone view: they belong to the
        // Nameservers tab, and keeping upstream hostnames out of here keeps
        // the customer experience brand-clean.
        $records = array_values(array_filter(
            $result['records'] ?? [],
            static fn (array $row): bool => strtoupper((string) ($row['type'] ?? '')) !== 'NS'
        ));

        return [
            'domain' => $fqdn,
            'records' => $records,
            'cloudflare_proxy' => (bool) ($result['cloudflare'] ?? false),
            'registrar_nameservers' => $this->usesRegistrarNameservers($service),
            'types' => self::RECORD_TYPES,
            'min_ttl' => self::MIN_TTL,
            'max_ttl' => self::MAX_TTL,
        ];
    }

    /**
     * Create with a duplicate guard: if the identical record already exists,
     * return it instead of stacking clones on browser retries/double-clicks.
     */
    public function createRecord(int $clientId, int $orderId, array $input): array
    {
        [$order, $service, $fqdn] = $this->findOwnedDomainService($clientId, $orderId);
        $adapter = $this->adapterFor($service, $order);
        $record = $this->validateRecord($input, $fqdn);

        foreach ($this->dnsGuard(fn () => $adapter->dnsListRecords($fqdn))['records'] ?? [] as $row) {
            $sameName = strcasecmp((string) $row['name'], $record['name']) === 0;
            $sameContent = strcasecmp(trim((string) $row['content']), $record['content']) === 0;
            if ($sameName && $row['type'] === $record['type'] && $sameContent) {
                return ['id' => (string) $row['id'], 'record' => $record, 'already_existed' => true];
            }
        }

        $id = $this->dnsGuard(fn () => $adapter->dnsCreateRecord($fqdn, $record));
        if ($id === '') {
            throw new InformationException('The record was created but no reference was returned. Refresh the list in a moment.');
        }

        return ['id' => $id, 'record' => $record, 'already_existed' => false];
    }

    public function editRecord(int $clientId, int $orderId, string $recordId, array $input): array
    {
        [$order, $service, $fqdn] = $this->findOwnedDomainService($clientId, $orderId);
        $adapter = $this->adapterFor($service, $order);
        $record = $this->validateRecord($input, $fqdn);

        $this->dnsGuard(fn () => $adapter->dnsEditRecord($fqdn, $recordId, $record));

        return ['id' => $recordId, 'record' => $record];
    }

    /**
     * Delete is tolerant of repeats: when the upstream delete refused, the goal
     * is considered met only if a fresh listing proves the record is really
     * gone — anything else rethrows the original error.
     */
    public function deleteRecord(int $clientId, int $orderId, string $recordId): bool
    {
        [$order, $service, $fqdn] = $this->findOwnedDomainService($clientId, $orderId);
        $adapter = $this->adapterFor($service, $order);

        try {
            $adapter->dnsDeleteRecord($fqdn, $recordId);
        } catch (\Registrar_Exception $exception) {
            $stillThere = false;
            foreach (($adapter->dnsListRecords($fqdn)['records'] ?? []) as $row) {
                if ((string) $row['id'] === $recordId) {
                    $stillThere = true;
                    break;
                }
            }
            if ($stillThere) {
                // Re-raise through the guard so customer-facing wording stays clean.
                $this->dnsGuard(function () use ($exception) { throw $exception; });
            }
        }

        return true;
    }

    // ---------------------------------------------------------------------
    // Branding engine: parked-page replacement + upstream fingerprint sweep
    //
    // Runs three times in a domain's life: right after order activation
    // (event hook below), throttled on manage-page views (self-heals anything
    // the activation pass could not reach, like a domain that was not opted
    // into the registrar API yet), and on demand from the staff API.
    // ---------------------------------------------------------------------

    /**
     * The Hook module wires any public Service method typed on Box_Event as a
     * listener when extensions activate / hooks reconnect — so this fires the
     * moment a domain order activates (checkout auto-activation, staff
     * re-activate, adoption). Fail-soft by design: activation of a paid order
     * must never break because of a cosmetic branding step.
     */
    public static function onAfterAdminOrderActivate(\Box_Event $event): void
    {
        $orderId = (int) ($event->getParameters()['id'] ?? 0);
        if ($orderId <= 0) {
            return;
        }
        $di = $event->getDi();
        try {
            $order = $di['db']->load('ClientOrder', $orderId);
            if (!$order instanceof \Model_ClientOrder) {
                return;
            }
            if (!in_array((string) $order->service_type, ['domain', 'servicedomain'], true)) {
                return;
            }
            $di['mod_service']('quizontaldomains')->applyBrandingToService($order, 'activation');
        } catch (\Throwable $exception) {
            if (isset($di['logger'])) {
                $di['logger']->err('Quizontaldomains: post-activation branding skipped (order #%d): %s', $orderId, $exception->getMessage());
            }
        }
    }

    /**
     * One idempotent pass that makes a domain brand-clean:
     *   1. sync the registrar's current nameservers into ns1..ns4 (fills the
     *      Nameservers tab — after a manual adoption those fields can sit empty);
     *   2. sweep the upstream parking records (whitelisted fingerprints only);
     *   3. plant our own A records pointing at the Quizontal welcome page —
     *      but only when no record the sweeper rejects already claims the name.
     *
     * Every external step is individually wrapped: a partial failure is
     * logged and retried on the next pass, never fatal to the caller.
     */
    public function applyBrandingToService(\Model_ClientOrder $order, string $trigger = 'manual'): array
    {
        $summary = ['trigger' => $trigger, 'domain' => '', 'ns_synced' => false, 'swept' => 0, 'branded' => false, 'deferred' => false];

        $service = $this->di['mod_service']('Order')->getOrderService($order);
        if (!$service instanceof \Model_ServiceDomain) {
            throw new InformationException('Domain service details were not found.');
        }
        $fqdn = strtolower(trim((string) $service->sld) . trim((string) $service->tld));
        $summary['domain'] = $fqdn;
        $adapter = $this->adapterFor($service, $order);

        try {
            $summary['ns_synced'] = $this->syncNameservers($service, $adapter);
        } catch (\Throwable $exception) {
            if (isset($this->di['logger'])) {
                $this->di['logger']->info('Quizontaldomains: nameserver sync deferred for %s: %s', $fqdn, $exception->getMessage());
            }
        }

        try {
            $records = $adapter->dnsListRecords($fqdn)['records'] ?? [];
        } catch (\Throwable $exception) {
            // Zone API not usable yet (e.g. the registrar's per-domain API
            // opt-in is still pending) — the throttled auto pass retries it.
            $summary['deferred'] = true;
            if (isset($this->di['logger'])) {
                $this->di['logger']->info('Quizontaldomains: branding deferred for %s: %s', $fqdn, $exception->getMessage());
            }

            return $summary;
        }

        foreach ($records as $record) {
            if (!$this->isParkingFingerprint($record)) {
                continue;
            }
            try {
                $adapter->dnsDeleteRecord($fqdn, (string) $record['id']);
                $summary['swept']++;
            } catch (\Throwable $exception) {
                if (isset($this->di['logger'])) {
                    $this->di['logger']->err('Quizontaldomains: could not sweep parking record #%s on %s: %s', $record['id'] ?? '?', $fqdn, $exception->getMessage());
                }
            }
        }

        $summary['branded'] = $this->ensureBrandedDefaults($adapter, $fqdn, $records);

        if (isset($this->di['logger'])) {
            $this->di['logger']->info(
                'Quizontaldomains: branding pass (%s) for %s — nameservers %s, %d parking record(s) swept, welcome records %s.',
                $trigger,
                $fqdn,
                $summary['ns_synced'] ? 'synced' : 'unchanged',
                $summary['swept'],
                $summary['branded'] ? 'planted' : 'already in place'
            );
        }

        return $summary;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Resolve the registrar adapter for the domain's extension. The TLD carries
     * the registrar link; when the stored link is missing or stale (e.g. the
     * price was created from the products screen before a registrar linkage
     * existed), fall back to the live TLD record for the service extension.
     */
    private function adapterFor(\Model_ServiceDomain $service, \Model_ClientOrder $order)
    {
        $tldRegistrarId = (int) ($service->tld_registrar_id ?? 0);
        if ($tldRegistrarId <= 0) {
            $tld = $this->di['db']->findOne('Tld', 'tld = ?', [(string) $service->tld]);
            $tldRegistrarId = (int) ($tld->tld_registrar_id ?? 0);
        }
        $tldRegistrar = $tldRegistrarId > 0 ? $this->di['db']->load('TldRegistrar', $tldRegistrarId) : null;
        if (!$tldRegistrar instanceof \Model_TldRegistrar) {
            throw new InformationException('No registrar is configured for this domain extension.');
        }
        $adapter = $this->di['mod_service']('Servicedomain')->registrarGetRegistrarAdapter($tldRegistrar, $order);
        if (!method_exists($adapter, 'dnsListRecords')) {
            throw new InformationException('DNS management is not available for this domain. Contact support and we will adjust records for you.');
        }

        return $adapter;
    }

    /**
     * Tri-state: TRUE = every stored nameserver is the registrar's DNS (zone
     * records here are live), FALSE = at least one custom nameserver (zone
     * records are not live — worth a banner), NULL = nothing stored yet
     * (fresh adoption — don't warn either way).
     */
    private function usesRegistrarNameservers(\Model_ServiceDomain $service): ?bool
    {
        $ns = [];
        foreach (['ns1', 'ns2', 'ns3', 'ns4'] as $field) {
            $value = strtolower(trim((string) ($service->{$field} ?? '')));
            if ($value !== '') $ns[] = $value;
        }
        if ($ns === []) return null;
        foreach ($ns as $host) {
            if (!str_ends_with($host, '.ns.porkbun.com')) return false;
        }

        return true;
    }

    /**
     * Self-healing branding pass tied to the manage-page probe, throttled per
     * order through an extension_meta marker. Covers everything the activation
     * hook could not finish (most commonly: the domain had no registrar API
     * opt-in yet). The marker stores the ATTEMPT time, so even a failing
     * upstream can never turn a page render into a retry loop.
     */
    private function maybeAutoBrand(\Model_ClientOrder $order, \Model_ServiceDomain $service): void
    {
        try {
            $stamp = $this->di['db']->findOne(
                'extension_meta',
                " extension = 'quizontaldomains' AND rel_type = 'order' AND rel_id = ? AND meta_key = 'branding_last_run' ",
                [(string) $order->id]
            );
            $lastRun = $stamp ? strtotime((string) $stamp->meta_value) : 0;
            if ($lastRun > 0 && (time() - $lastRun) < self::AUTOBRAND_INTERVAL) {
                return;
            }

            // Attempt-time marker FIRST: even a permanently failing upstream
            // backs off for an hour instead of retrying on every page view.
            if (!$stamp) {
                $stamp = $this->di['db']->dispense('extension_meta');
                $stamp->extension = 'quizontaldomains';
                $stamp->rel_type = 'order';
                $stamp->rel_id = (string) $order->id;
                $stamp->meta_key = 'branding_last_run';
                $stamp->created_at = date('Y-m-d H:i:s');
            }
            $stamp->meta_value = date('Y-m-d H:i:s');
            $stamp->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($stamp);

            $this->applyBrandingToService($order, 'auto');
        } catch (\Throwable $exception) {
            if (isset($this->di['logger'])) {
                $this->di['logger']->info('Quizontaldomains: auto branding skipped (order #%d): %s', $order->id, $exception->getMessage());
            }
        }
    }

    /**
     * Copy the registrar's current nameservers into the service fields so the
     * Nameservers tab and the lock-state banner quote reality. Only fills
     * empty fields — a customer's own nameserver edit is never overwritten.
     */
    private function syncNameservers(\Model_ServiceDomain $service, $adapter): bool
    {
        $wrapper = new \Registrar_Domain();
        $wrapper->setSld((string) $service->sld);
        $wrapper->setTld((string) $service->tld);
        $details = $adapter->getDomainDetails($wrapper);

        $changed = false;
        foreach (['ns1' => 'getNs1', 'ns2' => 'getNs2', 'ns3' => 'getNs3', 'ns4' => 'getNs4'] as $field => $getter) {
            if (trim((string) ($service->{$field} ?? '')) !== '') {
                continue;
            }
            $fresh = strtolower(trim((string) ($details->{$getter}() ?? '')));
            if ($fresh !== '') {
                $service->{$field} = $fresh;
                $changed = true;
            }
        }
        if ($changed) {
            $this->di['db']->store($service);
        }

        return $changed;
    }

    /**
     * Plant welcome-page A records for the root and the wildcard — unless a
     * record the sweeps rejects already claims that name. Works off the
     * pre-sweep listing, so the just-deleted parking rows can never fake
     * "already exists" and block planting.
     */
    private function ensureBrandedDefaults($adapter, string $fqdn, array $records): bool
    {
        $ip = $this->parkedIp();
        $planted = false;
        foreach (['', '*'] as $name) {
            $exists = false;
            foreach ($records as $record) {
                if ((string) ($record['name'] ?? '') !== $name) {
                    continue;
                }
                if (!in_array(strtoupper((string) ($record['type'] ?? '')), ['A', 'AAAA', 'ALIAS', 'CNAME', 'URL'], true)) {
                    continue;
                }
                if ($this->isParkingFingerprint($record)) {
                    continue; // being swept this very pass
                }
                $exists = true;
                break;
            }
            if ($exists) {
                continue;
            }
            try {
                $adapter->dnsCreateRecord($fqdn, ['name' => $name, 'type' => 'A', 'content' => $ip, 'ttl' => self::MIN_TTL, 'prio' => 0]);
                $planted = true;
            } catch (\Throwable $exception) {
                if (isset($this->di['logger'])) {
                    $this->di['logger']->err('Quizontaldomains: could not plant the welcome record on %s: %s', $fqdn, $exception->getMessage());
                }
            }
        }

        return $planted;
    }

    /**
     * Whitelist match on the upstream's default parking assets: their parking
     * IPs and any answer pointing at their own hostnames. Anything that does
     * not match is considered customer content and is left untouched — the
     * sweep can therefore never eat a real record.
     */
    private function isParkingFingerprint(array $record): bool
    {
        if (!in_array(strtoupper((string) ($record['type'] ?? '')), self::SWEEPABLE_TYPES, true)) {
            return false; // NS and anything unknown are untouchable
        }
        $content = strtolower(rtrim(trim((string) ($record['content'] ?? '')), '.'));
        if (in_array($content, self::PARKING_IPS, true)) {
            return true;
        }

        return $content !== '' && str_contains($content, self::PARKING_HOSTMARKER);
    }

    /**
     * Welcome-page target IP. Defaults to the store network; can be moved
     * later from the extension settings (parked_ip) without a code change.
     */
    private function parkedIp(): string
    {
        try {
            $config = $this->di['mod']('quizontaldomains')->getConfig();
            $ip = trim((string) ($config['parked_ip'] ?? ''));
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        } catch (\Throwable) {
            // no stored config — the default carries us
        }

        return self::DEFAULT_PARKED_IP;
    }

    /**
     * Route adapter failures to customer-safe wording. Anything describing a
     * store-side setting the customer cannot change (like the registrar's
     * per-domain API opt-in) becomes a generic retry/contact message, with
     * the raw reason logged for the store owner.
     *
     * @throws InformationException
     */
    private function dnsGuard(callable $operation)
    {
        try {
            return $operation();
        } catch (\Registrar_Exception $exception) {
            $message = $exception->getMessage();
            if (stripos($message, 'opted in to API access') !== false) {
                if (isset($this->di['logger'])) {
                    $this->di['logger']->err('Quizontaldomains: DNS refused — domain is not opted into registrar API access. Enable it in the registrar dashboard. Raw reason: %s', $message);
                }
                throw new InformationException('DNS management is being enabled for this domain. Please try again shortly, or contact support and we will finish the setup.');
            }
            throw new InformationException($message !== '' ? $message : 'The domain DNS service reported an error.');
        }
    }

    /**
     * Normalize + validate one record into the exact payload the adapter
     * sends upstream. Throws friendly, specific messages on bad input.
     */
    private function validateRecord(array $input, string $fqdn): array
    {
        $type = strtoupper(trim((string) ($input['type'] ?? '')));
        if (!in_array($type, self::RECORD_TYPES, true)) {
            throw new InformationException('Choose a record type: A, AAAA, CNAME, ALIAS, MX, TXT, SRV or CAA.');
        }

        // Name: accept '@'/blank for the root and auto-strip the zone suffix
        // when a full hostname is pasted (www.example.com -> www).
        $name = strtolower(trim((string) ($input['name'] ?? '')));
        $name = rtrim($name === '@' ? '' : $name, '.');
        $zone = strtolower($fqdn);
        if ($name === $zone) {
            $name = '';
        } elseif (str_ends_with($name, '.' . $zone)) {
            $name = substr($name, 0, -(strlen($zone) + 1));
        }
        if ($name !== '' && !preg_match('/^(\*|[a-z0-9_](?:[a-z0-9_\-]{0,61}[a-z0-9_])?)(\.[a-z0-9_](?:[a-z0-9_\-]{0,61}[a-z0-9_])?)*$/', $name)) {
            throw new InformationException('Enter a valid record name — like www, mail, * or @ for the root domain.');
        }

        $content = trim((string) ($input['content'] ?? ''));
        switch ($type) {
            case 'A':
                if (!filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    throw new InformationException('An A record needs a valid IPv4 address, like 192.0.2.10.');
                }
                break;
            case 'AAAA':
                if (!filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    throw new InformationException('An AAAA record needs a valid IPv6 address, like 2001:db8::10.');
                }
                break;
            case 'CNAME':
            case 'ALIAS':
            case 'MX':
                $host = strtolower(rtrim($content, '.'));
                if (!preg_match('/^([a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?)+$/', $host)) {
                    throw new InformationException('Enter a valid target hostname, like mail.example.com.');
                }
                $content = $host;
                break;
            case 'TXT':
                if ($content === '' || strlen($content) > 512) {
                    throw new InformationException('TXT content must be between 1 and 512 characters.');
                }
                break;
            case 'SRV':
                if (!preg_match('/^\d{1,5}\s+\d{1,5}\s+\S+$/', $content)) {
                    throw new InformationException('SRV content format is: weight port target — for example 0 443 sip.example.com.');
                }
                break;
            case 'CAA':
                if (!preg_match('/^\d{1,3}\s+(issue|issuewild|iodef)\s+\S+$/i', $content)) {
                    throw new InformationException('CAA content format is: flags tag value — for example 0 issue "letsencrypt.org".');
                }
                break;
        }

        $ttl = (int) ($input['ttl'] ?? self::MIN_TTL);
        $ttl = max(self::MIN_TTL, min(self::MAX_TTL, $ttl));

        $prio = in_array($type, ['MX', 'SRV'], true) ? max(0, (int) ($input['prio'] ?? 10)) : 0;

        return ['name' => $name, 'type' => $type, 'content' => $content, 'ttl' => $ttl, 'prio' => $prio];
    }
}
