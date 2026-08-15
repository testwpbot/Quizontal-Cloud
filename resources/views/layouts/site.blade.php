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
      <div class="drawer-head"><img src="https://res.cloudinary.com/dt1sdefd6/image/upload/v1786643376/ChatGPT_Image_Aug_13_2026_11_17_40_PM-remove-bg-io_y5zxiu.png" alt="Quizontal Cloud"><button type="button" class="drawer-close" aria-label="Close menu">✕</button></div>
      <span class="drawer-label">Menu</span>
      <a href="{{ route('home') }}" @if(request()->routeIs('home')) class="nav-active" @endif><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9.5 21v-6h5v6"/></svg></span>Home</a>
      <a href="{{ route('hosting') }}" @if(request()->routeIs('hosting')) class="nav-active" @endif><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="7" rx="2"/><rect x="4" y="13" width="16" height="7" rx="2"/><path d="M7.5 7.5h.01M7.5 16.5h.01"/></svg></span>Web Hosting</a>
      <a href="{{ route('vps') }}" @if(request()->routeIs('vps')) class="nav-active" @endif><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 18a5 5 0 1 1 .9-9.9A6 6 0 0 1 19 13.5a4 4 0 0 1 0 8H7z"/></svg></span>Cloud VPS</a>
      <a href="{{ route('domains') }}" @if(request()->routeIs('domains')) class="nav-active" @endif><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18z"/></svg></span>Domains</a>
      <a href="{{ route('pricing') }}" @if(request()->routeIs('pricing')) class="nav-active" @endif><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-7 7-9-9z"/><circle cx="7.5" cy="7.5" r="1.2"/></svg></span>Pricing</a>
      <div class="mobile-account">
        <span class="ma-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/></svg></span>
        <h6>Client area</h6>
        <p>Manage domains, hosting & servers — all in one place.</p>
        <a id="mobileClientArea" data-client-link href="{{ route('client-area') }}" class="button button-primary">Open client area</a>
      </div>
      <div class="drawer-footer">Quizontal Cloud · {{ date('Y') }}</div>
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
</body>
</html>
