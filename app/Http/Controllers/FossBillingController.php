<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class FossBillingController extends Controller
{
    /** Redirect customers to the separately deployed FossBilling client portal. */
    public function __invoke(): RedirectResponse
    {
        $baseUrl = config('services.fossbilling.url');
        $loginUrl = config('services.fossbilling.login_url');
        abort_unless($loginUrl || $baseUrl, 503, 'The client area is being configured.');

        return redirect()->away($loginUrl ?: rtrim((string) $baseUrl, '/').'/login');
    }
}
