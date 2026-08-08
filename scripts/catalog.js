import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const dataPath = path.join(root, 'data', 'products.json');
export const samplePath = path.join(root, 'data', 'catalog.sample.json');

const number = (value, fallback = 0) => {
  const parsed = Number(String(value ?? '').replace(/[^0-9.-]/g, ''));
  return Number.isFinite(parsed) ? parsed : fallback;
};
const first = (source, keys, fallback) => {
  for (const key of keys) if (source?.[key] !== undefined && source[key] !== null && source[key] !== '') return source[key];
  return fallback;
};

export async function getCatalog() {
  try { return JSON.parse(await fs.readFile(dataPath, 'utf8')); }
  catch { return JSON.parse(await fs.readFile(samplePath, 'utf8')); }
}

function unwrapProducts(payload) {
  if (Array.isArray(payload)) return payload;
  for (const key of ['products', 'plans', 'vps', 'data', 'items', 'orders']) {
    if (Array.isArray(payload?.[key])) return payload[key];
  }
  // InterServer ordering responses can group plans by platform.
  if (payload && typeof payload === 'object') {
    return Object.entries(payload)
      .filter(([, value]) => Array.isArray(value))
      .flatMap(([platform, items]) => items.map(item => ({ ...item, platform: item.platform || platform })));
  }
  return [];
}

function categoryFor(product) {
  const combined = `${product.platform || ''} ${product.type || ''} ${product.name || ''} ${product.os || ''}`.toLowerCase();
  if (combined.includes('hyperv') || combined.includes('windows')) return 'windows';
  if (combined.includes('storage') || combined.includes('hdd') || combined.includes('sata')) return 'storage';
  return 'general';
}

export function normalizeProducts(payload, rate, profitUsd) {
  const source = unwrapProducts(payload);
  return source.map((raw, index) => {
    const category = categoryFor(raw);
    const basePriceUsd = number(first(raw, ['monthly_price', 'monthlyPrice', 'price', 'cost', 'price_usd'], 0));
    const cpu = number(first(raw, ['cpu', 'cores', 'vcpu', 'cpu_cores'], 1), 1);
    const ramMb = number(first(raw, ['ram_mb', 'memory_mb'], 0));
    const ramGb = number(first(raw, ['ram_gb', 'ram', 'memory'], ramMb ? ramMb / 1024 : 1), 1);
    const storageGb = number(first(raw, ['storage_gb', 'disk_gb', 'disk', 'storage'], 0));
    const bandwidthGb = number(first(raw, ['bandwidth_gb', 'transfer_gb', 'bandwidth', 'transfer'], 0));
    const storageType = category === 'storage' ? 'SATA' : 'NVMe';
    const providerProductId = String(first(raw, ['id', 'product_id', 'plan_id', 'sku', 'name'], `interserver-${index + 1}`));
    const retailPriceUsd = Math.round((basePriceUsd + profitUsd) * 100) / 100;
    return {
      id: `interserver-${providerProductId}`.replace(/[^a-zA-Z0-9_-]/g, '-'),
      providerProductId,
      name: String(first(raw, ['name', 'title', 'description', 'plan_name'], `InterServer VPS ${index + 1}`)),
      category, cpu, ramGb, storageGb, storageType, bandwidthGb,
      basePriceUsd, retailPriceUsd,
      priceLkr: Math.round(retailPriceUsd * rate),
      available: first(raw, ['available', 'active'], true) !== false
    };
  }).filter(product => product.basePriceUsd > 0);
}

export async function saveCatalog(catalog) {
  await fs.mkdir(path.dirname(dataPath), { recursive: true });
  await fs.writeFile(dataPath, `${JSON.stringify(catalog, null, 2)}\n`, 'utf8');
}
