<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Syncs the sellable TLD catalog into FOSSBilling automatically.
 *
 * Prices come from Porkbun's public pricing feed (no credentials needed),
 * are converted to LKR using the same ExchangeRate-API source as the VPS
 * catalog, get the configured USD margin applied, and are upserted as
 * FOSSBilling TLD rows attached to the Porkbun registrar. Store managers
 * never maintain TLDs by hand.
 */
class SyncDomainTldsToFossBilling extends Command
{
    protected $signature = 'fossbilling:sync-domains
        {--dry-run : Show the price list that would be written without making changes}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Sync domain TLDs and LKR prices from Porkbun into FossBilling via Admin API';

    /**
     * Popular, API-registerable extensions synced when DOMAIN_SYNC_TLDS is
     * empty. '*' syncs every extension in the pricing feed instead.
     */
    private const DEFAULT_TLDS = [
        'com', 'net', 'org', 'co', 'io', 'dev', 'app', 'ai', 'xyz', 'me',
        'info', 'biz', 'store', 'online', 'site', 'tech', 'cloud', 'shop',
        'blog', 'pro', 'club', 'tv', 'cc', 'gg',
    ];

    /**
     * Extensions Porkbun does not support through its API (website-only
     * because of registry rules). Kept out so orders can never fail.
     */
    private const EXCLUDED_TLDS = [
        'uk', 'co.uk', 'org.uk', 'me.uk',
        'us', 'ca', 'eu', 'au', 'com.au', 'net.au', 'org.au',
    ];

    public function handle(): int
    {
        $fossbillingUrl = rtrim((string) config('services.fossbilling.url'), '/');
        $apiKey = (string) config('services.fossbilling.admin_api_key');

        if ($fossbillingUrl === '' || $apiKey === '') {
            $this->error('Set FOSSBILLING_URL and FOSSBILLING_ADMIN_API_KEY in .env first.');
            return self::FAILURE;
        }

        // Step 1: locate the Porkbun registrar record inside FossBilling
        $this->info('Step 1: Locating the Porkbun registrar in FossBilling...');
        $registrar = $this->findPorkbunRegistrar($fossbillingUrl, $apiKey);
        if ($registrar === null) {
            $this->error('No registrar using the Porkbun adapter exists in FossBilling yet.');
            $this->line('Create it first: admin → Domain registration → New domain registrar → Porkbun.');
            return self::FAILURE;
        }
        $this->info(sprintf('Using registrar "%s" (id %s).', $registrar['title'], $registrar['id']));

        // Step 2: live USD -> LKR rate (same source as the VPS catalog)
        $this->info('Step 2: Reading the current USD/LKR rate...');
        $rate = $this->exchangeRate();
        if ($rate === null) {
            return self::FAILURE;
        }
        $this->info('USD/LKR rate: '.$rate);

        // Step 3: Porkbun's public price feed (no credentials required)
        $this->info('Step 3: Downloading the Porkbun price list...');
        $pricing = $this->porkbunPricing();
        if ($pricing === null) {
            return self::FAILURE;
        }
        $this->info(count($pricing).' extensions in the feed.');

        // Step 4: decide which TLDs to sync
        $targets = $this->targetTlds($pricing);
        if ($targets === []) {
            $this->error('No target TLDs matched the pricing feed. Check DOMAIN_SYNC_TLDS.');
            return self::FAILURE;
        }
        $this->info(count($targets).' extensions selected for syncing.');

        // Step 5: existing TLD rows in FossBilling
        $existing = $this->existingTlds($fossbillingUrl, $apiKey);
        if ($existing === null) {
            return self::FAILURE;
        }

        // Step 6: upsert
        $profit = (float) config('services.porkbun.profit_usd', 1);
        $this->info(sprintf('Step 6: Syncing (margin: USD %.2f per domain per year)...', $profit));
        $this->newLine();

        $created = $updated = $skipped = 0;
        foreach ($targets as $tld) {
            $row = $pricing[$tld];
            $prices = [
                'price_registration' => $this->toLkr($row['registration'] ?? null, $rate, $profit),
                'price_renew' => $this->toLkr($row['renewal'] ?? null, $rate, $profit),
                'price_transfer' => $this->toLkr($row['transfer'] ?? null, $rate, $profit),
            ];
            if ($prices['price_registration'] === null) {
                $this->warn(sprintf('  .%s skipped — no registration price in the feed.', $tld));
                $skipped++;
                continue;
            }

            $payload = [
                'tld' => '.'.$tld,
                'tld_registrar_id' => $registrar['id'],
                'min_years' => 1,
                'allow_register' => 1,
                'allow_transfer' => 1,
                'active' => 1,
            ] + $prices;

            $exists = array_key_exists('.'.$tld, $existing);
            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    '  %s .%s  register Rs. %s%s  renew Rs. %s  transfer Rs. %s',
                    $exists ? 'update' : 'create',
                    $tld,
                    number_format((float) $prices['price_registration'], 0),
                    $exists ? ' (was Rs. '.number_format((float) ($existing['.'.$tld]['price_registration'] ?? 0), 0).')' : '',
                    number_format((float) ($prices['price_renew'] ?? 0), 0),
                    number_format((float) ($prices['price_transfer'] ?? 0), 0),
                ));
                $exists ? $updated++ : $created++;
                continue;
            }

            try {
                if ($exists) {
                    $this->adminCall($fossbillingUrl, $apiKey, 'servicedomain/tld_update', $payload);
                    $updated++;
                } else {
                    $this->adminCall($fossbillingUrl, $apiKey, 'servicedomain/tld_create', $payload);
                    $created++;
                }
                $this->line(sprintf('  %s .%s  register Rs. %s', $exists ? 'updated' : 'created', $tld, number_format((float) $prices['price_registration'], 0)));
            } catch (\RuntimeException $exception) {
                $this->warn(sprintf('  .%s failed: %s', $tld, $exception->getMessage()));
                $skipped++;
            }
        }

        $this->newLine();
        $this->info(sprintf('Done%s: %d created, %d updated, %d skipped.', $this->option('dry-run') ? ' (dry run)' : '', $created, $updated, $skipped));

        return self::SUCCESS;
    }

    /** @return array{id:string,title:string}|null */
    private function findPorkbunRegistrar(string $url, string $apiKey): ?array
    {
        try {
            $list = $this->adminCall($url, $apiKey, 'servicedomain/registrar_get_list', ['per_page' => 100]);
            $rows = $list['list'] ?? $list ?? [];

            $seen = [];
            foreach ((array) $rows as $row) {
                $adapter = strtolower((string) ($row['registrar'] ?? $row['adapter'] ?? ''));
                $name = strtolower((string) ($row['title'] ?? $row['name'] ?? ''));
                $config = (array) ($row['config'] ?? []);

                // Porkbun API keys always start with "pk1_" (live) or "pk1_sb_" (sandbox).
                $hasPorkbunKey = str_starts_with(strtolower((string) ($config['api-key'] ?? '')), 'pk1_');

                $isPorkbun = str_contains($adapter, 'porkbun') || str_contains($name, 'porkbun') || $hasPorkbunKey;

                $seen[] = [
                    'id' => (string) ($row['id'] ?? ''),
                    'title' => (string) ($row['title'] ?? $row['name'] ?? '?'),
                    'adapter' => (string) ($row['registrar'] ?? $row['adapter'] ?? ''),
                    'isPorkbun' => $isPorkbun,
                ];

                if ($isPorkbun) {
                    return ['id' => (string) ($row['id'] ?? ''), 'title' => (string) ($row['title'] ?? $row['name'] ?? 'Porkbun')];
                }
            }

            // Nothing matched — show every registrar so the operator can see why.
            if ($seen !== []) {
                $this->line('Registrars found in FOSSBilling:');
                foreach ($seen as $r) {
                    $this->line(sprintf(
                        '  #%s "%s" (adapter: %s)%s',
                        $r['id'] !== '' ? $r['id'] : '?',
                        $r['title'],
                        $r['adapter'] !== '' ? $r['adapter'] : 'none',
                        $r['isPorkbun'] ? '  <-- matches' : ''
                    ));
                }
            }
        } catch (\RuntimeException $exception) {
            $this->error('Could not read registrars: '.$exception->getMessage());
        }

        return null;
    }

    private function exchangeRate(): ?float
    {
        $exchangeKey = (string) config('services.exchange_rate.key');
        if ($exchangeKey === '') {
            $this->error('Set EXCHANGERATE_API_KEY in .env first (same key the VPS import uses).');
            return null;
        }

        try {
            $exchange = Http::acceptJson()->timeout(20)
                ->get("https://v6.exchangerate-api.com/v6/{$exchangeKey}/latest/USD")
                ->throw()->json();
            $rate = (float) data_get($exchange, 'conversion_rates.LKR');
            if ($rate <= 0) {
                throw new \RuntimeException('no LKR rate in the response');
            }

            return $rate;
        } catch (\Throwable $exception) {
            $this->error('ExchangeRate-API failed: '.$exception->getMessage());
            return null;
        }
    }

    /** @return array<string, array<string, string>>|null */
    private function porkbunPricing(): ?array
    {
        $base = rtrim((string) config('services.porkbun.api_url', 'https://api.porkbun.com/api/json/v3'), '/');
        try {
            $json = Http::acceptJson()->asJson()->timeout(30)->post($base.'/pricing/get')->throw()->json();
            $pricing = $json['pricing'] ?? null;
            if (! is_array($pricing) || $pricing === []) {
                throw new \RuntimeException('empty pricing payload');
            }

            return $pricing;
        } catch (\Throwable $exception) {
            $this->error('Could not read the Porkbun price feed: '.$exception->getMessage());
            return null;
        }
    }

    /** @param array<string, mixed> $pricing */
    private function targetTlds(array $pricing): array
    {
        $configured = strtolower(trim((string) config('services.porkbun.sync_tlds', '')));
        $excluded = array_merge(self::EXCLUDED_TLDS, $this->envList('DOMAIN_SYNC_EXCLUDE'));

        if ($configured === '' ) {
            $targets = self::DEFAULT_TLDS;
        } elseif ($configured === '*') {
            $targets = array_keys($pricing);
        } else {
            $targets = $this->envList('DOMAIN_SYNC_TLDS');
        }

        return collect($targets)
            ->map(fn ($tld): string => strtolower(ltrim(trim((string) $tld), '.')))
            ->filter(fn (string $tld): bool => $tld !== '' && isset($pricing[$tld]) && ! in_array($tld, $excluded, true))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return array<string, array>|null Keyed by dotted TLD ('.com') */
    private function existingTlds(string $url, string $apiKey): ?array
    {
        try {
            $list = $this->adminCall($url, $apiKey, 'servicedomain/tld_get_list', ['per_page' => 1000]);
            $rows = $list['list'] ?? $list ?? [];

            return collect((array) $rows)->keyBy(fn ($row): string => strtolower((string) ($row['tld'] ?? '')))->all();
        } catch (\RuntimeException $exception) {
            $this->error('Could not read existing TLDs: '.$exception->getMessage());
            return null;
        }
    }

    private function toLkr(mixed $usdPrice, float $rate, float $profitUsd): ?float
    {
        if ($usdPrice === null || ! is_numeric($usdPrice) || (float) $usdPrice <= 0) {
            return null;
        }

        return round(((float) $usdPrice + $profitUsd) * $rate, 2);
    }

    /** @return string[] */
    private function envList(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) env($key, '')))));
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
