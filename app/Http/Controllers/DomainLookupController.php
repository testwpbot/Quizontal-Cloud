<?php

namespace App\Http\Controllers;

use App\Support\FossBillingDomains;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Fast, parallel availability checks via public RDAP (registry) services.
 *
 * One browser request checks a keyword against up to 24 sellable TLDs at
 * once using Laravel's HTTP pool — total time ≈ the slowest single registry
 * (~1s), instead of the registrar's one-check-per-10-seconds pace. RDAP
 * answers "registered / not found": anything ambiguous (no RDAP service for
 * the TLD, timeouts, rate limits) is reported as "unknown" so the UI can
 * fall back to the authoritative registrar check. The real validation always
 * happens again inside FOSSBilling at order time.
 */
class DomainLookupController extends Controller
{
    private const MAX_TARGETS = 24;

    private const BOOTSTRAP_URL = 'https://data.iana.org/rdap/dns.json';

    private const BOOTSTRAP_TTL = 86400;

    private const RESULT_TTL = 300;

    public function __invoke(Request $request): JsonResponse
    {
        $sld = strtolower(trim((string) $request->query('sld', '')));
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $sld) !== 1) {
            return response()->json(['ok' => false, 'message' => 'That name contains characters domains cannot use.'], 422);
        }

        $sellable = collect(FossBillingDomains::tlds())->keyBy('tld');
        $targets = collect(explode(',', (string) $request->query('tlds', '')))
            ->map(fn (string $tld): string => '.'.ltrim(strtolower(trim($tld)), '.'))
            ->filter(fn (string $tld): bool => preg_match('/^\.[a-z0-9-]+$/', $tld) === 1 && $sellable->has($tld))
            ->unique()
            ->take(self::MAX_TARGETS)
            ->values();

        if ($targets->isEmpty()) {
            return response()->json(['ok' => false, 'message' => 'No supported extensions were requested.'], 422);
        }

        $servers = $this->rdapServers();
        $results = [];
        $pending = [];

        foreach ($targets as $tld) {
            $base = $servers[substr($tld, 1)] ?? null;
            if ($base === null) {
                $results[$tld] = 'unknown';
                continue;
            }
            $cached = Cache::get($this->cacheKey($sld, $tld));
            if (is_string($cached)) {
                $results[$tld] = $cached;
                continue;
            }
            $pending[$tld] = $base;
        }

        if ($pending !== []) {
            $responses = Http::pool(fn (Pool $pool): array => collect($pending)
                ->map(fn (string $base, string $tld) => $pool->as($tld)
                    ->timeout(6)
                    ->withHeaders(['Accept' => 'application/rdap+json, application/json'])
                    ->get(rtrim($base, '/').'/domain/'.$sld.$tld))
                ->all());

            foreach ($responses as $tld => $response) {
                $status = 'unknown';
                if ($response instanceof Response) {
                    if ($response->status() === 404) {
                        $status = 'available';
                    } elseif ($response->ok() && ($response->json('rdapConformance') !== null || $response->json('objectClassName') === 'domain')) {
                        $status = 'taken';
                    }
                }
                Cache::put($this->cacheKey($sld, $tld), $status, $status === 'unknown' ? 60 : self::RESULT_TTL);
                $results[$tld] = $status;
            }
        }

        return response()->json([
            'ok' => true,
            'sld' => $sld,
            'results' => $results,
        ]);
    }

    private function cacheKey(string $sld, string $tld): string
    {
        return 'storefront.rdap:'.md5($sld.$tld);
    }

    /** @return array<string, string> TLD label (no dot) => RDAP base URL */
    private function rdapServers(): array
    {
        return Cache::remember('storefront.rdap-servers', self::BOOTSTRAP_TTL, function (): array {
            try {
                $json = Http::acceptJson()->timeout(10)->get(self::BOOTSTRAP_URL)->throw()->json();
            } catch (\Throwable) {
                return [];
            }

            $map = [];
            foreach ((array) ($json['services'] ?? []) as $service) {
                $tlds = (array) ($service[0] ?? []);
                $urls = (array) ($service[1] ?? []);
                $base = collect($urls)->first(fn ($url): bool => is_string($url) && str_starts_with($url, 'https://'));
                if ($base === null) {
                    continue;
                }
                foreach ($tlds as $tld) {
                    $label = strtolower((string) $tld);
                    if (! isset($map[$label])) {
                        $map[$label] = $base;
                    }
                }
            }

            return $map;
        });
    }
}
