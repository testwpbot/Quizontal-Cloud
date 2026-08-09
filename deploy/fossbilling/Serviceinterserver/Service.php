<?php

declare(strict_types=1);

namespace Box\Mod\Serviceinterserver;

use FOSSBilling\InformationException;

class Service implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }

    public function getModulePermissions(): array
    {
        return [
            'manage' => ['type' => 'bool', 'display_name' => 'Manage InterServer provisioning', 'description' => 'Configure products and retry validation-only provisioning.'],
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
            `status` VARCHAR(40) NOT NULL DEFAULT 'pending_validation',
            `validation_response` MEDIUMTEXT NULL,
            `last_error` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `service_interserver_order_unique` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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
        if (!is_object($model)) throw new InformationException('InterServer service record was not created.');
        if ($model->status === 'provisioned' && $model->provider_vps_id) return ['provider_vps_id' => $model->provider_vps_id];

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
            'rootpass' => $this->generatePassword(),
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
            throw new InformationException('InterServer validation failed: '.$model->last_error);
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
        return ['provisioning_mode' => 'validate_only', 'validation_status' => 'validated'];
    }

    public function renew($order, $model): bool { if (is_object($model)) { $model->updated_at = date('Y-m-d H:i:s'); $this->di['db']->store($model); } return true; }
    public function suspend($order, $model): bool { return true; }
    public function unsuspend($order, $model): bool { return true; }
    public function cancel($order, $model): bool { return true; }
    public function uncancel($order, $model): bool { return true; }
    public function delete($order, $model): bool { return true; }

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
            'provider_vps_id' => $model->provider_vps_id,
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
            $di['mod_service']('Order')->saveStatusChange($order, 'InterServer configuration validated. Live provisioning is disabled; no VPS was purchased.');
        }
    }

    public function getConfig(): array
    {
        return array_merge(['api_url' => 'https://my.interserver.net/apiv2', 'api_key' => '', 'mode' => 'validate_only', 'cost_tolerance_usd' => 0.01], (array) $this->di['mod_config']('serviceinterserver'));
    }

    private function validateWithProvider(array $payload): array
    {
        $config = $this->getConfig();
        if (($config['mode'] ?? '') !== 'validate_only') throw new InformationException('Only validate_only provisioning mode is supported by this module version.');
        if (trim((string) $config['api_key']) === '') throw new InformationException('Configure the InterServer API key in the module settings.');
        $ch = curl_init(rtrim((string) $config['api_url'], '/').'/vps/order');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'X-API-KEY: '.$config['api_key']],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $body = curl_exec($ch);
        if ($body === false) { $error = curl_error($ch); curl_close($ch); throw new InformationException('Could not reach InterServer: '.$error); }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) throw new InformationException("InterServer returned HTTP {$status} with an invalid response.");
        return [$status, $decoded];
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
