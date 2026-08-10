<?php

namespace App\Http\Controllers;

use App\Support\FossBillingDomains;
use Illuminate\Http\JsonResponse;

class DomainTldsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $tlds = FossBillingDomains::tlds();

        if ($tlds === []) {
            return response()->json([
                'configured' => false,
                'tlds' => [],
                'orderUrl' => FossBillingDomains::orderUrl(),
            ], FossBillingDomains::configured() ? 200 : 503);
        }

        return response()->json([
            'configured' => true,
            'tlds' => $tlds,
            'orderUrl' => FossBillingDomains::orderUrl(),
        ]);
    }
}
