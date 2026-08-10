const $ = selector => document.querySelector(selector);
const state = { catalog: [], category: 'general', planId: null, config: { orderUrl: '#plans' } };
const money = value => `Rs. ${new Intl.NumberFormat('en-LK', { maximumFractionDigits: 0 }).format(Number(value) || 0)}`;
const storage = gb => Number(gb) >= 1000 ? `${(Number(gb) / 1000).toFixed(Number(gb) % 1000 ? 1 : 0)} TB` : `${Number(gb)} GB`;
const categoryName = category => ({ general: 'KVM Linux', storage: 'KVM Storage', windows: 'Hyper-V Windows' }[category] || category);
const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[character]));
const plans = () => state.catalog
  .filter(plan => plan.category === state.category && plan.available)
  .sort((a, b) => Number(a.priceLkr) - Number(b.priceLkr) || Number(a.slices ?? a.cpu) - Number(b.slices ?? b.cpu));

function checkoutUrl(plan) {
  const base = state.config.orderUrl || '#plans';
  if (base.startsWith('#')) return base;
  if (base.includes('{productId}')) return base.replace('{productId}', encodeURIComponent(plan.providerProductId));
  return `${base}${base.includes('?') ? '&' : '?'}product=${encodeURIComponent(plan.providerProductId)}`;
}
function planSpecs(plan) { return `${plan.cpu} vCPU · ${plan.ramGb} GB RAM · ${storage(plan.storageGb)} ${plan.storageType} · ${storage(plan.bandwidthGb)} transfer`; }
function empty(message) { return `<div class="empty-state"><h3>No plans available</h3><p>${escapeHtml(message)}</p></div>`; }

function updatePlanSelector() {
  const available = plans();
  if (!available.some(plan => String(plan.id) === String(state.planId))) state.planId = available[0]?.id ?? null;
  $('#planSelector').innerHTML = available.length
    ? available.map(plan => `<option value="${escapeHtml(plan.id)}" ${String(plan.id) === String(state.planId) ? 'selected' : ''}>${escapeHtml(plan.name)}</option>`).join('')
    : '<option value="">No plans currently available</option>';
  $('#planSelectMenu').innerHTML = available.length ? available.map(plan => `<button type="button" class="plan-select-option ${String(plan.id) === String(state.planId) ? 'active' : ''}" role="option" aria-selected="${String(plan.id) === String(state.planId)}" data-plan-id="${escapeHtml(plan.id)}"><span><strong>${escapeHtml(plan.name)}</strong><small>${escapeHtml(plan.cpu)} vCPU · ${escapeHtml(plan.ramGb)} GB RAM · ${escapeHtml(storage(plan.storageGb))} ${escapeHtml(plan.storageType)}</small></span><b>${money(plan.priceLkr)}<small>/mo</small></b></button>`).join('') : '<div class="plan-select-empty">No plans currently available</div>';
  const selected = selectedPlan();
  $('#planSelectTitle').textContent = selected?.name || 'No plan available';
  $('#planSelectMeta').textContent = selected ? `${selected.cpu} vCPU · ${selected.ramGb} GB RAM · ${storage(selected.storageGb)} ${selected.storageType}` : 'Try another category';
  document.querySelectorAll('.plan-select-option').forEach(option => option.addEventListener('click', () => { state.planId = option.dataset.planId; $('#planSelector').value = state.planId; $('#customPlanSelect').classList.remove('open'); $('#planSelectTrigger').setAttribute('aria-expanded', 'false'); updatePlanSelector(); updatePrice(); }));
}
function selectedPlan() { return plans().find(plan => String(plan.id) === String(state.planId)) || plans()[0]; }
function updatePrice() {
  const plan = selectedPlan();
  $('#dynamicPrice').innerHTML = plan ? `${money(plan.priceLkr)}<span>/mo</span>` : 'Unavailable';
  $('#dynamicSpecs').textContent = plan ? planSpecs(plan) : `No ${categoryName(state.category)} products are currently available.`;
}
function card(plan, displayIndex) {
  const featured = displayIndex === 2;
  return `<article class="plan ${featured ? 'featured' : ''}" data-id="${escapeHtml(plan.id)}">
    ${featured ? '<div class="plan-badge">Most popular</div>' : ''}
    <div class="plan-name">${escapeHtml(plan.name)}</div>
    <div class="plan-price">${money(plan.priceLkr)}<span>/month</span></div>
    <p class="plan-desc">${escapeHtml(planSpecs(plan))}</p>
    <ul class="plan-specs"><li><span class="check">✓</span>${escapeHtml(plan.cpu)} vCPU core${Number(plan.cpu) !== 1 ? 's' : ''}</li><li><span class="check">✓</span>${escapeHtml(plan.ramGb)} GB memory</li><li><span class="check">✓</span>${escapeHtml(storage(plan.storageGb))} ${escapeHtml(plan.storageType)} storage</li><li><span class="check">✓</span>${escapeHtml(storage(plan.bandwidthGb))} monthly transfer</li></ul>
    <a href="${escapeHtml(checkoutUrl(plan))}" class="button ${featured ? 'button-primary' : 'button-ghost'}">Configure plan <span>→</span></a>
  </article>`;
}
function renderPlans() {
  const current = plans();
  const featured = current.slice(0, 6);
  $('#featuredPlans').innerHTML = featured.length ? featured.map((plan, displayIndex) => card(plan, displayIndex)).join('') : empty(`We are refreshing the ${categoryName(state.category)} catalog.`);
  $('#plansTableBody').innerHTML = current.map(plan => `<tr data-row-id="${escapeHtml(plan.id)}"><td><strong>${escapeHtml(plan.name)}</strong></td><td>${escapeHtml(plan.cpu)}</td><td>${escapeHtml(plan.ramGb)} GB</td><td>${escapeHtml(storage(plan.storageGb))} ${escapeHtml(plan.storageType)}</td><td>${escapeHtml(storage(plan.bandwidthGb))}</td><td class="price-cell">${money(plan.priceLkr)}/mo</td><td><a class="button button-ghost button-small" href="${escapeHtml(checkoutUrl(plan))}">Configure</a></td></tr>`).join('') || '<tr><td colspan="7">No plans available in this category.</td></tr>';
}
function renderAll() { updatePlanSelector(); updatePrice(); renderPlans(); }
function setClientLinks(config) {
  ['#clientArea', '#mobileClientArea', '#heroClientArea', '#featureClientArea', '#faqClientArea', '#ctaClientArea', '#footerClient'].forEach(selector => { const element = $(selector); if (element) element.href = config.clientAreaUrl || '/client-area'; });
}
async function initialize() {
  try {
    const [catalogResponse, configResponse] = await Promise.all([fetch('/api/catalog'), fetch('/api/config')]);
    if (!catalogResponse.ok) throw new Error('Catalog unavailable');
    const catalog = await catalogResponse.json();
    state.catalog = Array.isArray(catalog.products) ? catalog.products : [];
    $('#planSource').textContent = catalog.source === 'billing_database' ? 'Current available plans and monthly prices' : 'Latest available configurations';
    state.config = configResponse.ok ? await configResponse.json() : state.config;
    setClientLinks(state.config);
    const lowest = state.catalog.filter(plan => plan.available).sort((a, b) => Number(a.priceLkr) - Number(b.priceLkr))[0];
    const lowestPrice = lowest ? money(lowest.priceLkr) : '—';
    $('#fromPrice').textContent = lowestPrice; $('#heroFromPrice').textContent = lowestPrice;
    if (String(catalog.updatedAt || '').startsWith('Demo')) { const note = $('#catalogNote'); note.hidden = false; note.textContent = 'Demo catalog shown — import the current infrastructure catalog before accepting orders.'; }
    renderAll();
  } catch (error) {
    console.error(error); $('#featuredPlans').innerHTML = empty('The product catalog could not be loaded. Please try again shortly.'); $('#dynamicPrice').textContent = 'Unavailable'; $('#dynamicSpecs').textContent = 'Catalog temporarily unavailable';
  }
}

document.querySelectorAll('.category-tab').forEach(button => button.addEventListener('click', () => { document.querySelectorAll('.category-tab').forEach(item => item.classList.remove('active')); button.classList.add('active'); state.category = button.dataset.category; state.planId = null; renderAll(); }));
$('#planSelector').addEventListener('change', event => { state.planId = event.target.value; updatePlanSelector(); updatePrice(); });
$('#planSelectTrigger').addEventListener('click', () => { const select = $('#customPlanSelect'); select.classList.toggle('open'); $('#planSelectTrigger').setAttribute('aria-expanded', select.classList.contains('open') ? 'true' : 'false'); });
document.addEventListener('click', event => { if (!event.target.closest('#customPlanSelect')) { $('#customPlanSelect').classList.remove('open'); $('#planSelectTrigger').setAttribute('aria-expanded', 'false'); } });
$('#seeAllPlans').addEventListener('click', () => { const panel = $('#allPlansWrapper'); panel.classList.toggle('show'); $('#seeAllPlans').innerHTML = panel.classList.contains('show') ? 'Hide all plans <span>↑</span>' : 'See all plans <span>↓</span>'; });
$('#findPlanBtn').addEventListener('click', () => { const plan = selectedPlan(); if (!plan) return; const planCard = document.querySelector(`.plan[data-id="${CSS.escape(String(plan.id))}"]`); if (planCard) { document.querySelectorAll('.plan').forEach(item => item.classList.remove('highlight')); planCard.classList.add('highlight'); planCard.scrollIntoView({ behavior: 'smooth', block: 'center' }); return; } $('#allPlansWrapper').classList.add('show'); const row = document.querySelector(`[data-row-id="${CSS.escape(String(plan.id))}"]`); if (row) { row.style.background = 'rgba(227,28,100,.1)'; row.scrollIntoView({ behavior: 'smooth', block: 'center' }); } });
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
function setMenu(open) {
  const menu = $('#navLinks');
  const button = $('#mobileMenuBtn');
  menu.classList.toggle('open', open);
  menuBackdropElement().classList.toggle('show', open);
  document.body.classList.toggle('menu-open', open);
  button.setAttribute('aria-expanded', open ? 'true' : 'false');
  button.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
}
$('#mobileMenuBtn').addEventListener('click', () => setMenu(!$('#navLinks').classList.contains('open')));
window.addEventListener('keydown', event => { if (event.key === 'Escape') setMenu(false); });
window.addEventListener('resize', () => { if (window.innerWidth > 700) setMenu(false); });
document.querySelectorAll('#navLinks a').forEach(link => link.addEventListener('click', () => setMenu(false)));
$('#year').textContent = new Date().getFullYear();
initialize();
