/* Quizontal Cloud — domains page: keyword search, name ideas, parallel live availability, price filters */
const $ = selector => document.querySelector(selector);
const POPULAR = ['.com', '.lk', '.net', '.org', '.io', '.co', '.ai', '.dev', '.app', '.xyz', '.me', '.shop', '.store', '.cloud', '.tech'];
const MAX_BULK_CHECKS = 24;
const GRID_LIMIT = 24;
const PLACEHOLDERS = ['myshop.com', 'myshop', 'kadeonline.store', 'devteam.io', 'nextbigthing.xyz', 'salonhq.shop'];

const state = {
  tlds: [],
  orderUrl: null,
  checkInterval: 11000,
  search: null,
  rows: [],
  checks: new Map(),
  timers: [],
  resultSort: 'best',
  resultFilter: 'all',
  gridQuery: '',
  gridSort: 'popular',
  gridRange: 'all',
  gridExpanded: false,
};

const money = value => `Rs. ${new Intl.NumberFormat('en-LK', { maximumFractionDigits: 0 }).format(Number(value) || 0)}`;
const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[character]));
const withParams = (base, params) => base + (base.includes('?') ? '&' : '?') + params;
const registerUrl = (sld, tld) => state.orderUrl ? withParams(state.orderUrl, `register_sld=${encodeURIComponent(sld)}&register_tld=${encodeURIComponent(tld)}`) : '/client-area';
const transferUrl = (sld, tld) => state.orderUrl ? withParams(state.orderUrl, `transfer_sld=${encodeURIComponent(sld)}&transfer_tld=${encodeURIComponent(tld)}`) : '/client-area';
const popularRank = tld => { const index = POPULAR.indexOf(tld); return index === -1 ? 999 : index; };
const statusOf = tld => state.checks.get(tld) || { status: 'unknown' };

/* ---------- Mobile navigation ---------- */

function menuBackdropElement() {
  let backdrop = $('#menuBackdrop');
  if (!backdrop) {
    backdrop = document.createElement('div');
    backdrop.id = 'menuBackdrop';
    backdrop.className = 'menu-backdrop';
    backdrop.setAttribute('aria-hidden', 'true');
    document.body.appendChild(backdrop);
    backdrop.addEventListener('click', () => setMenu(false));
  }
  return backdrop;
}
let menuCloseTimer = null;
function setMenu(open) {
  const menu = $('#navLinks');
  const button = $('#mobileMenuBtn');
  clearTimeout(menuCloseTimer);
  if (open) {
    if (getComputedStyle(menu).display === 'none') menu.style.display = 'flex';
    void menu.offsetWidth; // reflow so the slide-in transition runs
    menu.classList.add('open');
  } else {
    menu.classList.remove('open');
    menu.style.display = 'flex'; // stay painted while sliding out
    menuCloseTimer = setTimeout(() => { if (!menu.classList.contains('open')) menu.style.display = ''; }, 360);
  }
  menuBackdropElement().classList.toggle('show', open);
  document.body.classList.toggle('menu-open', open);
  button.setAttribute('aria-expanded', open ? 'true' : 'false');
  button.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
}

/* ---------- Hero ---------- */

function renderChips() {
  const wrap = $('#domainChips');
  if (!state.tlds.length) return;
  const available = state.tlds.map(t => t.tld);
  const shown = [...POPULAR.filter(t => available.includes(t)), ...available.filter(t => !POPULAR.includes(t))].slice(0, 6);
  wrap.innerHTML = '<small>Popular:</small>' + shown.map(tld => `<button type="button" data-tld="${escapeHtml(tld)}">${escapeHtml(tld)}</button>`).join('');
  wrap.querySelectorAll('button').forEach(button => button.addEventListener('click', () => {
    const input = $('#domainSearchInput');
    const raw = input.value.trim().toLowerCase().replace(/\.\S*$/, '');
    input.value = (raw || 'yourname') + button.dataset.tld;
    input.focus();
    if (raw) search(raw + button.dataset.tld);
  }));
}

function renderHeroStats() {
  if (!state.tlds.length) return;
  const priced = state.tlds.filter(t => t.register != null);
  const cheapest = [...priced].sort((a, b) => a.register - b.register)[0];
  const count = $('#statExtensions');
  const from = $('#statFrom');
  if (count) count.textContent = `${state.tlds.length}+`;
  if (from && cheapest) from.textContent = money(cheapest.register);
}

let placeholderIndex = 0;
setInterval(() => {
  const input = $('#domainSearchInput');
  if (input && document.activeElement !== input && input.value === '') {
    placeholderIndex = (placeholderIndex + 1) % PLACEHOLDERS.length;
    input.setAttribute('placeholder', PLACEHOLDERS[placeholderIndex]);
  }
}, 3000);

/* ---------- Search flow ---------- */

function skeletonRows(count) {
  return Array.from({ length: count }, () => '<div class="rrow rrow-skel"><span></span><span></span><span></span><span></span></div>').join('');
}

function clearTimers() {
  state.timers.forEach(clearTimeout);
  state.timers = [];
}

function showProgress(text) {
  const bar = $('#checkProgress');
  $('#checkProgressLabel').textContent = text;
  $('#checkProgressBar').style.width = '';
  bar.querySelector('.check-progress-track').classList.add('is-indeterminate');
  bar.hidden = false;
}

function hideProgress() {
  const bar = $('#checkProgress');
  bar.querySelector('.check-progress-track').classList.remove('is-indeterminate');
  bar.hidden = true;
}

async function search(name) {
  const section = $('#results');
  section.hidden = false;
  clearTimers();
  state.checks = new Map();
  state.resultSort = 'best';
  state.resultFilter = 'all';
  $('#resultSort').value = 'best';
  document.querySelectorAll('#resultFilters .tool-chip').forEach(chip => chip.classList.toggle('is-active', chip.dataset.filter === 'all'));
  $('#domainSpotlight').innerHTML = '';
  $('#resultsIdeas').hidden = true;
  $('#resultsNotice').hidden = true;
  hideProgress();
  $('#resultsTitle').textContent = `Searching “${name}”…`;
  $('#resultsSub').textContent = 'Comparing prices across every extension we sell…';
  $('#resultsList').innerHTML = skeletonRows(8);
  section.scrollIntoView({ behavior: 'smooth', block: 'start' });

  try {
    const response = await fetch(`/api/domains/search?name=${encodeURIComponent(name)}`);
    const data = await response.json();
    if (!response.ok || data.ok === false) {
      renderSearchError(data.message || 'That does not look like something we can search.');
      return;
    }
    state.search = data;
    state.rows = Array.isArray(data.results) ? data.results : [];
    if (data.orderUrl) state.orderUrl = data.orderUrl;
    if (data.checkInterval) state.checkInterval = data.checkInterval * 1000;
    renderResults();
    bulkLookup();
  } catch (error) {
    console.error(error);
    renderSearchError('Search is unreachable right now. Please try again in a moment.');
  }
}

function renderSearchError(message) {
  $('#resultsTitle').textContent = 'Hmm, one sec';
  $('#resultsSub').textContent = '';
  hideProgress();
  $('#domainSpotlight').innerHTML = `<div class="domain-result-card is-info"><div class="result-note">${escapeHtml(message)}</div></div>`;
  $('#resultsList').innerHTML = '';
  $('#resultsIdeas').hidden = true;
}

/* ---------- Results rendering ---------- */

function sortedRows() {
  const rows = [...state.rows];
  const byPrice = (a, b) => ((a.register ?? 9e18) - (b.register ?? 9e18)) || a.tld.localeCompare(b.tld);
  switch (state.resultSort) {
    case 'price-asc': return rows.sort(byPrice);
    case 'price-desc': return rows.sort((a, b) => ((b.register ?? -1) - (a.register ?? -1)) || a.tld.localeCompare(b.tld));
    case 'name': return rows.sort((a, b) => a.tld.localeCompare(b.tld));
    default: return rows.sort((a, b) => (popularRank(a.tld) - popularRank(b.tld)) || byPrice(a, b));
  }
}

function renderResults() {
  const data = state.search;
  $('#resultsTitle').textContent = data.type === 'exact'
    ? `“${data.sld}${data.tld}” plus ${Math.max(state.rows.length - 1, 0)} more ways to own it`
    : `Results for “${data.sld}”`;
  $('#resultsSub').textContent = `${state.rows.length} extensions · prices are instant and per year · availability lights up in one batch and is confirmed again securely at checkout.`;
  const notice = $('#resultsNotice');
  if (data.notice) {
    notice.hidden = false;
    notice.textContent = data.notice;
  } else {
    notice.hidden = true;
  }
  renderIdeas();
  renderList();
  renderSpotlight();
}

function badgeHtml(tld) {
  const check = statusOf(tld);
  switch (check.status) {
    case 'queued': return '<span class="badge badge-queue"><span class="dot-pulse"></span>In queue</span>';
    case 'checking': return '<span class="badge badge-checking"><span class="mini-spinner"></span>Checking…</span>';
    case 'available': return '<span class="badge badge-available">✓ Available</span>';
    case 'taken': return '<span class="badge badge-taken">✕ Taken</span>';
    case 'error': return `<button type="button" class="badge badge-retry" data-check="${escapeHtml(tld)}" title="${escapeHtml(check.message || 'Check failed')}">↻ Retry</button>`;
    default: return `<button type="button" class="badge badge-check" data-check="${escapeHtml(tld)}">Check live</button>`;
  }
}

function ctaHtml(row) {
  const check = statusOf(row.tld);
  if (check.status === 'available' && row.allowRegister) {
    return `<a class="button button-primary rrow-btn" href="${escapeHtml(registerUrl(state.search.sld, row.tld))}">Register <span>→</span></a>`;
  }
  if (check.status === 'taken') {
    return row.allowTransfer
      ? `<a class="button button-ghost rrow-btn" href="${escapeHtml(transferUrl(state.search.sld, row.tld))}">Transfer <span>→</span></a>`
      : '';
  }
  if (row.allowRegister) {
    return `<a class="button button-ghost rrow-btn" href="${escapeHtml(registerUrl(state.search.sld, row.tld))}">Register <span>→</span></a>`;
  }
  return '';
}

function rowHtml(row) {
  const check = statusOf(row.tld);
  const price = row.register != null ? `<b>${money(row.register)}</b><small>/first year</small>` : '<b>—</b>';
  const details = [];
  if (row.renew != null) details.push(`renews ${money(row.renew)}`);
  if (row.allowTransfer && row.transfer != null) details.push(`transfer ${money(row.transfer)}`);
  return `<div class="rrow${check.status === 'available' ? ' is-available' : ''}${check.status === 'taken' ? ' is-taken' : ''}" data-tld="${escapeHtml(row.tld)}">
    <div class="rrow-name"><span class="rrow-sld">${escapeHtml(state.search.sld)}</span><span class="rrow-tld">${escapeHtml(row.tld)}</span>${row.popular ? '<em class="rrow-tag">Popular</em>' : ''}</div>
    <div class="rrow-status">${badgeHtml(row.tld)}</div>
    <div class="rrow-price">${price}${details.length ? `<span>${escapeHtml(details.join(' · '))}</span>` : ''}</div>
    <div class="rrow-cta">${ctaHtml(row)}</div>
  </div>`;
}

function visibleRows() {
  let rows = sortedRows();
  if (state.search && state.search.type === 'exact') rows = rows.filter(row => row.tld !== state.search.tld);
  if (state.resultFilter === 'hide-taken') rows = rows.filter(row => statusOf(row.tld).status !== 'taken');
  if (state.resultFilter === 'available') rows = rows.filter(row => statusOf(row.tld).status === 'available');
  return rows;
}

function renderList() {
  const list = $('#resultsList');
  if (!state.search) { list.innerHTML = ''; return; }
  const rows = visibleRows();
  if (!rows.length) {
    list.innerHTML = `<div class="results-empty">${state.resultFilter === 'available'
      ? 'No confirmed-available names yet — checks are still running or every option is taken.'
      : 'Nothing matches this filter.'}</div>`;
    return;
  }
  list.innerHTML = rows.map(rowHtml).join('');
  bindRowButtons(list);
}

function renderSpotlight() {
  const box = $('#domainSpotlight');
  if (!state.search || state.search.type !== 'exact') { box.innerHTML = ''; return; }
  const row = state.rows.find(item => item.tld === state.search.tld);
  if (!row) { box.innerHTML = ''; return; }
  const check = statusOf(row.tld);
  const sld = state.search.sld;
  const priceHtml = row.register != null ? `<span class="result-price">${money(row.register)}<small>/first year</small></span>` : '';
  let card = '';
  if (check.status === 'available') {
    card = `<div class="domain-result-card is-available spotlight">
      <div class="result-main"><span class="result-domain">${escapeHtml(sld + row.tld)}</span><div class="result-note">Available right now — register it before someone else does. Free WHOIS privacy included.</div></div>
      <div class="result-actions">${priceHtml}<a class="button button-primary" href="${escapeHtml(registerUrl(sld, row.tld))}">Register this domain <span>→</span></a></div></div>`;
  } else if (check.status === 'taken') {
    const action = row.allowTransfer ? `<a class="button button-primary" href="${escapeHtml(transferUrl(sld, row.tld))}">Transfer it to us <span>→</span></a>` : '';
    const note = row.allowTransfer
      ? `Already registered elsewhere — you can transfer it here${row.transfer != null ? ` for ${money(row.transfer)} (adds a year)` : ''}, or grab another extension below.`
      : 'This domain is already registered. Grab one of the other extensions below.';
    card = `<div class="domain-result-card is-taken spotlight">
      <div class="result-main"><span class="result-domain">${escapeHtml(sld + row.tld)}</span><div class="result-note">${escapeHtml(note)}</div></div>
      <div class="result-actions">${action}</div></div>`;
  } else if (check.status === 'error') {
    card = `<div class="domain-result-card is-info spotlight">
      <div class="result-main"><span class="result-domain">${escapeHtml(sld + row.tld)}</span><div class="result-note">${escapeHtml(check.message || 'Availability could not be checked right now.')}</div></div>
      <div class="result-actions"><button type="button" class="button button-ghost" data-check="${escapeHtml(row.tld)}">Try again</button></div></div>`;
  } else {
    card = `<div class="domain-result-card is-info spotlight"><div class="domain-result-loading"><span class="spinner"></span><span>Checking <b>${escapeHtml(sld + row.tld)}</b> at the registry…</span></div></div>`;
  }
  box.innerHTML = card;
  bindRowButtons(box);
}

function renderIdeas() {
  const wrap = $('#resultsIdeas');
  const ideas = (state.search && state.search.suggestions) || [];
  if (!ideas.length) { wrap.hidden = true; return; }
  wrap.hidden = false;
  wrap.innerHTML = `<small>Name ideas — same vibe, different twist:</small><div>${ideas.map(idea => `<button type="button" class="idea-chip" data-idea="${escapeHtml(idea)}">${escapeHtml(idea)}</button>`).join('')}</div>`;
  wrap.querySelectorAll('.idea-chip').forEach(chip => chip.addEventListener('click', () => {
    $('#domainSearchInput').value = chip.dataset.idea;
    search(chip.dataset.idea);
  }));
}

function bindRowButtons(scope) {
  scope.querySelectorAll('[data-check]').forEach(button => button.addEventListener('click', () => doCheck(button.dataset.check)));
}

function refreshRow(tld) {
  const selector = `[data-tld="${tld}"]`;
  const element = document.querySelector(`.rrow${selector}`);
  const row = state.rows.find(item => item.tld === tld);
  if (element && row) {
    element.outerHTML = rowHtml(row);
    const fresh = document.querySelector(`.rrow${selector}`);
    if (fresh) bindRowButtons(fresh);
  } else if (row) {
    renderList(); // row is currently filtered out of view — rebuild to be safe
  }
  renderSpotlight();
}

/* ---------- Parallel live availability (RDAP) with registrar fallback ---------- */

async function bulkLookup() {
  if (!state.search) return;
  const exactFirst = (a, b) => (a.tld === state.search.tld ? -1 : 0) - (b.tld === state.search.tld ? -1 : 0);
  const targets = sortedRows()
    .filter(row => row.allowRegister && statusOf(row.tld).status === 'unknown')
    .sort(exactFirst)
    .slice(0, MAX_BULK_CHECKS);
  if (!targets.length) return;

  targets.forEach(row => state.checks.set(row.tld, { status: 'checking' }));
  renderList();
  renderSpotlight();
  showProgress(`Checking live availability across ${targets.length} extension${targets.length === 1 ? '' : 's'}…`);

  try {
    const query = targets.map(row => row.tld).join(',');
    const response = await fetch(`/api/domains/lookup?sld=${encodeURIComponent(state.search.sld)}&tlds=${encodeURIComponent(query)}`);
    const data = await response.json();
    const map = (response.ok && data && data.ok && data.results) || {};
    targets.forEach(row => {
      const status = map[row.tld];
      state.checks.set(row.tld, status === 'available' ? { status: 'available' } : status === 'taken' ? { status: 'taken' } : { status: 'unknown' });
    });
  } catch (error) {
    console.error(error);
    targets.forEach(row => state.checks.set(row.tld, { status: 'unknown' }));
  }
  hideProgress();
  renderList();
  renderSpotlight();
}

/* Authoritative single check (registrar) — paced, used for rows RDAP can't answer and manual re-checks. */
async function doCheck(tld) {
  if (!state.search) return;
  const current = statusOf(tld);
  if (['available', 'taken', 'checking'].includes(current.status)) return;
  state.checks.set(tld, { status: 'checking' });
  refreshRow(tld);

  try {
    const response = await fetch(`/api/domains/check?name=${encodeURIComponent(state.search.sld + tld)}`);
    const data = await response.json();
    if (data && data.code === 'throttled') {
      state.checks.set(tld, { status: 'queued' });
      state.timers.push(setTimeout(() => doCheck(tld), Math.max(1, data.retryAfter || 10) * 1000));
    } else if (response.ok && data && data.ok) {
      state.checks.set(tld, { status: data.available ? 'available' : 'taken', message: data.message || null });
    } else {
      state.checks.set(tld, { status: 'error', message: (data && data.message) || 'Availability check failed.' });
    }
  } catch (error) {
    console.error(error);
    state.checks.set(tld, { status: 'error', message: 'Connection problem — please retry.' });
  }
  refreshRow(tld);
}

/* ---------- Price grid with filters ---------- */

const RANGE_FILTERS = {
  u2000: tld => tld.register != null && tld.register < 2000,
  u5000: tld => tld.register != null && tld.register >= 2000 && tld.register < 5000,
  o5000: tld => tld.register != null && tld.register >= 5000,
};

function gridVisible() {
  let list = [...state.tlds];
  if (state.gridQuery) list = list.filter(tld => tld.tld.includes(state.gridQuery));
  if (state.gridRange !== 'all' && RANGE_FILTERS[state.gridRange]) list = list.filter(RANGE_FILTERS[state.gridRange]);
  switch (state.gridSort) {
    case 'price-asc': list.sort((a, b) => (a.register ?? 9e18) - (b.register ?? 9e18) || a.tld.localeCompare(b.tld)); break;
    case 'price-desc': list.sort((a, b) => (b.register ?? -1) - (a.register ?? -1) || a.tld.localeCompare(b.tld)); break;
    case 'name': list.sort((a, b) => a.tld.localeCompare(b.tld)); break;
    default: list.sort((a, b) => (popularRank(a.tld) - popularRank(b.tld)) || (a.register ?? 9e18) - (b.register ?? 9e18) || a.tld.localeCompare(b.tld));
  }
  return list;
}

function renderGrid() {
  const grid = $('#tldGrid');
  const note = $('#tldNote');
  const tools = $('#tldTools');
  if (!state.tlds.length) {
    tools.hidden = true;
    grid.innerHTML = `<div class="tld-empty"><strong>Domain pricing is on its way</strong>We are finishing the domain launch setup. Check back shortly, or open a ticket in the client area and we will sort you out manually.</div>`;
    return;
  }
  tools.hidden = false;
  const filtered = gridVisible();
  $('#tldCount').textContent = `${filtered.length} of ${state.tlds.length} extensions`;

  if (!filtered.length) {
    grid.innerHTML = `<div class="tld-empty"><strong>No extensions match${state.gridQuery ? ` “${escapeHtml(state.gridQuery)}”` : ''}</strong>Try a different price range or clear the filter text.</div>`;
    note.hidden = true;
    return;
  }

  const filtering = state.gridQuery !== '' || state.gridRange !== 'all' || state.gridSort !== 'popular';
  const cheapest = [...state.tlds].filter(t => t.register != null).sort((a, b) => a.register - b.register)[0];
  const visible = (!filtering && !state.gridExpanded) ? filtered.slice(0, GRID_LIMIT) : filtered;
  const cardHtml = tld => `
    <article class="tld-card">
      ${!filtering && tld.tld === '.com' ? '<span class="tld-tag">Most popular</span>' : ''}
      ${!filtering && cheapest && tld.tld === cheapest.tld ? '<span class="tld-tag">Best value</span>' : ''}
      <span class="tld-name">${escapeHtml(tld.tld)}</span>
      <div><div class="tld-register">${tld.register != null ? money(tld.register) : '—'}<small>/first year</small></div>
      <div class="tld-renew">Renews at ${tld.renew != null ? money(tld.renew) : '—'}/year${tld.transfer != null ? ` · Transfer ${money(tld.transfer)}` : ''}</div></div>
      <button type="button" class="tld-go" data-tld="${escapeHtml(tld.tld)}">Search ${escapeHtml(tld.tld)} names →</button>
    </article>`;
  const toggle = (!filtering && filtered.length > GRID_LIMIT)
    ? `<div class="tld-toggle-row"><button type="button" class="text-button" id="tldToggle">${state.gridExpanded ? 'Show less ↑' : `Show all ${filtered.length} extensions <span>↓</span>`}</button></div>`
    : '';
  grid.innerHTML = visible.map(cardHtml).join('') + toggle;
  grid.querySelectorAll('.tld-go').forEach(button => button.addEventListener('click', () => {
    const input = $('#domainSearchInput');
    const raw = input.value.trim().toLowerCase().replace(/\.\S*$/, '');
    input.value = (raw || 'yourname') + button.dataset.tld;
    $('#find').scrollIntoView({ behavior: 'smooth' });
    input.focus({ preventScroll: true });
    if (raw) search(raw + button.dataset.tld);
  }));
  const toggleButton = $('#tldToggle');
  if (toggleButton) toggleButton.addEventListener('click', () => { state.gridExpanded = !state.gridExpanded; renderGrid(); });
  note.hidden = false;
  note.textContent = 'Prices sync automatically from our billing system in LKR, per year, including free WHOIS privacy on supported extensions.';
}

/* ---------- Toolbar bindings ---------- */

function bindTools() {
  $('#resultSort').addEventListener('change', event => { state.resultSort = event.target.value; renderList(); });
  $('#resultFilters').addEventListener('click', event => {
    const button = event.target.closest('.tool-chip');
    if (!button) return;
    state.resultFilter = button.dataset.filter;
    document.querySelectorAll('#resultFilters .tool-chip').forEach(chip => chip.classList.toggle('is-active', chip === button));
    renderList();
  });

  let gridTimer = null;
  $('#tldFilter').addEventListener('input', event => {
    clearTimeout(gridTimer);
    gridTimer = setTimeout(() => {
      state.gridQuery = event.target.value.trim().toLowerCase().replace(/[^a-z0-9.-]/g, '');
      renderGrid();
    }, 150);
  });
  $('#tldRanges').addEventListener('click', event => {
    const button = event.target.closest('.tool-chip');
    if (!button) return;
    state.gridRange = button.dataset.range;
    document.querySelectorAll('#tldRanges .tool-chip').forEach(chip => chip.classList.toggle('is-active', chip === button));
    renderGrid();
  });
  $('#tldSort').addEventListener('change', event => { state.gridSort = event.target.value; state.gridExpanded = false; renderGrid(); });
}

/* ---------- Boot ---------- */

function setClientLinks(config) {
  ['#clientArea', '#mobileClientArea', '#faqClientArea', '#ctaClientArea', '#footerClient'].forEach(selector => { const element = $(selector); if (element) element.href = config.clientAreaUrl || '/client-area'; });
}

async function initialize() {
  try {
    const [tldsResponse, configResponse] = await Promise.all([fetch('/api/domains/tlds'), fetch('/api/config')]);
    if (configResponse.ok) setClientLinks(await configResponse.json());
    if (tldsResponse.ok) {
      const payload = await tldsResponse.json();
      state.tlds = Array.isArray(payload.tlds) ? payload.tlds : [];
      state.orderUrl = payload.orderUrl || null;
    }
    renderChips();
    renderGrid();
    renderHeroStats();
  } catch (error) {
    console.error(error);
    renderGrid();
  }
}

$('#domainSearchForm').addEventListener('submit', event => {
  event.preventDefault();
  const name = $('#domainSearchInput').value.trim();
  if (name) search(name);
});
$('#mobileMenuBtn').addEventListener('click', () => setMenu(!$('#navLinks').classList.contains('open')));
window.addEventListener('keydown', event => { if (event.key === 'Escape') setMenu(false); });
window.addEventListener('resize', () => { if (window.innerWidth > 700) setMenu(false); });
document.querySelectorAll('#navLinks a').forEach(link => link.addEventListener('click', () => setMenu(false)));
$('#year').textContent = new Date().getFullYear();
document.scrollingElement.scrollLeft = 0; // drop any restored horizontal offset
bindTools();
initialize();
