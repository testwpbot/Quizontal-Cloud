/* Quizontal Cloud — shared storefront chrome (header, drawer, reveals, config) */
(function () {
  const $ = s => document.querySelector(s);

  /* ---------- Shared config (one fetch per page, reused by page engines) ---------- */
  window.QC = window.QC || {};
  QC.config = { clientAreaUrl: '/client-area', orderUrl: '' };
  QC.loadConfig = function () {
    if (!QC._cfg) {
      QC._cfg = fetch('/api/config')
        .then(r => (r.ok ? r.json() : {}))
        .then(cfg => Object.assign(QC.config, cfg))
        .catch(() => QC.config);
    }
    return QC._cfg;
  };
  QC.money = value => `Rs. ${new Intl.NumberFormat('en-LK', { maximumFractionDigits: 0 }).format(Number(value) || 0)}`;
  QC.escape = value => String(value ?? '').replace(/[&<>'"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[c]);

  function applyClientLinks(cfg) {
    const url = (cfg && cfg.clientAreaUrl) || '/client-area';
    document.querySelectorAll('[data-client-link]').forEach(a => { a.href = url; });
    ['#clientArea', '#mobileClientArea', '#heroClientArea', '#featureClientArea', '#faqClientArea', '#ctaClientArea', '#footerClient']
      .forEach(sel => { const el = $(sel); if (el) el.href = url; });
  }

  /* ---------- Mobile drawer ---------- */
  function menuBackdropElement() {
    let backdrop = $('#menuBackdrop');
    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.id = 'menuBackdrop';
      backdrop.className = 'menu-backdrop';
      backdrop.setAttribute('aria-hidden', 'true');
      document.body.appendChild(backdrop);
    }
    if (!backdrop.dataset.bound) {
      backdrop.dataset.bound = '1';
      backdrop.addEventListener('click', () => setMenu(false));
    }
    return backdrop;
  }
  function placeMenuForViewport() {
    const menu = $('#navLinks');
    const shell = document.querySelector('.nav-shell');
    if (!menu || !shell) return;
    if (window.innerWidth <= 700) {
      if (menu.parentElement !== document.body) document.body.appendChild(menu);
    } else if (menu.parentElement === document.body) {
      shell.insertBefore(menu, shell.querySelector('.nav-ctas'));
    }
  }
  let menuCloseTimer = null;
  function setMenu(open) {
    const menu = $('#navLinks');
    const button = $('#mobileMenuBtn');
    if (!menu || !button) return;
    clearTimeout(menuCloseTimer);
    if (open) {
      if (getComputedStyle(menu).display === 'none') menu.style.display = 'flex';
      void menu.offsetWidth; // reflow so the slide-in transition runs
      menu.classList.add('open');
    } else {
      menu.classList.remove('open');
      menu.style.display = 'flex';
      menuCloseTimer = setTimeout(() => { if (!menu.classList.contains('open')) menu.style.display = ''; }, 360);
    }
    menuBackdropElement().classList.toggle('show', open);
    document.body.classList.toggle('menu-open', open);
    document.documentElement.classList.toggle('menu-open', open);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    button.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
  }

  /* ---------- Scroll reveals ---------- */
  function initReveals() {
    const items = document.querySelectorAll('.reveal');
    if (!items.length) return;
    if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      items.forEach(el => el.classList.add('in'));
      return;
    }
    const io = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('in');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    items.forEach(el => {
      const delay = el.getAttribute('data-reveal-delay');
      if (delay) el.style.transitionDelay = `${Number(delay) || 0}ms`;
      io.observe(el);
    });
  }
  // Expose so JS-rendered content (plan cards etc.) can animate too.
  QC.refreshReveals = initReveals;

  /* ---------- Sticky header state ---------- */
  function initHeader() {
    const header = document.querySelector('.site-header');
    if (!header) return;
    const onScroll = () => header.classList.toggle('scrolled', window.scrollY > 8);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ---------- Boot ---------- */
  document.addEventListener('DOMContentLoaded', () => {
    const btn = $('#mobileMenuBtn');
    if (btn) btn.addEventListener('click', () => setMenu(!$('#navLinks').classList.contains('open')));
    document.querySelectorAll('.drawer-close').forEach(b => b.addEventListener('click', () => setMenu(false)));
    window.addEventListener('keydown', e => { if (e.key === 'Escape') setMenu(false); });
    window.addEventListener('resize', () => { if (window.innerWidth > 700) setMenu(false); placeMenuForViewport(); });
    const links = $('#navLinks');
    if (links) links.querySelectorAll('a').forEach(a => a.addEventListener('click', () => setMenu(false)));
    document.addEventListener('touchmove', e => {
      if (document.body.classList.contains('menu-open') && !e.target.closest('.nav-menu')) e.preventDefault();
    }, { passive: false });

    const year = $('#year');
    if (year) year.textContent = new Date().getFullYear();
    document.scrollingElement.scrollLeft = 0;

    placeMenuForViewport();
    initHeader();
    initReveals();
    QC.loadConfig().then(applyClientLinks);
  });
})();
