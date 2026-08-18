<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class HostingController extends Controller
{
    public function __invoke(): Response
    {
        // HTML must never be cached: it carries the versioned asset URLs that
        // bust the CSS/JS cache on every deploy.
        return response()
            ->view('hosting', ['freeTrialUrl' => $this->freeTrialUrl()])
            ->header('Cache-Control', 'no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    /**
     * The seven-day trial wizard is served by FOSSBilling. When no explicit URL
     * is configured we derive it from the billing base URL; when billing is not
     * configured at all the storefront simply omits the call to action.
     */
    private function freeTrialUrl(): ?string
    {
        $explicit = trim((string) config('services.fossbilling.free_trial_url'));
        if ($explicit !== '') {
            return $explicit;
        }

        $base = rtrim((string) config('services.fossbilling.url'), '/');

        return $base === '' ? null : $base.'/quizontalfreetrial';
    }
}
