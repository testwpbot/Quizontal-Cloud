@extends('layouts.site')

@section('title', 'Quizontal Cloud — Domains, Web Hosting & Cloud VPS priced in LKR')
@section('meta_description', 'Register domains, launch fast NVMe web hosting and deploy cloud VPS — all priced in Sri Lankan Rupees with one wallet and one dashboard.')

@section('content')
<section class="hero container">
  <div class="hero-copy">
    <div class="eyebrow"><span></span> Domains · Hosting · Cloud servers — one platform</div>
    <h1>Everything your project<br><em>needs on the internet.</em></h1>
    <p>Register your domain, launch fast web hosting and scale to full cloud servers — priced honestly in Sri Lankan Rupees and managed from one simple dashboard.</p>
    <div class="hero-actions">
      <a href="{{ route('pricing') }}" class="button button-primary button-large">Explore pricing <span>→</span></a>
      <a id="heroClientArea" data-client-link href="{{ route('client-area') }}" class="button button-ghost button-large">Open client area</a>
    </div>
    <div class="chip-stat-row" style="justify-content:flex-start;margin-top:34px">
      <span class="chip-stat"><i>▦</i>VPS from <b id="heroFromPrice">—</b>/mo</span>
      <span class="chip-stat"><i>⚡</i>Hosting from <b>Rs. 499</b>/mo</span>
      <span class="chip-stat"><i>◎</i>20+ domain extensions</span>
    </div>
  </div>
  <div class="hero-visual" aria-label="Cloud infrastructure overview illustration">
    <div class="visual-card server-console">
      <div class="console-head"><span class="console-icon">Q</span><div><strong>Cloud server</strong><small>Operational</small></div><span class="live-dot">Live</span></div>
      <div class="console-chart"><span style="height:25%"></span><span style="height:42%"></span><span style="height:33%"></span><span style="height:62%"></span><span style="height:48%"></span><span style="height:77%"></span><span style="height:58%"></span><span style="height:88%"></span><span style="height:70%"></span><span style="height:94%"></span></div>
      <div class="console-metrics"><div><small>CPU load</small><strong>18%</strong></div><div><small>Network</small><strong>1.2 Gb/s</strong></div><div><small>Status</small><strong class="online">Online</strong></div></div>
    </div>
    <div class="visual-card location-float"><span class="flag">🇺🇸</span><div><small>Deployed in</small><strong>New Jersey</strong></div></div>
    <div class="hero-float hosting-float"><span class="hf-dot"></span><div><small>Web hosting</small><strong>From Rs. 499/mo</strong></div></div>
    <div class="visual-card price-float"><small>VPS plans from</small><strong id="fromPrice">—</strong><span>/ month</span></div>
    <div class="orbit orbit-one"></div><div class="orbit orbit-two"></div>
  </div>
</section>

<section class="trust-strip"><div class="container trust-grid"><div><strong>3-in-1</strong><span>Domains + hosting + VPS</span></div><div><strong>LKR</strong><span>Local rupee pricing</span></div><div><strong>99.9%</strong><span>Network uptime target</span></div><div><strong>24/7</strong><span>Account access</span></div></div></section>

<section class="section container" id="products">
  <div class="section-intro reveal"><div><span class="section-kicker">One store, three products</span><h2>Start small. Scale when you're ready.</h2></div><p>Grab a domain and a web hosting plan today — then graduate to your own cloud server when traffic grows, without ever leaving the platform.</p></div>
  <div class="trio-grid">
    <article class="trio-card reveal">
      <div class="trio-icon">⚡</div>
      <h3>Web Hosting</h3>
      <span class="trio-tag">Fast NVMe shared hosting on LiteSpeed — perfect for websites, blogs and small businesses.</span>
      <span class="trio-from">Starting at<b>Rs. 499<small> / month</small></b></span>
      <ul class="trio-feats">
        <li><span class="check">✓</span>Free SSL certificate on every site</li>
        <li><span class="check">✓</span>Daily & weekly backup options</li>
        <li><span class="check">✓</span>Instant activation after payment</li>
      </ul>
      <a href="{{ route('hosting') }}" class="button button-primary">View hosting plans <span>→</span></a>
    </article>
    <article class="trio-card reveal" data-reveal-delay="100">
      <div class="trio-icon">▦</div>
      <h3>Cloud VPS</h3>
      <span class="trio-tag">KVM Linux and Windows virtual servers with dedicated resources, deployed in three US locations.</span>
      <span class="trio-from">Starting at<b><span id="trioVpsFrom">—</span><small> / month</small></b></span>
      <ul class="trio-feats">
        <li><span class="check">✓</span>Full root access & OS of choice</li>
        <li><span class="check">✓</span>KVM, storage or Windows flavours</li>
        <li><span class="check">✓</span>Transparent monthly LKR pricing</li>
      </ul>
      <a href="{{ route('vps') }}" class="button button-ghost">Explore VPS plans <span>→</span></a>
    </article>
    <article class="trio-card reveal" data-reveal-delay="200">
      <div class="trio-icon">◎</div>
      <h3>Domain Names</h3>
      <span class="trio-tag">Live availability search across every extension we sell — with free WHOIS privacy included.</span>
      <span class="trio-from">Extensions<b>20+<small> & growing</small></b></span>
      <ul class="trio-feats">
        <li><span class="check">✓</span>Free WHOIS privacy, on by default</li>
        <li><span class="check">✓</span>Honest renewal prices shown upfront</li>
        <li><span class="check">✓</span>DNS managed in your client area</li>
      </ul>
      <a href="{{ route('domains') }}" class="button button-ghost">Find your domain <span>→</span></a>
    </article>
  </div>
</section>

<section class="section section-surface" id="plans">
  <div class="container">
    <div class="section-intro reveal"><div><span class="section-kicker">Featured cloud VPS</span><h2>Popular configurations</h2></div><p>Live from our billing system — pick a size and configure location, OS and hostname during checkout.</p></div>
    <div class="catalog-note" id="catalogNote" hidden></div>
    <div class="plans-grid" id="featuredPlans" aria-live="polite"><div class="plan-skeleton"></div><div class="plan-skeleton"></div><div class="plan-skeleton"></div></div>
    <div style="text-align:center;margin-top:34px" class="reveal"><a href="{{ route('vps') }}" class="button button-ghost button-large">Compare all VPS configurations <span>→</span></a></div>
  </div>
</section>

<section class="section container" id="why">
  <div class="center-intro reveal"><span class="section-kicker">Why Quizontal Cloud</span><h2>Everything you need. Nothing you don't.</h2><p>Clear infrastructure, straightforward local billing and a customer experience designed to stay out of your way.</p></div>
  <div class="feature-grid">
    <article class="feature-card feature-wide reveal"><div class="feature-icon">⚡</div><h3>Fast, modern infrastructure</h3><p>NVMe storage, LiteSpeed web servers for hosting and KVM virtualization for cloud — engineered for real workloads, not just spec sheets.</p><div class="mini-terminal"><span>$ cloud status</span><strong>All systems operational</strong></div></article>
    <article class="feature-card reveal" data-reveal-delay="80"><div class="feature-icon">₨</div><h3>Local LKR pricing</h3><p>Understand your monthly cost before checkout — no foreign-currency surprises, ever.</p></article>
    <article class="feature-card reveal" data-reveal-delay="160"><div class="feature-icon">◈</div><h3>Secure wallet billing</h3><p>Fund your account by bank transfer, track verification, and pay invoices from one customer wallet.</p></article>
    <article class="feature-card reveal" data-reveal-delay="240"><div class="feature-icon">↗</div><h3>Easy scalability</h3><p>Move from hosting to VPS through predictable plan sizes as your projects and traffic grow.</p></article>
    <article class="feature-card feature-accent reveal" data-reveal-delay="320"><div class="feature-icon">✓</div><h3>One client dashboard</h3><p>Domains, DNS, hosting, servers, invoices, wallet and support — all in one place.</p><a id="featureClientArea" data-client-link href="{{ route('client-area') }}">Visit client area →</a></article>
  </div>
</section>

<section class="section section-surface" id="how"><div class="container"><div class="center-intro reveal"><span class="section-kicker">Simple from day one</span><h2>From idea to online in three steps</h2></div><div class="steps-grid">
  <article class="reveal"><span>01</span><div class="step-icon">◎</div><h3>Pick your product</h3><p>Register a domain, choose a hosting plan or configure a cloud server — in any combination.</p></article>
  <article class="reveal" data-reveal-delay="100"><span>02</span><div class="step-icon">◫</div><h3>Fund your wallet</h3><p>Add funds by bank transfer and upload your slip — verification is fast and human.</p></article>
  <article class="reveal" data-reveal-delay="200"><span>03</span><div class="step-icon">✓</div><h3>You're live</h3><p>Your service activates automatically after payment. Manage everything from the client area.</p></article>
</div></div></section>

<section class="section container" id="locations"><div class="section-intro reveal"><div><span class="section-kicker">Cloud locations</span><h2>Deploy closer to your audience</h2></div><p>Select from three US locations during VPS configuration, matched to your virtualization platform.</p></div><div class="location-grid">
  <article class="reveal"><span>🇺🇸</span><div><small>East Coast</small><h3>New Jersey</h3><p>Great for North American and transatlantic workloads.</p></div><b>01</b></article>
  <article class="reveal" data-reveal-delay="100"><span>🇺🇸</span><div><small>West Coast</small><h3>Los Angeles</h3><p>Well positioned for western US and Pacific routes.</p></div><b>02</b></article>
  <article class="reveal" data-reveal-delay="200"><span>🇺🇸</span><div><small>Central US</small><h3>Dallas, Texas</h3><p>A balanced central location for nationwide reach.</p></div><b>03</b></article>
</div></section>

<section class="section section-surface" id="faq"><div class="container"><div class="faq-layout"><div class="reveal"><span class="section-kicker">Frequently asked</span><h2>Questions before you start?</h2><p>Here are the essentials. For anything else, open a support ticket from your client area.</p><a id="faqClientArea" data-client-link href="{{ route('client-area') }}" class="button button-ghost">Contact support</a></div><div class="faq-list">
  <details open class="reveal"><summary>How do I pay? Do you accept cards?<span>+</span></summary><p>Add funds to your Quizontal Cloud wallet by manual bank transfer with slip verification. Once credited, your wallet pays for domains, hosting, VPS and renewals.</p></details>
  <details class="reveal" data-reveal-delay="80"><summary>What's the difference between web hosting and a VPS?<span>+</span></summary><p>Web hosting is a managed space for websites — we handle the server. A VPS gives you a full virtual server with root access — you control everything. Most sites start on hosting.</p></details>
  <details class="reveal" data-reveal-delay="160"><summary>How fast is activation after payment?<span>+</span></summary><p>Automatic. Domains register, hosting accounts create and VPS provisioning begins as soon as your invoice is paid — no manual waiting.</p></details>
  <details class="reveal" data-reveal-delay="240"><summary>Can I manage everything from one account?<span>+</span></summary><p>Yes — domains, DNS, hosting, servers, invoices, wallet history, email records and support tickets all live in the same client area.</p></details>
</div></div></div></section>

<section class="container reveal"><div class="final-cta band-dark"><div><span class="section-kicker">Ready when you are</span><h2>Launch your next project on Quizontal Cloud.</h2><p>Transparent LKR prices, one wallet, and a platform that grows with you.</p></div><div><a href="{{ route('pricing') }}" class="button button-primary button-large">Explore plans</a><a id="ctaClientArea" data-client-link href="{{ route('client-area') }}" class="button button-ghost button-large">Client area</a></div></div></section>
@endsection

@push('page-scripts')
<script src="/app.js?v={{ filemtime(public_path('app.js')) }}" defer></script>
@endpush
