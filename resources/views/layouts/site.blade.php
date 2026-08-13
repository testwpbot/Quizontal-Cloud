<!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="@yield('meta_description', 'Quizontal Cloud — domains, web hosting and cloud VPS with transparent LKR pricing, one wallet and one dashboard.')">
  <meta property="og:type" content="website">
  <meta property="og:title" content="@yield('meta_title', 'Quizontal Cloud — domains, hosting & cloud VPS priced in LKR')">
  <meta property="og:description" content="@yield('meta_description', 'Quizontal Cloud — domains, web hosting and cloud VPS with transparent LKR pricing, one wallet and one dashboard.')">
  <meta property="og:image" content="https://res.cloudinary.com/dt1sdefd6/image/upload/v1786291141/quizontal-cloud-logo_wa4agd.png">
  <link rel="canonical" href="{{ url()->current() }}">
  <title>@yield('title', 'Quizontal Cloud — Domains, Hosting & Cloud VPS')</title>
  <link rel="icon" type="image/png" href="https://res.cloudinary.com/dt1sdefd6/image/upload/v1786291141/quizontal-cloud-logo_wa4agd.png">
  <link rel="apple-touch-icon" href="https://res.cloudinary.com/dt1sdefd6/image/upload/v1786291141/quizontal-cloud-logo_wa4agd.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/styles.css?v={{ filemtime(public_path('styles.css')) }}">
  <link rel="stylesheet" href="/modern.css?v={{ filemtime(public_path('modern.css')) }}">
  @stack('page-styles')
  <script type="application/ld+json">
    {
      "@@context": "https://schema.org",
      "@@type": "Organization",
      "name": "Quizontal Cloud",
      "url": "{{ url('/') }}",
      "logo": "https://res.cloudinary.com/dt1sdefd6/image/upload/v1786291141/quizontal-cloud-logo_wa4agd.png"
    }
  </script>
  @stack('jsonld')
</head>
<body>
<div class="site-glow glow-one"></div><div class="site-glow glow-two"></div>
<header class="site-header">
  <nav class="container nav-shell">
    <a href="{{ route('home') }}" class="brand" aria-label="Quizontal Cloud home"><img src="https://res.cloudinary.com/dt1sdefd6/image/upload/v1786291141/quizontal-cloud-logo_wa4agd.png" alt="Quizontal Cloud"></a>
    <button class="mobile-toggle" id="mobileMenuBtn" aria-label="Open menu" aria-expanded="false"><span></span><span></span><span></span></button>
    <div class="nav-menu" id="navLinks">
      <div class="drawer-head"><img src="https://res.cloudinary.com/dt1sdefd6/image/upload/v1786291141/quizontal-cloud-logo_wa4agd.png" alt="Quizontal Cloud"><button type="button" class="drawer-close" aria-label="Close menu">✕</button></div>
      <span class="drawer-label">Menu</span>
      <a href="{{ route('home') }}" @if(request()->routeIs('home')) class="nav-active" @endif><span class="nav-icon">⌂</span>Home</a>
      <a href="{{ route('hosting') }}" @if(request()->routeIs('hosting')) class="nav-active" @endif><span class="nav-icon">⚡</span>Web Hosting</a>
      <a href="{{ route('vps') }}" @if(request()->routeIs('vps')) class="nav-active" @endif><span class="nav-icon">▦</span>Cloud VPS</a>
      <a href="{{ route('domains') }}" @if(request()->routeIs('domains')) class="nav-active" @endif><span class="nav-icon">◎</span>Domains</a>
      <a href="{{ route('pricing') }}" @if(request()->routeIs('pricing')) class="nav-active" @endif><span class="nav-icon">₨</span>Pricing</a>
      <div class="mobile-account"><small>Already have an account?</small><a id="mobileClientArea" data-client-link href="{{ route('client-area') }}" class="button button-primary">Open client area</a></div>
    </div>
    <div class="nav-ctas">
      <a id="clientArea" data-client-link class="button button-ghost" href="{{ route('client-area') }}">Client area</a>
      <a class="button button-primary desktop-cta" href="{{ route('pricing') }}">View pricing</a>
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
      <img src="https://res.cloudinary.com/dt1sdefd6/image/upload/v1786291141/quizontal-cloud-logo_wa4agd.png" alt="Quizontal Cloud" width="178" height="47">
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
