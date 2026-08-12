<?php

namespace App\Console\Commands;

use App\Support\FossBillingDomains;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Guarantees the billing panel can actually sell domain names.
 *
 * FOSSBilling only lets customers add a domain to the cart through a product
 * of type "domain" (at most one may exist). Without it the storefront's
 * "Add to cart" links have nothing to target, the order-page domain search
 * hero stays hidden, and customers land on a bare product picker. This
 * command provisions (or repairs) that product idempotently through the
 * admin API — safe to run on every deploy.
 */
class EnsureFossBillingDomainProduct extends Command
{
    protected $signature = 'fossbilling:ensure-domain-product
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Create or repair the FOSSBilling "domain" product that every domain checkout attaches to';

    public function handle(): int
    {
        $url = rtrim((string) config('services.fossbilling.url'), '/');
        $apiKey = (string) config('services.fossbilling.admin_api_key');

        if ($url === '' || $apiKey === '') {
            $this->error('Set FOSSBILLING_URL and FOSSBILLING_ADMIN_API_KEY in .env first.');

            return self::FAILURE;
        }

        try {
            $domain = $this->findDomainProduct($url, $apiKey);
        } catch (\RuntimeException $exception) {
            $this->error('Could not read the product catalogue: '.$exception->getMessage());

            return self::FAILURE;
        }

        try {
            if ($domain === null) {
                $categoryId = $this->ensureDomainsCategory($url, $apiKey);

                if ($this->option('dry-run')) {
                    $this->warn('Would create product "Domain Registration" (type: domain, enabled) in the Domains category.');

                    return self::SUCCESS;
                }

                $payload = ['title' => 'Domain Registration', 'type' => 'domain'];
                if ($categoryId !== null) {
                    $payload['product_category_id'] = $categoryId;
                }
                $id = (int) $this->adminCall($url, $apiKey, 'product/prepare', $payload);

                // New products are created DISABLED — enable it so guests can buy.
                $update = ['id' => $id, 'status' => 'enabled'];
                if ($categoryId !== null) {
                    $update['product_category_id'] = $categoryId;
                }
                $this->adminCall($url, $apiKey, 'product/update', $update);

                $this->info(sprintf('Created domain product #%d and enabled it.', $id));
            } else {
                $id = (int) $domain['id'];
                $status = (string) ($domain['status'] ?? 'enabled');
                $needsCategory = empty($domain['product_category_id']);

                if ($status === 'enabled' && ! $needsCategory) {
                    $this->info(sprintf('Domain product #%d ("%s") already exists and is enabled. Nothing to do.', $id, $domain['title'] ?? ''));
                } else {
                    if ($this->option('dry-run')) {
                        if ($status !== 'enabled') {
                            $this->warn(sprintf('Domain product #%d exists but is "%s" — would re-enable it.', $id, $status));
                        }
                        if ($needsCategory) {
                            $this->warn(sprintf('Domain product #%d has no category — would move it into "Domains" so the order page lists it.', $id));
                        }
                    } else {
                        $update = ['id' => $id];
                        if ($status !== 'enabled') {
                            $update['status'] = 'enabled';
                        }
                        if ($needsCategory) {
                            $categoryId = $this->ensureDomainsCategory($url, $apiKey);
                            if ($categoryId !== null) {
                                $update['product_category_id'] = $categoryId;
                            }
                        }
                        $this->adminCall($url, $apiKey, 'product/update', $update);
                        $this->info(sprintf('Repaired domain product #%d (enabled, categorised).', $id));
                    }
                }
            }
        } catch (\RuntimeException $exception) {
            $this->error('Provisioning failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('dry-run')) {
            // The storefront caches the discovered id for 5 minutes — drop it now.
            Cache::forget('storefront.domain-product-id');
            $this->line('Storefront discovery cache cleared.');
            $this->line('Domain order links now point at: '.(FossBillingDomains::orderUrl() ?? '(unresolved)'));
        }

        return self::SUCCESS;
    }

    /**
     * The catalogue scan asks for domain-type products directly, then falls
     * back to a full scan in case the API ignores the type filter.
     */
    private function findDomainProduct(string $url, string $apiKey): ?array
    {
        foreach ([['per_page' => 100, 'type' => 'domain'], ['per_page' => 100]] as $query) {
            $list = $this->adminCall($url, $apiKey, 'product/get_list', $query);
            foreach ((array) ($list['list'] ?? $list ?? []) as $row) {
                if (($row['type'] ?? null) === 'domain') {
                    return $row;
                }
            }
        }

        return null;
    }

    /** The order-page picker groups by category, so the product needs one. */
    private function ensureDomainsCategory(string $url, string $apiKey): ?int
    {
        $pairs = (array) $this->adminCall($url, $apiKey, 'product/category_get_pairs', []);
        foreach ($pairs as $id => $title) {
            if (strtolower(trim((string) $title)) === 'domains') {
                return (int) $id;
            }
        }

        return (int) $this->adminCall($url, $apiKey, 'product/category_create', [
            'title' => 'Domains',
            'description' => 'Domain registration, transfer and renewal.',
        ]);
    }

    /** @throws \RuntimeException */
    private function adminCall(string $url, string $apiKey, string $endpoint, array $params = []): mixed
    {
        $json = Http::withBasicAuth('admin', $apiKey)
            ->acceptJson()->asJson()->timeout(30)
            ->post($url.'/api/admin/'.$endpoint, $params)
            ->throw()->json();

        if (! empty($json['error'])) {
            throw new \RuntimeException((string) data_get($json, 'error.message', 'Billing API error'));
        }

        return $json['result'] ?? null;
    }
}
