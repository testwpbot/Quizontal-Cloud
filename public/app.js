/* Quizontal Cloud — Cloud VPS catalog engine (home featured grid + /vps builder).
   Shared chrome (menu, reveals, config, client links) lives in site.js. */
(function () {
  const $ = selector => document.querySelector(selector);
  const state = { catalog: [], category: 'general', planId: null, config: { orderUrl: '#plans' } };
  const money = QC.money;
  const escapeHtml = QC.escape;
  const storage = gb => Number(gb) >= 1000 ? `${(Number(gb) / 1000).toFixed(Number(gb) % 1000 ? 1 : 0)} TB` : `${Number(gb)} GB`;
  const categoryName = category => ({ general: 'KVM Linux', storage: 'KVM Storage', windows: 'Hyper-V Windows' }[category] || category);
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

  /* ---------- Builder (only runs where the builder markup exists: /vps) ---------- */
  const hasBuilder = () => !!$('#planSelector');

  function updatePlanSelector() {
    if (!hasBuilder()) return;
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
    if (!hasBuilder()) return;
    $('#dynamicPrice').innerHTML = plan ? `${money(plan.priceLkr)}<span>/mo</span>` : 'Unavailable';
    $('#dynamicSpecs').textContent = plan ? planSpecs(plan) : `No ${categoryName(state.category)} products are currently available.`;
  }

  function card(plan, displayIndex) {
    const featured = displayIndex === 2;
    return `<article class="plan reveal ${featured ? 'featured' : ''}" data-id="${escapeHtml(plan.id)}" data-reveal-delay="${displayIndex * 60}">
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
    const grid = $('#featuredPlans');
    if (grid) {
      grid.innerHTML = current.length
        ? current.slice(0, grid.dataset.limit ? Number(grid.dataset.limit) : 6).map((plan, i) => card(plan, i)).join('')
        : empty(`We are refreshing the ${categoryName(state.category)} catalog.`);
      QC.refreshReveals();
    }
    const body = $('#plansTableBody');
    if (body) {
      body.innerHTML = current.map(plan => `<tr data-row-id="${escapeHtml(plan.id)}"><td><strong>${escapeHtml(plan.name)}</strong></td><td>${escapeHtml(plan.cpu)}</td><td>${escapeHtml(plan.ramGb)} GB</td><td>${escapeHtml(storage(plan.storageGb))} ${escapeHtml(plan.storageType)}</td><td>${escapeHtml(storage(plan.bandwidthGb))}</td><td class="price-cell">${money(plan.priceLkr)}/mo</td><td><a class="button button-ghost button-small" href="${escapeHtml(checkoutUrl(plan))}">Configure</a></td></tr>`).join('') || '<tr><td colspan="7">No plans available in this category.</td></tr>';
    }
  }
  function renderAll() { updatePlanSelector(); updatePrice(); renderPlans(); }

  async function initialize() {
    try {
      const [catalogResponse, cfg] = await Promise.all([fetch('/api/catalog'), QC.loadConfig()]);
      if (!catalogResponse.ok) throw new Error('Catalog unavailable');
      const catalog = await catalogResponse.json();
      state.catalog = Array.isArray(catalog.products) ? catalog.products : [];
      state.config = Object.assign(state.config, cfg);
      const source = $('#planSource');
      if (source) source.textContent = catalog.source === 'billing_database' ? 'Current available plans and monthly prices' : 'Latest available configurations';
      const lowest = state.catalog.filter(plan => plan.available).sort((a, b) => Number(a.priceLkr) - Number(b.priceLkr))[0];
      const lowestPrice = lowest ? money(lowest.priceLkr) : '—';
      ['#fromPrice', '#heroFromPrice', '#trioVpsFrom'].forEach(sel => { const el = $(sel); if (el) el.textContent = lowestPrice; });
      if (String(catalog.updatedAt || '').startsWith('Demo')) { const note = $('#catalogNote'); if (note) { note.hidden = false; note.textContent = 'Demo catalog shown — import the current infrastructure catalog before accepting orders.'; } }
      renderAll();
    } catch (error) {
      console.error(error);
      const grid = $('#featuredPlans');
      if (grid) grid.innerHTML = empty('The product catalog could not be loaded. Please try again shortly.');
      if (hasBuilder()) { $('#dynamicPrice').textContent = 'Unavailable'; $('#dynamicSpecs').textContent = 'Catalog temporarily unavailable'; }
    }
  }

  /* ---------- Bindings (each guarded so any subset of the UI can exist) ---------- */
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.category-tab').forEach(button => button.addEventListener('click', () => {
      document.querySelectorAll('.category-tab').forEach(item => item.classList.remove('active'));
      button.classList.add('active');
      state.category = button.dataset.category;
      state.planId = null;
      renderAll();
    }));
    const native = $('#planSelector');
    if (native) native.addEventListener('change', e => { state.planId = e.target.value; updatePlanSelector(); updatePrice(); });
    const trigger = $('#planSelectTrigger');
    if (trigger) trigger.addEventListener('click', () => { const s = $('#customPlanSelect'); s.classList.toggle('open'); trigger.setAttribute('aria-expanded', s.classList.contains('open') ? 'true' : 'false'); });
    document.addEventListener('click', e => { const s = $('#customPlanSelect'); if (s && trigger && !e.target.closest('#customPlanSelect')) { s.classList.remove('open'); trigger.setAttribute('aria-expanded', 'false'); } });
    const seeAll = $('#seeAllPlans');
    if (seeAll) seeAll.addEventListener('click', () => { const panel = $('#allPlansWrapper'); panel.classList.toggle('show'); seeAll.innerHTML = panel.classList.contains('show') ? 'Hide all plans <span>↑</span>' : 'See all plans <span>↓</span>'; });
    const find = $('#findPlanBtn');
    if (find) find.addEventListener('click', () => {
      const plan = selectedPlan(); if (!plan) return;
      const planCard = document.querySelector(`.plan[data-id="${CSS.escape(String(plan.id))}"]`);
      if (planCard) { document.querySelectorAll('.plan').forEach(item => item.classList.remove('highlight')); planCard.classList.add('highlight'); planCard.scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
      const wrapper = $('#allPlansWrapper');
      if (wrapper) wrapper.classList.add('show');
      const row = document.querySelector(`[data-row-id="${CSS.escape(String(plan.id))}"]`);
      if (row) { row.style.background = 'rgba(227,28,100,.1)'; row.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    });
    initialize();
  });
})();
