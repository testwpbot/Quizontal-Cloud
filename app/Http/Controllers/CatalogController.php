<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $disk = Storage::disk('local');
        if (!$disk->exists('catalog.json')) {
            abort_unless($disk->exists('catalog.sample.json'), 503, 'The product catalog is being prepared.');
            $catalog = json_decode($disk->get('catalog.sample.json'), true, flags: JSON_THROW_ON_ERROR);
        } else {
            $catalog = json_decode($disk->get('catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        }

        $billingProducts = $this->fossBillingProducts();
        if ($billingProducts !== []) {
            $catalog['products'] = collect($catalog['products'] ?? [])
                ->filter(fn (array $product): bool => isset($billingProducts[$product['id']]))
                ->map(function (array $product) use ($billingProducts): array {
                    $stored = $billingProducts[$product['id']];
                    $monthly = data_get($stored, 'pricing.recurrent.1M');
                    $product['name'] = (string) ($stored['title'] ?? $product['name']);
                    $product['priceLkr'] = (float) ($monthly['price'] ?? $product['priceLkr']);
                    $product['available'] = ($stored['status'] ?? 'disabled') === 'enabled'
                        && !(bool) ($stored['hidden'] ?? false)
                        && (bool) ($monthly['enabled'] ?? false);
                    $product['billingProductId'] = (int) $stored['id'];
                    unset($product['basePriceUsd'], $product['retailPriceUsd']);
                    return $product;
                })->values()->all();
            $catalog['source'] = 'billing_database';
        } else {
            $catalog['source'] = 'imported_catalog';
        }

        return response()->json($catalog);
    }

    /** @return array<string, array<string, mixed>> */
    private function fossBillingProducts(): array
    {
        $url = rtrim((string) config('services.fossbilling.url'), '/');
        $key = (string) config('services.fossbilling.admin_api_key');
        if ($url === '' || $key === '') return [];

        try {
            return Cache::remember('storefront.fossbilling-products', 60, function () use ($url, $key): array {
                $json = Http::withBasicAuth('admin', $key)
                    ->acceptJson()->asJson()->timeout(15)
                    ->post($url.'/api/admin/product/get_list', ['per_page' => 500])
                    ->throw()->json();
                if (!empty($json['error'])) throw new \RuntimeException((string) data_get($json, 'error.message', 'Billing catalog error'));
                return collect(data_get($json, 'result.list', []))
                    ->filter(fn (array $product): bool => str_starts_with((string) ($product['slug'] ?? ''), 'interserver-'))
                    ->keyBy('slug')->all();
            });
        } catch (\Throwable $exception) {
            Log::warning('Storefront could not read FossBilling products; using imported catalog.', ['message' => $exception->getMessage()]);
            return [];
        }
    }
}
