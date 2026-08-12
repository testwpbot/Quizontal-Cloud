@extends('layouts.site')

@section('title', 'Domain Search — Live availability & honest LKR prices | Quizontal Cloud')
@section('meta_description', 'Search any name or idea and instantly see prices across every extension Quizontal Cloud sells — live availability, name suggestions and transparent LKR pricing with free WHOIS privacy.')

@push('page-styles')
<link rel="stylesheet" href="/domains.css?v={{ filemtime(public_path('domains.css')) }}">
@endpush

@section('content')
<section class="hero container domain-hero" id="find">
  <div class="domain-hero-copy">
    <div class="eyebrow"><span></span> Domain names · LKR pricing · Live availability</div>
    <h1>Claim your name<br><em>before someone else does.</em></h1>
    <p>Type a name or just an idea — instantly see prices across every extension we sell, compare renewals, and register in a couple of clicks from the dashboard you already know.</p>
    <form class="domain-search" id="domainSearchForm" role="search">
      <div class="domain-search-box">
        <span class="domain-search-icon">◎</span>
        <input id="domainSearchInput" type="text" inputmode="url" autocomplete="off" spellcheck="false" placeholder="myshop, myshop.com, any idea…" aria-label="Domain name or idea to search">
        <button type="submit" class="button button-primary" id="domainSearchBtn">Search</button>
      </div>
      <div class="domain-chips" id="domainChips" aria-label="Popular extensions">
        <small>Popular:</small>
        <button type="button" data-tld=".com">.com</button><button type="button" data-tld=".net">.net</button><button type="button" data-tld=".org">.org</button><button type="button" data-tld=".io">.io</button><button type="button" data-tld=".dev">.dev</button><button type="button" data-tld=".xyz">.xyz</button>
      </div>
    </form>
    <div class="domain-hero-proof">
      <div><strong id="statExtensions">20+</strong><span>extensions with honest yearly pricing</span></div>
      <div><strong id="statFrom">—</strong><span>cheapest first-year registration</span></div>
      <div><strong>Free</strong><span>WHOIS privacy on supported extensions</span></div>
    </div>
  </div>
</section>

<section class="section container domain-results" id="results" hidden aria-live="polite">
  <div class="results-head">
    <div>
      <span class="section-kicker">Search results</span>
      <h2 id="resultsTitle">Results</h2>
      <p class="results-sub" id="resultsSub"></p>
    </div>
    <div class="results-tools">
      <div class="chip-row" id="resultFilters" role="group" aria-label="Filter results">
        <button type="button" class="tool-chip is-active" data-filter="all">All</button>
        <button type="button" class="tool-chip" data-filter="hide-taken">Hide taken</button>
        <button type="button" class="tool-chip" data-filter="available">Available only</button>
      </div>
      <select id="resultSort" class="tool-select" aria-label="Sort results">
        <option value="best">Best match</option>
        <option value="price-asc">Price: low → high</option>
        <option value="price-desc">Price: high → low</option>
        <option value="name">Extension A–Z</option>
      </select>
    </div>
  </div>
  <div class="results-notice" id="resultsNotice" hidden></div>
  <div class="check-progress" id="checkProgress" hidden>
    <span id="checkProgressLabel"></span>
    <div class="check-progress-track"><i id="checkProgressBar"></i></div>
  </div>
  <div id="domainSpotlight"></div>
  <div class="results-list" id="resultsList"></div>
  <div class="results-ideas" id="resultsIdeas" hidden></div>
</section>

<section class="section container" id="prices">
  <div class="section-intro reveal"><div><span class="section-kicker">Transparent pricing</span><h2>Domain prices, no surprises</h2></div><p>Registration, renewal and transfer prices per year in Sri Lankan Rupees, synced automatically from our billing system. What you see is what you pay.</p></div>
  <div class="tld-tools" id="tldTools" hidden>
    <label class="tld-filter" for="tldFilter">
      <span>⌕</span>
      <input id="tldFilter" type="text" placeholder="Filter extensions — .store, .dev, .ai…" autocomplete="off" spellcheck="false">
    </label>
    <div class="chip-row" id="tldRanges" role="group" aria-label="Filter by price">
      <button type="button" class="tool-chip is-active" data-range="all">All prices</button>
      <button type="button" class="tool-chip" data-range="u2000">Under Rs. 2,000</button>
      <button type="button" class="tool-chip" data-range="u5000">Rs. 2,000–5,000</button>
      <button type="button" class="tool-chip" data-range="o5000">Rs. 5,000+</button>
    </div>
    <select id="tldSort" class="tool-select" aria-label="Sort extensions">
      <option value="popular">Most popular</option>
      <option value="price-asc">Price: low → high</option>
      <option value="price-desc">Price: high → low</option>
      <option value="name">Extension A–Z</option>
    </select>
    <span class="tld-count" id="tldCount"></span>
  </div>
  <div class="tld-grid" id="tldGrid" aria-live="polite">
    <div class="tld-skeleton"></div><div class="tld-skeleton"></div><div class="tld-skeleton"></div><div class="tld-skeleton"></div><div class="tld-skeleton"></div><div class="tld-skeleton"></div>
  </div>
  <div class="catalog-note" id="tldNote" hidden></div>
</section>

<section class="section section-surface" id="why"><div class="container"><div class="center-intro reveal"><span class="section-kicker">Why register with us</span><h2>Domains without the dark patterns.</h2><p>No bait pricing that triples on renewal, no paid privacy add-ons, no mystery checkout steps.</p></div><div class="feature-grid">
  <article class="feature-card reveal"><div class="feature-icon">◎</div><h3>Free WHOIS privacy</h3><p>Your personal details stay hidden from public WHOIS lookups on every supported extension — enabled by default, never a paid add-on.</p></article>
  <article class="feature-card reveal" data-reveal-delay="80"><div class="feature-icon">₨</div><h3>Local LKR billing</h3><p>See real prices in rupees, pay by bank transfer into your wallet, and spend it when you are ready. The same wallet runs your hosting and VPS too.</p></article>
  <article class="feature-card reveal" data-reveal-delay="160"><div class="feature-icon">✓</div><h3>One dashboard</h3><p>Domains appear next to your hosting and servers in the same client area — nameservers, DNS, contacts, transfers and renewals in one place.</p></article>
  <article class="feature-card reveal" data-reveal-delay="240"><div class="feature-icon">↗</div><h3>Honest renewals</h3><p>The renewal price is shown next to the registration price before you buy. First-year promos are clearly marked.</p></article>
</div></div></section>

<section class="section container" id="domain-steps"><div class="center-intro reveal"><span class="section-kicker">Simple from day one</span><h2>From idea to domain in minutes</h2></div><div class="steps-grid">
  <article class="reveal"><span>01</span><div class="step-icon">◎</div><h3>Search and pick</h3><p>Search any word above — we show prices across every extension at once, plus smart name ideas if your first pick is taken.</p></article>
  <article class="reveal" data-reveal-delay="100"><span>02</span><div class="step-icon">◫</div><h3>Pay from your wallet</h3><p>Fund your Quizontal Cloud wallet by bank transfer, then complete the domain order in the client area.</p></article>
  <article class="reveal" data-reveal-delay="200"><span>03</span><div class="step-icon">✓</div><h3>It is yours</h3><p>Your domain is registered automatically with free privacy. Point it at your hosting or VPS with one change.</p></article>
</div></section>

<section class="section section-surface" id="domain-faq"><div class="container"><div class="faq-layout"><div class="reveal"><span class="section-kicker">Frequently asked</span><h2>Domain questions, answered</h2><p>Everything about registering, renewing and moving domains with Quizontal Cloud.</p><a id="faqClientArea" data-client-link href="{{ route('client-area') }}" class="button button-ghost">Contact support</a></div><div class="faq-list">
  <details open class="reveal"><summary>How fast is my domain registered?<span>+</span></summary><p>As soon as your wallet payment is confirmed, our billing system registers the domain automatically. Most domains are active within minutes, with a branded parking page until you build.</p></details>
  <details class="reveal" data-reveal-delay="60"><summary>How can availability checks be this fast?<span>+</span></summary><p>We query public registry (RDAP) feeds for up to 24 extensions in one parallel batch — usually about a second. Extensions without a public feed offer a “Check live” button, and availability is confirmed once more before you pay.</p></details>
  <details class="reveal" data-reveal-delay="120"><summary>Is WHOIS privacy really free?<span>+</span></summary><p>Yes. Privacy is included at no cost on supported extensions, and we enable it by default on every registration.</p></details>
  <details class="reveal" data-reveal-delay="180"><summary>What does a renewal cost?<span>+</span></summary><p>The renewal price for every extension is listed right next to the registration price — in search results and in the pricing table — before you buy. No inflated second-year surprises.</p></details>
  <details class="reveal" data-reveal-delay="240"><summary>Can I transfer my existing domain to Quizontal Cloud?<span>+</span></summary><p>Yes. Search for your domain above and use the transfer option with the EPP/authorization code from your current registrar. Transfers typically complete in 5–7 days and add a year of registration.</p></details>
  <details class="reveal" data-reveal-delay="300"><summary>Can I point the domain at my hosting or VPS?<span>+</span></summary><p>Absolutely. Nameservers and DNS are managed in the same client area — set an A record to your server IP or point it to your hosting package in a click.</p></details>
</div></div></div></section>

<section class="container reveal"><div class="final-cta band-dark"><div><span class="section-kicker">Ready when you are</span><h2>Secure the name your project deserves.</h2><p>Live availability, fair LKR pricing and free privacy — all one search away.</p></div><div><a href="#find" class="button button-primary button-large">Search domains</a><a id="ctaClientArea" data-client-link href="{{ route('client-area') }}" class="button button-ghost button-large">Client area</a></div></section>
@endsection

@push('page-scripts')
<script src="/domains.js?v={{ filemtime(public_path('domains.js')) }}" defer></script>
@endpush
