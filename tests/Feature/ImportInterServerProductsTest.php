<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportInterServerProductsTest extends TestCase
{
    public function test_slice_resources_follow_the_current_provider_grid(): void
    {
        Storage::fake('local');
        config([
            'services.interserver.url' => 'https://provider.test/apiv2',
            'services.interserver.key' => 'provider-key',
            'services.interserver.profit_usd' => 1,
            'services.exchange_rate.key' => 'exchange-key',
        ]);
        Http::fake([
            'https://provider.test/apiv2/vps/order' => Http::response([
                'maxSlices' => 4,
                'ramSlice' => 2048,
                'hdSlice' => 40,
                'hdStorageSlice' => 1000,
                'bwSlice' => 2000,
                'vpsSliceKvmLCost' => 3,
                'vpsSliceKvmStorageCost' => 3,
                'vpsSliceKvmWCost' => 5,
                'locationStock' => [],
            ]),
            'https://v6.exchangerate-api.com/v6/exchange-key/latest/USD' => Http::response([
                'conversion_rates' => ['LKR' => 335.34],
            ]),
        ]);

        $this->artisan('interserver:import-products')->assertSuccessful();
        $catalog = json_decode(Storage::disk('local')->get('catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        $linux = collect($catalog['products'])->where('platform', 'kvm')->values();

        $this->assertSame([1, 1, 2, 2], $linux->pluck('cpu')->all());
        $this->assertSame([2, 4, 6, 8], $linux->pluck('ramGb')->all());
        $this->assertSame([40, 80, 120, 160], $linux->pluck('storageGb')->all());
        $this->assertSame([2000, 4000, 6000, 8000], $linux->pluck('bandwidthGb')->all());
        $this->assertSame([3, 6, 9, 12], $linux->pluck('basePriceUsd')->all());
    }
}
