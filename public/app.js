const $ = selector => document.querySelector(selector);
const state = { catalog: [], category: 'general', cpu: null, ram: null, config: { orderUrl: '#plans' } };
const money = value => `Rs. ${new Intl.NumberFormat('en-LK', { maximumFractionDigits: 0 }).format(Number(value) || 0)}`;
const storage = gb => Number(gb) >= 1000 ? `${(Number(gb) / 1000).toFixed(Number(gb) % 1000 ? 1 : 0)} TB` : `${Number(gb)} GB`;
const categoryName = category => ({ general: 'KVM Linux', storage: 'KVM Storage', windows: 'Hyper-V Windows' }[category] || category);
const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[character]));
const plans = () => state.catalog.filter(plan => plan.category === state.category && plan.available);
const uniqueSorted = values => [...new Set(values.map(Number))].sort((a, b) => a - b);

function checkoutUrl(plan) {
  const base = state.config.orderUrl || '#plans';
  if (base.startsWith('#')) return base;
  if (base.includes('{productId}')) return base.replace('{productId}', encodeURIComponent(plan.providerProductId));
  return `${base}${base.includes('?') ? '&' : '?'}product=${encodeURIComponent(plan.providerProductId)}`;
}
function planSpecs(plan) { return `${plan.cpu} vCPU · ${plan.ramGb} GB RAM · ${storage(plan.storageGb)} ${plan.storageType} · ${storage(plan.bandwidthGb)} transfer`; }
function empty(message) { return `<div class="empty-state"><h3>No plans available</h3><p>${escapeHtml(message)}</p></div>`; }

function updateSelectors() {
  const available = plans();
  const cpus = uniqueSorted(available.map(plan => plan.cpu));
  if (!cpus.includes(Number(state.cpu))) state.cpu = cpus[0] ?? null;
  $('#cpuSelector').innerHTML = cpus.map(cpu => `<button class="toggle-btn ${cpu === Number(state.cpu) ? 'active' : ''}" data-cpu="${cpu}">${cpu}</button>`).join('') || '<span class="loading-line"></span>';
  const ramOptions = uniqueSorted(available.filter(plan => Number(plan.cpu) === Number(state.cpu)).map(plan => plan.ramGb));
  if (!ramOptions.includes(Number(state.ram))) state.ram = ramOptions[0] ?? null;
  $('#ramSelector').innerHTML = ramOptions.map(ram => `<button class="toggle-btn ${ram === Number(state.ram) ? 'active' : ''}" data-ram="${ram}">${ram} GB</button>`).join('') || '<span class="loading-line"></span>';
  document.querySelectorAll('[data-cpu]').forEach(button => button.addEventListener('click', () => { state.cpu = Number(button.dataset.cpu); state.ram = null; updateSelectors(); updatePrice(); }));
  document.querySelectorAll('[data-ram]').forEach(button => button.addEventListener('click', () => { state.ram = Number(button.dataset.ram); updateSelectors(); updatePrice(); }));
}
function selectedPlan() { return plans().find(plan => Number(plan.cpu) === Number(state.cpu) && Number(plan.ramGb) === Number(state.ram)) || plans()[0]; }
function updatePrice() {
  const plan = selectedPlan();
  $('#dynamicPrice').innerHTML = plan ? `${money(plan.priceLkr)}<span>/mo</span>` : 'Unavailable';
  $('#dynamicSpecs').textContent = plan ? planSpecs(plan) : `No ${categoryName(state.category)} products are currently available.`;
}
function card(plan, displayIndex) {
  const featured = displayIndex === 1;
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
  const indexes = uniqueSorted([0, Math.floor((current.length - 1) / 2), current.length - 1]).filter(index => index >= 0);
  $('#featuredPlans').innerHTML = indexes.length ? indexes.map((index, displayIndex) => card(current[index], displayIndex)).join('') : empty(`We are refreshing the ${categoryName(state.category)} catalog.`);
  $('#plansTableBody').innerHTML = current.map(plan => `<tr data-row-id="${escapeHtml(plan.id)}"><td><strong>${escapeHtml(plan.name)}</strong></td><td>${escapeHtml(plan.cpu)}</td><td>${escapeHtml(plan.ramGb)} GB</td><td>${escapeHtml(storage(plan.storageGb))} ${escapeHtml(plan.storageType)}</td><td>${escapeHtml(storage(plan.bandwidthGb))}</td><td class="price-cell">${money(plan.priceLkr)}/mo</td><td><a class="button button-ghost button-small" href="${escapeHtml(checkoutUrl(plan))}">Configure</a></td></tr>`).join('') || '<tr><td colspan="7">No plans available in this category.</td></tr>';
}
function renderAll() { updateSelectors(); updatePrice(); renderPlans(); }
function setClientLinks(config) {
  ['#clientArea', '#heroClientArea', '#featureClientArea', '#faqClientArea', '#ctaClientArea', '#footerClient'].forEach(selector => { const element = $(selector); if (element) element.href = config.clientAreaUrl || '/client-area'; });
}
async function initialize() {
  try {
    const [catalogResponse, configResponse] = await Promise.all([fetch('/api/catalog'), fetch('/api/config')]);
    if (!catalogResponse.ok) throw new Error('Catalog unavailable');
    const catalog = await catalogResponse.json();
    state.catalog = Array.isArray(catalog.products) ? catalog.products : [];
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

document.querySelectorAll('.category-tab').forEach(button => button.addEventListener('click', () => { document.querySelectorAll('.category-tab').forEach(item => item.classList.remove('active')); button.classList.add('active'); state.category = button.dataset.category; state.cpu = null; state.ram = null; renderAll(); }));
$('#seeAllPlans').addEventListener('click', () => { const panel = $('#allPlansWrapper'); panel.classList.toggle('show'); $('#seeAllPlans').innerHTML = panel.classList.contains('show') ? 'Hide all plans <span>↑</span>' : 'See all plans <span>↓</span>'; });
$('#findPlanBtn').addEventListener('click', () => { const plan = selectedPlan(); if (!plan) return; const planCard = document.querySelector(`.plan[data-id="${CSS.escape(String(plan.id))}"]`); if (planCard) { document.querySelectorAll('.plan').forEach(item => item.classList.remove('highlight')); planCard.classList.add('highlight'); planCard.scrollIntoView({ behavior: 'smooth', block: 'center' }); return; } $('#allPlansWrapper').classList.add('show'); const row = document.querySelector(`[data-row-id="${CSS.escape(String(plan.id))}"]`); if (row) { row.style.background = 'rgba(227,28,100,.1)'; row.scrollIntoView({ behavior: 'smooth', block: 'center' }); } });
$('#mobileMenuBtn').addEventListener('click', () => { const menu = $('#navLinks'); menu.classList.toggle('open'); $('#mobileMenuBtn').setAttribute('aria-expanded', menu.classList.contains('open') ? 'true' : 'false'); });
document.querySelectorAll('#navLinks a').forEach(link => link.addEventListener('click', () => $('#navLinks').classList.remove('open')));
$('#year').textContent = new Date().getFullYear();
initialize();
