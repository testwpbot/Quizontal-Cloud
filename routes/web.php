<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DomainCheckController;
use App\Http\Controllers\DomainsController;
use App\Http\Controllers\DomainSearchController;
use App\Http\Controllers\DomainTldsController;
use App\Http\Controllers\FossBillingController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\StorefrontConfigController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/domains', DomainsController::class)->name('domains');
Route::get('/client-area', FossBillingController::class)->name('client-area');
Route::get('/api/catalog', CatalogController::class)->name('catalog');
Route::get('/api/domains/tlds', DomainTldsController::class)->name('domains.tlds');
Route::get('/api/domains/search', DomainSearchController::class)->name('domains.search');
Route::get('/api/domains/check', DomainCheckController::class)->name('domains.check');
Route::get('/api/config', StorefrontConfigController::class)->name('storefront.config');
