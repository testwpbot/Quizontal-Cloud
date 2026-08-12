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
        $result = $adapter->dnsListRecords($fqdn);

        return [
            'domain' => $fqdn,
            'records' => $result['records'],
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

        foreach ($adapter->dnsListRecords($fqdn)['records'] ?? [] as $row) {
            $sameName = strcasecmp((string) $row['name'], $record['name']) === 0;
            $sameContent = strcasecmp(trim((string) $row['content']), $record['content']) === 0;
            if ($sameName && $row['type'] === $record['type'] && $sameContent) {
                return ['id' => (string) $row['id'], 'record' => $record, 'already_existed' => true];
            }
        }

        $id = $adapter->dnsCreateRecord($fqdn, $record);
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

        $adapter->dnsEditRecord($fqdn, $recordId, $record);

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
            if ($stillThere) throw $exception;
        }

        return true;
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

    /** Records only resolve publicly while the zone is hosted on registrar DNS. */
    private function usesRegistrarNameservers(\Model_ServiceDomain $service): bool
    {
        $ns = [];
        foreach (['ns1', 'ns2', 'ns3', 'ns4'] as $field) {
            $value = strtolower(trim((string) ($service->{$field} ?? '')));
            if ($value !== '') $ns[] = $value;
        }
        if ($ns === []) return false;
        foreach ($ns as $host) {
            if (!str_ends_with($host, '.ns.porkbun.com')) return false;
        }

        return true;
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
