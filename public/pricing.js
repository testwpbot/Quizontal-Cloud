/* Quizontal Cloud — /pricing engine: VPS configuration table + domain TLD table.
   Hosting cards on the same page are rendered by hosting.js. */
(function () {
  const $ = s => document.querySelector(s);
  const storage = gb => Number(gb) >= 1000 ? `${(Number(gb) / 1000).toFixed(Number(gb) % 1000 ? 1 : 0)} TB` : `${Number(gb)} GB`;
  const state = { catalog: [], category: 'general', tlds: [], filter: '', sort: 'price-asc', orderUrl: '' };

  /* ---------- VPS table ---------- */
  function vpsRows() {
    const rows = state.catalog
      .filter(plan => plan.category === state.category && plan.available)
      .sort((a, b) => Number(a.priceLkr) - Number(b.priceLkr));
    if (!rows.length) return `<tr><td colspan="7">No configurations currently listed in this category. Try another tab.</td></tr>`;
    return rows.map(plan => {
      const base = state.orderUrl;
      const url = base && !base.startsWith('#')
        ? (base.includes('{productId}') ? base.replace('{productId}', encodeURIComponent(plan.providerProductId)) : `${base}${base.includes('?') ? '&' : '?'}product=${encodeURIComponent(plan.providerProductId)}`)
        : '/vps';
      return `<tr>
        <td><strong>${QC.escape(plan.name)}</strong></td>
        <td>${QC.escape(plan.cpu)}</td>
        <td>${QC.escape(plan.ramGb)} GB</td>
        <td>${QC.escape(storage(plan.storageGb))} ${QC.escape(plan.storageType)}</td>
        <td>${QC.escape(storage(plan.bandwidthGb))}</td>
        <td class="price-cell">${QC.money(plan.priceLkr)}/mo</td>
        <td><a class="button button-ghost button-small" href="${QC.escape(url)}">Configure</a></td>
      </tr>`;
    }).join('');
  }
  function renderVps() {
    const body = $('#pricingVpsTable tbody');
    if (body) body.innerHTML = vpsRows();
  }

  /* ---------- Domain TLD table ---------- */
  function tldRows() {
    let rows = state.tlds.filter(t => t.tld.toLowerCase().includes(state.filter));
    rows = rows.sort((a, b) => {
      if (state.sort === 'name') return a.tld.localeCompare(b.tld);
      if (state.sort === 'price-desc') return Number(b.register) - Number(a.register);
      return Number(a.register) - Number(b.register);
    });
    if (!rows.length) return `<tr><td colspan="5">No extensions match “${QC.escape(state.filter)}”.</td></tr>`;
    return rows.map(t => `<tr>
      <td><strong>${QC.escape(t.tld)}</strong></td>
      <td class="price-cell">${t.register != null ? QC.money(t.register) : '—'}</td>
      <td>${t.renew != null ? QC.money(t.renew) : '—'}</td>
      <td>${t.transfer != null ? QC.money(t.transfer) : '—'}</td>
      <td><a class="button button-ghost button-small" href="/domains#find">Search</a></td>
    </tr>`).join('');
  }
  function renderTlds() {
    const body = $('#pricingTldTable tbody');
    if (body) body.innerHTML = tldRows();
    const count = $('#pricingTldCount');
    if (count) {
      const shown = state.tlds.filter(t => t.tld.toLowerCase().includes(state.filter)).length;
      count.textContent = `${shown} extension${shown === 1 ? '' : 's'}`;
    }
  }

  /* ---------- Boot ---------- */
  document.addEventListener('DOMContentLoaded', async () => {
    document.querySelectorAll('#pricingVpsTabs .category-tab').forEach(btn => btn.addEventListener('click', () => {
      document.querySelectorAll('#pricingVpsTabs .category-tab').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      state.category = btn.dataset.category;
      renderVps();
    }));
    const filter = $('#pricingTldFilter');
    if (filter) filter.addEventListener('input', e => { state.filter = e.target.value.trim().toLowerCase(); renderTlds(); });
    const sort = $('#pricingTldSort');
    if (sort) sort.addEventListener('change', e => { state.sort = e.target.value; renderTlds(); });

    try {
      const cfg = await QC.loadConfig();
      state.orderUrl = cfg.orderUrl || '';
    } catch (_) { /* table still renders with /vps links */ }

    if ($('#pricingVpsTable')) {
      try {
        const res = await fetch('/api/catalog');
        const data = res.ok ? await res.json() : { products: [] };
        state.catalog = Array.isArray(data.products) ? data.products : [];
        if (String(data.updatedAt || '').startsWith('Demo')) {
          const note = $('#pricingCatalogNote');
          if (note) { note.hidden = false; note.textContent = 'Demo catalog shown — import the current infrastructure catalog before accepting orders.'; }
        }
      } catch (error) { console.error(error); }
      renderVps();
    }

    if ($('#pricingTldTable')) {
      try {
        const res = await fetch('/api/domains/tlds');
        const data = res.ok ? await res.json() : { tlds: [] };
        state.tlds = (Array.isArray(data.tlds) ? data.tlds : []).filter(t => t && t.tld);
        const tools = $('#pricingDomainTools');
        if (tools && state.tlds.length) tools.hidden = false;
      } catch (error) { console.error(error); }
      renderTlds();
    }
  });
})();
