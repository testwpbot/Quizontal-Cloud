<?php

declare(strict_types=1);

namespace Box\Mod\Serviceinterserver;

use FOSSBilling\Config;
use FOSSBilling\InformationException;

class Service implements \FOSSBilling\InjectionAwareInterface
{
    private const HOSTNAME_TAKEN_MESSAGE = 'This hostname is already in use. Please enter a different hostname.';
    private const HOSTNAME_BLOCKING_ORDER_STATUSES = ['pending_setup', 'active', 'suspended'];
    private const HOSTNAME_PRECHECK_TIMEOUT = 12;

    /** Per-request memoization so the cart and validation gates share one provider lookup. */
    private array $providerHostnameCache = [];

    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }

    public function getModulePermissions(): array
    {
        return [
            'manage' => ['type' => 'bool', 'display_name' => 'Manage cloud provisioning', 'description' => 'Configure products and manage cloud provisioning.'],
            'manage_settings' => [],
        ];
    }

    public function install(): bool
    {
        $this->di['db']->exec("CREATE TABLE IF NOT EXISTS `service_interserver` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `client_id` BIGINT UNSIGNED NOT NULL,
            `order_id` BIGINT UNSIGNED NOT NULL,
            `platform` VARCHAR(32) NOT NULL,
            `slices` INT UNSIGNED NOT NULL,
            `location` INT UNSIGNED NOT NULL,
            `os_distro` VARCHAR(100) NOT NULL,
            `os_version` VARCHAR(191) NOT NULL,
            `hostname` VARCHAR(255) NOT NULL,
            `coupon` VARCHAR(64) NULL,
            `control_panel` VARCHAR(32) NOT NULL DEFAULT 'none',
            `provider_cost_usd` DECIMAL(12,2) NULL,
            `expected_cost_usd` DECIMAL(12,2) NULL,
            `provider_vps_id` VARCHAR(100) NULL,
            `primary_ip` VARCHAR(64) NULL,
            `power_status` VARCHAR(40) NULL,
            `bandwidth_used_gb` DECIMAL(14,2) NULL,
            `disk_used_gb` DECIMAL(14,2) NULL,
            `encrypted_root_password` TEXT NULL,
            `password_viewed_at` DATETIME NULL,
            `status` VARCHAR(40) NOT NULL DEFAULT 'pending_validation',
            `validation_response` MEDIUMTEXT NULL,
            `provider_response` MEDIUMTEXT NULL,
            `last_error` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `service_interserver_order_unique` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->ensureColumn('primary_ip', 'VARCHAR(64) NULL');
        $this->ensureColumn('power_status', 'VARCHAR(40) NULL');
        $this->ensureColumn('bandwidth_used_gb', 'DECIMAL(14,2) NULL');
        $this->ensureColumn('disk_used_gb', 'DECIMAL(14,2) NULL');
        $this->ensureColumn('encrypted_root_password', 'TEXT NULL');
        $this->ensureColumn('password_viewed_at', 'DATETIME NULL');
        $this->ensureColumn('provider_response', 'MEDIUMTEXT NULL');
        $this->ensureColumn('ready_at', 'DATETIME NULL');
        $this->ensureColumn('coupon', 'VARCHAR(64) NULL');
        return true;
    }

    public function uninstall(): bool
    {
        // Preserve service mappings for financial and provisioning audit safety.
        return true;
    }

    public function attachOrderConfig(\Model_Product $product, array $data): array
    {
        $private = json_decode($product->config ?? '', true) ?? [];
        foreach (['provider', 'platform', 'slices', 'expected_cost_usd'] as $key) {
            if (array_key_exists($key, $private)) $data[$key] = $private[$key];
        }
        $data['controlPanel'] = $data['controlPanel'] ?? 'none';
        return $data;
    }

    public function validateOrderData(array &$data, bool $verifyAvailability = true): void
    {
        foreach (['platform', 'slices', 'expected_cost_usd', 'location', 'osDistro', 'osVersion', 'hostname'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') throw new InformationException("Missing VPS configuration field: {$field}.");
        }
        if (($data['provider'] ?? 'interserver') !== 'interserver') throw new InformationException('Unsupported VPS provider.');
        if (!in_array($data['platform'], ['kvm', 'kvmstorage', 'hyperv'], true)) throw new InformationException('Invalid VPS platform.');
        $data['slices'] = (int) $data['slices'];
        if ($data['slices'] < 1 || $data['slices'] > 32) throw new InformationException('Invalid VPS plan size.');
        if ($data['platform'] === 'hyperv' && $data['slices'] < 2) throw new InformationException('Hyper-V requires at least two plan units.');
        $data['location'] = (int) $data['location'];
        if (!in_array($data['location'], [1, 2, 3], true)) throw new InformationException('Invalid VPS location.');
        if (!$this->isAllowedOs($data['platform'], (string) $data['osDistro'], (string) $data['osVersion'])) throw new InformationException('The selected operating system is not valid for this plan.');

        // Hostname: allow FQDN (server1.example.com) OR single label (vps3559895) for manual assign
        $data['hostname'] = strtolower(trim((string) $data['hostname']));
        if ($data['hostname'] === '') throw new InformationException('Enter a valid hostname.');
        $isFqdn = str_contains($data['hostname'], '.');
        if ($isFqdn) {
            if (!filter_var($data['hostname'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                throw new InformationException('Enter a valid fully-qualified hostname like server1.example.com.');
            }
            // extra safety: must have at least one dot and TLD >=2 chars
            if (!preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $data['hostname'])) {
                throw new InformationException('Enter a valid fully-qualified hostname like server1.example.com.');
            }
        } else {
            // single label - allow InterServer auto-generated like vps3559895
            if (!preg_match('/^[a-z0-9]([a-z0-9-]{1,61}[a-z0-9])?$/', $data['hostname'])) {
                throw new InformationException('Enter a valid hostname. Use FQDN like server1.example.com or single label like vps3559895 (3-63 chars).');
            }
        }

        // Coupon - optional, for InterServer promo codes
        $data['coupon'] = strtolower(trim((string) ($data['coupon'] ?? '')));
        if ($data['coupon'] !== '' && !preg_match('/^[a-z0-9_-]{2,32}$/', $data['coupon'])) {
            throw new InformationException('Invalid coupon format. Use letters, numbers, dash or underscore, 2-32 chars.');
        }

        // A duplicate hostname must fail before any order, invoice, or wallet charge exists.
        // The activation path calls this with $verifyAvailability=false because it performs
        // its own provider-aware reconciliation instead (safe against retry races).
        if ($verifyAvailability) $this->assertHostnameAvailable($data['hostname']);
        $data['controlPanel'] = 'none';
    }

    /**
     * Rejects a hostname that is already taken by another order or by any cloud
     * server in the infrastructure account. The provider lookup is fail-open:
     * when the infrastructure API is unreachable the local check still applies
     * and the guarded activation flow remains the final line of protection.
     */
    public function assertHostnameAvailable(string $hostname, ?int $excludeOrderId = null, bool $includeProvider = true): void
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '') return;
        if ($this->hostnameTakenLocally($hostname, $excludeOrderId)) {
            throw new InformationException(self::HOSTNAME_TAKEN_MESSAGE);
        }
        if ($includeProvider && $this->providerHostnameTaken($hostname) === true) {
            throw new InformationException(self::HOSTNAME_TAKEN_MESSAGE);
        }
    }

    private function hostnameTakenLocally(string $hostname, ?int $excludeOrderId = null): bool
    {
        $blocking = implode(',', array_map(fn (string $status) => $this->di['pdo']->quote($status), self::HOSTNAME_BLOCKING_ORDER_STATUSES));
        $sql = 'SELECT si.id FROM service_interserver si'
            .' INNER JOIN client_order co ON co.id = si.order_id'
            .' WHERE si.hostname = :hostname'
            ." AND (si.provider_vps_id IS NOT NULL OR co.status IN ({$blocking}))";
        $bindings = ['hostname' => $hostname];
        if ($excludeOrderId !== null) {
            $sql .= ' AND co.id != :exclude_order';
            $bindings['exclude_order'] = $excludeOrderId;
        }
        $sql .= ' LIMIT 1';
        $statement = $this->di['pdo']->prepare($sql);
        $statement->execute($bindings);
        return $statement->fetchColumn() !== false;
    }

    private function hostnameInCart(int $cartId, string $hostname): bool
    {
        foreach ($this->di['db']->find('CartProduct', 'cart_id = ?', [$cartId]) as $item) {
            $config = json_decode((string) ($item->config ?? ''), true);
            if (is_array($config) && strtolower(trim((string) ($config['hostname'] ?? ''))) === $hostname) return true;
        }
        return false;
    }

    /** Returns true when the hostname exists in the infrastructure account, or null when that could not be determined. */
    private function providerHostnameTaken(string $hostname): ?bool
    {
        $hostname = strtolower(trim($hostname));
        if (array_key_exists($hostname, $this->providerHostnameCache)) return $this->providerHostnameCache[$hostname];
        try {
            [$status, $response] = $this->providerRequest('GET', '/vps', null, self::HOSTNAME_PRECHECK_TIMEOUT);
        } catch (\Throwable) {
            return $this->providerHostnameCache[$hostname] = null;
        }
        if ($status < 200 || $status >= 300) return $this->providerHostnameCache[$hostname] = null;
        $matches = [];
        $this->collectProviderServices($response, $hostname, null, $matches);
        return $this->providerHostnameCache[$hostname] = count($matches) > 0;
    }

    public function create($order, $existing = null)
    {
        if (is_object($existing)) return $existing;
        $config = json_decode($order->config ?? '', true) ?? [];
        $this->validateOrderData($config, false);
        // Local duplicates are still impossible at activation time; the provider-side
        // duplicate path is intentionally left to placeLiveOrder() so retries can reconcile.
        $this->assertHostnameAvailable((string) $config['hostname'], (int) $order->id, false);
        $duplicate = $this->di['db']->findOne('service_interserver', 'order_id = ?', [(int) $order->id]);
        if ($duplicate) return $duplicate;

        $model = $this->di['db']->dispense('service_interserver');
        $model->client_id = (int) $order->client_id;
        $model->order_id = (int) $order->id;
        $model->platform = $config['platform'];
        $model->slices = $config['slices'];
        $model->location = $config['location'];
        $model->os_distro = $config['osDistro'];
        $model->os_version = $config['osVersion'];
        $model->hostname = $config['hostname'];
        $model->coupon = $config['coupon'] ?? null;
        $model->control_panel = 'none';
        $model->expected_cost_usd = (float) $config['expected_cost_usd'];
        $model->status = 'pending_validation';
        $model->created_at = date('Y-m-d H:i:s');
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        return $model;
    }

    public function activate($order, $model): array
    {
        if (!is_object($model)) throw new InformationException('Cloud service record was not created.');
        if ($model->status === 'provisioned' && $model->provider_vps_id) return ['cloud_service_id' => $model->provider_vps_id];
        if (in_array($model->status, ['submitting', 'manual_review'], true)) {
            return ['provisioning_state' => $model->status, 'duplicate_submission_blocked' => true];
        }
        $provisioningMode = $this->mode();

        $rootPassword = $this->generatePassword();
        $payload = [
            'osDistro' => $model->os_distro,
            'slices' => (int) $model->slices,
            'vpsPlatform' => $model->platform,
            'controlPanel' => 'none',
            'period' => 1,
            'location' => (int) $model->location,
            'osVersion' => $model->os_version,
            'hostname' => $model->hostname,
            'coupon' => trim((string) ($model->coupon ?? '')),
            'rootpass' => $rootPassword,
        ];

        $model->status = 'validating';
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        [$httpCode, $response] = $this->validateWithProvider($payload);
        $safe = $this->redact($response);
        $model->validation_response = json_encode(['http_status' => $httpCode] + $safe, JSON_UNESCAPED_SLASHES);
        $model->provider_cost_usd = isset($response['monthly_service_cost']) ? (float) $response['monthly_service_cost'] : null;
        $model->updated_at = date('Y-m-d H:i:s');

        if ($httpCode < 200 || $httpCode >= 300 || ($response['continue'] ?? false) !== true) {
            $model->status = 'validation_failed';
            $model->last_error = $this->responseMessage($safe);
            $this->di['db']->store($model);
            throw new InformationException('Cloud configuration validation failed: '.$model->last_error);
        }

        // Allow cheaper when coupon is used - only fail if provider MORE expensive than expected + tolerance
        $tolerance = max(0, (float) ($this->getConfig()['cost_tolerance_usd'] ?? 0.01));
        $expected = (float) $model->expected_cost_usd;
        $provider = $model->provider_cost_usd;
        $hasCoupon = trim((string) ($model->coupon ?? '')) !== '';
        if ($provider === null) {
            $model->status = 'price_review';
            $model->last_error = 'Provider did not return a price.';
            $this->di['db']->store($model);
            throw new InformationException($model->last_error);
        }
        // If coupon present, cheaper is OK. If no coupon, allow small overage tolerance, but cheaper is also OK (price drop)
        if ($provider - $expected > $tolerance) {
            $model->status = 'price_review';
            $model->last_error = 'Provider price differs from the imported expected price.';
            $this->di['db']->store($model);
            throw new InformationException($model->last_error);
        }

        $model->status = 'validated';
        $model->last_error = null;
        $this->di['db']->store($model);

        if ($provisioningMode !== 'live') {
            return ['provisioning_mode' => 'test', 'validation_status' => 'validated'];
        }

        return $this->placeLiveOrder($order, $model, $payload, $rootPassword);
    }

    private function placeLiveOrder($order, $model, array $payload, string $rootPassword): array
    {
        $this->assertOrderPaid((int) $order->id);
        if (!$this->claimLiveSubmission((int) $model->id)) {
            $current = $this->di['db']->load('service_interserver', (int) $model->id);
            if ($current && $current->provider_vps_id) return ['cloud_service_id' => (string) $current->provider_vps_id, 'duplicate_submission_blocked' => true];
            return ['provisioning_state' => (string) ($current->status ?? 'submitting'), 'duplicate_submission_blocked' => true];
        }
        $model->status = 'submitting';
        $existing = $this->findProviderServiceByHostname((string) $model->hostname);
        if ($existing !== null) {
            if (empty($model->provider_response)) {
                $model->status = 'manual_review';
                $model->last_error = 'A cloud server already uses this hostname. Administrator reconciliation is required.';
                $this->di['db']->store($model);
                throw new InformationException($model->last_error);
            }
            $model->provider_vps_id = (string) $existing['id'];
            $model->status = 'provisioned';
            $model->encrypted_root_password = null;
            $model->provider_response = json_encode($this->redact($existing), JSON_UNESCAPED_SLASHES);
            $this->applyProviderDetails($model, $existing);
            $this->di['db']->store($model);
            return ['cloud_service_id' => $model->provider_vps_id, 'reconciled' => true];
        }

        $model->status = 'submitting';
        // Persist the generated credential before the non-idempotent request so it
        // remains available if the provider creates the server but the response is incomplete.
        $model->encrypted_root_password = $this->di['crypt']->encrypt($rootPassword, Config::getProperty('info.salt'));
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        [$httpCode, $response] = $this->providerRequest('POST', '/vps/order', $payload);
        $model->provider_response = json_encode(['http_status' => $httpCode] + $this->redact($response), JSON_UNESCAPED_SLASHES);
        $model->updated_at = date('Y-m-d H:i:s');
        if ($httpCode < 200 || $httpCode >= 300 || (($response['continue'] ?? true) === false)) {
            $model->status = 'provisioning_failed';
            $model->last_error = $this->responseMessage($response);
            $this->di['db']->store($model);
            throw new InformationException('Cloud provisioning failed: '.$model->last_error);
        }

        $providerId = $this->extractProviderId($response);
        $reconciled = null;
        if ($providerId === null) {
            // New services can take a few seconds to appear in the account list.
            // Poll with GET only; never repeat the purchasing POST.
            for ($attempt = 1; $attempt <= 6 && $providerId === null; $attempt++) {
                if ($attempt > 1) sleep(2);
                $reconciled = $this->findProviderServiceByHostname((string) $model->hostname);
                $providerId = $reconciled['id'] ?? null;
            }
        }
        if ($providerId === null) {
            $model->status = 'manual_review';
            $model->last_error = 'The provider accepted the order but did not return a service ID. Do not retry automatically.';
            $this->di['db']->store($model);
            throw new InformationException($model->last_error);
        }

        $model->provider_vps_id = (string) $providerId;
        $model->encrypted_root_password = $this->di['crypt']->encrypt($rootPassword, Config::getProperty('info.salt'));
        $model->status = 'provisioned';
        $model->last_error = null;
        $this->di['db']->store($model);
        try {
            $this->syncService($model);
        } catch (\Throwable $exception) {
            $model->last_error = 'Server was created, but the first detail synchronization is still pending.';
            $this->di['db']->store($model);
        }
        return ['cloud_service_id' => (string) $providerId];
    }

    public function reconcileExisting($order, $model): bool
    {
        if (!is_object($model)) throw new InformationException('Cloud service record was not found.');
        if ($model->provider_vps_id) return true;
        $existing = $this->findProviderServiceByHostname((string) $model->hostname);
        if ($existing === null) throw new InformationException('The existing cloud server is not visible in the provider account yet. Wait briefly and try reconciliation again.');
        $model->provider_vps_id = (string) $existing['id'];
        $model->status = 'provisioned';
        $model->last_error = null;
        $model->provider_response = json_encode($this->redact($existing), JSON_UNESCAPED_SLASHES);
        $this->applyProviderDetails($model, $existing);
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        $order->status = \Model_ClientOrder::STATUS_ACTIVE;
        $order->activated_at = $order->activated_at ?: date('Y-m-d H:i:s');
        $order->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($order);
        $this->di['mod_service']('Order')->saveStatusChange($order, 'Existing cloud server reconciled successfully.');
        try { $this->syncService($model); } catch (\Throwable) {}
        return true;
    }

    public function syncService($model): bool
    {
        if (!is_object($model) || !$model->provider_vps_id) throw new InformationException('Cloud service has not been provisioned.');
        $hadIp = trim((string) ($model->primary_ip ?? '')) !== '';
        [$status, $details] = $this->providerRequest('GET', '/vps/'.rawurlencode((string) $model->provider_vps_id));
        if ($status < 200 || $status >= 300) throw new InformationException('Could not synchronize cloud service details.');
        $this->applyProviderDetails($model, $details);
        try {
            [$trafficStatus, $traffic] = $this->providerRequest('GET', '/vps/'.rawurlencode((string) $model->provider_vps_id).'/traffic_usage');
            if ($trafficStatus >= 200 && $trafficStatus < 300) $model->bandwidth_used_gb = $this->firstNumeric($traffic, ['bandwidth_used_gb', 'usage_gb', 'used', 'total']);
        } catch (\Throwable) {
            // Detail synchronization remains useful when optional traffic data is unavailable.
        }
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        $this->markReadyIfSynced($model, $hadIp);
        return true;
    }

    public function powerAction($model, string $action): bool
    {
        if ($this->mode() !== 'live') throw new InformationException('Server controls are disabled in test mode.');
        if (!in_array($action, ['start', 'stop', 'restart'], true)) throw new InformationException('Unsupported server action.');
        if (!is_object($model) || !$model->provider_vps_id) throw new InformationException('Cloud service has not been provisioned.');
        [$status, $response] = $this->providerRequest('GET', '/vps/'.rawurlencode((string) $model->provider_vps_id).'/'.$action);
        if ($status < 200 || $status >= 300) throw new InformationException('The server action failed: '.$this->responseMessage($response));
        $model->power_status = $action === 'stop' ? 'stopped' : ($action === 'start' ? 'running' : 'restarting');
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        return true;
    }

    public function revealPassword($model): string
    {
        if (!is_object($model) || !$model->encrypted_root_password) throw new InformationException('The one-time password is no longer available. Use password reset instead.');
        $password = $this->di['crypt']->decrypt((string) $model->encrypted_root_password, Config::getProperty('info.salt'));
        $model->encrypted_root_password = null;
        $model->password_viewed_at = date('Y-m-d H:i:s');
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        return (string) $password;
    }

    public function renew($order, $model): bool { if (is_object($model)) { $model->updated_at = date('Y-m-d H:i:s'); $this->di['db']->store($model); } return true; }
    public function suspend($order, $model): bool { if ($this->mode() === 'live' && is_object($model) && $model->provider_vps_id) return $this->powerAction($model, 'stop'); return true; }
    public function unsuspend($order, $model): bool { if ($this->mode() === 'live' && is_object($model) && $model->provider_vps_id) return $this->powerAction($model, 'start'); return true; }
    public function cancel($order, $model): bool { if (is_object($model) && $model->provider_vps_id) throw new InformationException('Automatic cloud cancellation is locked. An administrator must confirm provider cancellation before closing this service.'); return true; }
    public function uncancel($order, $model): bool { return true; }
    public function delete($order, $model): bool { if (is_object($model) && $model->provider_vps_id) throw new InformationException('Cannot delete a provisioned cloud mapping.'); return true; }

    public function toApiArray($model): array
    {
        $provisioningState = $this->provisioningState($model);
        return [
            'id' => (int) $model->id,
            'order_id' => (int) $model->order_id,
            'platform' => (string) $model->platform,
            'slices' => (int) $model->slices,
            'location' => (int) $model->location,
            'location_name' => $this->locationName((int) $model->location),
            'os_distro' => (string) $model->os_distro,
            'os_version' => (string) $model->os_version,
            'hostname' => (string) $model->hostname,
            'coupon' => $model->coupon ?? null,
            'status' => (string) $model->status,
            // Customer-facing lifecycle derived from real infrastructure signals:
            // a server is only "active" once the provider has assigned its IP.
            'server_ready' => $provisioningState === 'active',
            'provisioning_state' => $provisioningState,
            'provisioning_message' => $this->provisioningMessage($provisioningState),
            'ready_at' => $model->ready_at ?? null,
            'provider_cost_usd' => $model->provider_cost_usd,
            'expected_cost_usd' => $model->expected_cost_usd,
            'cloud_service_id' => $model->provider_vps_id,
            'primary_ip' => $model->primary_ip,
            'power_status' => $model->power_status,
            'cpu_cores' => (int) ceil((int) $model->slices / 2),
            'ram_gb' => (int) $model->slices * 2,
            'storage_gb' => (int) $model->slices * ($model->platform === 'kvmstorage' ? 1000 : 40),
            'storage_type' => $model->platform === 'kvmstorage' ? 'SATA' : 'NVMe',
            'bandwidth_total_gb' => (int) $model->slices * 2000,
            'bandwidth_used_gb' => $model->bandwidth_used_gb,
            'bandwidth_percent' => min(100, max(0, ((int) $model->slices * 2000) > 0 ? ((float) ($model->bandwidth_used_gb ?? 0) / ((int) $model->slices * 2000) * 100) : 0)),
            'disk_used_gb' => $model->disk_used_gb,
            'password_available' => !empty($model->encrypted_root_password),
            'password_viewed_at' => $model->password_viewed_at,
            'last_error' => $model->last_error,
            'created_at' => $model->created_at,
            'updated_at' => $model->updated_at,
        ];
    }

    public static function onAfterAdminOrderActivate(\Box_Event $event): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        $order = $di['db']->load('ClientOrder', (int) ($params['id'] ?? 0));
        if (!$order instanceof \Model_ClientOrder || $order->service_type !== 'interserver') return;
        $service = $di['db']->load('service_interserver', (int) $order->service_id);
        if ($service && !$service->provider_vps_id && in_array($service->status, ['validated', 'submitting', 'manual_review'], true)) {
            $order->status = \Model_ClientOrder::STATUS_PENDING_SETUP;
            $order->updated_at = date('Y-m-d H:i:s');
            $di['db']->store($order);
            $message = $service->status === 'validated'
                ? 'Cloud configuration validated in test mode. No server was purchased.'
                : 'Cloud provisioning is already processing or awaiting reconciliation. Duplicate submission was blocked.';
            $di['mod_service']('Order')->saveStatusChange($order, $message);
        }
    }

    /**
     * Pre-order hostname guard. Fires before a product is added to the cart, so a
     * duplicate hostname is rejected immediately with a friendly message instead of
     * surfacing as a reconciliation task after the customer has paid.
     */
    public static function onBeforeProductAddedToCart(\Box_Event $event): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        try {
            $product = $di['db']->load('Product', (int) ($params['product_id'] ?? 0));
            if (!$product || (string) $product->type !== 'interserver') return;
            $service = $di['mod_service']('serviceinterserver');
            $service->assertCartHostnameUsable((string) ($params['hostname'] ?? ''), (int) ($params['cart_id'] ?? 0));
        } catch (InformationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $di['logger']->error('Quizontal Cloud add-to-cart hostname check failed: %s', $exception->getMessage());
        }
    }

    /**
     * Checkout-time hostname guard. Fires before orders, invoices, and wallet charges
     * are created for the cart, so nothing is billed when a hostname is unavailable.
     */
    public static function onBeforeClientCheckout(\Box_Event $event): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        $cartId = (int) ($params['cart_id'] ?? 0);
        if ($cartId < 1) return;
        try {
            $di['mod_service']('serviceinterserver')->assertCartCheckoutable($cartId);
        } catch (InformationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $di['logger']->error('Quizontal Cloud checkout hostname check failed: %s', $exception->getMessage());
        }
    }

    /**
     * Cron housekeeping: refresh paid services that are still awaiting their IP
     * address, mark them ready once the provider assigns one, and email the customer
     * at the exact moment the server becomes usable.
     */
    public static function onAfterAdminCronRun(\Box_Event $event): void
    {
        $di = $event->getDi();
        try {
            $di['mod_service']('serviceinterserver')->syncPendingProvisioning();
        } catch (\Throwable $exception) {
            $di['logger']->error('Quizontal Cloud cron synchronization failed: %s', $exception->getMessage());
        }
    }

    /**
     * Validates the hostname sent with an add-to-cart request: format first, then
     * uniqueness against the rest of the cart, existing orders, and the provider.
     * Allows both FQDN and single label like vps3559895 for manual assignments.
     */
    public function assertCartHostnameUsable(string $rawHostname, int $cartId = 0): void
    {
        $hostname = strtolower(trim($rawHostname));
        if ($hostname === '') throw new InformationException('Enter a valid fully-qualified hostname.');
        // allow FQDN or single label
        $isValid = filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || preg_match('/^[a-z0-9]([a-z0-9-]{1,61}[a-z0-9])?$/', $hostname);
        if (!$isValid) {
            throw new InformationException('Enter a valid hostname like server1.example.com or vps3559895.');
        }
        if ($cartId > 0 && $this->hostnameInCart($cartId, $hostname)) {
            throw new InformationException('Another server in your cart already uses this hostname. Please enter a different hostname.');
        }
        $this->assertHostnameAvailable($hostname);
    }

    /**
     * Validates every cloud product in the cart before checkout charges the wallet.
     */
    public function assertCartCheckoutable(int $cartId): void
    {
        $seen = [];
        foreach ($this->di['db']->find('CartProduct', 'cart_id = ? ORDER BY id ASC', [$cartId]) as $item) {
            $product = $this->di['db']->load('Product', (int) ($item->product_id ?? 0));
            if (!$product || (string) $product->type !== 'interserver') continue;
            $config = json_decode((string) ($item->config ?? ''), true);
            if (!is_array($config)) continue;
            $hostname = strtolower(trim((string) ($config['hostname'] ?? '')));
            if ($hostname === '') continue;
            if (isset($seen[$hostname])) {
                throw new InformationException('Your cart contains two servers with the same hostname. Please remove one of them or enter a different hostname.');
            }
            $seen[$hostname] = true;
            $this->assertHostnameAvailable($hostname);
        }
    }

    /**
     * Synchronizes provisioned services that do not have an IP address yet. The
     * first sync that returns an IP marks the service as ready and notifies the
     * customer, so the portal never claims a server is available before it is.
     */
    public function syncPendingProvisioning(): int
    {
        if (trim((string) ($this->getConfig()['api_key'] ?? '')) === '') return 0;
        $services = $this->di['db']->find(
            'service_interserver',
            "provider_vps_id IS NOT NULL AND (primary_ip IS NULL OR primary_ip = '') ORDER BY id ASC LIMIT 15"
        );
        $synced = 0;
        foreach ($services as $service) {
            try {
                // syncService() records readiness and notifies the customer on the
                // first sync that observes an IP address.
                $this->syncService($service);
                ++$synced;
            } catch (\Throwable $exception) {
                $this->di['logger']->error('Quizontal Cloud sync failed for service #%s: %s', $service->id, $exception->getMessage());
            }
        }
        return $synced;
    }

    /**
     * Marks a service ready the first time the provider reports an IP address.
     * Services that already had an IP before this feature was deployed are stamped
     * silently so existing customers never receive a surprise notification.
     */
    private function markReadyIfSynced($service, bool $hadIp): void
    {
        if (trim((string) ($service->primary_ip ?? '')) === '') return;
        if (!empty($service->ready_at)) return;
        try {
            $this->ensureReadyColumn();
            $service->ready_at = date('Y-m-d H:i:s');
            $this->di['db']->store($service);
        } catch (\Throwable $exception) {
            $this->di['logger']->error('Quizontal Cloud readiness timestamp failed for service #%s: %s', $service->id ?? 0, $exception->getMessage());
            return;
        }
        if ($hadIp) return;
        $this->notifyServerReady($service);
    }

    private function notifyServerReady($service): void
    {
        $order = $this->di['db']->load('ClientOrder', (int) $service->order_id);
        if (!$order instanceof \Model_ClientOrder || $order->status === \Model_ClientOrder::STATUS_CANCELED) return;
        try {
            $this->di['mod_service']('Order')->saveStatusChange($order, 'Cloud server is ready: an IP address was assigned.');
        } catch (\Throwable $exception) {
            $this->di['logger']->error('Quizontal Cloud readiness note failed for service #%s: %s', $service->id ?? 0, $exception->getMessage());
        }
        // Rendered from html_email/mod_serviceinterserver_ready.html.twig. The
        // activation helper force-resets that database template on every deploy:
        // email_template rows live forever once created, and an early stub row
        // (FOSSBilling-branded auto content) previously kept overriding the file.
        try {
            $this->di['mod_service']('email')->sendTemplate([
                'to_client' => (int) $order->client_id,
                'code' => 'mod_serviceinterserver_ready',
                'service' => $this->toApiArray($service),
                'order' => $this->di['mod_service']('Order')->toApiArray($order),
            ]);
        } catch (\Throwable $exception) {
            $this->di['logger']->error('Quizontal Cloud ready notification failed for service #%s: %s', $service->id ?? 0, $exception->getMessage());
        }
    }

    /**
     * Deletes database email templates of retired notification codes. Rows in
     * email_template persist forever once created — batch generation registered
     * these while their files still shipped, and after the files were removed
     * the generic "Cloud service update" rows would keep mailing customers
     * stale content. Deleting the files alone never reaches the database.
     * Idempotent; returns how many rows were removed.
     */
    public function purgeRetiredEmailTemplates(): int
    {
        $retired = [
            'mod_serviceinterserver_activated',
            'mod_serviceinterserver_renewed',
            'mod_serviceinterserver_suspended',
            'mod_serviceinterserver_unsuspended',
            'mod_serviceinterserver_canceled',
        ];
        $rows = $this->di['db']->find('EmailTemplate', 'action_code IN ('.implode(',', array_fill(0, count($retired), '?')).')', $retired);
        foreach ($rows as $row) {
            $this->di['db']->trash($row);
        }
        if ($rows) {
            $this->di['logger']->info('Purged %d retired Quizontal Cloud service email templates', count($rows));
        }
        return count($rows);
    }

    public function diagnoseOrder(int $orderId): array
    {
        $order = $this->di['db']->getExistingModelById('ClientOrder', $orderId, 'Order not found.');
        $product = $this->di['db']->load('Product', (int) $order->product_id);
        $service = $order->service_id ? $this->di['db']->load('service_interserver', (int) $order->service_id) : null;
        $statement = $this->di['pdo']->prepare("SELECT i.id, i.status, i.approved, i.gateway_id, ii.status AS item_status, ii.task FROM invoice i JOIN invoice_item ii ON ii.invoice_id=i.id WHERE ii.type='order' AND ii.rel_id=? ORDER BY i.id DESC");
        $statement->execute([$orderId]);
        $invoices = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $hookStatement = $this->di['pdo']->prepare("SELECT COUNT(*) FROM extension_meta WHERE extension='mod_hook' AND rel_type='mod' AND rel_id='serviceinterserver' AND meta_key='listener'");
        $hookStatement->execute();
        $config = $this->getConfig();
        return [
            'order' => ['id' => (int) $order->id, 'status' => $order->status, 'service_type' => $order->service_type, 'service_id' => $order->service_id, 'product_id' => $order->product_id, 'unpaid_invoice_id' => $order->unpaid_invoice_id],
            'product' => $product ? ['type' => $product->type, 'setup' => $product->setup, 'status' => $product->status, 'slug' => $product->slug] : null,
            'invoices' => $invoices,
            'service' => $service ? ['status' => $service->status, 'cloud_service_id' => $service->provider_vps_id, 'hostname' => $service->hostname, 'coupon' => $service->coupon ?? null, 'last_error' => $service->last_error] : null,
            'provisioning' => ['mode' => $config['mode'] ?? 'test', 'live_confirmed' => ($config['live_confirmation'] ?? '') === 'ENABLE LIVE VPS ORDERS'],
            'registered_hooks' => (int) $hookStatement->fetchColumn(),
        ];
    }

    public function activatePaidOrder(int $orderId): bool
    {
        $order = $this->di['db']->getExistingModelById('ClientOrder', $orderId, 'Order not found.');
        if ($order->service_type !== 'interserver') throw new InformationException('The order product is not configured for cloud provisioning. Run product synchronization first.');
        $this->assertOrderPaid($orderId);
        $this->di['mod_service']('Order')->activateOrder($order);
        return true;
    }

    public function setCredentials(string $url, string $key): bool
    {
        $url = rtrim(trim($url), '/');
        $key = trim($key);
        if (!filter_var($url, FILTER_VALIDATE_URL) || $key === '') throw new InformationException('Valid infrastructure API credentials are required.');
        $extension = $this->di['mod_service']('extension');
        $config = (array) $extension->getConfig('mod_serviceinterserver');
        $config['ext'] = 'mod_serviceinterserver';
        $config['api_url'] = $url;
        $config['api_key'] = $key;
        $config['mode'] = ($config['mode'] ?? 'test') === 'live' ? 'live' : 'test';
        $config['cost_tolerance_usd'] = $config['cost_tolerance_usd'] ?? '0.01';
        return $extension->setConfig($config);
    }

    public function getConfig(): array
    {
        return array_merge(['api_url' => 'https://my.interserver.net/apiv2', 'api_key' => '', 'mode' => 'test', 'live_confirmation' => '', 'cost_tolerance_usd' => 0.01], (array) $this->di['mod_config']('serviceinterserver'));
    }

    private function mode(): string
    {
        $config = $this->getConfig();
        $mode = ($config['mode'] ?? 'test') === 'live' ? 'live' : 'test';
        if ($mode === 'live' && ($config['live_confirmation'] ?? '') !== 'ENABLE LIVE VPS ORDERS') {
            throw new InformationException('Live mode is locked. Enter the required confirmation phrase in administrator settings.');
        }
        return $mode;
    }

    private function validateWithProvider(array $payload): array
    {
        return $this->providerRequest('PUT', '/vps/order', $payload);
    }

    private function providerRequest(string $method, string $path, ?array $payload = null, int $timeout = 35): array
    {
        $config = $this->getConfig();
        if (trim((string) $config['api_key']) === '') throw new InformationException('Configure the infrastructure API key in administrator settings.');
        $ch = curl_init(rtrim((string) $config['api_url'], '/').'/'.ltrim($path, '/'));
        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'X-API-KEY: '.$config['api_key']],
        ];
        if ($payload !== null) $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        if ($body === false) { $error = curl_error($ch); curl_close($ch); throw new InformationException('Could not reach the cloud infrastructure API: '.$error); }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) throw new InformationException("Cloud infrastructure returned HTTP {$status} with an invalid response.");
        return [$status, $decoded];
    }

    private function findProviderServiceByHostname(string $hostname): ?array
    {
        [$status, $response] = $this->providerRequest('GET', '/vps');
        if ($status < 200 || $status >= 300) return null;
        $matches = [];
        $this->collectProviderServices($response, strtolower(trim($hostname)), null, $matches);
        $matches = array_values($matches);
        if (count($matches) > 1) throw new InformationException('Multiple cloud servers use this hostname. Cancel the duplicate in the infrastructure account before reconciliation.');
        return $matches[0] ?? null;
    }

    private function collectProviderServices(array $value, string $hostname, string|int|null $recordKey, array &$matches): void
    {
        $rowHostname = strtolower(trim((string) ($value['vps_hostname'] ?? $value['hostname'] ?? $value['name'] ?? '')));
        if ($rowHostname !== '' && $rowHostname === $hostname) {
            $id = $value['id'] ?? $value['vps_id'] ?? $value['service_id'] ?? (is_numeric($recordKey) ? $recordKey : null);
            if ($id !== null && $id !== '') $matches[(string) $id] = ['id' => $id] + $value;
        }
        foreach ($value as $key => $child) if (is_array($child)) $this->collectProviderServices($child, $hostname, $key, $matches);
    }

    private function findRecord(array $value, callable $matches, string|int|null $recordKey = null): ?array
    {
        if ($matches($value)) {
            $id = $value['id'] ?? $value['vps_id'] ?? $value['service_id'] ?? $value['order_id'] ?? (is_numeric($recordKey) ? $recordKey : null);
            if ($id !== null && $id !== '') return ['id' => $id] + $value;
        }
        foreach ($value as $key => $child) if (is_array($child)) { $found = $this->findRecord($child, $matches, $key); if ($found !== null) return $found; }
        return null;
    }

    private function extractProviderId(array $response): string|int|null
    {
        foreach (['id', 'vps_id', 'service_id', 'order_id'] as $key) if (isset($response[$key]) && $response[$key] !== '') return $response[$key];
        $found = $this->findRecord($response, fn (array $row) => isset($row['id']) && count($row) > 1);
        return $found['id'] ?? null;
    }

    private function applyProviderDetails($model, array $details): void
    {
        $model->primary_ip = (string) ($this->firstScalar($details, ['ip', 'ip_address', 'main_ip', 'vps_ip']) ?? $model->primary_ip ?? '');
        $model->power_status = (string) ($this->firstScalar($details, ['vps_server_status', 'power_status', 'state', 'vps_status', 'status']) ?? $model->power_status ?? 'unknown');
        $disk = $this->firstNumeric($details, ['disk_used_gb', 'disk_used', 'hd_used']);
        if ($disk !== null) $model->disk_used_gb = $disk;
        $model->provider_response = json_encode($this->redact($details), JSON_UNESCAPED_SLASHES);
    }

    private function firstScalar(array $data, array $keys): string|int|float|null
    {
        foreach ($keys as $key) if (isset($data[$key]) && is_scalar($data[$key])) return $data[$key];
        foreach ($data as $child) if (is_array($child)) { $found = $this->firstScalar($child, $keys); if ($found !== null) return $found; }
        return null;
    }

    private function firstNumeric(array $data, array $keys): ?float
    {
        foreach ($keys as $key) if (isset($data[$key]) && is_numeric($data[$key])) return (float) $data[$key];
        foreach ($data as $child) if (is_array($child)) { $found = $this->firstNumeric($child, $keys); if ($found !== null) return $found; }
        return null;
    }

    private function claimLiveSubmission(int $serviceId): bool
    {
        // Atomic compare-and-set: only one PHP request may transition a validated
        // service to submitting. Concurrent invoice hooks, browser retries, cron,
        // and administrator actions therefore cannot issue a second provider POST.
        $statement = $this->di['pdo']->prepare("UPDATE service_interserver SET status='submitting', updated_at=NOW() WHERE id=? AND provider_vps_id IS NULL AND status='validated'");
        $statement->execute([$serviceId]);
        return $statement->rowCount() === 1;
    }

    private function assertOrderPaid(int $orderId): void
    {
        $statement = $this->di['pdo']->prepare("SELECT i.id FROM invoice i JOIN invoice_item ii ON ii.invoice_id=i.id WHERE ii.type='order' AND ii.rel_id=? AND i.status='paid' LIMIT 1");
        $statement->execute([$orderId]);
        if (!$statement->fetchColumn()) throw new InformationException('Live provisioning requires a confirmed paid invoice.');
    }

    private function ensureColumn(string $name, string $definition): void
    {
        $statement = $this->di['pdo']->prepare('SHOW COLUMNS FROM service_interserver LIKE ?');
        $statement->execute([$name]);
        if (!$statement->fetchColumn()) $this->di['db']->exec("ALTER TABLE service_interserver ADD COLUMN `{$name}` {$definition}");
    }

    private static bool $readyColumnEnsured = false;

    /** Applies the ready_at migration lazily so already-installed deployments upgrade safely. */
    private function ensureReadyColumn(): void
    {
        if (self::$readyColumnEnsured) return;
        $this->ensureColumn('ready_at', 'DATETIME NULL');
        self::$readyColumnEnsured = true;
    }

    /**
     * Customer-facing lifecycle. The internal status remains available to admins,
     * but customers only see a calm three-state model that never claims a server
     * is active before the infrastructure account actually shows it with an IP.
     */
    private function provisioningState($model): string
    {
        if ($model->status === 'provisioned' && trim((string) ($model->primary_ip ?? '')) !== '') return 'active';
        if (in_array($model->status, ['validation_failed', 'provisioning_failed', 'price_review'], true)) return 'attention';
        return 'setting_up';
    }

    private function provisioningMessage(string $state): string
    {
        return match ($state) {
            'active' => 'Your server is live. Connect to it using the IP address and credentials below.',
            'attention' => 'Your payment is confirmed and our team is completing the final setup step for your server. No action is needed from your side — we will email you the moment it is ready.',
            default => 'Payment confirmed. Your cloud server is now being created — most servers are ready within 30 minutes of payment. Your IP address and login details will appear here automatically, and we will email you as soon as it is ready.',
        };
    }

    private function isAllowedOs(string $platform, string $distro, string $version): bool
    {
        $linux = ['ubuntu' => ['ubuntu-20.04', 'ubuntu-22.04', 'ubuntu24', 'ubuntu26'], 'debian' => ['debian-11', 'debian12', 'debian13'], 'almalinux' => ['almalinux-8.3', 'almalinux9', 'alma10']];
        $windows = ['windows' => ['Windows2019Standard', 'Windows2022', 'Windows2025Standard']];
        $allowed = $platform === 'hyperv' ? $windows : $linux;
        return isset($allowed[$distro]) && in_array($version, $allowed[$distro], true);
    }

    private function generatePassword(): string
    {
        return 'Qz!'.bin2hex(random_bytes(10)).'aA7';
    }

    private function responseMessage(array $response): string
    {
        if (isset($response['errors']) && is_array($response['errors'])) return implode('; ', array_map('strval', $response['errors']));
        return (string) ($response['message'] ?? $response['status_text'] ?? 'Unknown provider validation error.');
    }

    private function redact(mixed $value, string $key = ''): mixed
    {
        if (preg_match('/pass|password|secret|token|api.?key|session/i', $key)) return '[REDACTED]';
        if (!is_array($value)) return $value;
        $safe = [];
        foreach ($value as $k => $v) $safe[$k] = $this->redact($v, (string) $k);
        return $safe;
    }

    private function locationName(int $id): string { return [1 => 'New Jersey', 2 => 'Los Angeles', 3 => 'Dallas, TX'][$id] ?? 'Unknown'; }
}
