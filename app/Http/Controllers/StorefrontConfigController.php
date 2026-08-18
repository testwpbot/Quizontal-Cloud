<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class StorefrontConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'clientAreaUrl' => route('client-area'),
            'orderUrl' => config('services.fossbilling.order_url') ?: route('client-area'),
            'freeTrialUrl' => $this->freeTrialUrl(),
        ]);
    }

    /**
     * The trial wizard lives in FOSSBilling. When no explicit URL is set we
     * derive it from the billing base URL rather than hiding the feature.
     */
    private function freeTrialUrl(): ?string
    {
        $explicit = trim((string) config('services.fossbilling.free_trial_url'));
        if ($explicit !== '') {
            return $explicit;
        }

        $base = rtrim((string) config('services.fossbilling.url'), '/');

        return $base === '' ? null : $base.'/free-trial';
    }
}
