<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ValidateInterServerVpsOrder extends Command
{
    protected $signature = 'interserver:validate-vps-order
        {--show-options : Display safe VPS ordering options without validating an order}
        {--platform= : InterServer platform, for example kvm, kvmstorage, or hyperv}
        {--slices= : Number of VPS slices}
        {--location= : InterServer location ID}
        {--os= : InterServer template file/OS identifier}
        {--os-version= : InterServer OS family/version identifier}
        {--hostname=validation.quizontal-cloud.invalid : Validation-only hostname}
        {--controlpanel=none : none, cpanel, or da}
        {--period=1 : Billing period in months}
        {--coupon= : Optional InterServer coupon code}';

    protected $description = 'Inspect or validate an InterServer VPS order without purchasing anything';

    public function handle(): int
    {
        $url = rtrim((string) config('services.interserver.url'), '/');
        $key = (string) config('services.interserver.key');
        if ($url === '' || $key === '') {
            $this->error('Set INTERSERVER_API_URL and INTERSERVER_API_KEY in .env first.');
            return self::FAILURE;
        }

        try {
            $client = Http::acceptJson()
                ->withHeaders(['X-API-KEY' => $key])
                ->timeout(30);
            $ordering = $client->get($url.'/vps/order')->throw()->json();
        } catch (ConnectionException) {
            $this->error('Could not reach the InterServer API.');
            return self::FAILURE;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Could not load VPS ordering information. Check the API key and Laravel log.');
            return self::FAILURE;
        }

        if ($this->option('show-options')) {
            $this->displayOptions((array) $ordering);
            $this->newLine();
            $this->warn('Inspection only: no validation or purchase request was sent.');
            return self::SUCCESS;
        }

        $payload = $this->payload();
        $errors = $this->validateInput($payload, (array) $ordering);
        if ($errors !== []) {
            foreach ($errors as $error) $this->error($error);
            $this->newLine();
            $this->line('Inspect valid values first: php artisan interserver:validate-vps-order --show-options');
            return self::FAILURE;
        }

        $this->warn('VALIDATION-ONLY MODE — this command never sends POST and cannot place an order.');
        $this->table(['Field', 'Value'], collect($payload)
            ->except(['rootPassword', 'coupon'])
            ->map(fn ($value, $field) => [$field, is_scalar($value) ? (string) $value : json_encode($value)])
            ->values()->all());

        try {
            // InterServer's REST API uses PUT /vps/order for validation and
            // POST /vps/order for the real purchase. Never change this to POST here.
            $httpResponse = $client->put($url.'/vps/order', $payload);
        } catch (ConnectionException) {
            $this->error('Could not reach InterServer while validating the VPS order.');
            return self::FAILURE;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('The validation request failed before InterServer returned a response.');
            return self::FAILURE;
        }

        $decoded = $httpResponse->json();
        if (!is_array($decoded)) {
            $this->error("InterServer returned HTTP {$httpResponse->status()} with a non-JSON response. Check the Laravel log/provider availability.");
            return self::FAILURE;
        }

        $safe = $this->redact($decoded);
        $safe = ['http_status' => $httpResponse->status()] + $safe;
        $status = strtolower((string) ($safe['status'] ?? 'unknown'));
        $this->newLine();
        $this->line(json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->newLine();

        if (!$httpResponse->successful()) {
            $message = (string) ($safe['status_text'] ?? $safe['message'] ?? $safe['error'] ?? 'Review the response above.');
            $this->error("InterServer returned HTTP {$httpResponse->status()}: {$message}");
            return self::FAILURE;
        }

        if ($status === 'ok' || $status === 'success' || ($safe['valid'] ?? false) === true) {
            $this->info('InterServer accepted the VPS configuration for validation. No VPS was purchased.');
            return self::SUCCESS;
        }

        $message = (string) ($safe['status_text'] ?? $safe['message'] ?? 'Review the response above.');
        $this->error('Validation did not pass: '.$message);
        return self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'osDistro' => trim((string) $this->option('os')),
            'slices' => (int) $this->option('slices'),
            'vpsPlatform' => strtolower(trim((string) $this->option('platform'))),
            'controlPanel' => strtolower(trim((string) $this->option('controlpanel'))),
            'period' => (int) $this->option('period'),
            'location' => (int) $this->option('location'),
            'osVersion' => trim((string) $this->option('os-version')),
            'hostname' => strtolower(trim((string) $this->option('hostname'))),
            'coupon' => trim((string) $this->option('coupon')),
            // Generated only for validation, never printed or persisted.
            'rootPassword' => Str::password(24, symbols: true),
        ];
    }

    /** @return array<int, string> */
    private function validateInput(array $payload, array $ordering): array
    {
        $errors = [];
        if (!in_array($payload['vpsPlatform'], ['kvm', 'kvmstorage', 'hyperv'], true)) $errors[] = '--platform must be kvm, kvmstorage, or hyperv.';
        $max = max(1, (int) ($ordering['maxSlices'] ?? 32));
        if ($payload['slices'] < 1 || $payload['slices'] > $max) $errors[] = "--slices must be between 1 and {$max}.";
        if ($payload['vpsPlatform'] === 'hyperv' && $payload['slices'] < 2) $errors[] = 'Hyper-V Windows requires at least 2 slices.';
        if ($payload['location'] < 1) $errors[] = '--location must be a valid InterServer location ID.';
        if ($payload['osDistro'] === '') $errors[] = '--os is required and must match an InterServer template identifier.';
        if ($payload['osVersion'] === '') $errors[] = '--os-version is required and must match the selected template family/version.';
        if (!in_array($payload['controlPanel'], ['none', 'cpanel', 'da'], true)) $errors[] = '--controlpanel must be none, cpanel, or da.';
        if ($payload['period'] < 1 || $payload['period'] > 36) $errors[] = '--period must be between 1 and 36 months.';
        if (!filter_var($payload['hostname'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($payload['hostname'], '.')) $errors[] = '--hostname must be a fully-qualified hostname.';
        return $errors;
    }

    private function displayOptions(array $ordering): void
    {
        $this->info('InterServer VPS ordering information (safe fields only)');
        $allowed = [
            'maxSlices', 'ramSlice', 'hdSlice', 'hdStorageSlice', 'bwSlice',
            'vpsSliceKvmLCost', 'vpsSliceKvmStorageCost', 'vpsSliceKvmWCost',
            'platforms', 'locations', 'locationStock', 'templates', 'os',
            'operatingSystems', 'controlpanels', 'controlPanels',
        ];
        $safe = [];
        foreach ($ordering as $field => $value) {
            if (in_array($field, $allowed, true) || preg_match('/location|template|operating|platform|control.?panel|slice|cost|^os$/i', (string) $field)) {
                $safe[$field] = $value;
            }
        }
        if ($safe === []) {
            $this->warn('The API returned an unfamiliar response shape. Top-level field names:');
            $this->line(implode(', ', array_keys($ordering)));
            return;
        }
        $this->line(json_encode($this->redact($safe), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function redact(mixed $value, string $key = ''): mixed
    {
        if (preg_match('/pass|password|secret|token|api.?key|session/i', $key)) return '[REDACTED]';
        if (!is_array($value)) return $value;
        $safe = [];
        foreach ($value as $childKey => $child) $safe[$childKey] = $this->redact($child, (string) $childKey);
        return $safe;
    }
}
