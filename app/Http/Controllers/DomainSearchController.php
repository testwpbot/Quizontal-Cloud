<?php

namespace App\Http\Controllers;

use App\Support\FossBillingDomains;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Instant keyword search for domains.
 *
 * One request expands a bare word ("myshop") — or an exact domain
 * ("myshop.com") — into every sellable TLD with FOSSBilling's cached LKR
 * prices, plus alternative name ideas. It performs zero registry traffic of
 * its own; live availability is fetched row-by-row through
 * /api/domains/check and paced to the registrar's rate limit.
 */
class DomainSearchController extends Controller
{
    /** Extensions pushed to the top of results, in store-relevance order. */
    private const POPULAR_ORDER = ['.com', '.lk', '.net', '.org', '.io', '.co', '.ai', '.dev', '.app', '.xyz', '.me', '.shop', '.store', '.cloud', '.tech'];

    private const PREFIXES = ['get', 'try', 'my', 'go', 'use', 'the'];

    private const SUFFIXES = ['hq', 'shop', 'store', 'online', 'app', 'cloud', 'labs', 'lk'];

    /** Seconds the browser should wait between live checks (Porkbun paces checkDomain at ~10s). */
    public const CHECK_INTERVAL = 11;

    public function __invoke(Request $request): JsonResponse
    {
        $raw = strtolower(trim((string) $request->query('name', '')));
        $raw = preg_replace('#^https?://#', '', $raw) ?? $raw;
        $raw = preg_replace('/^www\./', '', $raw) ?? $raw;
        $raw = rtrim((string) explode('/', $raw)[0], '.');
        $raw = trim($raw, '.');

        if ($raw === '') {
            return $this->invalid('Type a name or an idea — for example “myshop” or “myshop.com”.');
        }

        $tlds = FossBillingDomains::tlds();
        if ($tlds === []) {
            return response()->json([
                'ok' => false,
                'configured' => FossBillingDomains::configured(),
                'message' => 'Domain pricing is not set up yet. Please try again shortly.',
            ], FossBillingDomains::configured() ? 200 : 503);
        }

        [$sld, $tld, $notice] = $this->interpret($raw, $tlds);
        if ($sld === null) {
            return $this->invalid('That name contains characters domains cannot use. Letters, numbers and hyphens only.');
        }

        return response()->json([
            'ok' => true,
            'type' => $tld !== null ? 'exact' : 'keyword',
            'sld' => $sld,
            'tld' => $tld,
            'notice' => $notice,
            'results' => $this->results($sld, $tlds, $tld),
            'suggestions' => $this->suggestions($sld),
            'count' => count($tlds),
            'checkInterval' => self::CHECK_INTERVAL,
            'orderUrl' => FossBillingDomains::orderUrl(),
        ]);
    }

    /**
     * Exact-domain when the input ends with a sellable TLD; otherwise treat
     * the first label as a keyword — dropping unsupported extensions with a
     * notice so the search still returns value.
     *
     * @return array{0:?string, 1:?string, 2:?string} [sld, tld|null, notice|null]
     */
    private function interpret(string $raw, array $tlds): array
    {
        if (! str_contains($raw, '.')) {
            return [$this->sanitizeLabel($raw), null, null];
        }

        $matchedTld = null;
        $matchedSld = null;
        $candidates = collect($tlds)->pluck('tld')->sortByDesc(fn (string $tld): int => strlen($tld));
        foreach ($candidates as $candidate) {
            if (str_ends_with($raw, $candidate)) {
                $matchedTld = $candidate;
                $matchedSld = rtrim(substr($raw, 0, -strlen($candidate)), '.');
                break;
            }
        }

        if ($matchedTld !== null && $matchedSld !== null && $this->validLabel($matchedSld)) {
            return [$matchedSld, $matchedTld, null];
        }

        $label = (string) (explode('.', $raw)[0] ?? '');
        $notice = $matchedTld !== null
            ? 'Subdomains cannot be registered — searching the base name across all extensions instead.'
            : sprintf('We do not sell %s domains yet — here is “%s” across every extension we do sell.', substr($raw, strlen($label)), $label);

        return [$this->sanitizeLabel($label), null, $notice];
    }

    private function sanitizeLabel(string $label): ?string
    {
        $label = str_replace([' ', '_'], '-', $label);
        $label = preg_replace('/[^a-z0-9-]/', '', $label) ?? '';
        $label = trim(preg_replace('/-+/', '-', $label) ?? '', '-');

        return $this->validLabel($label) ? $label : null;
    }

    private function validLabel(string $label): bool
    {
        return preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label) === 1;
    }

    /** @return array<int, array<string, mixed>> */
    private function results(string $sld, array $tlds, ?string $exactTld): array
    {
        $rank = array_flip(self::POPULAR_ORDER);
        $rows = array_map(fn (array $row): array => [
            'tld' => $row['tld'],
            'domain' => $sld.$row['tld'],
            'register' => $row['register'],
            'renew' => $row['renew'],
            'transfer' => $row['transfer'],
            'allowRegister' => (bool) ($row['allow_register'] ?? false),
            'allowTransfer' => (bool) ($row['allow_transfer'] ?? false),
            'popular' => isset($rank[$row['tld']]),
        ], $tlds);

        usort($rows, function (array $a, array $b) use ($rank, $exactTld): int {
            if ($exactTld !== null) {
                if ($a['tld'] === $exactTld) {
                    return -1;
                }
                if ($b['tld'] === $exactTld) {
                    return 1;
                }
            }
            $ra = $rank[$a['tld']] ?? PHP_INT_MAX;
            $rb = $rank[$b['tld']] ?? PHP_INT_MAX;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            $pa = $a['register'] ?? PHP_INT_MAX;
            $pb = $b['register'] ?? PHP_INT_MAX;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return strcmp($a['tld'], $b['tld']);
        });

        return $rows;
    }

    /** @return string[] */
    private function suggestions(string $sld): array
    {
        $ideas = [];
        foreach (self::PREFIXES as $prefix) {
            $ideas[] = $prefix.$sld;
            $ideas[] = $prefix.'-'.$sld;
        }
        foreach (self::SUFFIXES as $suffix) {
            $ideas[] = $sld.$suffix;
        }
        if (str_contains($sld, '-')) {
            $ideas[] = str_replace('-', '', $sld);
        }

        $unique = [];
        foreach ($ideas as $idea) {
            if ($idea !== $sld && ! isset($unique[$idea]) && $this->validLabel($idea)) {
                $unique[$idea] = true;
            }
        }

        return array_slice(array_keys($unique), 0, 8);
    }

    private function invalid(string $message): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message], 422);
    }
}
