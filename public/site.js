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
    }, { threshold: 0, rootMargin: '0px 0px -60px 0px' });
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
    initAnimatedText();
    initPremiumButtons();
    QC.loadConfig().then(applyClientLinks);
  });

  /* ---------- Word-by-word animated hero text ---------- */
  function initAnimatedText() {
    document.querySelectorAll('[data-animated-text]').forEach(function (el) {
      var words = el.getAttribute('data-animated-text').split(' ');
      var lastWord = el.getAttribute('data-last-word') || '';
      var baseDelay = parseInt(el.getAttribute('data-delay') || '0', 10);
      var wordDelay = el.classList.contains('qc-hero-tagline') ? 20 : 75;
      el.textContent = '';
      var html = '';
      words.forEach(function (w, i) {
        html += '<span class="mz-word" style="animation-delay:' + (baseDelay + i * wordDelay) + 'ms;">' + w + '</span>';
      });
      if (lastWord) {
        html += '<span class="mz-word gradient" style="animation-delay:' + (baseDelay + words.length * wordDelay) + 'ms;"><span>' + lastWord + '</span></span>';
      }
      el.innerHTML = html;
    });
  }

  /* ---------- Premium buttons (white -> rose letter-swap) ---------- */
  function initPremiumButtons() {
    const arrow = '<svg viewBox="0 0 24 24" fill="none"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    document.querySelectorAll('.premium-btn').forEach(btn => {
      const label = btn.textContent.replace(/\s+/g, ' ').trim();
      btn.textContent = '';
      const layer1 = document.createElement('span');
      const layer2 = document.createElement('span');
      layer1.className = 'span-mother';
      layer2.className = 'span-mother2';
      for (let i = 0; i < label.length; i++) {
        const ch = label[i];
        const s1 = document.createElement('span');
        const s2 = document.createElement('span');
        s1.textContent = ch === ' ' ? '\u00A0' : ch;
        s2.textContent = ch === ' ' ? '\u00A0' : ch;
        s1.style.transitionDelay = (i * 0.05) + 's';
        s2.style.transitionDelay = (i * 0.05) + 's';
        layer1.appendChild(s1);
        layer2.appendChild(s2);
      }
      const arrowEl = document.createElement('span');
      arrowEl.className = 'premium-arrow';
      arrowEl.setAttribute('aria-hidden', 'true');
      arrowEl.innerHTML = arrow;
      btn.appendChild(layer1);
      btn.appendChild(layer2);
      btn.appendChild(arrowEl);
    });
    document.querySelectorAll('.premium-btn-static').forEach(btn => {
      const arrowEl = document.createElement('span');
      arrowEl.className = 'premium-arrow';
      arrowEl.setAttribute('aria-hidden', 'true');
      arrowEl.innerHTML = arrow;
      btn.appendChild(arrowEl);
    });
  }
})();
