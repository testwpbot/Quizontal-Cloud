<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Live web-hosting products from FOSSBilling (guest API — no credentials),
 * so storefront prices/order links always match whatever is configured in
 * billing. The page JS renders a static fallback set if this answers empty.
 */
class HostingProductsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $base = rtrim((string) config('services.fossbilling.url'), '/');
        if ($base === '') {
            return response()->json(['products' => [], 'source' => 'unconfigured']);
        }

        try {
            $products = Cache::remember('storefront.hosting-products', 300, function () use ($base): array {
                $json = Http::acceptJson()->asJson()->timeout(15)
                    ->post($base.'/api/guest/product/get_list', ['per_page' => 100])
                    ->throw()
                    ->json();

                if (! empty($json['error'])) {
                    throw new \RuntimeException((string) data_get($json, 'error.message', 'Billing catalog error'));
                }

                return collect(data_get($json, 'result.list', []))
                    ->filter(fn (array $p): bool => ($p['type'] ?? null) === 'hosting')
                    ->map(function (array $p) use ($base): array {
                        $monthly = data_get($p, 'pricing.recurrent.1M') ?? [];

                        return [
                            'id' => (int) ($p['id'] ?? 0),
                            'title' => (string) ($p['title'] ?? 'Hosting plan'),
                            'description' => (string) ($p['description'] ?? ''),
                            'price' => (float) ($monthly['price'] ?? 0),
                            'orderUrl' => $base.'/order?product='.(int) ($p['id'] ?? 0),
                        ];
                    })
                    ->filter(fn (array $p): bool => $p['id'] > 0)
                    ->sortBy('price')
                    ->values()
                    ->all();
            });
        } catch (\Throwable $exception) {
            Log::warning('Storefront could not read FossBilling hosting products.', ['message' => $exception->getMessage()]);

            return response()->json(['products' => [], 'source' => 'error']);
        }

        return response()->json(['products' => $products, 'source' => 'billing']);
    }
}
