<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class FossBillingController extends Controller
{
    /** Redirect customers to the separately deployed FossBilling client portal. */
    public function __invoke(): RedirectResponse
    {
        $url = config('services.fossbilling.url');
        abort_unless($url, 503, 'The client area is being configured.');

        return redirect()->away(rtrim($url, '/').'/index.php?_url=/client/login');
    }
}
