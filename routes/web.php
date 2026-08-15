<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DomainCheckController;
use App\Http\Controllers\DomainsController;
use App\Http\Controllers\DomainLookupController;
use App\Http\Controllers\DomainSearchController;
use App\Http\Controllers\DomainTldsController;
use App\Http\Controllers\FossBillingController;
use App\Http\Controllers\HostingController;
use App\Http\Controllers\HostingProductsController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\VpsController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\StorefrontConfigController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
Route::get('/domains', DomainsController::class)->name('domains');
Route::get('/vps', VpsController::class)->name('vps');
Route::get('/hosting', HostingController::class)->name('hosting');
Route::get('/pricing', PricingController::class)->name('pricing');
Route::get('/client-area', FossBillingController::class)->name('client-area');
Route::get('/api/catalog', CatalogController::class)->name('catalog');
Route::get('/api/hosting', HostingProductsController::class)->name('api.hosting');
Route::get('/api/domains/tlds', DomainTldsController::class)->name('domains.tlds');
Route::get('/api/domains/search', DomainSearchController::class)->name('domains.search');
Route::get('/api/domains/lookup', DomainLookupController::class)->name('domains.lookup');
Route::get('/api/domains/check', DomainCheckController::class)->name('domains.check');
Route::get('/api/config', StorefrontConfigController::class)->name('storefront.config');
