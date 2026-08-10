<?php

namespace App\Http\Controllers;

use App\Support\FossBillingDomains;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DomainCheckController extends Controller
{
    /** Cache key of the last live check timestamp (site-wide throttle). */
    private const THROTTLE_KEY = 'storefront.domain-check.last';

    public function __invoke(Request $request): JsonResponse
    {
        $input = strtolower(trim((string) $request->query('name', '')));
        $input = preg_replace('#^https?://#', '', $input) ?? $input;
        $input = preg_replace('/^www\./', '', $input) ?? $input;
        $input = rtrim(explode('/', $input)[0] ?? '', '.');

        if ($input === '' || ! str_contains($input, '.')) {
            return response()->json([
                'ok' => false,
                'message' => 'Type a full domain name, for example mystore.com',
            ], 422);
        }

        if (! preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $input) || str_contains($input, '..')) {
            return response()->json([
                'ok' => false,
                'message' => 'That domain contains characters that are not allowed.',
            ], 422);
        }

        [$sld, $tld] = $this->splitDomain($input);
        if ($sld === null || $tld === null) {
            return response()->json([
                'ok' => false,
                'message' => 'That domain extension is not supported yet. Try .com, .net or .org.',
            ], 422);
        }

        $sellable = collect(FossBillingDomains::tlds());
        $tldInfo = $sellable->firstWhere('tld', $tld);
        if ($tldInfo === null) {
            return response()->json([
                'ok' => false,
                'message' => sprintf('We do not offer %s domains yet. Try one of the extensions listed below.', $tld),
            ], 422);
        }

        // The registrar paces live lookups (Porkbun: ~1 check per 10s per API
        // key). Turn bursts into a polite "retry in Ns" instead of an error.
        $lastCheck = (int) Cache::get(self::THROTTLE_KEY, 0);
        $retryAfter = DomainSearchController::CHECK_INTERVAL - (time() - $lastCheck);
        if ($lastCheck > 0 && $retryAfter > 0) {
            return response()->json([
                'ok' => false,
                'code' => 'throttled',
                'retryAfter' => $retryAfter,
                'message' => 'The registry paces live checks — retrying shortly.',
            ]);
        }
        Cache::put(self::THROTTLE_KEY, time(), 3600);

        $availability = FossBillingDomains::checkAvailability($sld, $tld);

        if ($availability['error']) {
            $message = (string) ($availability['message'] ?? '');
            if (preg_match('/rate.?limit|too many|throttl|limit reached|try again/i', $message) === 1) {
                return response()->json([
                    'ok' => false,
                    'code' => 'throttled',
                    'retryAfter' => DomainSearchController::CHECK_INTERVAL,
                    'message' => 'The registry is pacing live checks — retrying shortly.',
                ]);
            }

            return response()->json([
                'ok' => false,
                'code' => 'error',
                'message' => $message !== '' ? $message : 'Availability could not be checked right now.',
            ]);
        }

        $payload = [
            'ok' => true,
            'domain' => $sld.$tld,
            'sld' => $sld,
            'tld' => $tld,
            'available' => $availability['available'],
            'price' => $tldInfo['register'] ?? null,
            'renew' => $tldInfo['renew'] ?? null,
            'transferable' => false,
            'transferOffered' => (bool) ($tldInfo['allow_transfer'] ?? false),
            'transferPrice' => $tldInfo['transfer'] ?? null,
            'message' => $availability['message'],
            'orderUrl' => FossBillingDomains::orderUrl(),
        ];

        // Transfer probing costs an extra registrar call, so it only runs when
        // explicitly requested (?withTransfer=1). The order form re-validates.
        if (! $availability['available'] && $payload['transferOffered'] && $request->boolean('withTransfer')) {
            $payload['transferable'] = FossBillingDomains::canBeTransferred($sld, $tld);
        }

        return response()->json($payload);
    }

    /**
     * Splits "shop.example.co.uk" into [sld, tld] using the known TLD list so
     * multi-label extensions are handled correctly. Falls back to the last two
     * labels when the TLD list is unavailable.
     *
     * @return array{0:?string, 1:?string}
     */
    private function splitDomain(string $input): array
    {
        $tlds = collect(FossBillingDomains::tlds())
            ->pluck('tld')
            ->filter()
            ->sortByDesc(fn (string $tld): int => strlen($tld));

        foreach ($tlds as $tld) {
            if (str_ends_with($input, $tld)) {
                $sld = substr($input, 0, -strlen($tld));
                $sld = rtrim($sld, '.');

                return str_contains($sld, '.') || $sld === ''
                    ? [null, null]
                    : [$sld, $tld];
            }
        }

        // Fallback: treat the last label as the TLD (with leading dot).
        $position = strrpos($input, '.');
        $sld = substr($input, 0, $position);
        $tld = substr($input, $position);

        return str_contains($sld, '.') || $sld === '' || strlen($tld) < 2
            ? [null, null]
            : [$sld, $tld];
    }
}
