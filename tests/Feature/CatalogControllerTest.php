<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogControllerTest extends TestCase
{
    public function test_storefront_uses_enabled_products_and_monthly_prices_from_billing_database(): void
    {
        Storage::fake('local');
        Cache::forget('storefront.fossbilling-products');
        config([
            'services.fossbilling.url' => 'https://billing.test',
            'services.fossbilling.admin_api_key' => 'secret',
        ]);
        Storage::disk('local')->put('catalog.json', json_encode([
            'updatedAt' => '2026-08-10T00:00:00Z',
            'products' => [
                ['id' => 'interserver-kvm-1', 'name' => 'Old name', 'category' => 'general', 'cpu' => 1, 'ramGb' => 2, 'storageGb' => 40, 'storageType' => 'NVMe', 'bandwidthGb' => 2000, 'priceLkr' => 1, 'basePriceUsd' => 3, 'retailPriceUsd' => 4, 'providerProductId' => 'kvm:1', 'available' => true],
                ['id' => 'interserver-kvm-2', 'name' => 'Not stored', 'category' => 'general', 'priceLkr' => 1, 'available' => true],
            ],
        ], JSON_THROW_ON_ERROR));
        Http::fake([
            'https://billing.test/api/admin/product/get_list' => Http::response([
                'result' => ['list' => [[
                    'id' => 20, 'slug' => 'interserver-kvm-1', 'title' => 'KVM Linux Plan 1',
                    'status' => 'enabled', 'hidden' => 0,
                    'pricing' => ['recurrent' => ['1M' => ['price' => '1341.00', 'enabled' => 1]]],
                ]], 'error' => null,
            ]),
        ]);

        $response = $this->getJson('/api/catalog')->assertOk()
            ->assertJsonPath('source', 'billing_database')
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.name', 'KVM Linux Plan 1')
            ->assertJsonPath('products.0.priceLkr', 1341);

        $this->assertArrayNotHasKey('basePriceUsd', $response->json('products.0'));
        $this->assertArrayNotHasKey('retailPriceUsd', $response->json('products.0'));
    }
}
