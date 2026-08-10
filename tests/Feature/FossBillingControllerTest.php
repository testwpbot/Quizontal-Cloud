<?php

namespace Tests\Feature;

use Tests\TestCase;

class FossBillingControllerTest extends TestCase
{
    public function test_client_area_redirects_to_login_derived_from_environment_base_url(): void
    {
        config([
            'services.fossbilling.url' => 'https://billing.example.com',
            'services.fossbilling.login_url' => null,
        ]);

        $this->get('/client-area')->assertRedirect('https://billing.example.com/login');
    }

    public function test_explicit_environment_login_url_takes_precedence(): void
    {
        config([
            'services.fossbilling.url' => 'https://billing.example.com',
            'services.fossbilling.login_url' => 'https://accounts.example.com/sign-in',
        ]);

        $this->get('/client-area')->assertRedirect('https://accounts.example.com/sign-in');
    }
}
