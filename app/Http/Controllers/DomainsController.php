<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class DomainsController extends Controller
{
    public function __invoke(): View
    {
        return view('domains');
    }
}
