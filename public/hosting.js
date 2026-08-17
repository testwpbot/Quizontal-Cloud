/* Quizontal Cloud — web hosting plan cards (used by /hosting and /pricing).
   Renders live products from /api/hosting (billing = source of truth) and
   falls back to the published marketing matrix if billing is unreachable. */
(function () {
  const $ = s => document.querySelector(s);

  // Mirrors the published plan matrix — keep in sync with the billing products.
  const FALLBACK = [
    {
      title: 'Starter', note: 'Perfect for personal websites & blogs', price: 499, icon: '🌐',
      mini: ['1|Websites', '10 GB|Storage', '100 GB|Bandwidth'],
      feats: ['1 Website', '10 GB NVMe SSD Storage', '100 GB Bandwidth', 'Custom Domain', 'Free SSL Certificate', '1 Email Account', 'Weekly Backups', 'LiteSpeed Server', 'Basic DDoS Protection', 'Standard Support'],
      no: ['Website Migration'], orderUrl: '',
    },
    {
      title: 'Business', note: 'Ideal for small businesses & growing brands', price: 999, icon: '⭐',
      mini: ['5|Websites', '50 GB|Storage', '500 GB|Bandwidth'],
      feats: ['5 Websites', '50 GB NVMe SSD Storage', '500 GB Bandwidth', 'Custom Domain', 'Free SSL Certificate', '5 Email Accounts', 'Daily Backups', 'LiteSpeed Server', 'Advanced DDoS Protection', 'Website Migration', 'Priority Support'],
      no: [], orderUrl: '',
    },
    {
      title: 'Premium', note: 'For high-traffic sites & resource-heavy apps', price: 1499, icon: '⚡',
      mini: ['Unlimited|Websites', 'Unlimited|Storage', 'Unlimited|Bandwidth'],
      feats: ['Unlimited Websites', 'Unlimited NVMe SSD Storage', 'Unlimited Bandwidth', 'Custom Domain', 'Free SSL Certificate', '20 Email Accounts', 'Daily Backups', 'LiteSpeed Server', 'Premium DDoS Protection', 'Website Migration', 'VIP Support'],
      no: [], orderUrl: '',
    },
  ];

  const ICONS = ['🌐', '⭐', '⚡'];

  function bullets(description) {
    return String(description || '')
      .split(/\r?\n/).map(line => line.trim()).filter(Boolean)
      .map(line => {
        const bullet = /^([-•✓✗]|\d+[.)]|\*)\s*/.test(line);
        const text = line
          .replace(/^([-•✓✗]|\d+[.)]|\*)\s*/, '')   // strip bullet prefix (incl. leading *)
          .replace(/[*_]/g, '')                     // strip markdown bold/italic markers
          .trim();
        return { text, bullet };
      })
      .filter(line => line.text !== '');
  }
  function miniFromFeats(feats) {
    const pick = re => { const hit = feats.find(f => re.test(f)); return hit ? hit.replace(/\bNVMe\b|\bSSD\b|\bStorage\b/gi, m => m).trim() : null; };
    const sites = pick(/website/i);
    const disk = pick(/(GB|TB|unlimited).*(storage|SSD)/i) || pick(/storage/i);
    const bw = pick(/bandwidth/i);
    const out = [];
    if (sites) out.push(sites.replace(/\s*Websites?/i, '') + '|Websites');
    if (disk) out.push(disk.replace(/\s*(NVMe\s*)?(SSD\s*)?Storage/i, '') + '|Storage');
    if (bw) out.push(bw.replace(/\s*Bandwidth/i, '') + '|Bandwidth');
    return out.slice(0, 3);
  }
  function normalize(product, index) {
    if (product.feats) return product; // fallback rows are already normalized
    const parts = bullets(product.description);
    const feats = parts.filter(p => p.bullet).map(p => p.text);
    const note = (parts.find(p => !p.bullet) || {}).text || '';
    return Object.assign({}, product, {
      title: product.title.replace(/\s*web hosting\s*/i, '').trim() || product.title,
      note,
      feats: feats.length ? feats : note ? [note] : [],
      no: [],
      icon: ICONS[index % ICONS.length],
      mini: feats.length ? miniFromFeats(feats) : [],
    });
  }

  function card(plan, index, featuredIndex) {
    const featured = index === featuredIndex;
    const cta = plan.orderUrl || (QC.config && QC.config.clientAreaUrl) || '/client-area';
    const ctaLabel = plan.orderUrl ? 'Get started' : 'Order in client area';
    // Product #98 is Starter Hosting. The protected trial route performs all
    // eligibility checks; repeat customers receive the ordinary paid path.
    const starterTrial = Number(plan.id) === 98 && plan.orderUrl
      ? `${String(plan.orderUrl).split('/order')[0]}/hosting-trial/start/98`
      : '';
    return `<article class="h-card reveal ${featured ? 'featured' : ''}" data-reveal-delay="${index * 90}">
      ${featured ? '<div class="h-badge">Most popular</div>' : ''}
      <div class="h-icon">${plan.icon}</div>
      <h3>${QC.escape(plan.title)}</h3>
      <p class="h-note">${QC.escape(plan.note || '')}</p>
      <div class="h-price">${QC.money(plan.price)}<small> /month</small></div>
      ${plan.mini && plan.mini.length ? `<div class="h-mini">${plan.mini.map(m => { const [b, s] = String(m).split('|'); return `<div><b>${QC.escape(b)}</b><span>${QC.escape(s || '')}</span></div>`; }).join('')}</div>` : ''}
      <ul class="h-feats">
        ${plan.feats.map(f => `<li><span class="check">✓</span>${QC.escape(f)}</li>`).join('')}
        ${(plan.no || []).map(f => `<li class="no"><span class="check">✗</span>${QC.escape(f)}</li>`).join('')}
      </ul>
      ${starterTrial ? `<a class="button button-primary" href="${QC.escape(starterTrial)}">Start 7-day free trial <span>→</span></a><a class="button button-ghost" style="margin-top:10px" href="${QC.escape(cta)}">Order Starter Hosting <span>→</span></a>` : `<a class="button ${featured ? 'button-primary' : 'button-ghost'}" href="${QC.escape(cta)}">${ctaLabel} <span>→</span></a>`}
    </article>`;
  }

  function renderInto(container, products) {
    const list = (products && products.length ? products : FALLBACK).map(normalize).sort((a, b) => a.price - b.price);
    const featuredIndex = list.length > 2 ? 1 : Math.floor(list.length / 2);
    container.innerHTML = list.map((p, i) => card(p, i, featuredIndex)).join('');
    if (QC.refreshReveals) QC.refreshReveals();
  }

  async function boot(selector) {
    const container = $(selector);
    if (!container) return;
    try {
      const response = await fetch('/api/hosting');
      const data = response.ok ? await response.json() : { products: [] };
      renderInto(container, data.products);
    } catch (error) {
      console.error(error);
      renderInto(container, []);
    }
  }

  window.QCHosting = { boot, renderInto };

  document.addEventListener('DOMContentLoaded', () => {
    ['#hostingPlans', '#hostingPlansPricing'].forEach(sel => { if ($(sel)) boot(sel); });
  });
})();
