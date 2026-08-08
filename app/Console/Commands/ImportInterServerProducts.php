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
        Storage::disk('local')->put('catalog.json', json_encode(['updatedAt' => now()->toIso8601String(), 'exchangeRate' => $rate, 'profitUsd' => $profit, 'products' => $products], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

    private function normalize(array $raw, int $index, float $rate, float $profit): array
    {
        $text = strtolower(implode(' ', Arr::only($raw, ['platform', 'type', 'name', 'os'])));
        $category = str_contains($text, 'hyperv') || str_contains($text, 'windows') ? 'windows' : ((str_contains($text, 'storage') || str_contains($text, 'hdd') || str_contains($text, 'sata')) ? 'storage' : 'general');
        $base = $this->number($this->firstValue($raw, ['monthly_price', 'monthlyPrice', 'price', 'cost', 'price_usd'], 0));
        $ramMb = $this->number($this->firstValue($raw, ['ram_mb', 'memory_mb'], 0));
        $ram = $this->number($this->firstValue($raw, ['ram_gb', 'ram', 'memory'], $ramMb ? $ramMb / 1024 : 1));
        $id = (string) $this->firstValue($raw, ['id', 'product_id', 'plan_id', 'sku', 'name'], 'interserver-'.($index + 1));
        $retail = round(($base + $profit) * 100) / 100;
        return ['id' => 'interserver-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $id), 'providerProductId' => $id, 'name' => (string) $this->firstValue($raw, ['name', 'title', 'description', 'plan_name'], 'InterServer VPS '.($index + 1)), 'category' => $category, 'cpu' => $this->number($this->firstValue($raw, ['cpu', 'cores', 'vcpu', 'cpu_cores'], 1)), 'ramGb' => $ram, 'storageGb' => $this->number($this->firstValue($raw, ['storage_gb', 'disk_gb', 'disk', 'storage'], 0)), 'storageType' => $category === 'storage' ? 'SATA' : 'NVMe', 'bandwidthGb' => $this->number($this->firstValue($raw, ['bandwidth_gb', 'transfer_gb', 'bandwidth', 'transfer'], 0)), 'basePriceUsd' => $base, 'retailPriceUsd' => $retail, 'priceLkr' => round($retail * $rate), 'available' => $this->firstValue($raw, ['available', 'active'], true) !== false];
    }
    private function firstValue(array $source, array $keys, mixed $default): mixed { foreach ($keys as $key) if (isset($source[$key]) && $source[$key] !== '') return $source[$key]; return $default; }
    private function number(mixed $value): float { return (float) preg_replace('/[^0-9.-]/', '', (string) $value); }
}
