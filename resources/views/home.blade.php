<!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Quizontal Cloud VPS hosting with transparent monthly pricing in Sri Lankan Rupees, fast deployment and a simple customer wallet.">
  <title>Quizontal Cloud — Fast, simple VPS hosting</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/styles.css?v={{ filemtime(public_path('styles.css')) }}">
</head>
<body>
<div class="site-glow glow-one"></div><div class="site-glow glow-two"></div>
<header class="site-header">
  <nav class="container nav-shell">
    <a href="#top" class="brand" aria-label="Quizontal Cloud home"><img src="https://res.cloudinary.com/dt1sdefd6/image/upload/v1786291141/quizontal-cloud-logo_wa4agd.png" alt="Quizontal Cloud"></a>
    <button class="mobile-toggle" id="mobileMenuBtn" aria-label="Open menu" aria-expanded="false"><span></span><span></span><span></span></button>
    <div class="nav-menu" id="navLinks">
      <a href="#plans"><span class="nav-icon">▦</span>VPS plans</a><a href="/domains"><span class="nav-icon">◎</span>Domains</a><a href="#features"><span class="nav-icon">✦</span>Features</a><a href="#locations"><span class="nav-icon">⌖</span>Locations</a><a href="#how-it-works"><span class="nav-icon">↗</span>How it works</a><a href="#faq"><span class="nav-icon">?</span>FAQ</a>
      <div class="mobile-account"><small>Already have an account?</small><a id="mobileClientArea" href="/client-area" class="button button-primary">Open client area</a></div>
    </div>
    <div class="nav-ctas"><a id="clientArea" class="button button-ghost" href="/client-area">Client area</a><a class="button button-primary desktop-cta" href="#plans">Choose a plan</a></div>
  </nav>
</header>
<div class="menu-backdrop" id="menuBackdrop" aria-hidden="true"></div>
<main id="top">
  <section class="hero container">
    <div class="hero-copy">
      <div class="eyebrow"><span></span> Cloud infrastructure made simple</div>
      <h1>Your next server,<br><em>ready for anything.</em></h1>
      <p>Deploy reliable VPS resources with transparent monthly prices in Sri Lankan Rupees. Choose your plan, fund your secure wallet and manage everything from one clean dashboard.</p>
      <div class="hero-actions"><a href="#plans" class="button button-primary button-large">Explore VPS plans <span>→</span></a><a id="heroClientArea" href="/client-area" class="button button-ghost button-large">Open client area</a></div>
      <div class="hero-proof"><div class="proof-avatars"><span>Q</span><span>24</span><span>✓</span></div><div><strong>Simple monthly billing</strong><small>No confusing foreign-currency checkout</small></div></div>
    </div>
    <div class="hero-visual" aria-label="Cloud server overview illustration">
      <div class="visual-card server-console">
        <div class="console-head"><span class="console-icon">Q</span><div><strong>Cloud server</strong><small>Operational</small></div><span class="live-dot">Live</span></div>
        <div class="console-chart"><span style="height:25%"></span><span style="height:42%"></span><span style="height:33%"></span><span style="height:62%"></span><span style="height:48%"></span><span style="height:77%"></span><span style="height:58%"></span><span style="height:88%"></span><span style="height:70%"></span><span style="height:94%"></span></div>
        <div class="console-metrics"><div><small>CPU load</small><strong>18%</strong></div><div><small>Network</small><strong>1.2 Gb/s</strong></div><div><small>Status</small><strong class="online">Online</strong></div></div>
      </div>
      <div class="visual-card location-float"><span class="flag">🇺🇸</span><div><small>Deployed in</small><strong>New Jersey</strong></div></div>
      <div class="visual-card price-float"><small>Plans from</small><strong id="heroFromPrice">Loading…</strong><span>/ month</span></div>
      <div class="orbit orbit-one"></div><div class="orbit orbit-two"></div>
    </div>
  </section>
  <section class="trust-strip"><div class="container trust-grid"><div><strong id="fromPrice">—</strong><span>Starting monthly price</span></div><div><strong>3</strong><span>US cloud locations</span></div><div><strong>99.9%</strong><span>Network uptime target</span></div><div><strong>24/7</strong><span>Account access</span></div></div></section>

  <section class="section container" id="plans">
    <div class="section-intro"><div><span class="section-kicker">VPS pricing</span><h2>Find the right cloud plan</h2></div><p>Every plan includes clear resource allocations and a monthly LKR price. Scale from a lightweight server to a larger production workload.</p></div>
    <div class="catalog-note" id="catalogNote" hidden></div>
    <div class="plan-panel">
      <div class="category-tabs" role="tablist"><button class="category-tab active" data-category="general">KVM Linux</button><button class="category-tab" data-category="storage">KVM Storage</button><button class="category-tab" data-category="windows">Hyper-V Windows</button></div>
      <div class="plan-builder">
        <div class="builder-copy"><span>Your configuration</span><h3>Build a plan around your workload</h3><p>Choose the CPU and RAM combination you need. We’ll match it to the current available plan.</p></div>
        <div class="selector-group plan-select-group"><label>Available plan</label><div class="custom-plan-select" id="customPlanSelect"><button type="button" class="plan-select-trigger" id="planSelectTrigger" aria-haspopup="listbox" aria-expanded="false"><span><strong id="planSelectTitle">Loading plans…</strong><small id="planSelectMeta">Please wait</small></span><i>⌄</i></button><div class="plan-select-menu" id="planSelectMenu" role="listbox"></div></div><select id="planSelector" class="plan-select-native" aria-label="Select a VPS plan" tabindex="-1"><option>Loading plans…</option></select><small id="planSource">Current available configurations</small></div>
        <div class="builder-price"><small>Monthly total</small><div id="dynamicPrice">Loading…</div><p id="dynamicSpecs">Fetching the latest billing products</p><button class="button button-primary" id="findPlanBtn">View selected plan</button></div>
      </div>
    </div>
    <div class="subheading"><div><span>Recommended</span><h3>Popular configurations</h3></div><button class="text-button" id="seeAllPlans">See all plans <span>↓</span></button></div>
    <div class="plans-grid" id="featuredPlans" aria-live="polite"><div class="plan-skeleton"></div><div class="plan-skeleton"></div><div class="plan-skeleton"></div><div class="plan-skeleton"></div><div class="plan-skeleton"></div><div class="plan-skeleton"></div></div>
    <div class="all-plans-wrapper" id="allPlansWrapper"><div class="table-scroll"><table class="plans-table"><thead><tr><th>Plan</th><th>vCPU</th><th>Memory</th><th>Storage</th><th>Transfer</th><th>Monthly price</th><th></th></tr></thead><tbody id="plansTableBody"></tbody></table></div></div>
  </section>

  <section class="section section-surface" id="features"><div class="container"><div class="center-intro"><span class="section-kicker">Built for real workloads</span><h2>Everything you need. Nothing you don’t.</h2><p>Clear infrastructure, straightforward billing and a customer experience designed to stay out of your way.</p></div><div class="feature-grid">
    <article class="feature-card feature-wide"><div class="feature-icon">⚡</div><h3>Fast cloud resources</h3><p>Choose KVM compute, high-capacity storage, or Windows virtualization with transparent CPU, memory, disk and transfer allocations.</p><div class="mini-terminal"><span>$ cloud status</span><strong>All systems operational</strong></div></article>
    <article class="feature-card"><div class="feature-icon">₨</div><h3>Local LKR pricing</h3><p>Understand your monthly cost before checkout—without manually calculating a USD conversion.</p></article>
    <article class="feature-card"><div class="feature-icon">◈</div><h3>Secure wallet billing</h3><p>Fund your account, track payment verification and pay invoices from one customer wallet.</p></article>
    <article class="feature-card"><div class="feature-icon">↗</div><h3>Easy scalability</h3><p>Move through predictable plan sizes as your projects and traffic grow.</p></article>
    <article class="feature-card feature-accent"><div class="feature-icon">✓</div><h3>One client dashboard</h3><p>Access services, invoices, wallet history, email records and support from one place.</p><a id="featureClientArea" href="/client-area">Visit client area →</a></article>
  </div></div></section>

  <section class="section container" id="locations"><div class="section-intro"><div><span class="section-kicker">Cloud locations</span><h2>Deploy closer to your audience</h2></div><p>Select from three US locations during configuration. Availability is matched to the chosen virtualization platform.</p></div><div class="location-grid"><article><span>🇺🇸</span><div><small>East Coast</small><h3>New Jersey</h3><p>Great for North American and transatlantic workloads.</p></div><b>01</b></article><article><span>🇺🇸</span><div><small>West Coast</small><h3>Los Angeles</h3><p>Well positioned for western US and Pacific routes.</p></div><b>02</b></article><article><span>🇺🇸</span><div><small>Central US</small><h3>Dallas, Texas</h3><p>A balanced central location for nationwide reach.</p></div><b>03</b></article></div></section>

  <section class="section section-surface" id="how-it-works"><div class="container"><div class="center-intro"><span class="section-kicker">Simple from day one</span><h2>From plan to cloud server</h2></div><div class="steps-grid"><article><span>01</span><div class="step-icon">⌁</div><h3>Choose and configure</h3><p>Select a plan, location, operating system and hostname.</p></article><article><span>02</span><div class="step-icon">◫</div><h3>Fund your wallet</h3><p>Add funds securely and upload your payment slip for verification.</p></article><article><span>03</span><div class="step-icon">✓</div><h3>Pay and manage</h3><p>Use your wallet to pay, then manage service and billing details in the client area.</p></article></div></div></section>

  <section class="section container" id="faq"><div class="faq-layout"><div><span class="section-kicker">Frequently asked</span><h2>Questions before you deploy?</h2><p>Here are the essentials. For anything else, open a support ticket from your client area.</p><a id="faqClientArea" href="/client-area" class="button button-ghost">Contact support</a></div><div class="faq-list"><details open><summary>How are prices calculated?<span>+</span></summary><p>Plans are based on the current upstream monthly infrastructure cost, include Quizontal Cloud’s configured margin, and are converted to LKR using the imported exchange rate.</p></details><details><summary>How do I pay for a VPS?<span>+</span></summary><p>Add funds to your Quizontal Cloud wallet using manual bank transfer. Once your payment slip is approved, wallet credit can be used for product and renewal invoices.</p></details><details><summary>Can I choose the operating system and location?<span>+</span></summary><p>Yes. Supported plans provide a clean configuration step with available locations and compatible Linux or Windows operating systems.</p></details><details><summary>Where can I manage my account?<span>+</span></summary><p>The client area contains your wallet, invoices, services, email history and support tickets.</p></details></div></div></section>

  <section class="container final-cta"><div><span class="section-kicker">Ready when you are</span><h2>Launch your next project on Quizontal Cloud.</h2><p>Choose a transparent monthly plan and manage everything from one simple account.</p></div><div><a href="#plans" class="button button-primary button-large">Explore plans</a><a id="ctaClientArea" href="/client-area" class="button button-ghost button-large">Client area</a></div></section>
</main>
<footer class="site-footer"><div class="container footer-grid"><div><img src="https://res.cloudinary.com/dt1sdefd6/image/upload/v1786291141/quizontal-cloud-logo_wa4agd.png" alt="Quizontal Cloud"><p>Simple cloud infrastructure with transparent LKR pricing.</p></div><div><strong>Platform</strong><a href="#plans">VPS plans</a><a href="/domains">Domains</a><a href="#features">Features</a><a href="#locations">Locations</a></div><div><strong>Account</strong><a id="footerClient" href="/client-area">Client area</a><a href="#faq">FAQ</a><a href="#how-it-works">How it works</a></div><div class="footer-meta"><span>© <b id="year"></b> Quizontal Cloud</span><span class="footer-status"><i></i> Systems operational</span></div></div></footer>
<script src="/app.js?v={{ filemtime(public_path('app.js')) }}" defer></script>
</body>
</html>
