<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\FossBillingController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\StorefrontConfigController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/client-area', FossBillingController::class)->name('client-area');
Route::get('/api/catalog', CatalogController::class)->name('catalog');
Route::get('/api/config', StorefrontConfigController::class)->name('storefront.config');
