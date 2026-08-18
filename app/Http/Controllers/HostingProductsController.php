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

        $trialProductId = (int) config('services.fossbilling.free_trial_product_id');
        $trialUrl = $this->freeTrialUrl($base);

        try {
            // The trial URL/product are part of the payload, so the cache key
            // must change with them or a config edit would not take effect.
            $cacheKey = 'storefront.hosting-products.'.md5((string) $trialUrl.'|'.$trialProductId);
            $products = Cache::remember($cacheKey, 300, function () use ($base, $trialProductId, $trialUrl): array {
                $json = Http::acceptJson()->asJson()->timeout(15)
                    ->post($base.'/api/guest/product/get_list', ['per_page' => 100])
                    ->throw()
                    ->json();

                if (! empty($json['error'])) {
                    throw new \RuntimeException((string) data_get($json, 'error.message', 'Billing catalog error'));
                }

                return collect(data_get($json, 'result.list', []))
                    ->filter(fn (array $p): bool => ($p['type'] ?? null) === 'hosting')
                    ->map(function (array $p) use ($base, $trialProductId, $trialUrl): array {
                        $monthly = data_get($p, 'pricing.recurrent.1M') ?? [];
                        $id = (int) ($p['id'] ?? 0);

                        return [
                            'id' => $id,
                            'title' => (string) ($p['title'] ?? 'Hosting plan'),
                            'description' => (string) ($p['description'] ?? ''),
                            'price' => (float) ($monthly['price'] ?? 0),
                            'orderUrl' => $base.'/order?product='.$id,
                            // Only the trial plan gets the trial button, so the
                            // card CTA never promises a trial we cannot deliver.
                            'trialUrl' => ($trialUrl !== null && $id === $trialProductId) ? $trialUrl : null,
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

        return response()->json([
            'products' => $products,
            'source' => 'billing',
            'freeTrialUrl' => $trialUrl,
        ]);
    }

    /**
     * Canonical wizard URL. FOSSBilling routes by the first path segment, so
     * the module's own page is /quizontalfreetrial; /free-trial only exists as
     * a Redirect module entry and is not safe to rely on here.
     */
    private function freeTrialUrl(string $base): ?string
    {
        $explicit = trim((string) config('services.fossbilling.free_trial_url'));
        if ($explicit !== '') {
            return $explicit;
        }

        return $base === '' ? null : $base.'/quizontalfreetrial';
    }
}
