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
    initDemoTabs();
    initDemoCounters();
    initDemoChart();
    initDemoToolbar();
    initPremiumButtons();
    QC.loadConfig().then(applyClientLinks);
  });

  /* ---------- Dashboard preview tabs ---------- */
  function initDemoTabs() {
    const tabs = document.querySelectorAll('.qc-demo-tab');
    const panels = document.querySelectorAll('.qc-demo-panel');
    if (!tabs.length || !panels.length) return;
    tabs.forEach(tab => {
      tab.addEventListener('click', () => {
        tabs.forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        panels.forEach(p => p.classList.toggle('active', p.getAttribute('data-qc-panel') === tab.getAttribute('data-qc-tab')));
      });
    });
  }

  /* ---------- Animated count-up (dashboard preview stats) ---------- */
  function initDemoCounters() {
    document.querySelectorAll('.qc-count[data-count]').forEach(el => {
      const target = parseFloat(el.dataset.count) || 0;
      const dur = 1200; let start = null;
      function step(ts) {
        if (!start) start = ts;
        const p = Math.min((ts - start) / dur, 1);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = Math.round(target * eased).toLocaleString('en-US');
        if (p < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    });
  }

  /* ---------- Spending line chart (SVG) ---------- */
  function initDemoChart() {
    const svg = document.getElementById('qcSpendChart');
    if (!svg) return;
    const W = 560, H = 220, padL = 8, padR = 8, padT = 14, padB = 24;
    const labels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const payments = [1800, 2100, 1600, 2600, 2300, 3100, 2800, 3600, 3200, 4100, 3700, 4500];
    const events = [2, 3, 1, 4, 3, 4, 2, 5, 3, 5, 4, 6];
    const max = 5000;
    const pink = '#f06b9d', cyan = '#22d3ee';

    function render(from) {
      const p = payments.slice(from);
      const e = events.slice(from);
      const l = labels.slice(from);
      const step = (W - padL - padR) / Math.max(l.length - 1, 1);
      const y = v => H - padB - (v / max) * (H - padT - padB);

      function line(data, color) {
        const pts = data.map((v, i) => [padL + i * step, y(v)]);
        const d = 'M' + pts.map(p => p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' L');
        const dots = pts.map(pt => '<circle cx="' + pt[0].toFixed(1) + '" cy="' + pt[1].toFixed(1) + '" r="3.5" fill="' + color + '" stroke="#0b0b0e" stroke-width="2"/>').join('');
        return '<path d="' + d + '" fill="none" stroke="' + color + '" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="filter:drop-shadow(0 3px 6px ' + color + '66)"/>' + dots;
      }

      let grid = '';
      for (let g = 0; g <= 4; g++) {
        const gy = padT + (g * (H - padT - padB)) / 4;
        grid += '<line x1="' + padL + '" y1="' + gy.toFixed(1) + '" x2="' + (W - padR) + '" y2="' + gy.toFixed(1) + '" stroke="rgba(255,255,255,.06)" stroke-width="1" stroke-dasharray="3 3"/>';
      }
      let xl = '';
      l.forEach((lab, i) => {
        xl += '<text x="' + (padL + i * step).toFixed(1) + '" y="' + (H - 8) + '" text-anchor="middle" font-size="9" fill="#7d8494">' + lab + '</text>';
      });
      svg.innerHTML = grid + line(p, pink) + line(e, cyan) + xl;
    }

    render(0);
    document.querySelectorAll('.qc-chart-tabs [data-qc-range]').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.qc-chart-tabs [data-qc-range]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        render(btn.dataset.qcRange === '6' ? 6 : 0);
      });
    });
  }

  /* ---------- Demo toolbar: multiselect + table filter ---------- */
  function initDemoToolbar() {
    // category multiselect
    const trigger = document.getElementById('qcCategoryTrigger');
    const menu = document.getElementById('qcCategoryMenu');
    const label = document.getElementById('qcCategoryLabel');
    if (trigger && menu) {
      const names = { vps: 'Cloud VPS', hosting: 'Web Hosting', domains: 'Domains' };
      const update = () => {
        const checked = [...menu.querySelectorAll('input:checked')].map(i => names[i.value]);
        label.textContent = checked.length ? checked.join(', ') : 'All services';
      };
      trigger.addEventListener('click', e => {
        e.stopPropagation();
        const open = menu.classList.toggle('open');
        trigger.classList.toggle('open', open);
      });
      menu.addEventListener('click', e => { if (e.target.closest('.qc-msel-option')) update(); });
      document.addEventListener('click', () => { menu.classList.remove('open'); trigger.classList.remove('open'); });
      update();
    }

    // service table search
    const search = document.getElementById('qcTableSearch');
    const table = document.getElementById('qcDemoTable');
    if (search && table) {
      const rows = [...table.querySelectorAll('tbody tr')];
      const count = document.getElementById('qcTableCount');
      search.addEventListener('input', () => {
        const q = search.value.trim().toLowerCase();
        let n = 0;
        rows.forEach(r => {
          const show = !q || (r.getAttribute('data-qc-search') || '').indexOf(q) !== -1;
          r.style.display = show ? '' : 'none';
          if (show) n++;
        });
        if (count) count.textContent = n + ' row' + (n === 1 ? '' : 's');
      });
    }

    // period select (visual only)
    const period = document.getElementById('qcPeriodSelect');
    if (period) period.addEventListener('change', () => {});
  }

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
