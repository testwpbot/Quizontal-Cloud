@extends('layouts.site')

@section('body_class', 'theme-dark')

@section('title', 'Quizontal Cloud — Domains, Web Hosting & Cloud VPS priced in LKR')
@section('meta_description', 'Register domains, launch fast NVMe web hosting and deploy cloud VPS — all priced in Sri Lankan Rupees with one wallet and one dashboard.')

@section('content')
<section class="hero qc-hero container">
  <div class="qc-hero-inner">
    <h2 class="qc-hero-tagline" data-animated-text="Domains · Hosting · Cloud servers — one platform" data-delay="0"></h2>
    <h1 class="qc-hero-title" data-animated-text="Everything your project needs on the" data-last-word="internet." data-delay="400"></h1>
    <p class="qc-hero-sub">Register your domain, launch fast web hosting and scale to full cloud servers — priced honestly in Sri Lankan Rupees and managed from one simple dashboard.</p>
    <div class="qc-hero-actions">
      <a href="{{ route('pricing') }}" class="premium-btn">Get started</a>
      <a id="heroClientArea" data-client-link href="{{ route('client-area') }}" class="button button-ghost button-large">Open client area</a>
    </div>
    <div class="chip-stat-row qc-hero-chips">
      <span class="chip-stat"><i>▦</i>VPS from <b id="heroFromPrice">—</b>/mo</span>
      <span class="chip-stat"><i>⚡</i>Hosting from <b>Rs. 499</b>/mo</span>
      <span class="chip-stat"><i>◎</i>20+ domain extensions</span>
    </div>
  </div>
</section>

<section class="section container qc-demo" id="demo" style="padding-top:0">
  <div class="qc-demo-frame">
    {{-- ===== DASHBOARD ===== --}}
    <div class="qc-demo-panel active" data-qc-panel="dashboard">
      <div class="p">
        <div class="qc-demo-toolbar">
          <div class="qc-field" style="min-width:150px;max-width:200px">
            <label>Time period</label>
            <select id="qcPeriodSelect">
              <option value="last_7_days" selected>Last 7 days</option>
              <option value="last_30_days">Last 30 days</option>
              <option value="last_90_days">Last 90 days</option>
              <option value="custom">Custom range</option>
            </select>
          </div>
          <div class="qc-field qc-msel">
            <label>Categories</label>
            <div class="qc-msel-trigger" id="qcCategoryTrigger" tabindex="0">
              <span id="qcCategoryLabel">VPS, Hosting, Domains</span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 9l6 6 6-6"/></svg>
            </div>
            <div class="qc-msel-menu" id="qcCategoryMenu">
              <label class="qc-msel-option"><input type="checkbox" value="vps" checked><span>Cloud VPS</span></label>
              <label class="qc-msel-option"><input type="checkbox" value="hosting" checked><span>Web Hosting</span></label>
              <label class="qc-msel-option"><input type="checkbox" value="domains" checked><span>Domains</span></label>
            </div>
          </div>
          <div class="qc-field" style="flex:1;min-width:220px;max-width:300px">
            <label>Date range</label>
            <input type="text" id="qcDateRange" readonly value="Last 7 days">
          </div>
        </div>

        <div class="qc-demo-stats" style="margin-bottom:18px">
          <article class="stat-card">
            <div class="stat-ring green" style="--val:85"><span class="stat-ring-inner">₨</span></div>
            <div class="stat-meta"><span class="stat-label">Total Revenue</span><strong class="stat-value"><span class="qc-count" data-count="184300" data-prefix="LKR ">0</span></strong><span class="stat-sub">All time</span></div>
          </article>
          <article class="stat-card">
            <div class="stat-ring cyan" style="--val:65"><span class="stat-ring-inner">▦</span></div>
            <div class="stat-meta"><span class="stat-label">Active Services</span><strong class="stat-value"><span class="qc-count" data-count="24">0</span></strong><span class="stat-sub">VPS · hosting · domains</span></div>
          </article>
          <article class="stat-card">
            <div class="stat-ring amber" style="--val:92"><span class="stat-ring-inner">◫</span></div>
            <div class="stat-meta"><span class="stat-label">New Invoices</span><strong class="stat-value"><span class="qc-count" data-count="148">0</span></strong><span class="stat-sub">This year</span></div>
          </article>
          <article class="stat-card">
            <div class="stat-ring" style="--val:78"><span class="stat-ring-inner">♥</span></div>
            <div class="stat-meta"><span class="stat-label">Customer Satisfaction</span><strong class="stat-value"><span class="qc-count" data-count="94" data-suffix="%">0</span></strong><span class="stat-sub">Based on tickets</span></div>
          </article>
        </div>

        <div class="qc-demo-card">
          <div class="qc-demo-card-head">
            <h3 class="qc-demo-card-title">Recent invoices</h3>
            <span class="qc-demo-badge qc-demo-badge-pink" id="qcSelBadge" style="display:none">0 selected</span>
          </div>
          <div class="qc-demo-card-body">
            <div class="qc-table-tools">
              <input type="text" id="qcInvoiceSearch" placeholder="Search invoices…">
              <span class="qc-demo-count" id="qcInvoiceCount">10 rows</span>
            </div>
            <div style="overflow-x:auto">
              <table class="qc-demo-table" id="qcInvoiceTable">
                <thead>
                  <tr>
                    <th style="cursor:default"><input type="checkbox" class="qc-table-check" id="qcCheckAll"></th>
                    <th data-key="id">Invoice <span class="qc-sort-ind"></span></th>
                    <th data-key="customer">Customer <span class="qc-sort-ind"></span></th>
                    <th data-key="service">Service <span class="qc-sort-ind"></span></th>
                    <th data-key="amount">Amount <span class="qc-sort-ind"></span></th>
                    <th data-key="status">Status <span class="qc-sort-ind"></span></th>
                    <th data-key="date">Date <span class="qc-sort-ind"></span></th>
                  </tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
            <div class="qc-pagination">
              <button class="qc-page-btn" data-qc-page="prev" disabled>‹</button>
              <button class="qc-page-btn active" data-qc-page="1">1</button>
              <button class="qc-page-btn" data-qc-page="2">2</button>
              <button class="qc-page-btn" data-qc-page="next">›</button>
              <span class="qc-page-info">Page 1 of 2</span>
            </div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1.6fr 1fr;gap:14px;margin-top:14px" class="qc-demo-split">
          <div class="qc-demo-card">
            <div class="qc-demo-card-head"><h3 class="qc-demo-card-title">Revenue overview</h3></div>
            <div class="qc-demo-card-body">
              <svg class="qc-demo-chart" id="qcSpendChart" viewBox="0 0 560 220" preserveAspectRatio="none" aria-label="Revenue chart"></svg>
              <div class="qc-demo-legend">
                <span><span class="qc-demo-dot" id="qcLegendPrimary"></span>Revenue</span>
                <span><span class="qc-demo-dot" style="background:#22d3ee"></span>New services</span>
              </div>
            </div>
          </div>

          <div class="qc-demo-card">
            <div class="qc-demo-card-head">
              <h3 class="qc-demo-card-title">Revenue target</h3>
              <span style="font-size:1.25rem;font-weight:800;color:var(--text)" id="qcTargetPercent">75%</span>
            </div>
            <div class="qc-demo-card-body" style="display:flex;flex-direction:column;justify-content:space-between;gap:14px">
              <div>
                <input type="range" class="qc-slider" id="qcTargetSlider" min="0" max="100" value="75">
                <div style="text-align:center;margin-top:8px;font-size:.75rem;color:var(--muted-2)">Drag the slider to adjust target</div>
              </div>
              <div style="display:flex;align-items:center;justify-content:space-between">
                <div><p style="font-weight:700;font-size:.85rem;margin:0;color:var(--text)">Monthly target</p><p style="margin:0;font-size:.8rem;color:var(--muted-2)">LKR <span id="qcTargetMoney">75,000</span></p></div>
                <div style="text-align:right"><p style="font-weight:700;font-size:.85rem;margin:0;color:var(--text)">Achieved</p><p style="margin:0;font-size:.8rem;color:var(--green);font-weight:700">LKR 50,000</p></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>
</section>

<section class="section container" id="stats" style="padding-top:0">
  <div class="stat-grid">
    <article class="stat-card reveal">
      <div class="stat-ring" style="--val:100"><span class="stat-ring-inner">⚡</span></div>
      <div class="stat-meta"><span class="stat-label">Products</span><strong class="stat-value">3-in-1</strong><span class="stat-sub">Domains · Hosting · VPS</span></div>
    </article>
    <article class="stat-card reveal" data-reveal-delay="70">
      <div class="stat-ring cyan" style="--val:100"><span class="stat-ring-inner">₨</span></div>
      <div class="stat-meta"><span class="stat-label">Pricing</span><strong class="stat-value">LKR</strong><span class="stat-sub">Local rupee pricing</span></div>
    </article>
    <article class="stat-card reveal" data-reveal-delay="140">
      <div class="stat-ring green" style="--val:90"><span class="stat-ring-inner">▦</span></div>
      <div class="stat-meta"><span class="stat-label">Uptime</span><strong class="stat-value">99.9%</strong><span class="stat-sub">Network uptime target</span></div>
    </article>
    <article class="stat-card reveal" data-reveal-delay="210">
      <div class="stat-ring amber" style="--val:100"><span class="stat-ring-inner">✓</span></div>
      <div class="stat-meta"><span class="stat-label">Support</span><strong class="stat-value">24/7</strong><span class="stat-sub">Account access</span></div>
    </article>
  </div>
</section>

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

<section class="container reveal"><div class="final-cta band-dark"><div><span class="section-kicker">Ready when you are</span><h2>Launch your next project on Quizontal Cloud.</h2><p>Transparent LKR prices, one wallet, and a platform that grows with you.</p></div><div><a href="{{ route('pricing') }}" class="premium-btn">Explore plans</a><a id="ctaClientArea" data-client-link href="{{ route('client-area') }}" class="button button-ghost button-large">Client area</a></div></div></section>
@endsection

@push('page-scripts')
<script src="/app.js?v={{ filemtime(public_path('app.js')) }}" defer></script>
@endpush
