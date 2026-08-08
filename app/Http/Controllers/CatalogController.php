<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class CatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $disk = Storage::disk('local');
        $path = 'catalog.json';
        $contents = $disk->exists($path)
            ? $disk->get($path)
            : $disk->get('catalog.sample.json');

        return response()->json(json_decode($contents, true, flags: JSON_THROW_ON_ERROR));
    }
}
