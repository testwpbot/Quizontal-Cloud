@extends('layouts.site')

@section('title', 'Web Hosting — Fast NVMe hosting from Rs. 499/month | Quizontal Cloud')
@section('meta_description', 'Fast LiteSpeed NVMe web hosting with free SSL, backups, email accounts and LKR monthly pricing. Starter, Business and Premium plans with instant activation.')

@push('jsonld')
<script type="application/ld+json">
{
  "@@context": "https://schema.org",
  "@@type": "ItemList",
  "name": "Quizontal Cloud Web Hosting Plans",
  "itemListElement": [
    { "@@type": "ListItem", "position": 1, "item": { "@@type": "Product", "name": "Starter Web Hosting", "description": "1 website, 10 GB NVMe SSD storage, 100 GB bandwidth, free SSL.", "offers": { "@@type": "Offer", "priceCurrency": "LKR", "price": "499", "availability": "https://schema.org/InStock" } } },
    { "@@type": "ListItem", "position": 2, "item": { "@@type": "Product", "name": "Business Web Hosting", "description": "5 websites, 50 GB NVMe SSD storage, 500 GB bandwidth, free SSL, daily backups.", "offers": { "@@type": "Offer", "priceCurrency": "LKR", "price": "999", "availability": "https://schema.org/InStock" } } },
    { "@@type": "ListItem", "position": 3, "item": { "@@type": "Product", "name": "Premium Web Hosting", "description": "Unlimited websites, storage and bandwidth with 20 email accounts and daily backups.", "offers": { "@@type": "Offer", "priceCurrency": "LKR", "price": "1499", "availability": "https://schema.org/InStock" } } }
  ]
}
</script>
@endpush

@section('content')
<section class="page-hero container">
  <div class="eyebrow reveal"><span></span> Web hosting</div>
  <h1 class="reveal" data-reveal-delay="80">Fast web hosting<br><em>that just works.</em></h1>
  <p class="reveal" data-reveal-delay="160">LiteSpeed servers on NVMe storage with free SSL, real backups and DDoS protection. Pay in rupees, get online in minutes — no server skills needed.</p>
  <div class="chip-stat-row reveal" data-reveal-delay="240">
    <span class="chip-stat"><i>₨</i>From <b>Rs. 499</b>/mo</span>
    <span class="chip-stat"><i>⚡</i>LiteSpeed + NVMe</span>
    <span class="chip-stat"><i>✓</i>Free SSL included</span>
    <span class="chip-stat"><i>◈</i>Instant activation</span>
  </div>
</section>

<section class="section container" id="plans" style="padding-top:54px">
  <div class="section-intro reveal"><div><span class="section-kicker">Hosting plans</span><h2>Pick your size</h2></div><p>Live prices from our billing system. Every plan activates automatically the moment your invoice is paid.</p></div>
  <div class="hosting-grid" id="hostingPlans" aria-live="polite">
    <div class="h-skel"></div><div class="h-skel"></div><div class="h-skel"></div>
  </div>
  <p class="hosting-note reveal">Prices in Sri Lankan Rupees, billed monthly to your wallet. Upgrades between plans are one support ticket away.</p>
</section>

<section class="section section-surface" id="compare"><div class="container">
  <div class="center-intro reveal"><span class="section-kicker">Compare plans</span><h2>Side by side</h2><p>The full picture, plan by plan.</p></div>
  <div class="table-wrap reveal">
    <table class="compare-table">
      <thead><tr><th>Feature</th><th>Starter</th><th class="head-pro">Business</th><th>Premium</th></tr></thead>
      <tbody>
        <tr><td>Monthly price</td><td><strong>Rs. 499</strong></td><td class="head-pro"><strong>Rs. 999</strong></td><td><strong>Rs. 1,499</strong></td></tr>
        <tr><td>Websites</td><td>1</td><td class="head-pro">5</td><td><span class="pills">Unlimited</span></td></tr>
        <tr><td>NVMe SSD storage</td><td>10 GB</td><td class="head-pro">50 GB</td><td><span class="pills">Unlimited</span></td></tr>
        <tr><td>Monthly bandwidth</td><td>100 GB</td><td class="head-pro">500 GB</td><td><span class="pills">Unlimited</span></td></tr>
        <tr><td>Custom domain support</td><td class="yes">✓</td><td class="yes">✓</td><td class="yes">✓</td></tr>
        <tr><td>Free SSL certificate</td><td class="yes">✓</td><td class="yes">✓</td><td class="yes">✓</td></tr>
        <tr><td>Email accounts</td><td>1</td><td class="head-pro">5</td><td>20</td></tr>
        <tr><td>Backups</td><td>Weekly</td><td class="head-pro">Daily</td><td>Daily</td></tr>
        <tr><td>Web server</td><td>LiteSpeed</td><td class="head-pro">LiteSpeed</td><td>LiteSpeed</td></tr>
        <tr><td>DDoS protection</td><td>Basic</td><td class="head-pro">Advanced</td><td>Premium</td></tr>
        <tr><td>Website migration</td><td class="no">✗</td><td class="yes">✓ Free</td><td class="yes">✓ Free</td></tr>
        <tr><td>Support level</td><td>Standard</td><td class="head-pro">Priority</td><td>VIP</td></tr>
      </tbody>
    </table>
  </div>
</div></section>

<section class="section container" id="hosting-steps">
  <div class="center-intro reveal"><span class="section-kicker">Zero to online</span><h2>Hosting without the learning curve</h2></div>
  <div class="steps-grid">
    <article class="reveal"><span>01</span><div class="step-icon">◎</div><h3>Order with your domain</h3><p>Register a new domain with us or bring one you already own — the order links them automatically.</p></article>
    <article class="reveal" data-reveal-delay="100"><span>02</span><div class="step-icon">⚡</div><h3>Instant activation</h3><p>The moment payment clears, your hosting account is created and waiting in the client area.</p></article>
    <article class="reveal" data-reveal-delay="200"><span>03</span><div class="step-icon">✓</div><h3>Publish your site</h3><p>Log in to your control panel, upload your site or install your app, and you are live on fast infrastructure.</p></article>
  </div>
  <div style="text-align:center;margin-top:40px" class="reveal"><p style="color:var(--muted);font-size:14px;max-width:560px;margin:0 auto 18px">Need a domain too? Search live availability and pair it with hosting in one flow.</p><a href="{{ route('domains') }}" class="button button-ghost">Find a domain <span>→</span></a></div>
</section>

<section class="section section-surface" id="hosting-faq"><div class="container"><div class="faq-layout"><div class="reveal"><span class="section-kicker">Frequently asked</span><h2>Hosting questions, answered</h2><p>The essentials about our web hosting plans.</p><a data-client-link href="{{ route('client-area') }}" class="button button-ghost">Contact support</a></div><div class="faq-list">
  <details open class="reveal"><summary>Which plan should I start with?<span>+</span></summary><p>Starter covers one personal site comfortably. Business suits portfolios or a few business sites with daily backups. Premium is for many sites or heavier traffic — with unlimited everything critical.</p></details>
  <details class="reveal" data-reveal-delay="80"><summary>Can I upgrade later without downtime?<span>+</span></summary><p>Yes. Open a ticket and we move you to a bigger plan while your site stays online. You only pay the difference for the remaining billing period.</p></details>
  <details class="reveal" data-reveal-delay="160"><summary>What does “unlimited” mean on Premium?<span>+</span></summary><p>Unlimited websites, storage and bandwidth for normal website use — files must belong to your hosted sites, and all plans follow our fair-use policy. File-sharing or backup-dump usage isn't hosting use.</p></details>
  <details class="reveal" data-reveal-delay="240"><summary>Do you move my existing site over?<span>+</span></summary><p>Migration is free on Business and Premium. Send a ticket with your current hosting details and we handle the copy with minimal disruption.</p></details>
  <details class="reveal" data-reveal-delay="320"><summary>How is this different from a VPS?<span>+</span></summary><p>Hosting is fully managed — we run the server and you just upload your site. A <a href="{{ route('vps') }}">Cloud VPS</a> gives you an entire virtual server with root access to configure yourself.</p></details>
</div></div></div></section>

<section class="container reveal"><div class="final-cta band-dark"><div><span class="section-kicker">Ready when you are</span><h2>Put your website on fast hosting today.</h2><p>From Rs. 499/month, SSL and backups included, live in minutes.</p></div><div><a href="#plans" class="button button-primary button-large">Choose a hosting plan</a><a href="{{ route('pricing') }}" class="button button-ghost button-large">See all pricing</a></div></div></section>
@endsection

@push('page-scripts')
<script src="/hosting.js?v={{ filemtime(public_path('hosting.js')) }}" defer></script>
@endpush
