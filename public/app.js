const $ = selector => document.querySelector(selector);
const state = { catalog: [], category: 'general', cpu: null, ram: null, config: { orderUrl: '#plans' } };
const money = value => `Rs. ${new Intl.NumberFormat('en-LK', { maximumFractionDigits: 0 }).format(value)}`;
const storage = gb => gb >= 1000 ? `${(gb / 1000).toFixed(gb % 1000 ? 1 : 0)} TB` : `${gb} GB`;
const categoryName = category => ({ general: 'General purpose', storage: 'Storage optimized', windows: 'Windows VPS' }[category] || category);
const plans = () => state.catalog.filter(plan => plan.category === state.category && plan.available);
const uniqueSorted = values => [...new Set(values)].sort((a, b) => a - b);

function checkoutUrl(plan) {
  const base = state.config.orderUrl || '#plans';
  if (base.startsWith('#')) return base;
  if (base.includes('{productId}')) return base.replace('{productId}', encodeURIComponent(plan.providerProductId));
  const separator = base.includes('?') ? '&' : '?';
  return `${base}${separator}product=${encodeURIComponent(plan.providerProductId)}`;
}
function planSpecs(plan) { return `${plan.cpu} vCPU · ${plan.ramGb} GB RAM · ${storage(plan.storageGb)} ${plan.storageType} · ${storage(plan.bandwidthGb)} transfer`; }
function empty(message) { return `<div class="plan" style="grid-column:1/-1;text-align:center"><h3>No plans available</h3><p class="plan-desc">${message}</p></div>`; }

function updateSelectors() {
  const available = plans();
  const cpus = uniqueSorted(available.map(p => p.cpu));
  if (!cpus.includes(state.cpu)) state.cpu = cpus[0] ?? null;
  $('#cpuSelector').innerHTML = cpus.map(cpu => `<button class="toggle-btn ${cpu === state.cpu ? 'active' : ''}" data-cpu="${cpu}">${cpu}</button>`).join('') || '<span class="specs">No options</span>';
  const ramOptions = uniqueSorted(available.filter(p => p.cpu === state.cpu).map(p => p.ramGb));
  if (!ramOptions.includes(state.ram)) state.ram = ramOptions[0] ?? null;
  $('#ramSelector').innerHTML = ramOptions.map(ram => `<button class="toggle-btn ${ram === state.ram ? 'active' : ''}" data-ram="${ram}">${ram} GB</button>`).join('') || '<span class="specs">No options</span>';
  document.querySelectorAll('[data-cpu]').forEach(button => button.addEventListener('click', () => { state.cpu = Number(button.dataset.cpu); state.ram = null; updateSelectors(); updatePrice(); }));
  document.querySelectorAll('[data-ram]').forEach(button => button.addEventListener('click', () => { state.ram = Number(button.dataset.ram); updateSelectors(); updatePrice(); }));
}
function selectedPlan() { return plans().find(p => p.cpu === state.cpu && p.ramGb === state.ram) || plans()[0]; }
function updatePrice() {
  const plan = selectedPlan();
  $('#dynamicPrice').innerHTML = plan ? `${money(plan.priceLkr)}<span>/mo</span>` : 'Unavailable';
  $('#dynamicSpecs').textContent = plan ? planSpecs(plan) : `No ${categoryName(state.category).toLowerCase()} products are currently in the catalog.`;
}
function card(plan, index) {
  const featured = index === Math.floor(Math.min(3, plans().length) / 2);
  return `<article class="plan ${featured ? 'featured' : ''}" data-id="${plan.id}">${featured ? '<div class="plan-badge">Popular</div>' : ''}<div class="plan-name">${plan.name}</div><div class="plan-price">${money(plan.priceLkr)}<span>/mo</span></div><p class="plan-desc">${planSpecs(plan)}</p><ul class="plan-specs"><li><span class="check">✓</span>${plan.cpu} vCPU core${plan.cpu !== 1 ? 's' : ''}</li><li><span class="check">✓</span>${plan.ramGb} GB RAM</li><li><span class="check">✓</span>${storage(plan.storageGb)} ${plan.storageType} storage</li><li><span class="check">✓</span>${storage(plan.bandwidthGb)} transfer</li></ul><a href="${checkoutUrl(plan)}" class="btn ${featured ? 'btn-primary' : 'btn-outline'}">Order now</a></article>`;
}
function renderPlans() {
  const currentPlans = plans();
  // small, medium and larger representative plans; all products remain in the price table
  const indexes = uniqueSorted([0, Math.floor((currentPlans.length - 1) / 2), currentPlans.length - 1]).filter(index => index >= 0);
  $('#featuredPlans').innerHTML = indexes.length ? indexes.map((index, displayIndex) => card(currentPlans[index], displayIndex)).join('') : empty(`We are refreshing our ${categoryName(state.category).toLowerCase()} catalog.`);
  $('#plansTableBody').innerHTML = currentPlans.map(plan => `<tr><td><strong>${plan.name}</strong></td><td>${plan.cpu}</td><td>${plan.ramGb} GB</td><td>${storage(plan.storageGb)} ${plan.storageType}</td><td>${storage(plan.bandwidthGb)}</td><td class="price-cell">${money(plan.priceLkr)}/mo</td><td><a class="btn btn-outline btn-sm" href="${checkoutUrl(plan)}">Order</a></td></tr>`).join('') || '<tr><td colspan="7">No plans available in this category.</td></tr>';
}
function renderAll() { updateSelectors(); updatePrice(); renderPlans(); }

async function initialize() {
  try {
    const [catalogResponse, configResponse] = await Promise.all([fetch('/api/catalog'), fetch('/api/config')]);
    if (!catalogResponse.ok) throw new Error('Catalog unavailable');
    const catalog = await catalogResponse.json();
    state.catalog = catalog.products || [];
    state.config = configResponse.ok ? await configResponse.json() : state.config;
    $('#clientArea').href = state.config.clientAreaUrl || '#client-area'; $('#footerClient').href = state.config.clientAreaUrl || '#client-area';
    const from = state.catalog.filter(p => p.available).sort((a, b) => a.priceLkr - b.priceLkr)[0];
    $('#fromPrice').textContent = from ? money(from.priceLkr) : '—';
    if (String(catalog.updatedAt || '').startsWith('Demo')) { const note = $('#catalogNote'); note.hidden = false; note.textContent = 'Demo catalog shown — configure your server and import InterServer products before accepting orders.'; }
    renderAll();
  } catch (error) {
    console.error(error); $('#featuredPlans').innerHTML = empty('The product catalog could not be loaded. Please try again shortly.'); $('#dynamicPrice').textContent = 'Unavailable'; $('#dynamicSpecs').textContent = 'Catalog temporarily unavailable';
  }
}

document.querySelectorAll('.category-tab').forEach(button => button.addEventListener('click', () => { document.querySelectorAll('.category-tab').forEach(item => item.classList.remove('active')); button.classList.add('active'); state.category = button.dataset.category; state.cpu = null; state.ram = null; renderAll(); }));
$('#seeAllPlans').addEventListener('click', () => { const panel = $('#allPlansWrapper'); panel.classList.toggle('show'); $('#seeAllPlans').textContent = panel.classList.contains('show') ? 'Hide pricing options ↑' : 'See all pricing options →'; });
$('#findPlanBtn').addEventListener('click', () => { const plan = selectedPlan(); const cardElement = plan && document.querySelector(`[data-id="${CSS.escape(plan.id)}"]`); if (cardElement) { document.querySelectorAll('.plan').forEach(card => card.classList.remove('highlight')); cardElement.classList.add('highlight'); cardElement.scrollIntoView({ behavior: 'smooth', block: 'center' }); } else { $('#allPlansWrapper').classList.add('show'); $('#plansTableBody').scrollIntoView({ behavior: 'smooth', block: 'center' }); } });
const root = document.documentElement; const saved = localStorage.getItem('quizontal-theme'); root.dataset.theme = saved || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
function updateThemeIcon() { $('#themeToggle').textContent = root.dataset.theme === 'dark' ? '☀' : '☾'; } updateThemeIcon();
$('#themeToggle').addEventListener('click', () => { root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark'; localStorage.setItem('quizontal-theme', root.dataset.theme); updateThemeIcon(); });
$('#mobileMenuBtn').addEventListener('click', () => $('#navLinks').classList.toggle('open'));
$('#year').textContent = new Date().getFullYear(); initialize();
