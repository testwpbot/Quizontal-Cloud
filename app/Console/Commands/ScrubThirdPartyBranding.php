<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Rewrites third-party provider names out of the FOSSBilling catalog so
 * customers only ever see Quizontal branding. Reads through the guest API,
 * writes through the admin API (official endpoints only — no direct DB
 * edits). Dry-run by default; --force applies the changes.
 *
 * Slugs are intentionally untouched: the Laravel catalog overlay matches
 * "interserver-*" slugs and FOSSBilling URLs depend on them.
 */
class ScrubThirdPartyBranding extends Command
{
    protected $signature = 'fossbilling:scrub-branding
        {--dry-run : Show every change without writing anything (default)}
        {--force : Apply the rewrites via the FossBilling admin API}';

    protected $description = 'Replace InterServer/Porkbun mentions in FOSSBilling product, category and registrar names with Quizontal branding';

    private const REPLACEMENTS = [
        'INTERSERVER' => 'QUIZONTAL',
        'InterServer' => 'Quizontal',
        'Interserver' => 'Quizontal',
        'interserver' => 'quizontal',
        'PORKBUN' => 'QUIZONTAL',
        'Porkbun' => 'Quizontal',
        'porkbun' => 'quizontal',
    ];

    public function handle(): int
    {
        $fossbillingUrl = rtrim((string) config('services.fossbilling.url'), '/');
        $apiKey = (string) config('services.fossbilling.admin_api_key');

        if ($fossbillingUrl === '' || $apiKey === '') {
            $this->error('Set FOSSBILLING_URL and FOSSBILLING_ADMIN_API_KEY in .env first.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('force');
        $changed = 0;

        $products = $this->adminCall($fossbillingUrl, $apiKey, 'product/get_list', ['per_page' => 1000]);
        // The admin API has no category list endpoint; the guest list carries
        // everything we need (id, title, description) without credentials.
        $categories = $this->guestCall($fossbillingUrl, 'product/category_get_list', ['per_page' => 500]);

        $this->info($apply ? 'Mode: APPLY (writing changes via admin API)' : 'Mode: DRY RUN (nothing will be written — use --force to apply)');
        $this->newLine();

        foreach ((array) ($products['list'] ?? $products ?? []) as $product) {
            $changed += $this->scrub(
                'product',
                (string) $product['id'],
                (string) ($product['title'] ?? ''),
                (string) ($product['description'] ?? ''),
                $fossbillingUrl,
                $apiKey,
                $apply
            );
        }

        foreach ((array) ($categories['list'] ?? $categories ?? []) as $category) {
            $changed += $this->scrub(
                'category',
                (string) $category['id'],
                (string) ($category['title'] ?? ''),
                (string) ($category['description'] ?? ''),
                $fossbillingUrl,
                $apiKey,
                $apply
            );
        }

        // Registrars: the guest TLD list exposes each registrar's display name
        // to every visitor, so those must stay brand-free as well.
        try {
            $registrars = $this->adminCall($fossbillingUrl, $apiKey, 'servicedomain/registrar_get_list', ['per_page' => 100]);
            foreach ((array) ($registrars['list'] ?? $registrars ?? []) as $registrar) {
                $name = (string) ($registrar['title'] ?? $registrar['name'] ?? '');
                $newName = $this->clean($name);
                if ($name === '' || $newName === $name) {
                    continue;
                }
                $this->line(sprintf('  [registrar #%s] %s  →  <info>%s</info>', $registrar['id'] ?? '?', $name, $newName));
                if ($apply) {
                    try {
                        $this->adminCall($fossbillingUrl, $apiKey, 'servicedomain/registrar_update', [
                            'id' => (int) ($registrar['id'] ?? 0),
                            'title' => $newName,
                        ]);
                        $this->line('      <info>updated ✓</info>');
                        $changed++;
                    } catch (\RuntimeException $exception) {
                        $this->warn('      rename failed (rename it manually: admin → Domain registration → registrars): '.$exception->getMessage());
                    }
                } else {
                    $changed++;
                }
            }
        } catch (\RuntimeException $exception) {
            $this->warn('Could not read registrars: '.$exception->getMessage());
        }

        $this->newLine();
        if ($changed === 0) {
            $this->info('Nothing contained third-party branding. Catalog is clean.');
        } else {
            $this->info(sprintf('%s: %d item(s) %s.', $apply ? 'Done' : 'Dry run done', $changed, $apply ? 'updated' : 'would be updated'));
            if (! $apply) {
                $this->line('Review the table above, then run: php artisan fossbilling:scrub-branding --force');
            }
        }
        $this->line('Note: product slugs were left untouched on purpose (the storefront matches interserver-* slugs).');

        return self::SUCCESS;
    }

    private function scrub(string $kind, string $id, string $title, string $description, string $url, string $apiKey, bool $apply): int
    {
        $newTitle = $this->clean($title);
        $newDescription = $this->clean($description);

        if ($newTitle === $title && $newDescription === $description) {
            return 0;
        }

        $this->line(sprintf('  [%s #%s] %s', $kind, $id, $title !== $newTitle ? $title.'  →  <info>'.$newTitle.'</info>' : $title));
        if ($description !== $newDescription) {
            $this->line(sprintf('      description: %s', str_replace(["\r", "\n"], ' ', $description)));
            $this->line(sprintf('           becomes: %s', str_replace(["\r", "\n"], ' ', $newDescription)));
        }

        if ($apply) {
            $endpoint = $kind === 'product' ? 'product/update' : 'product/category_update';
            $this->adminCall($url, $apiKey, $endpoint, [
                'id' => $id,
                'title' => $newTitle,
                'description' => $newDescription,
            ]);
            $this->line('      <info>updated ✓</info>');
        }

        return 1;
    }

    private function clean(string $value): string
    {
        $value = strtr($value, self::REPLACEMENTS);

        return trim((string) preg_replace('/\s{2,}/', ' ', $value) ?? $value);
    }

    private function guestCall(string $url, string $endpoint, array $params = []): mixed
    {
        $json = Http::acceptJson()->asJson()->timeout(30)
            ->post($url.'/api/guest/'.$endpoint, $params)
            ->throw()->json();

        if (! empty($json['error'])) {
            throw new \RuntimeException((string) data_get($json, 'error.message', 'Billing API error'));
        }

        return $json['result'] ?? null;
    }

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
