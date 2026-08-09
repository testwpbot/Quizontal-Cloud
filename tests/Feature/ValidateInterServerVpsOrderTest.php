<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ValidateInterServerVpsOrderTest extends TestCase
{
    public function test_it_validates_with_put_and_never_places_an_order(): void
    {
        config([
            'services.interserver.url' => 'https://provider.test/apiv2',
            'services.interserver.key' => 'private-test-key',
        ]);
        Http::fakeSequence('https://provider.test/apiv2/vps/order')
            ->push(['maxSlices' => 32], 200)
            ->push(['status' => 'ok', 'service_cost' => 3, 'rootpass' => 'must-not-be-printed'], 200);

        $this->artisan('interserver:validate-vps-order', [
            '--platform' => 'kvm',
            '--slices' => 1,
            '--location' => 1,
            '--os' => 'ubuntu-template.tar.gz',
            '--version' => 'ubuntu',
            '--hostname' => 'validation.quizontal-cloud.invalid',
        ])->expectsOutputToContain('No VPS was purchased.')
            ->doesntExpectOutput('must-not-be-printed')
            ->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET');
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT') return false;
            $payload = $request->data();
            return $payload['platform'] === 'kvm'
                && $payload['slices'] === 1
                && isset($payload['rootpass'])
                && strlen($payload['rootpass']) >= 16;
        });
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_show_options_only_fetches_ordering_information(): void
    {
        config([
            'services.interserver.url' => 'https://provider.test/apiv2',
            'services.interserver.key' => 'private-test-key',
        ]);
        Http::fake([
            'https://provider.test/apiv2/vps/order' => Http::response([
                'maxSlices' => 32,
                'locations' => [['id' => 1, 'name' => 'Test Location']],
                'templates' => [['file' => 'ubuntu.tar.gz', 'name' => 'Ubuntu']],
            ]),
        ]);

        $this->artisan('interserver:validate-vps-order', ['--show-options' => true])
            ->expectsOutputToContain('Inspection only')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET');
    }
}
