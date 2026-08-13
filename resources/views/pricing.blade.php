@extends('layouts.site')

@section('body_class', 'theme-dark')

@section('title', 'Pricing — Web Hosting, Cloud VPS & Domains in LKR | Quizontal Cloud')
@section('meta_description', 'Every Quizontal Cloud price on one page: web hosting from Rs. 499/month, cloud VPS configurations and transparent domain registration and renewal prices — all in Sri Lankan Rupees.')

@section('content')
<section class="page-hero container">
  <div class="eyebrow reveal"><span></span> Transparent pricing</div>
  <h1 class="reveal" data-reveal-delay="80">Every price.<br><em>One page. No surprises.</em></h1>
  <p class="reveal" data-reveal-delay="160">Everything is priced in Sri Lankan Rupees and synced live from our billing system. Renewals are shown next to promos — what you see is what you pay.</p>
</section>

<nav class="pricing-nav reveal" data-reveal-delay="280" aria-label="Pricing sections">
  <a href="#hosting"><i>⚡</i>Web Hosting</a>
  <a href="#vps"><i>▦</i>Cloud VPS</a>
  <a href="#domains"><i>◎</i>Domains</a>
</nav>

<section class="section container" id="hosting" style="padding-top:50px">
  <div class="section-intro reveal"><div><span class="section-kicker">Web hosting</span><h2>Hosting plans</h2></div><p>LiteSpeed + NVMe shared hosting with free SSL on every site. <a href="{{ route('hosting') }}">Full hosting details →</a></p></div>
  <div class="hosting-grid" id="hostingPlansPricing" aria-live="polite">
    <div class="h-skel"></div><div class="h-skel"></div><div class="h-skel"></div>
  </div>
</section>

<section class="section section-surface" id="vps"><div class="container">
  <div class="section-intro reveal"><div><span class="section-kicker">Cloud VPS</span><h2>Server configurations</h2></div><p>Full root-access servers in three US locations. Configure OS and location at checkout. <a href="{{ route('vps') }}">VPS details →</a></p></div>
  <div class="catalog-note" id="pricingCatalogNote" hidden></div>
  <div class="category-tabs reveal" role="tablist" id="pricingVpsTabs"><button class="category-tab active" data-category="general">KVM Linux</button><button class="category-tab" data-category="storage">KVM Storage</button><button class="category-tab" data-category="windows">Hyper-V Windows</button></div>
  <div class="table-wrap reveal" style="margin-top:18px">
    <table class="compare-table" id="pricingVpsTable"><thead><tr><th>Plan</th><th>vCPU</th><th>Memory</th><th>Storage</th><th>Transfer</th><th>Monthly</th><th></th></tr></thead><tbody><tr><td colspan="7">Loading configurations…</td></tr></tbody></table>
  </div>
</div></section>

<section class="section container" id="domains">
  <div class="section-intro reveal"><div><span class="section-kicker">Domain names</span><h2>Every extension we sell</h2></div><p>Registration and renewal prices per year, in rupees — free WHOIS privacy on supported extensions. <a href="{{ route('domains') }}">Search a name →</a></p></div>
  <div class="chip-row toolbar-line reveal" id="pricingDomainTools" hidden>
    <label class="tld-filter" for="pricingTldFilter"><span>⌕</span><input id="pricingTldFilter" type="text" placeholder="Filter extensions — .store, .dev, .lk…" autocomplete="off" spellcheck="false"></label>
    <select id="pricingTldSort" class="tool-select" aria-label="Sort extensions">
      <option value="price-asc">Price: low → high</option>
      <option value="price-desc">Price: high → low</option>
      <option value="name">Extension A–Z</option>
    </select>
    <span class="tld-count" id="pricingTldCount"></span>
  </div>
  <div class="table-wrap reveal">
    <table class="compare-table" id="pricingTldTable"><thead><tr><th>Extension</th><th>Registration / year</th><th>Renewal / year</th><th>Transfer / year</th><th></th></tr></thead><tbody><tr><td colspan="5">Loading domain prices…</td></tr></tbody></table>
  </div>
  <div id="pricingTldMore" style="text-align:center;margin-top:18px"></div>
  <p class="hosting-note reveal">Searching for a name? Live availability and smart suggestions are on the <a href="{{ route('domains') }}">domains page</a>.</p>
</section>

<section class="container reveal"><div class="final-cta band-dark"><div><span class="section-kicker">Ready when you are</span><h2>Found your plan?</h2><p>Fund your wallet once and everything here is a couple of clicks away.</p></div><div><a data-client-link href="{{ route('client-area') }}" class="button button-primary button-large">Open client area</a><a href="{{ route('domains') }}" class="button button-ghost button-large">Search domains</a></div></div></section>
@endsection

@push('page-styles')
<style>
  .toolbar-line { margin: 0 0 16px; align-items: center; }
  .toolbar-line .tld-count { margin-left: auto; color: var(--muted); font-size: 12px; }
</style>
@endpush

@push('page-scripts')
<script src="/hosting.js?v={{ filemtime(public_path('hosting.js')) }}" defer></script>
<script src="/pricing.js?v={{ filemtime(public_path('pricing.js')) }}" defer></script>
@endpush
