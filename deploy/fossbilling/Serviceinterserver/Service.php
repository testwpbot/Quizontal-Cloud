<?php

declare(strict_types=1);

namespace Box\Mod\Serviceinterserver;

use FOSSBilling\Config;
use FOSSBilling\InformationException;

class Service implements \FOSSBilling\InjectionAwareInterface
{
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

    public function validateOrderData(array &$data): void
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
        $data['hostname'] = strtolower(trim((string) $data['hostname']));
        if (!filter_var($data['hostname'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($data['hostname'], '.')) throw new InformationException('Enter a valid fully-qualified hostname.');
        $data['controlPanel'] = 'none';
    }

    public function create($order, $existing = null)
    {
        if (is_object($existing)) return $existing;
        $config = json_decode($order->config ?? '', true) ?? [];
        $this->validateOrderData($config);
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
            throw new InformationException('This order requires administrator reconciliation before another live submission can be attempted.');
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
            'coupon' => '',
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

        $tolerance = max(0, (float) ($this->getConfig()['cost_tolerance_usd'] ?? 0.01));
        if ($model->provider_cost_usd === null || abs((float) $model->provider_cost_usd - (float) $model->expected_cost_usd) > $tolerance) {
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
        $existing = $this->findProviderServiceByHostname((string) $model->hostname);
        if ($existing !== null) {
            $model->provider_vps_id = (string) $existing['id'];
            $model->status = 'provisioned';
            $model->encrypted_root_password = null;
            $model->provider_response = json_encode($this->redact($existing), JSON_UNESCAPED_SLASHES);
            $this->applyProviderDetails($model, $existing);
            $this->di['db']->store($model);
            return ['cloud_service_id' => $model->provider_vps_id, 'reconciled' => true];
        }

        $model->status = 'submitting';
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
        if ($providerId === null) {
            $reconciled = $this->findProviderServiceByHostname((string) $model->hostname);
            $providerId = $reconciled['id'] ?? null;
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

    public function syncService($model): bool
    {
        if (!is_object($model) || !$model->provider_vps_id) throw new InformationException('Cloud service has not been provisioned.');
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
            'status' => (string) $model->status,
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
        if ($service && $service->status === 'validated' && !$service->provider_vps_id) {
            $order->status = \Model_ClientOrder::STATUS_PENDING_SETUP;
            $order->updated_at = date('Y-m-d H:i:s');
            $di['db']->store($order);
            $di['mod_service']('Order')->saveStatusChange($order, 'Cloud configuration validated in test mode. No server was purchased.');
        }
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

    private function providerRequest(string $method, string $path, ?array $payload = null): array
    {
        $config = $this->getConfig();
        if (trim((string) $config['api_key']) === '') throw new InformationException('Configure the infrastructure API key in administrator settings.');
        $ch = curl_init(rtrim((string) $config['api_url'], '/').'/'.ltrim($path, '/'));
        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 35,
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
        try {
            [$status, $response] = $this->providerRequest('GET', '/vps');
            if ($status < 200 || $status >= 300) return null;
            return $this->findRecord($response, fn (array $row) => strtolower((string) ($row['hostname'] ?? $row['name'] ?? '')) === strtolower($hostname));
        } catch (\Throwable) {
            return null;
        }
    }

    private function findRecord(array $value, callable $matches): ?array
    {
        if ($matches($value) && isset($value['id'])) return $value;
        foreach ($value as $child) if (is_array($child)) { $found = $this->findRecord($child, $matches); if ($found !== null) return $found; }
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
        $model->power_status = (string) ($this->firstScalar($details, ['power_status', 'state', 'status']) ?? $model->power_status ?? 'unknown');
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
