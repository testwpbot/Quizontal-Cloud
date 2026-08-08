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
        ]);
    }
}
