const $ = selector => document.querySelector(selector);
const state = { tlds: [], orderUrl: null };
const money = value => `Rs. ${new Intl.NumberFormat('en-LK', { maximumFractionDigits: 0 }).format(Number(value) || 0)}`;
const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[character]));
const withParams = (base, params) => base + (base.includes('?') ? '&' : '?') + params;
const registerUrl = (sld, tld) => state.orderUrl ? withParams(state.orderUrl, `register_sld=${encodeURIComponent(sld)}&register_tld=${encodeURIComponent(tld)}`) : '/client-area';
const transferUrl = (sld, tld) => state.orderUrl ? withParams(state.orderUrl, `transfer_sld=${encodeURIComponent(sld)}&transfer_tld=${encodeURIComponent(tld)}`) : '/client-area';

function renderChips() {
  const wrap = $('#domainChips');
  if (!state.tlds.length) return;
  const preferred = ['.com', '.lk', '.net', '.org', '.io', '.dev', '.xyz'];
  const available = state.tlds.map(t => t.tld);
  const shown = [...preferred.filter(t => available.includes(t)), ...available.filter(t => !preferred.includes(t))].slice(0, 6);
  wrap.innerHTML = '<small>Popular:</small>' + shown.map(tld => `<button type="button" data-tld="${escapeHtml(tld)}">${escapeHtml(tld)}</button>`).join('');
  bindChips();
}
function bindChips() {
  document.querySelectorAll('#domainChips button').forEach(button => button.addEventListener('click', () => {
    const input = $('#domainSearchInput');
    const raw = input.value.trim().toLowerCase().replace(/\.\S*$/, '');
    input.value = (raw || 'yourname') + button.dataset.tld;
    input.focus();
    if (raw) search(raw + button.dataset.tld);
  }));
}

function renderGrid() {
  const grid = $('#tldGrid');
  const note = $('#tldNote');
  if (!state.tlds.length) {
    grid.innerHTML = `<div class="tld-empty"><strong>Domain pricing is on its way</strong>We are finishing the domain launch setup. Check back shortly, or open a ticket in the client area and we will sort you out manually.</div>`;
    return;
  }
  const cheapest = [...state.tlds].sort((a, b) => (a.register ?? 9e9) - (b.register ?? 9e9))[0];
  grid.innerHTML = state.tlds.map(tld => `
    <article class="tld-card">
      ${tld.tld === '.com' ? '<span class="tld-tag">Most popular</span>' : (cheapest && tld.tld === cheapest.tld ? '<span class="tld-tag">Best value</span>' : '')}
      <span class="tld-name">${escapeHtml(tld.tld)}</span>
      <div><div class="tld-register">${tld.register ? money(tld.register) : '—'}<small>/first year</small></div>
      <div class="tld-renew">Renews at ${tld.renew ? money(tld.renew) : '—'}/year${tld.transfer ? ` · Transfer ${money(tld.transfer)}` : ''}</div></div>
      <button type="button" class="tld-go" data-tld="${escapeHtml(tld.tld)}">Search ${escapeHtml(tld.tld)} names →</button>
    </article>`).join('');
  grid.querySelectorAll('.tld-go').forEach(button => button.addEventListener('click', () => {
    const input = $('#domainSearchInput');
    const raw = input.value.trim().toLowerCase().replace(/\.\S*$/, '');
    input.value = (raw || 'yourname') + button.dataset.tld;
    $('#find').scrollIntoView({ behavior: 'smooth' });
    input.focus({ preventScroll: true });
    if (raw) search(raw + button.dataset.tld);
  }));
  note.hidden = false;
  note.textContent = 'Prices sync automatically from our billing system in LKR, per year, including free WHOIS privacy on supported extensions.';
}

function renderResult(html) { const box = $('#domainResult'); box.hidden = false; box.innerHTML = html; }
function loadingResult(name) {
  renderResult(`<div class="domain-result-card is-info"><div class="domain-result-loading"><span class="spinner"></span><span>Checking <b>${escapeHtml(name)}</b> at the registry…</span></div></div>`);
}
function availableResult(data) {
  renderResult(`<div class="domain-result-card is-available">
    <div class="result-main"><span class="result-domain">${escapeHtml(data.domain)}</span><div class="result-note">Available right now — register it before someone else does. Free WHOIS privacy included.</div></div>
    <div class="result-actions"><span class="result-price">${data.price ? money(data.price) : ''}<small>/first year</small></span><a class="button button-primary" href="${escapeHtml(registerUrl(data.sld, data.tld))}">Register this domain <span>→</span></a></div>
  </div>`);
}
function takenResult(data) {
  const transfer = data.transferable
    ? `<a class="button button-primary" href="${escapeHtml(transferUrl(data.sld, data.tld))}">Transfer it to us <span>→</span></a>`
    : '';
  const note = data.transferable
    ? `Already registered — but you can move it here${data.transferPrice ? ` for ${money(data.transferPrice)} (adds a year)` : ''}. Transfers usually complete in 5–7 days.`
    : 'This domain is already registered. Try another name or a different extension below.';
  renderResult(`<div class="domain-result-card is-taken">
    <div class="result-main"><span class="result-domain">${escapeHtml(data.domain)}</span><div class="result-note">${escapeHtml(note)}</div></div>
    <div class="result-actions">${transfer}</div>
  </div>`);
}
function infoResult(message) {
  renderResult(`<div class="domain-result-card is-info"><div class="result-note">${escapeHtml(message)}</div></div>`);
}

async function search(name) {
  loadingResult(name);
  try {
    const response = await fetch(`/api/domains/check?name=${encodeURIComponent(name)}`);
    const data = await response.json();
    if (!response.ok || data.ok === false) { infoResult(data.message || 'That does not look like a domain name we can check.'); return; }
    data.available ? availableResult(data) : takenResult(data);
  } catch (error) {
    console.error(error);
    infoResult('Availability is unreachable right now. Please try again in a moment.');
  }
}

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
$('#mobileMenuBtn').addEventListener('click', () => { const menu = $('#navLinks'); menu.classList.toggle('open'); $('#mobileMenuBtn').setAttribute('aria-expanded', menu.classList.contains('open') ? 'true' : 'false'); });
document.querySelectorAll('#navLinks a').forEach(link => link.addEventListener('click', () => $('#navLinks').classList.remove('open')));
$('#year').textContent = new Date().getFullYear();
initialize();
