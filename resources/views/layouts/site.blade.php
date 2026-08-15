<!doctype html>
<html lang="en" data-theme="light">
<head>
  @php
    // Canonical / OG URL: absolute, scheme-correct, and free of query strings,
    // tracking params and trailing slashes so Google and social scrapers always
    // resolve the single authoritative URL for each page.
    $seoBase = rtrim((string) config('app.url'), '/');
    $seoPath = trim((string) request()->path(), '/');
    $seoUrl = $seoBase . ($seoPath === '' ? '/' : '/' . $seoPath);

    $seoTitle = trim((string) $__env->yieldContent('title', 'Quizontal Cloud — Domains, Hosting & Cloud VPS'));
    $seoDescription = trim((string) $__env->yieldContent('meta_description', 'Register domains, launch fast NVMe web hosting and deploy cloud VPS — all priced in Sri Lankan Rupees with one wallet and one dashboard.'));
    $seoOgTitle = trim((string) $__env->yieldContent('og_title', $seoTitle));
    $seoOgDescription = trim((string) $__env->yieldContent('og_description', $seoDescription));
    $seoOgType = trim((string) $__env->yieldContent('og_type', 'website'));
    $seoImage = trim((string) $__env->yieldContent('og_image', asset('images/og-cover.jpg')));
    $seoImageAlt = trim((string) $__env->yieldContent('og_image_alt', 'Quizontal Cloud — domains, web hosting and cloud VPS'));
  @endphp
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  {{-- Primary / basic --}}
  <title>{{ $seoTitle }}</title>
  <meta name="description" content="{{ $seoDescription }}">
  <link rel="canonical" href="{{ $seoUrl }}">
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
  <meta name="author" content="Quizontal Cloud">
  <meta name="theme-color" content="#050508">

  {{-- Open Graph (Facebook, LinkedIn, WhatsApp, Slack, etc.) --}}
  <meta property="og:type" content="{{ $seoOgType }}">
  <meta property="og:site_name" content="Quizontal Cloud">
  <meta property="og:locale" content="en_US">
  <meta property="og:title" content="{{ $seoOgTitle }}">
  <meta property="og:description" content="{{ $seoOgDescription }}">
  <meta property="og:url" content="{{ $seoUrl }}">
  <meta property="og:image" content="{{ $seoImage }}">
  <meta property="og:image:secure_url" content="{{ $seoImage }}">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="{{ $seoImageAlt }}">
  <meta property="og:image:type" content="image/jpeg">

  {{-- Twitter / X card --}}
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="{{ $seoOgTitle }}">
  <meta name="twitter:description" content="{{ $seoOgDescription }}">
  <meta name="twitter:image" content="{{ $seoImage }}">
  <meta name="twitter:image:alt" content="{{ $seoImageAlt }}">
  <meta name="twitter:url" content="{{ $seoUrl }}">

  <link rel="icon" type="image/png" href="https://res.cloudinary.com/dt1sdefd6/image/upload/v1786782955/favicon-quizontal_ps696w.png">
  <link rel="apple-touch-icon" href="https://res.cloudinary.com/dt1sdefd6/image/upload/v1786782955/favicon-quizontal_ps696w.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/styles.css?v={{ filemtime(public_path('styles.css')) }}">
  <link rel="stylesheet" href="/modern.css?v={{ filemtime(public_path('modern.css')) }}">
  <link rel="stylesheet" href="/premium.css?v={{ filemtime(public_path('premium.css')) }}">
  @stack('page-styles')
  {{-- Definitive navigation styles, inlined last so they always win over the
     legacy rules in styles/modern/premium.css regardless of load order. --}}
  <style>
  @media (min-width: 701px) {
    .mobile-toggle { display: none !important; }
    .nav-menu { display: flex !important; flex-direction: row !important; align-items: center !important; justify-content: center !important; gap: 4px !important; }
    .nav-menu .drawer-body { display: flex !important; flex-direction: row !important; align-items: center !important; gap: 4px !important; min-width: 0 !important; flex: 0 1 auto !important; padding: 0 !important; margin: 0 !important; overflow: visible !important; }
    .nav-menu .drawer-head, .nav-menu .drawer-label, .nav-menu .drawer-footer, .nav-menu .mobile-account, .nav-menu .nav-icon { display: none !important; }
    .nav-menu .drawer-body > a { position: relative !important; display: inline-flex !important; align-items: center !important; gap: 6px !important; margin: 0 !important; padding: 8px 14px !important; border: 1px solid transparent !important; border-radius: 10px !important; font-size: 0.86rem !important; font-weight: 600 !important; line-height: 1.4 !important; color: var(--muted) !important; background: transparent !important; text-decoration: none !important; white-space: nowrap !important; }
    .nav-menu .drawer-body > a::after { content: none !important; display: none !important; }
    .nav-menu .drawer-body > a:hover { background: var(--surface-2) !important; color: var(--text) !important; }
    .nav-menu .drawer-body > a.nav-active { background: var(--pink-soft) !important; color: var(--pink-2) !important; }
  }
  @media (max-width: 700px) {
    .mobile-toggle { display: block !important; margin-left: auto !important; }
    .nav-menu { position: fixed !important; top: 0 !important; right: 0 !important; bottom: 0 !important; left: auto !important; width: min(286px, 85vw) !important; height: 100vh !important; height: 100dvh !important; margin: 0 !important; padding: 0 !important; display: flex !important; flex-direction: column !important; align-items: stretch !important; gap: 0 !important; z-index: 72 !important; background: radial-gradient(120% 60% at 100% 0%, rgba(227, 28, 100, 0.10), transparent 55%), linear-gradient(180deg, rgba(17, 17, 23, 0.98), rgba(7, 7, 11, 0.99)) !important; border: 0 !important; border-left: 1px solid rgba(255, 255, 255, 0.08) !important; border-radius: 0 !important; box-shadow: -24px 0 60px rgba(0, 0, 0, 0.6) !important; transform: translateX(100%) !important; transition: transform 0.38s cubic-bezier(0.32, 0.72, 0.28, 1) !important; overflow: hidden !important; will-change: transform !important; }
    .nav-menu.open { transform: none !important; }
    .nav-menu .drawer-head { display: flex !important; flex: 0 0 auto !important; align-items: center !important; justify-content: space-between !important; gap: 12px !important; padding: 18px 18px 14px !important; border-bottom: 1px solid rgba(255, 255, 255, 0.06) !important; }
    .nav-menu .drawer-brand { display: flex !important; flex-direction: column !important; gap: 4px !important; min-width: 0 !important; }
    .nav-menu .drawer-brand img { width: 150px !important; height: 40px !important; object-fit: contain !important; }
    .nav-menu .drawer-close { width: 40px !important; height: 40px !important; flex: none !important; display: grid !important; place-items: center !important; border-radius: 12px !important; border: 1px solid rgba(255, 255, 255, 0.08) !important; background: rgba(255, 255, 255, 0.04) !important; color: #d7dae2 !important; cursor: pointer !important; }
    .nav-menu .drawer-close svg { width: 18px; height: 18px; }
    .nav-menu .drawer-body { display: flex !important; flex-direction: column !important; flex: 1 1 auto !important; overflow-y: auto !important; overscroll-behavior: contain !important; padding: 6px 16px 16px !important; gap: 0 !important; scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.25) transparent; }
    .nav-menu .drawer-body::-webkit-scrollbar { width: 6px; }
    .nav-menu .drawer-body::-webkit-scrollbar-track { background: transparent; }
    .nav-menu .drawer-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.25); border-radius: 999px; }
    .nav-menu .drawer-body::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.4); }
    .nav-menu .drawer-label { display: block !important; padding: 14px 10px 8px !important; font-size: 0.66rem; font-weight: 700; letter-spacing: 0.16em; text-transform: uppercase; color: #5b6270; }
    .nav-menu .drawer-body > a { display: flex !important; flex-direction: row !important; align-items: center !important; gap: 12px !important; margin: 3px 0 !important; padding: 12px !important; border: 1px solid transparent !important; border-radius: 14px !important; background: transparent !important; color: #c3c8d4 !important; font-size: 0.94rem !important; font-weight: 500 !important; text-decoration: none !important; }
    .nav-menu .drawer-body > a::after { content: "\203A" !important; display: block !important; margin-left: auto !important; color: #4b5160 !important; font-size: 1.15rem !important; line-height: 1 !important; background: none !important; border: 0 !important; position: static !important; height: auto !important; width: auto !important; }
    .nav-menu .drawer-body > a:hover { background: rgba(255, 255, 255, 0.04) !important; color: #fff !important; }
    .nav-menu .drawer-body > a.nav-active { background: linear-gradient(90deg, rgba(227, 28, 100, 0.16), rgba(227, 28, 100, 0.05)) !important; border-color: rgba(227, 28, 100, 0.24) !important; color: #fff !important; }
    .nav-menu .drawer-body > a.nav-active::after { color: var(--pink-2) !important; }
    .nav-menu .drawer-body > a.nav-active .nav-icon { background: var(--pink) !important; color: #fff !important; }
    .nav-menu .nav-icon { display: inline-flex !important; align-items: center !important; justify-content: center !important; width: 38px !important; height: 38px !important; flex: none !important; border-radius: 11px !important; background: rgba(255, 255, 255, 0.05) !important; color: #b8beca !important; border: 1px solid rgba(255, 255, 255, 0.05) !important; }
    .nav-menu .nav-icon svg { width: 19px; height: 19px; }
    .nav-menu .drawer-footer { display: flex !important; flex: 0 0 auto !important; flex-direction: column !important; gap: 8px !important; align-items: stretch !important; padding: 14px 16px 16px !important; border-top: 1px solid rgba(255, 255, 255, 0.06) !important; background: rgba(255, 255, 255, 0.015) !important; }
    .nav-menu .drawer-cta { display: flex !important; width: 100% !important; box-sizing: border-box !important; align-items: center !important; justify-content: center !important; gap: 10px !important; padding: 13px 18px !important; font-weight: 700 !important; color: #ffffff !important; }
    .nav-menu .drawer-cta svg { width: 19px; height: 19px; flex: none; color: #ffffff !important; }
    .nav-menu .drawer-cta-arrow { margin-left: 2px; font-size: 1.05rem; line-height: 1; }
    .nav-menu .drawer-footer .footer-status { display: inline-flex !important; align-items: center; justify-content: center; gap: 7px; font-size: 0.72rem; font-weight: 600; color: #9aa3b2; }
    .nav-menu .drawer-footer .footer-status i { width: 7px; height: 7px; border-radius: 50%; background: var(--green); box-shadow: 0 0 0 3px rgba(32, 201, 151, 0.18); }
    .nav-menu .drawer-footer .footer-copy { font-size: 0.7rem; color: #5b6270; text-align: center; }
  }
  </style>
  <script type="application/ld+json">
    {
      "@@context": "https://schema.org",
      "@@graph": [
        {
          "@@type": "Organization",
          "@@id": "{{ $seoBase }}/#organization",
          "name": "Quizontal Cloud",
          "url": "{{ $seoBase }}/",
          "logo": {
            "@@type": "ImageObject",
            "url": "https://res.cloudinary.com/dt1sdefd6/image/upload/v1786643376/ChatGPT_Image_Aug_13_2026_11_17_40_PM-remove-bg-io_y5zxiu.png"
          }
        },
        {
          "@@type": "WebSite",
          "@@id": "{{ $seoBase }}/#website",
          "url": "{{ $seoBase }}/",
          "name": "Quizontal Cloud",
          "description": "{{ $seoDescription }}",
          "publisher": { "@@id": "{{ $seoBase }}/#organization" },
          "potentialAction": {
            "@@type": "SearchAction",
            "target": {
              "@@type": "EntryPoint",
              "urlTemplate": "{{ $seoBase }}/domains?q={search_term_string}"
            },
            "query-input": "required name=search_term_string"
          }
        }
      ]
    }
  </script>
  @stack('jsonld')
  {{-- Critical loader styles — render the gradient spinner immediately to avoid any unstyled flash. --}}
  <style>
    #loading {
      position: fixed; inset: 0; z-index: 9999;
      background: #050508; display: flex; align-items: center; justify-content: center;
      transition: opacity .45s ease, visibility .45s ease;
    }
    #loading.hide { opacity: 0; visibility: hidden; }
    .loader-box { text-align: center; }
    .loader-spinner {
      background-image: linear-gradient(rgb(186, 66, 255) 35%, rgb(0, 225, 255));
      width: 92px; height: 92px;
      margin: 0 auto; border-radius: 50%;
      filter: blur(1px);
      box-shadow: 0px -5px 20px 0px rgb(186, 66, 255), 0px 5px 20px 0px rgb(0, 225, 255);
      animation: qcSpin 1.7s linear infinite;
      display: flex; align-items: center; justify-content: center;
    }
    .loader-spinner-core {
      background-color: #050508;
      width: 92px; height: 92px; border-radius: 50%;
      filter: blur(10px);
    }
    @keyframes qcSpin { to { transform: rotate(360deg); } }
  </style>
</head>
<body class="@yield('body_class')">
<div id="loading" aria-hidden="true">
  <div class="loader-box">
    <div class="loader-spinner"><div class="loader-spinner-core"></div></div>
  </div>
</div>
<div class="site-glow glow-one"></div><div class="site-glow glow-two"></div>
<header class="site-header">
  <nav class="container nav-shell">
    <a href="{{ route('home') }}" class="brand" aria-label="Quizontal Cloud home"><img src="https://res.cloudinary.com/dt1sdefd6/image/upload/v1786643376/ChatGPT_Image_Aug_13_2026_11_17_40_PM-remove-bg-io_y5zxiu.png" alt="Quizontal Cloud"></a>
    <button class="mobile-toggle" id="mobileMenuBtn" aria-label="Open menu" aria-expanded="false"><span></span><span></span><span></span></button>
    <div class="nav-menu" id="navLinks">
      <div class="drawer-head">
        <div class="drawer-brand">
          <img src="https://res.cloudinary.com/dt1sdefd6/image/upload/v1786643376/ChatGPT_Image_Aug_13_2026_11_17_40_PM-remove-bg-io_y5zxiu.png" alt="Quizontal Cloud">
        </div>
        <button type="button" class="drawer-close" aria-label="Close menu">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="drawer-body">
        <span class="drawer-label">Menu</span>
        <a href="{{ route('home') }}" @if(request()->routeIs('home')) class="nav-active" @endif><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9.5 21v-6h5v6"/></svg></span><span class="nav-text">Home</span></a>
        <a href="{{ route('hosting') }}" @if(request()->routeIs('hosting')) class="nav-active" @endif><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="7" rx="2"/><rect x="4" y="13" width="16" height="7" rx="2"/><path d="M7.5 7.5h.01M7.5 16.5h.01"/></svg></span><span class="nav-text">Web Hosting</span></a>
        <a href="{{ route('vps') }}" @if(request()->routeIs('vps')) class="nav-active" @endif><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 18a5 5 0 1 1 .9-9.9A6 6 0 0 1 19 13.5a4 4 0 0 1 0 8H7z"/></svg></span><span class="nav-text">Cloud VPS</span></a>
        <a href="{{ route('domains') }}" @if(request()->routeIs('domains')) class="nav-active" @endif><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18z"/></svg></span><span class="nav-text">Domains</span></a>
        <a href="{{ route('pricing') }}" @if(request()->routeIs('pricing')) class="nav-active" @endif><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-7 7-9-9z"/><circle cx="7.5" cy="7.5" r="1.2"/></svg></span><span class="nav-text">Pricing</span></a>
      </div>
      <div class="drawer-footer">
        <a id="mobileClientArea" data-client-link href="{{ route('client-area') }}" class="button button-primary drawer-cta">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/></svg>
          <span>Client area</span>
          <span class="drawer-cta-arrow">→</span>
        </a>
        <span class="footer-status"><i></i> All systems operational</span>
        <span class="footer-copy">Quizontal Cloud · {{ date('Y') }}</span>
      </div>
    </div>
    <div class="nav-ctas">
      <a id="clientArea" data-client-link class="button button-ghost" href="{{ route('client-area') }}">Client area</a>
      <a class="button button-primary desktop-cta" href="{{ route('pricing') }}">Get started</a>
    </div>
  </nav>
</header>
<div class="menu-backdrop" id="menuBackdrop" aria-hidden="true"></div>

<main id="top">
  @yield('content')
</main>

<footer class="site-footer">
  <div class="container footer-grid">
    <div>
      <img src="https://res.cloudinary.com/dt1sdefd6/image/upload/v1786643376/ChatGPT_Image_Aug_13_2026_11_17_40_PM-remove-bg-io_y5zxiu.png" alt="Quizontal Cloud" width="178" height="47">
      <p>Domains, web hosting and cloud servers — priced in rupees, managed from one simple dashboard.</p>
    </div>
    <div>
      <strong>Products</strong>
      <a href="{{ route('hosting') }}">Web hosting</a>
      <a href="{{ route('vps') }}">Cloud VPS</a>
      <a href="{{ route('domains') }}">Domain names</a>
      <a href="{{ route('pricing') }}">All pricing</a>
    </div>
    <div>
      <strong>Account</strong>
      <a id="footerClient" data-client-link href="{{ route('client-area') }}">Client area</a>
      <a href="{{ route('home') }}#how">How it works</a>
      <a href="{{ route('home') }}#faq">FAQ</a>
    </div>
    <div class="footer-meta"><span>© <b id="year">{{ date('Y') }}</b> Quizontal Cloud</span><span class="footer-status"><i></i> Systems operational</span></div>
  </div>
</footer>
<script src="/site.js?v={{ filemtime(public_path('site.js')) }}" defer></script>
@stack('page-scripts')
@include('partials.tawk')
</body>
</html>
