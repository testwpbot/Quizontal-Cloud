<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Server-side bridge to FOSSBilling's guest domain API.
 *
 * Keeps the marketing site's domain tooling free of CORS problems and never
 * exposes the billing origin to the browser. Uses only guest (unauthenticated)
 * endpoints, so no credentials are involved at all.
 */
class FossBillingDomains
{
    public static function configured(): bool
    {
        return trim((string) config('services.fossbilling.url')) !== '';
    }

    public static function orderUrl(): ?string
    {
        $explicit = trim((string) config('services.fossbilling.domain_order_url'));
        if ($explicit !== '') {
            return $explicit;
        }

        if (! self::configured()) {
            return null;
        }

        return rtrim((string) config('services.fossbilling.url'), '/').'/order/domain';
    }

    /**
     * Active, sellable TLDs with their FOSSBilling prices.
     *
     * @return array<int, array{tld:string, register:?float, renew:?float, transfer:?float, allow_register:bool, allow_transfer:bool}>
     */
    public static function tlds(): array
    {
        if (! self::configured()) {
            return [];
        }

        try {
            return Cache::remember('storefront.domain-tlds', 300, function (): array {
                $rows = self::guest('servicedomain/tlds');

                return collect($rows)
                    ->filter(fn ($row): bool => ! empty($row['active']) && ! empty($row['allow_register']))
                    ->map(fn ($row): array => [
                        'tld' => (string) ($row['tld'] ?? ''),
                        'register' => isset($row['price_registration']) ? (float) $row['price_registration'] : null,
                        'renew' => isset($row['price_renew']) ? (float) $row['price_renew'] : null,
                        'transfer' => isset($row['price_transfer']) ? (float) $row['price_transfer'] : null,
                        'allow_register' => (bool) ($row['allow_register'] ?? false),
                        'allow_transfer' => (bool) ($row['allow_transfer'] ?? false),
                    ])
                    ->filter(fn (array $row): bool => $row['tld'] !== '')
                    ->sortBy([['register', 'asc']])
                    ->values()
                    ->all();
            });
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{available:bool, message:?string} */
    public static function checkAvailability(string $sld, string $tld): array
    {
        try {
            self::guest('servicedomain/check', ['sld' => $sld, 'tld' => $tld]);

            return ['available' => true, 'message' => null];
        } catch (DomainApiException $exception) {
            return ['available' => false, 'message' => $exception->getMessage()];
        }
    }

    public static function canBeTransferred(string $sld, string $tld): bool
    {
        try {
            self::guest('servicedomain/can_be_transferred', ['sld' => $sld, 'tld' => $tld]);

            return true;
        } catch (DomainApiException) {
            return false;
        }
    }

    /**
     * Calls a FOSSBilling guest endpoint and returns the unwrapped result.
     *
     * @throws DomainApiException When the endpoint reports an error
     */
    private static function guest(string $endpoint, array $params = []): mixed
    {
        $url = rtrim((string) config('services.fossbilling.url'), '/').'/api/guest/'.$endpoint;

        try {
            $json = Http::acceptJson()->asJson()->timeout(15)->post($url, $params)->json();
        } catch (\Throwable $exception) {
            throw new DomainApiException('The domain service could not be reached. Please try again shortly.', previous: $exception);
        }

        if (! empty($json['error'])) {
            throw new DomainApiException((string) data_get($json, 'error.message', 'The domain service reported an error.'));
        }

        return $json['result'] ?? null;
    }
}
