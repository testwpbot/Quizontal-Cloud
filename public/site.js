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
    initDemoPresets();
    initDemoChart();
    initDemoTable();
    initDemoTarget();
    initDemoToolbar();
    initDemoProduct();
    initDemoAuth();
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
      const prefix = el.dataset.prefix || '';
      const suffix = el.dataset.suffix || '';
      const dur = 1200; let start = null;
      function step(ts) {
        if (!start) start = ts;
        const p = Math.min((ts - start) / dur, 1);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = prefix + Math.round(target * eased).toLocaleString('en-US') + suffix;
        if (p < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    });
  }

  /* ---------- Toast (demo interactions) ---------- */
  function qcToast(msg, type) {
    let wrap = document.querySelector('.qc-toast-wrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'qc-toast-wrap';
      document.body.appendChild(wrap);
    }
    const icons = {
      success: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm-1.2 14.4-3.6-3.6 1.4-1.4 2.2 2.2 5-5 1.4 1.4-6.4 6.4Z"/></svg>',
      info: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm1 15h-2v-6h2v6Zm0-8h-2V7h2v2Z"/></svg>',
      error: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm1 14.5h-2v-2h2v2Zm0-4.5h-2v-6h2v6Z"/></svg>'
    };
    const el = document.createElement('div');
    el.className = 'qc-toast ' + (type || 'info');
    el.innerHTML = (icons[type] || icons.info) + '<span>' + msg + '</span>';
    wrap.appendChild(el);
    setTimeout(() => { el.classList.add('hide'); setTimeout(() => el.remove(), 320); }, 3200);
  }

  /* ---------- Accent presets (Quizontal / Cyan / Violet / Emerald) ---------- */
  function initDemoPresets() {
    const root = document.querySelector('.qc-demo');
    const pills = document.querySelectorAll('.qc-preset-pill');
    if (!root || !pills.length) return;
    pills.forEach(pill => {
      pill.addEventListener('click', () => {
        pills.forEach(p => p.classList.remove('active'));
        pill.classList.add('active');
        root.setAttribute('data-qc-preset', pill.getAttribute('data-qc-preset'));
        drawRevenueChart();
      });
    });
  }

  /* ---------- Revenue chart (SVG, accent-aware) ---------- */
  let demoAccent = '#e31c64';
  function drawRevenueChart() {
    const svg = document.getElementById('qcSpendChart');
    if (!svg) return;
    const root = document.querySelector('.qc-demo');
    if (root) {
      const c = getComputedStyle(root).getPropertyValue('--qc-accent').trim();
      if (c) demoAccent = c;
    }
    const legend = document.getElementById('qcLegendPrimary');
    if (legend) legend.style.background = demoAccent;
    const W = 560, H = 220, padL = 10, padR = 10, padT = 14, padB = 24;
    const labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const revenue = [21000, 28500, 19600, 34200, 29800, 40500, 38600];
    const services = [2, 3, 1, 4, 3, 5, 4];
    const max = 45000;
    const step = (W - padL - padR) / (labels.length - 1);
    const y = v => H - padB - (v / max) * (H - padT - padB);
    function line(data, color) {
      const pts = data.map((v, i) => [padL + i * step, y(v)]);
      const d = 'M' + pts.map(p => p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' L');
      const dots = pts.map(pt => '<circle cx="' + pt[0].toFixed(1) + '" cy="' + pt[1].toFixed(1) + '" r="3.5" fill="' + color + '" stroke="#0b0b0e" stroke-width="2"/>').join('');
      return '<path d="' + d + '" fill="none" stroke="' + color + '" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="filter:drop-shadow(0 3px 6px ' + color + '66)"/>' + dots;
    }
    let grid = '';
    for (let g = 0; g <= 3; g++) {
      const gy = padT + (g * (H - padT - padB)) / 3;
      grid += '<line x1="' + padL + '" y1="' + gy.toFixed(1) + '" x2="' + (W - padR) + '" y2="' + gy.toFixed(1) + '" stroke="rgba(255,255,255,.06)" stroke-width="1" stroke-dasharray="3 3"/>';
    }
    let xl = '';
    labels.forEach((lab, i) => {
      xl += '<text x="' + (padL + i * step).toFixed(1) + '" y="' + (H - 8) + '" text-anchor="middle" font-size="9" fill="#7d8494">' + lab + '</text>';
    });
    svg.innerHTML = grid + line(revenue, demoAccent) + line(services, '#22d3ee') + xl;
  }
  function initDemoChart() { drawRevenueChart(); }

  /* ---------- Invoices table (sort, search, paginate, select) ---------- */
  function initDemoTable() {
    const table = document.getElementById('qcInvoiceTable');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const search = document.getElementById('qcInvoiceSearch');
    const count = document.getElementById('qcInvoiceCount');
    const badge = document.getElementById('qcSelBadge');
    const checkAll = document.getElementById('qcCheckAll');
    const info = document.querySelector('.qc-pagination .qc-page-info');
    const all = [
      { id: '#10008', customer: 'Dinuka Perera', service: 'Cloud VPS — KVM 2', amount: 2850, status: 'Paid', date: '2026-08-03' },
      { id: '#10007', customer: 'Nadeesha Silva', service: 'Business Hosting', amount: 999, status: 'Paid', date: '2026-08-01' },
      { id: '#10006', customer: 'Kasun Jayawardena', service: 'Domain example.lk', amount: 2950, status: 'Pending', date: '2026-07-28' },
      { id: '#10005', customer: 'Tharindu Fernando', service: 'Cloud VPS — KVM 4', amount: 5700, status: 'Paid', date: '2026-07-21' },
      { id: '#10004', customer: 'Ishara Wickramasinghe', service: 'Starter Hosting', amount: 499, status: 'Paid', date: '2026-07-19' },
      { id: '#10003', customer: 'Chamara Bandara', service: 'Domain store.lk', amount: 3200, status: 'Processing', date: '2026-07-14' },
      { id: '#10002', customer: 'Ravindu Herath', service: 'Storage VPS — SATA', amount: 3400, status: 'Paid', date: '2026-07-09' },
      { id: '#10001', customer: 'Malith Dissanayake', service: 'Business Hosting', amount: 999, status: 'Paid', date: '2026-07-03' },
      { id: '#10000', customer: 'Nimal Perera', service: 'Domain shop.lk', amount: 2950, status: 'Paid', date: '2026-06-28' },
      { id: '#9999', customer: 'Dilshan Rajapaksha', service: 'Cloud VPS — KVM 1', amount: 1450, status: 'Paid', date: '2026-06-21' }
    ];
    const statusClass = { Paid: 'qc-demo-badge-green', Pending: 'qc-demo-badge-amber', Processing: 'qc-demo-badge-cyan' };
    let sortKey = 'id', sortAsc = true, page = 1;
    const perPage = 5;
    const selected = {};

    function render() {
      const q = search.value.trim().toLowerCase();
      let rows = all.filter(o => !q || o.customer.toLowerCase().indexOf(q) !== -1 || o.service.toLowerCase().indexOf(q) !== -1 || o.status.toLowerCase().indexOf(q) !== -1);
      rows.sort((a, b) => {
        let va = a[sortKey], vb = b[sortKey];
        if (typeof va === 'string') { va = va.toLowerCase(); vb = vb.toLowerCase(); }
        return (va > vb ? 1 : va < vb ? -1 : 0) * (sortAsc ? 1 : -1);
      });
      count.textContent = rows.length + ' row' + (rows.length === 1 ? '' : 's');
      const pages = Math.max(1, Math.ceil(rows.length / perPage));
      page = Math.min(page, pages);
      const slice = rows.slice((page - 1) * perPage, (page - 1) * perPage + perPage);
      tbody.innerHTML = slice.map(o => {
        return '<tr class="' + (selected[o.id] ? 'qc-selected' : '') + '">' +
          '<td><input type="checkbox" class="qc-table-check qc-row-check" data-id="' + o.id + '"' + (selected[o.id] ? ' checked' : '') + '></td>' +
          '<td><strong>' + o.id + '</strong></td><td>' + o.customer + '</td><td>' + o.service + '</td>' +
          '<td><strong>LKR ' + o.amount.toLocaleString('en-US') + '</strong></td>' +
          '<td><span class="qc-demo-badge ' + statusClass[o.status] + '">' + o.status + '</span></td>' +
          '<td>' + o.date + '</td></tr>';
      }).join('');
      document.querySelectorAll('.qc-page-btn').forEach(b => {
        const p = b.getAttribute('data-qc-page');
        if (p === 'prev' || p === 'next') {
          b.disabled = (p === 'prev' && page === 1) || (p === 'next' && page === pages);
        } else {
          b.classList.toggle('active', page === parseInt(p));
          b.style.display = pages < parseInt(p) ? 'none' : '';
        }
      });
      info.textContent = 'Page ' + page + ' of ' + pages;
      const n = Object.keys(selected).length;
      badge.textContent = n + ' selected';
      badge.style.display = n ? '' : 'none';
      checkAll.checked = tbody.querySelectorAll('.qc-row-check').length > 0 && tbody.querySelectorAll('.qc-row-check').length === tbody.querySelectorAll('.qc-row-check:checked').length;
    }

    tbody.addEventListener('change', e => {
      if (!e.target.classList.contains('qc-row-check')) return;
      const id = e.target.getAttribute('data-id');
      if (e.target.checked) selected[id] = true; else delete selected[id];
      render();
    });
    checkAll.addEventListener('change', e => {
      tbody.querySelectorAll('.qc-row-check').forEach(c => {
        if (e.target.checked) selected[c.getAttribute('data-id')] = true; else delete selected[c.getAttribute('data-id')];
      });
      render();
    });
    search.addEventListener('input', () => { page = 1; render(); });
    document.querySelectorAll('.qc-page-btn').forEach(b => {
      b.addEventListener('click', () => {
        const p = b.getAttribute('data-qc-page');
        if (p === 'prev') page--; else if (p === 'next') page++; else page = parseInt(p);
        render();
      });
    });
    table.querySelectorAll('th[data-key]').forEach(th => {
      th.addEventListener('click', () => {
        const k = th.getAttribute('data-key');
        if (sortKey === k) sortAsc = !sortAsc; else { sortKey = k; sortAsc = true; }
        table.querySelectorAll('th .qc-sort-ind').forEach(s => s.textContent = '');
        th.querySelector('.qc-sort-ind').textContent = sortAsc ? '\u25B4' : '\u25BE';
        render();
      });
    });
    render();
  }

  /* ---------- Revenue target slider ---------- */
  function initDemoTarget() {
    const slider = document.getElementById('qcTargetSlider');
    if (!slider) return;
    slider.addEventListener('input', () => {
      const v = parseInt(slider.value);
      document.getElementById('qcTargetPercent').textContent = v + '%';
      document.getElementById('qcTargetMoney').textContent = (1000 * v).toLocaleString('en-US');
    });
  }

  /* ---------- Demo toolbar: multiselect + period + toast delegation ---------- */
  function initDemoToolbar() {
    // category multiselect
    const trigger = document.getElementById('qcCategoryTrigger');
    const menu = document.getElementById('qcCategoryMenu');
    const label = document.getElementById('qcCategoryLabel');
    if (trigger && menu) {
      const names = { vps: 'Cloud VPS', hosting: 'Web Hosting', domains: 'Domains' };
      const update = () => {
        const checked = [...menu.querySelectorAll('input:checked')].map(i => names[i.value]);
        label.textContent = checked.length ? checked.join(', ') : 'No category';
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
    // period select -> date range
    const period = document.getElementById('qcPeriodSelect');
    const range = document.getElementById('qcDateRange');
    if (period && range) {
      period.addEventListener('change', () => {
        const map = { last_7_days: 'Last 7 days', last_30_days: 'Last 30 days', last_90_days: 'Last 90 days', custom: 'Aug 1 — Aug 15, 2026' };
        range.value = map[period.value];
        qcToast('Filter updated: ' + range.value, 'info');
      });
    }
    // toast delegation for [data-qc-toast]
    document.addEventListener('click', e => {
      const t = e.target.closest('[data-qc-toast]');
      if (t) qcToast(t.getAttribute('data-qc-toast'), t.getAttribute('data-qc-toast-type') || 'info');
    });
  }

  /* ---------- Product gallery ---------- */
  function initDemoProduct() {
    const galleryImgs = [
      'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?w=800',
      'https://images.unsplash.com/photo-1544197150-b99a580bb7a8?w=800',
      'https://images.unsplash.com/photo-1518770660439-4636190af475?w=800',
      'https://images.unsplash.com/photo-1451187580459-43490279c0fa?w=800'
    ];
    const main = document.getElementById('qcGalleryMain');
    if (!main) return;
    document.querySelectorAll('.qc-gallery-thumb').forEach((th, i) => {
      th.addEventListener('click', () => {
        document.querySelectorAll('.qc-gallery-thumb').forEach(t => t.classList.remove('active'));
        th.classList.add('active');
        main.src = galleryImgs[i];
      });
    });
  }

  /* ---------- Auth demo (sign in -> 2FA) ---------- */
  function initDemoAuth() {
    const signinForm = document.getElementById('qcSigninForm');
    if (!signinForm) return;
    const twofaForm = document.getElementById('qcTwofaForm');
    const codeInputs = document.getElementById('qcCodeInputs');

    function buildCodeInputs() {
      let html = '';
      for (let i = 0; i < 6; i++) html += '<input type="text" maxlength="1" inputmode="numeric" data-idx="' + i + '" aria-label="Digit ' + (i + 1) + '">';
      codeInputs.innerHTML = html;
      codeInputs.querySelectorAll('input').forEach(inp => {
        inp.addEventListener('input', function () {
          this.value = this.value.replace(/\D/g, '');
          const idx = parseInt(this.dataset.idx);
          if (this.value && idx < 5) codeInputs.querySelectorAll('input')[idx + 1].focus();
          if (this.value && idx === 5 && getCode().length === 6) verifyCode(getCode());
        });
        inp.addEventListener('keydown', function (e) {
          if (e.key === 'Backspace' && !this.value && parseInt(this.dataset.idx) > 0) codeInputs.querySelectorAll('input')[parseInt(this.dataset.idx) - 1].focus();
        });
        inp.addEventListener('paste', function (e) {
          const text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
          if (text) {
            e.preventDefault();
            codeInputs.querySelectorAll('input').forEach((inp2, j) => { inp2.value = text[j] || ''; if (j === text.length - 1) inp2.focus(); });
            if (text.length === 6) verifyCode(text);
          }
        });
      });
    }
    function getCode() {
      let c = '';
      codeInputs.querySelectorAll('input').forEach(inp => c += inp.value);
      return c;
    }
    function goToStep(n) {
      document.querySelectorAll('.qc-step').forEach(s => {
        const sn = parseInt(s.getAttribute('data-qc-step'));
        s.classList.toggle('active', sn === n);
        s.classList.toggle('done', sn < n);
      });
      signinForm.style.display = n === 1 ? '' : 'none';
      twofaForm.style.display = n === 2 ? '' : 'none';
      if (n === 2) buildCodeInputs();
    }
    function verifyCode(code) {
      const hint = document.getElementById('qcCodeHint');
      if (code.length !== 6) {
        hint.style.display = '';
        codeInputs.querySelectorAll('input').forEach(i => i.classList.add('error'));
        qcToast('Please enter a valid code', 'error');
        return;
      }
      hint.style.display = 'none';
      codeInputs.querySelectorAll('input').forEach(i => i.classList.remove('error'));
      const btn = document.getElementById('qcVerifyBtn');
      btn.disabled = true; btn.textContent = 'Verifying…';
      setTimeout(() => {
        btn.disabled = false; btn.textContent = 'Verify';
        qcToast('Successfully signed in', 'success');
        goToStep(1);
        signinForm.reset();
      }, 1000);
    }
    signinForm.addEventListener('submit', e => {
      e.preventDefault();
      const email = document.getElementById('qcEmailInput').value.trim();
      const pass = document.getElementById('qcPasswordInput').value;
      const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
      document.getElementById('qcEmailField').classList.toggle('error', !emailOk);
      document.getElementById('qcEmailHint').style.display = emailOk ? 'none' : '';
      document.getElementById('qcPasswordField').classList.toggle('error', !pass);
      document.getElementById('qcPasswordHint').style.display = pass ? 'none' : '';
      if (!emailOk || !pass) { qcToast('Please fix the form errors', 'error'); return; }
      goToStep(2);
    });
    document.getElementById('qcBackBtn').addEventListener('click', () => goToStep(1));
    document.getElementById('qcVerifyBtn').addEventListener('click', () => verifyCode(getCode()));
    document.getElementById('qcResendBtn').addEventListener('click', function () {
      this.disabled = true; this.textContent = 'Sending…';
      setTimeout(() => {
        this.disabled = false; this.textContent = 'Resend code';
        qcToast('New code sent to your email', 'success');
        codeInputs.querySelectorAll('input').forEach(i => i.value = '');
        codeInputs.querySelectorAll('input')[0].focus();
      }, 1000);
    });
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
