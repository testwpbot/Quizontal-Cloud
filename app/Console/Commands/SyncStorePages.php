<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Creates/updates the dedicated "VPS Hosting" and "Domains" pages inside
 * FOSSBilling (Custom Pages module) and makes sure the module is enabled.
 *
 * Content lives in deploy/fossbilling/store-pages/*.html so it is versioned
 * with the repo — the billing DB only ever receives a copy. Idempotent:
 * re-running updates the pages in place (matched by slug), never duplicates.
 */
class SyncStorePages extends Command
{
    protected $signature = 'fossbilling:sync-pages
                            {--dry-run : Show what would change without writing to FOSSBilling}';

    protected $description = 'Publish the VPS Hosting and Domains catalog pages into FOSSBilling';

    /** slug => [title, description] (content file = deploy/fossbilling/store-pages/{slug}.html) */
    private const PAGES = [
        'vps' => ['VPS Hosting', 'Cloud VPS lines and plans with LKR pricing.'],
        'domains' => ['Domains', 'Search and register domains with live availability and LKR pricing.'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $billing = rtrim((string) config('services.fossbilling.url'), '/');
        $apiKey = (string) config('services.fossbilling.admin_api_key');

        if ($billing === '' || $apiKey === '') {
            $this->error('Set FOSSBILLING_URL and FOSSBILLING_ADMIN_API_KEY in .env first.');
            return self::FAILURE;
        }

        $storefront = rtrim((string) (env('STOREFRONT_URL') ?: config('app.url')), '/');
        $this->line(sprintf('Storefront link baked into pages: %s', $storefront === '' ? '<disabled, in-billing fallback>' : $storefront));

        // 1) Make sure the Custom Pages module is enabled.
        if (! $dryRun) {
            try {
                $this->adminCall($billing, $apiKey, 'extension/activate', ['type' => 'mod', 'id' => 'custompages']);
                $this->info('Custom Pages module: enabled.');
            } catch (Throwable $exception) {
                // Already active (or core module) — harmless either way, the page routes decide.
                $this->line('Custom Pages module activate: '.$exception->getMessage().' (continuing)');
            }
        }

        // 2) Upsert each page by slug.
        $existing = $this->existingPages($billing, $apiKey, $dryRun);
        $created = 0;
        $updated = 0;

        foreach (self::PAGES as $slug => [$title, $description]) {
            $file = base_path("deploy/fossbilling/store-pages/{$slug}.html");
            if (! is_file($file)) {
                $this->warn("Content file missing: {$file} — skipping {$slug}.");
                continue;
            }

            $content = str_replace('__STOREFRONT_URL__', $storefront, (string) file_get_contents($file));
            $page = $existing[$slug] ?? null;

            if ($dryRun) {
                $this->line(sprintf('[dry-run] %s page "%s" (slug: %s, %d bytes)', $page ? 'would update' : 'would create', $title, $slug, strlen($content)));
                continue;
            }

            try {
                if ($page) {
                    $this->adminCall($billing, $apiKey, 'custompages/update', [
                        'id' => $page['id'],
                        'title' => $title,
                        'slug' => $slug,
                        'content' => $content,
                        'description' => $description,
                    ]);
                    $updated++;
                    $this->line("Updated: {$title} ({$slug})");
                } else {
                    $this->adminCall($billing, $apiKey, 'custompages/create', [
                        'title' => $title,
                        'slug' => $slug,
                        'content' => $content,
                        'description' => $description,
                    ]);
                    $created++;
                    $this->line("Created: {$title} ({$slug})");
                }
            } catch (Throwable $exception) {
                $this->warn("Failed {$slug}: ".$exception->getMessage());
            }
        }

        if (! $dryRun) {
            $this->info("Done: {$created} created, {$updated} updated.");
            $this->line('Pages live at /custompages/vps and /custompages/domains — sidebar links appear automatically.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{id:int, slug:string}>
     */
    private function existingPages(string $billing, string $apiKey, bool $dryRun): array
    {
        if ($dryRun) {
            try {
                return $this->fetchPages($billing, $apiKey);
            } catch (Throwable) {
                return [];
            }
        }

        try {
            return $this->fetchPages($billing, $apiKey);
        } catch (Throwable $exception) {
            $this->warn('Could not list existing pages: '.$exception->getMessage());
            return [];
        }
    }

    /**
     * @return array<string, array{id:int, slug:string}>
     */
    private function fetchPages(string $billing, string $apiKey): array
    {
        $response = $this->adminCall($billing, $apiKey, 'custompages/get_list', ['per_page' => 100]);
        $list = $response['list'] ?? (is_array($response) ? $response : []);

        $map = [];
        foreach ($list as $page) {
            if (isset($page['slug'])) {
                $map[(string) $page['slug']] = ['id' => (int) $page['id'], 'slug' => (string) $page['slug']];
            }
        }

        return $map;
    }

    private function adminCall(string $billing, string $apiKey, string $endpoint, array $params = []): mixed
    {
        $json = Http::withBasicAuth('admin', $apiKey)
            ->asJson()
            ->timeout(30)
            ->post($billing.'/api/admin/'.$endpoint, $params)
            ->throw()
            ->json();

        if (is_array($json) && array_key_exists('result', $json)) {
            if (($json['error'] ?? null) && data_get($json, 'error.message')) {
                throw new \RuntimeException((string) data_get($json, 'error.message'));
            }

            return $json['result'];
        }

        return $json;
    }
}
