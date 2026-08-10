<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class DomainsController extends Controller
{
    public function __invoke(): Response
    {
        // HTML must never be cached: it carries the versioned asset URLs that
        // bust the CSS/JS cache on every deploy.
        return response()
            ->view('domains')
            ->header('Cache-Control', 'no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }
}
