<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class SyncProductsToFossBilling extends Command
{
    protected $signature = 'fossbilling:sync-products
        {--dry-run : Show what would be created/updated without making changes}
        {--force : Skip confirmation prompt}';

    protected $description = 'Import InterServer VPS products and sync them to FossBilling via Admin API';

    public function handle(): int
    {
        $fossbillingUrl = rtrim((string) config('services.fossbilling.url'), '/');

        if (! $fossbillingUrl) {
            $this->error('FOSSBILLING_URL is not set in .env');
            return self::FAILURE;
        }

        // Step 1: Get the FossBilling admin API key
        $apiKey = $this->getApiKey();
        if (! $apiKey) {
            return self::FAILURE;
        }

        // Step 2: First, run the InterServer import to get fresh catalog
        $this->info('Step 1: Importing products from InterServer...');
        $exitCode = $this->call('interserver:import-products');

        if ($exitCode !== 0) {
            $this->error('InterServer import failed. Fix the import first.');
            return self::FAILURE;
        }

        // Step 3: Load the catalog
        $catalogPath = 'catalog.json';
        if (! Storage::disk('local')->exists($catalogPath)) {
            $this->error('No catalog.json found. Run interserver:import-products first.');
            return self::FAILURE;
        }

        $catalog = json_decode(Storage::disk('local')->get($catalogPath), true);
        $products = $catalog['products'] ?? [];

        if (empty($products)) {
            $this->error('No products found in catalog.');
            return self::FAILURE;
        }

        $this->info('Found ' . count($products) . ' products in catalog.');
        $this->newLine();

        // Step 4: Get or create a product category in FossBilling
        $this->info('Step 2: Setting up FossBilling product categories...');
        $categories = $this->getOrCreateCategories($fossbillingUrl, $apiKey);

        if ($categories === null) {
            return self::FAILURE;
        }

        // Step 5: Get existing FossBilling products
        $this->info('Step 3: Fetching existing FossBilling products...');
        $existingProducts = $this->getExistingProducts($fossbillingUrl, $apiKey);

        if ($existingProducts === null) {
            return self::FAILURE;
        }

        $existingBySlug = collect($existingProducts)->keyBy('slug')->all();

        // Step 6: Create/update products in FossBilling
        $this->info('Step 4: Syncing products to FossBilling...');
        $this->newLine();

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($products as $product) {
            $slug = $product['id'];
            $categoryKey = $product['category'] ?? 'general';
            $categoryId = $categories[$categoryKey] ?? $categories['general'] ?? null;

            $title = $product['name'];
            $priceLkr = $product['priceLkr'] ?? 0;
            $description = $this->buildDescription($product);

            if ($this->option('dry-run')) {
                $action = isset($existingBySlug[$slug]) ? 'UPDATE' : 'CREATE';
                $this->line("  [{$action}] {$title} — LKR {$priceLkr}");
                $action === 'CREATE' ? $created++ : $updated++;
                continue;
            }

            if (isset($existingBySlug[$slug])) {
                // Update existing product
                $productId = $existingBySlug[$slug]['id'];
                $success = $this->updateFossBillingProduct(
                    $fossbillingUrl, $apiKey, $productId, $title, $description, $priceLkr, $categoryId, $slug
                );

                if ($success) {
                    $this->line("  <fg=yellow>✓ Updated:</> {$title}");
                    $updated++;
                } else {
                    $this->line("  <fg=red>✗ Failed to update:</> {$title}");
                    $skipped++;
                }
            } else {
                // Create new product
                $productId = $this->createFossBillingProduct(
                    $fossbillingUrl, $apiKey, $title, $description, $priceLkr, $categoryId, $slug
                );

                if ($productId) {
                    $this->line("  <fg=green>✓ Created:</> {$title} (ID: {$productId})");
                    $created++;
                } else {
                    $this->line("  <fg=red>✗ Failed to create:</> {$title}");
                    $skipped++;
                }
            }
        }

        $this->newLine();
        $this->info("Sync complete! Created: {$created} | Updated: {$updated} | Skipped: {$skipped}");

        if ($this->option('dry-run')) {
            $this->warn('This was a dry run. No changes were made. Remove --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    private function getApiKey(): ?string
    {
        $apiKey = env('FOSSBILLING_ADMIN_API_KEY');

        if ($apiKey) {
            return $apiKey;
        }

        $this->warn('FOSSBILLING_ADMIN_API_KEY is not set in .env');
        $this->newLine();
        $this->info('To get your FossBilling Admin API key:');
        $this->line('  1. Go to your FossBilling admin panel');
        $this->line('  2. Click on your profile (top right)');
        $this->line('  3. Go to "Profile" or "API Key" section');
        $this->line('  4. Copy the API key');
        $this->line('  5. Add to .env: FOSSBILLING_ADMIN_API_KEY=your_key_here');
        $this->newLine();

        $apiKey = $this->ask('Or paste your FossBilling Admin API key now');

        if (! $apiKey) {
            $this->error('API key is required.');
            return null;
        }

        return $apiKey;
    }

    private function fossbillingApi(string $baseUrl, string $apiKey, string $endpoint, array $data = []): ?array
    {
        try {
            $response = Http::withBasicAuth('admin', $apiKey)
                ->acceptJson()
                ->timeout(30)
                ->post("{$baseUrl}/api/admin/{$endpoint}", $data);

            $json = $response->json();

            if (isset($json['error']) && $json['error'] !== null) {
                $this->error("FossBilling API error ({$endpoint}): " . ($json['error']['message'] ?? 'Unknown error'));
                return null;
            }

            return $json;
        } catch (\Throwable $e) {
            $this->error("FossBilling API request failed ({$endpoint}): " . $e->getMessage());
            return null;
        }
    }

    private function getOrCreateCategories(string $baseUrl, string $apiKey): ?array
    {
        $categoryMap = [
            'general' => 'General Purpose VPS',
            'storage' => 'Storage Optimized VPS',
            'windows' => 'Windows VPS',
        ];

        // Get existing categories
        $response = $this->fossbillingApi($baseUrl, $apiKey, 'product/category_get_pairs');
        $existingCategories = $response['result'] ?? [];

        $categories = [];

        foreach ($categoryMap as $key => $title) {
            // Check if category already exists (by title)
            $foundId = null;
            foreach ($existingCategories as $id => $existingTitle) {
                if (strtolower($existingTitle) === strtolower($title)) {
                    $foundId = $id;
                    break;
                }
            }

            if ($foundId) {
                $categories[$key] = $foundId;
                $this->line("  Category exists: {$title} (ID: {$foundId})");
            } else {
                // Create the category
                $result = $this->fossbillingApi($baseUrl, $apiKey, 'product/category_create', [
                    'title' => $title,
                    'description' => "InterServer {$title} plans with LKR pricing.",
                ]);

                if ($result && isset($result['result'])) {
                    $categories[$key] = $result['result'];
                    $this->line("  <fg=green>Created category:</> {$title} (ID: {$result['result']})");
                } else {
                    $this->error("  Failed to create category: {$title}");
                    return null;
                }
            }
        }

        $this->newLine();
        return $categories;
    }

    private function getExistingProducts(string $baseUrl, string $apiKey): ?array
    {
        $response = $this->fossbillingApi($baseUrl, $apiKey, 'product/get_list', [
            'per_page' => 500,
        ]);

        if ($response === null) {
            return null;
        }

        return $response['result']['list'] ?? [];
    }

    private function createFossBillingProduct(
        string $baseUrl, string $apiKey, string $title, string $description,
        float $priceLkr, ?int $categoryId, string $slug
    ): ?int {
        // Step 1: Create the product (prepare)
        $result = $this->fossbillingApi($baseUrl, $apiKey, 'product/prepare', [
            'title' => $title,
            'type' => 'custom',
            'product_category_id' => $categoryId,
        ]);

        if (! $result || ! isset($result['result'])) {
            return null;
        }

        $productId = (int) $result['result'];

        // Step 2: Update with full details and pricing
        $this->updateFossBillingProduct(
            $baseUrl, $apiKey, $productId, $title, $description, $priceLkr, $categoryId, $slug
        );

        return $productId;
    }

    private function updateFossBillingProduct(
        string $baseUrl, string $apiKey, int $productId, string $title,
        string $description, float $priceLkr, ?int $categoryId, string $slug
    ): bool {
        $data = [
            'id' => $productId,
            'title' => $title,
            'description' => $description,
            'status' => 'enabled',
            'hidden' => 0,
            'slug' => $slug,
            'pricing' => [
                'type' => 'recurrent',
                'recurrent' => [
                    '1M' => [
                        'setup' => 0,
                        'price' => $priceLkr,
                        'enabled' => 1,
                    ],
                    '3M' => [
                        'setup' => 0,
                        'price' => round($priceLkr * 3 * 0.95), // 5% discount for quarterly
                        'enabled' => 1,
                    ],
                    '6M' => [
                        'setup' => 0,
                        'price' => round($priceLkr * 6 * 0.90), // 10% discount for semi-annual
                        'enabled' => 1,
                    ],
                    '1Y' => [
                        'setup' => 0,
                        'price' => round($priceLkr * 12 * 0.85), // 15% discount for annual
                        'enabled' => 1,
                    ],
                ],
            ],
        ];

        if ($categoryId) {
            $data['product_category_id'] = $categoryId;
        }

        $result = $this->fossbillingApi($baseUrl, $apiKey, 'product/update', $data);

        return $result !== null && ($result['result'] ?? false);
    }

    private function buildDescription(array $product): string
    {
        $parts = [];

        if (! empty($product['cpu'])) {
            $parts[] = $product['cpu'] . ' vCPU' . ($product['cpu'] > 1 ? 's' : '');
        }

        if (! empty($product['ramGb'])) {
            $parts[] = $product['ramGb'] . ' GB RAM';
        }

        if (! empty($product['storageGb'])) {
            $storageType = $product['storageType'] ?? 'SSD';
            $parts[] = $product['storageGb'] . ' GB ' . $storageType;
        }

        if (! empty($product['bandwidthGb'])) {
            $parts[] = $product['bandwidthGb'] . ' GB Transfer';
        }

        $desc = implode(' • ', $parts);

        if (! empty($product['platform'])) {
            $desc .= "\nPlatform: " . ucfirst($product['platform']);
        }

        if (! empty($product['available']) && $product['available'] === false) {
            $desc .= "\n⚠️ Currently out of stock";
        }

        return $desc;
    }
}
