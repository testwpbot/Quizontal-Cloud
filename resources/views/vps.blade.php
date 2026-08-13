@extends('layouts.site')

@section('body_class', 'theme-dark')

@section('title', 'Cloud VPS Hosting — KVM Linux, Storage & Windows servers | Quizontal Cloud')
@section('meta_description', 'Deploy KVM Linux, KVM Storage or Hyper-V Windows cloud servers in three US locations with transparent monthly pricing in Sri Lankan Rupees.')

@section('content')
<section class="page-hero container">
  <div class="eyebrow reveal"><span></span> Cloud VPS servers</div>
  <h1 class="reveal" data-reveal-delay="80">Raw cloud power,<br><em>predictable rupee pricing.</em></h1>
  <p class="reveal" data-reveal-delay="160">Full root access virtual servers with dedicated vCPU, RAM and storage. Choose a size, pick a location, select your OS — pay one clear monthly LKR price.</p>
  <div class="chip-stat-row reveal" data-reveal-delay="240">
    <span class="chip-stat"><i>▦</i>KVM Linux · Storage · Windows</span>
    <span class="chip-stat"><i>⌖</i>3 US locations</span>
    <span class="chip-stat"><i>₨</i>From <b id="heroFromPrice">—</b>/mo</span>
  </div>
</section>

<section class="section container" id="plans" style="padding-top:60px">
  <div class="catalog-note" id="catalogNote" hidden></div>
  <div class="plan-panel reveal">
    <div class="category-tabs" role="tablist"><button class="category-tab active" data-category="general">KVM Linux</button><button class="category-tab" data-category="storage">KVM Storage</button><button class="category-tab" data-category="windows">Hyper-V Windows</button></div>
    <div class="plan-builder">
      <div class="builder-copy"><span>Your configuration</span><h3>Build a plan around your workload</h3><p>Choose the plan that fits your CPU and RAM needs. Location and OS come at checkout.</p></div>
      <div class="selector-group plan-select-group"><label>Available plan</label><div class="custom-plan-select" id="customPlanSelect"><button type="button" class="plan-select-trigger" id="planSelectTrigger" aria-haspopup="listbox" aria-expanded="false"><span><strong id="planSelectTitle">Loading plans…</strong><small id="planSelectMeta">Please wait</small></span><i>⌄</i></button><div class="plan-select-menu" id="planSelectMenu" role="listbox"></div></div><select id="planSelector" class="plan-select-native" aria-label="Select a VPS plan" tabindex="-1"><option>Loading plans…</option></select><small id="planSource">Current available configurations</small></div>
      <div class="builder-price"><small>Monthly total</small><div id="dynamicPrice">Loading…</div><p id="dynamicSpecs">Fetching the latest billing products</p><button class="button button-primary" id="findPlanBtn">View selected plan</button></div>
    </div>
  </div>
  <div class="subheading reveal"><div><span>Recommended</span><h3>Popular configurations</h3></div><button class="text-button" id="seeAllPlans">See all plans <span>↓</span></button></div>
  <div class="plans-grid" id="featuredPlans" aria-live="polite"><div class="plan-skeleton"></div><div class="plan-skeleton"></div><div class="plan-skeleton"></div><div class="plan-skeleton"></div><div class="plan-skeleton"></div><div class="plan-skeleton"></div></div>
  <div class="all-plans-wrapper" id="allPlansWrapper"><div class="table-scroll"><table class="plans-table"><thead><tr><th>Plan</th><th>vCPU</th><th>Memory</th><th>Storage</th><th>Transfer</th><th>Monthly price</th><th></th></tr></thead><tbody id="plansTableBody"></tbody></table></div></div>
</section>

<section class="section section-surface" id="vps-features"><div class="container"><div class="center-intro reveal"><span class="section-kicker">Every VPS includes</span><h2>Serious infrastructure, zero surprises</h2><p>The essentials are baked into every plan — no paid add-ons required to run a real server.</p></div><div class="feature-grid">
  <article class="feature-card reveal"><div class="feature-icon">▦</div><h3>Dedicated resources</h3><p>Guaranteed vCPU, memory and NVMe-backed storage — your performance is yours alone.</p></article>
  <article class="feature-card reveal" data-reveal-delay="80"><div class="feature-icon">✓</div><h3>Full root access</h3><p>Install anything, configure everything. Your server, your rules — with your choice of Linux distro or Windows.</p></article>
  <article class="feature-card reveal" data-reveal-delay="160"><div class="feature-icon">⌖</div><h3>Three US locations</h3><p>New Jersey, Los Angeles and Dallas — deploy close to the people who use your product.</p></article>
  <article class="feature-card reveal" data-reveal-delay="240"><div class="feature-icon">↗</div><h3>Scale as you grow</h3><p>Move between predictable plan sizes; upgrades are a support ticket away.</p></article>
  <article class="feature-card reveal" data-reveal-delay="320"><div class="feature-icon">₨</div><h3>LKR monthly billing</h3><p>The catalog price in rupees is the price you pay. No exchange-rate guessing.</p></article>
  <article class="feature-card reveal" data-reveal-delay="400"><div class="feature-icon">◈</div><h3>Wallet-powered renewals</h3><p>Keep your wallet topped up and renewals pay themselves — services stay online, stress stays low.</p></article>
</div></div></section>

<section class="section container" id="locations"><div class="section-intro reveal"><div><span class="section-kicker">Cloud locations</span><h2>Deploy closer to your audience</h2></div><p>Select from three US locations during configuration. Availability is matched to the chosen virtualization platform.</p></div><div class="location-grid">
  <article class="reveal"><span>🇺🇸</span><div><small>East Coast</small><h3>New Jersey</h3><p>Great for North American and transatlantic workloads.</p></div><b>01</b></article>
  <article class="reveal" data-reveal-delay="100"><span>🇺🇸</span><div><small>West Coast</small><h3>Los Angeles</h3><p>Well positioned for western US and Pacific routes.</p></div><b>02</b></article>
  <article class="reveal" data-reveal-delay="200"><span>🇺🇸</span><div><small>Central US</small><h3>Dallas, Texas</h3><p>A balanced central location for nationwide reach.</p></div><b>03</b></article>
</div></section>

<section class="section section-surface" id="vps-faq"><div class="container"><div class="faq-layout"><div class="reveal"><span class="section-kicker">Frequently asked</span><h2>VPS questions, answered</h2><p>Everything about ordering and running a cloud server with us.</p><a id="faqClientArea" data-client-link href="{{ route('client-area') }}" class="button button-ghost">Contact support</a></div><div class="faq-list">
  <details open class="reveal"><summary>How are prices calculated?<span>+</span></summary><p>Plans are based on current upstream infrastructure cost plus our configured margin, converted to LKR at the imported exchange rate — synced straight from billing.</p></details>
  <details class="reveal" data-reveal-delay="80"><summary>Which operating systems can I install?<span>+</span></summary><p>KVM plans offer popular Linux distributions; the Hyper-V category offers Windows Server. You select the OS image during checkout configuration.</p></details>
  <details class="reveal" data-reveal-delay="160"><summary>How do I pay for a VPS?<span>+</span></summary><p>Fund your wallet by bank transfer (upload the slip for verification), then pay the invoice from your wallet balance. Renewals work the same way.</p></details>
  <details class="reveal" data-reveal-delay="240"><summary>Can I host multiple websites on one VPS?<span>+</span></summary><p>Absolutely — it's your server. If you'd rather skip server administration entirely, our web hosting plans manage the stack for you.</p></details>
</div></div></div></section>

<section class="container reveal"><div class="final-cta band-dark"><div><span class="section-kicker">Ready to deploy</span><h2>Your server is minutes away.</h2><p>Choose a configuration above, fund your wallet, and it spins up.</p></div><div><a href="#plans" class="button button-primary button-large">Choose a plan</a><a href="{{ route('hosting') }}" class="button button-ghost button-large">Just need hosting?</a></div></div></section>
@endsection

@push('page-scripts')
<script src="/app.js?v={{ filemtime(public_path('app.js')) }}" defer></script>
@endpush
