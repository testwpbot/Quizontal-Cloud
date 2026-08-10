<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImportInterServerProducts extends Command
{
    protected $signature = 'interserver:import-products {--debug : Print InterServer response keys only; never prints credentials}';
    protected $description = 'Import the InterServer VPS catalog, add the USD margin, and save LKR prices';

    public function handle(): int
    {
        $providerUrl = rtrim((string) config('services.interserver.url'), '/');
        $providerKey = (string) config('services.interserver.key');
        $exchangeKey = (string) config('services.exchange_rate.key');
        $profit = (float) config('services.interserver.profit_usd', 1);
        if (!$providerUrl || !$providerKey || !$exchangeKey) {
            $this->error('Set INTERSERVER_API_URL, INTERSERVER_API_KEY, and EXCHANGERATE_API_KEY in .env first.');
            return self::FAILURE;
        }
        try {
            $provider = Http::acceptJson()->withHeaders(['X-API-KEY' => $providerKey])->timeout(25)->get($providerUrl.'/vps/order')->throw()->json();
            $exchange = Http::acceptJson()->timeout(20)->get("https://v6.exchangerate-api.com/v6/{$exchangeKey}/latest/USD")->throw()->json();
        } catch (ConnectionException $exception) {
            $this->error('Could not reach a provider API. Check your server network and API URL.');
            return self::FAILURE;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Catalog import failed. Check your API credentials and Laravel log.');
            return self::FAILURE;
        }
        $rate = (float) data_get($exchange, 'conversion_rates.LKR');
        if ($rate <= 0) throw new RuntimeException('ExchangeRate-API did not return a valid LKR rate.');
        $products = collect($this->unwrap($provider))->map(fn ($item, $index) => $this->normalize((array) $item, $index, $rate, $profit))->filter(fn ($product) => $product['basePriceUsd'] > 0)->values()->all();
        // InterServer's current /vps/order response is an order-form configuration,
        // not a pre-built plans array. Generate one sellable plan for every allowed slice count.
        if (!$products) $products = $this->sliceProducts((array) $provider, $rate, $profit);

        // Apply customer-facing terminology after either import path. InterServer can
        // return pre-built names or order-form data, so normalizing only one path is insufficient.
        $products = collect($products)->map(function (array $product): array {
            $product['name'] = $this->customerFacingName((string) ($product['name'] ?? 'Cloud VPS'));
            return $product;
        })->all();

        if (!$products) {
            $this->error('No billable VPS plans were found. The InterServer response shape needs to be mapped before it can be imported.');
            $this->line('Run: php artisan interserver:import-products --debug');
            if ($this->option('debug')) {
                $this->newLine();
                $this->info('InterServer response structure (field names only; no credentials or values):');
                foreach ($this->describePayload($provider) as $line) $this->line($line);
            }
            return self::FAILURE;
        }
        $catalog = json_encode(['updatedAt' => now()->toIso8601String(), 'exchangeRate' => $rate, 'profitUsd' => $profit, 'products' => $products], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!Storage::disk('local')->put('catalog.json', $catalog)) {
            $this->error('Catalog import was calculated but could not be saved to storage/app/private/catalog.json. Check storage ownership and permissions.');
            return self::FAILURE;
        }
        $this->info('Imported '.count($products)." products at USD/LKR {$rate} with a USD {$profit} margin.");
        return self::SUCCESS;
    }

    private function unwrap(mixed $payload): array
    {
        if (is_array($payload) && array_is_list($payload)) return $payload;
        foreach (['products', 'plans', 'vps', 'data', 'items', 'orders'] as $key) if (is_array(data_get($payload, $key)) && array_is_list(data_get($payload, $key))) return data_get($payload, $key);
        return collect((array) $payload)->filter(fn ($value) => is_array($value) && array_is_list($value))->flatMap(fn ($items, $platform) => collect($items)->map(fn ($item) => array_merge((array) $item, ['platform' => $item['platform'] ?? $platform])))->all();
    }

    /** @return array<int, string> */
    private function describePayload(mixed $value, string $path = '$', int $depth = 0): array
    {
        if ($depth > 3 || !is_array($value)) return [];
        if (array_is_list($value)) {
            if (!$value) return ["{$path}: empty list"];
            $first = $value[0] ?? null;
            if (!is_array($first)) return ["{$path}: list of ".gettype($first)];
            return array_merge(["{$path}: list; item keys: ".implode(', ', array_keys($first))], $this->describePayload($first, "{$path}[0]", $depth + 1));
        }
        $lines = ["{$path}: object keys: ".implode(', ', array_keys($value))];
        foreach ($value as $key => $child) {
            if (is_array($child)) $lines = array_merge($lines, $this->describePayload($child, "{$path}.{$key}", $depth + 1));
        }
        return $lines;
    }

    /**
     * Build plans from InterServer's current VPS order-form fields.
     * Each plan unit adds 2 GB RAM, storage and transfer. CPU allocation scales
     * at one vCPU per two units, rounded up (1, 1, 2, 2, 3, 3, ...).
     * The one USD profit is applied once to each monthly VPS plan, not to every slice.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sliceProducts(array $orderForm, float $rate, float $profit): array
    {
        $maxSlices = max(1, (int) $this->number($orderForm['maxSlices'] ?? 0));
        $ramGbPerSlice = $this->number($orderForm['ramSlice'] ?? 0) / 1024;
        $nvmeGbPerSlice = $this->number($orderForm['hdSlice'] ?? 0);
        $storageGbPerSlice = $this->number($orderForm['hdStorageSlice'] ?? 0);
        $bandwidthGbPerSlice = $this->number($orderForm['bwSlice'] ?? 0);

        $platforms = [
            'kvm' => ['cost' => 'vpsSliceKvmLCost', 'category' => 'general', 'name' => 'KVM Linux', 'storage' => 'NVMe', 'minimumSlices' => 1, 'storageGb' => $nvmeGbPerSlice],
            'kvmstorage' => ['cost' => 'vpsSliceKvmStorageCost', 'category' => 'storage', 'name' => 'KVM Storage', 'storage' => 'SATA', 'minimumSlices' => 1, 'storageGb' => $storageGbPerSlice],
            'hyperv' => ['cost' => 'vpsSliceKvmWCost', 'category' => 'windows', 'name' => 'Hyper-V Windows', 'storage' => 'NVMe', 'minimumSlices' => 2, 'storageGb' => $nvmeGbPerSlice],
        ];

        $products = [];
        foreach ($platforms as $platform => $definition) {
            $perSliceCost = $this->number($orderForm[$definition['cost']] ?? 0);
            if ($perSliceCost <= 0) continue;
            for ($slices = $definition['minimumSlices']; $slices <= $maxSlices; $slices++) {
                $base = round($perSliceCost * $slices, 2);
                $retail = round(($base + $profit) * 100) / 100;
                $products[] = [
                    'id' => "interserver-{$platform}-{$slices}",
                    // This encodes the selected InterServer platform and slice quantity for the billing/provisioning mapper.
                    'providerProductId' => "{$platform}:{$slices}",
                    'name' => "{$definition['name']} Plan {$slices}",
                    'category' => $definition['category'],
                    'platform' => $platform,
                    'slices' => $slices,
                    'cpu' => (int) ceil($slices / 2),
                    'ramGb' => round($ramGbPerSlice * $slices, 2),
                    'storageGb' => round($definition['storageGb'] * $slices, 2),
                    'storageType' => $definition['storage'],
                    'bandwidthGb' => round($bandwidthGbPerSlice * $slices, 2),
                    'basePriceUsd' => $base,
                    'retailPriceUsd' => $retail,
                    'priceLkr' => round($retail * $rate),
                    'available' => $this->platformAvailable($orderForm, $platform),
                ];
            }
        }
        return $products;
    }

    private function platformAvailable(array $orderForm, string $platform): bool
    {
        $stock = $orderForm['locationStock'] ?? [];
        if (!is_array($stock) || !$stock) return true;
        $hasRecognisableStockValue = false;
        foreach ($stock as $location) {
            if (!is_array($location) || !array_key_exists($platform, $location)) continue;
            $value = $location[$platform];
            if (is_bool($value)) {
                $hasRecognisableStockValue = true;
                if ($value) return true;
                continue;
            }
            if (is_numeric($value)) {
                $hasRecognisableStockValue = true;
                if ((float) $value > 0) return true;
                continue;
            }
            if (is_string($value) && in_array(strtolower($value), ['yes', 'available', 'in stock'], true)) return true;
        }
        // Keep plans visible when InterServer changes this optional stock field's structure.
        return !$hasRecognisableStockValue;
    }

    private function normalize(array $raw, int $index, float $rate, float $profit): array
    {
        $text = strtolower(implode(' ', Arr::only($raw, ['platform', 'type', 'name', 'os'])));
        $category = str_contains($text, 'hyperv') || str_contains($text, 'windows')
            ? 'windows'
            : ((str_contains($text, 'storage') || str_contains($text, 'hdd') || str_contains($text, 'sata')) ? 'storage' : 'general');
        $platform = (string) ($raw['platform'] ?? match ($category) {
            'storage' => 'kvmstorage',
            'windows' => 'hyperv',
            default => 'kvm',
        });
        $base = $this->number($this->firstValue($raw, ['monthly_price', 'monthlyPrice', 'price', 'cost', 'price_usd'], 0));
        $ramMb = $this->number($this->firstValue($raw, ['ram_mb', 'memory_mb'], 0));
        $ram = $this->number($this->firstValue($raw, ['ram_gb', 'ram', 'memory'], $ramMb ? $ramMb / 1024 : 1));
        $id = (string) $this->firstValue($raw, ['id', 'product_id', 'plan_id', 'sku', 'name'], 'interserver-'.($index + 1));
        $name = (string) $this->firstValue($raw, ['name', 'title', 'description', 'plan_name'], 'Cloud VPS Plan '.($index + 1));
        $slices = (int) $this->number($this->firstValue($raw, ['slices', 'slice', 'quantity'], 0));
        if ($slices < 1 && preg_match('/\b(\d+)\s+Slices?\b/i', $name, $matches)) $slices = (int) $matches[1];
        if ($slices < 1 && preg_match('/\bPlan\s+(\d+)\b/i', $name, $matches)) $slices = (int) $matches[1];
        $reportedCpu = $this->number($this->firstValue($raw, ['cpu', 'cores', 'vcpu', 'cpu_cores'], 1));
        $cpu = $slices > 0 ? (int) ceil($slices / 2) : $reportedCpu;
        $retail = round(($base + $profit) * 100) / 100;

        return [
            'id' => 'interserver-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $id),
            'providerProductId' => $id,
            'name' => $name,
            'category' => $category,
            'platform' => $platform,
            'slices' => $slices ?: null,
            'cpu' => $cpu,
            'ramGb' => $ram,
            'storageGb' => $this->number($this->firstValue($raw, ['storage_gb', 'disk_gb', 'disk', 'storage'], 0)),
            'storageType' => $category === 'storage' ? 'SATA' : 'NVMe',
            'bandwidthGb' => $this->number($this->firstValue($raw, ['bandwidth_gb', 'transfer_gb', 'bandwidth', 'transfer'], 0)),
            'basePriceUsd' => $base,
            'retailPriceUsd' => $retail,
            'priceLkr' => round($retail * $rate),
            'available' => $this->firstValue($raw, ['available', 'active'], true) !== false,
        ];
    }

    private function customerFacingName(string $name): string
    {
        $renamed = preg_replace('/\s+(\d+)\s+Slices?\s*$/i', ' Plan $1', trim($name));

        return str_ireplace([' Slices', ' Slice'], ' Plan', $renamed ?? trim($name));
    }

    private function firstValue(array $source, array $keys, mixed $default): mixed { foreach ($keys as $key) if (isset($source[$key]) && $source[$key] !== '') return $source[$key]; return $default; }
    private function number(mixed $value): float { return (float) preg_replace('/[^0-9.-]/', '', (string) $value); }
}
