import { normalizeProducts, saveCatalog } from './catalog.js';

const required = ['INTERSERVER_API_URL', 'INTERSERVER_API_KEY', 'EXCHANGERATE_API_KEY'];
const missing = required.filter(key => !process.env[key]);
if (missing.length) throw new Error(`Missing required environment variables: ${missing.join(', ')}`);

const apiUrl = process.env.INTERSERVER_API_URL.replace(/\/$/, '');
const providerResponse = await fetch(`${apiUrl}/vps/order`, {
  headers: { 'X-API-KEY': process.env.INTERSERVER_API_KEY, Accept: 'application/json' }
});
if (!providerResponse.ok) throw new Error(`InterServer catalog request failed (${providerResponse.status})`);
const providerPayload = await providerResponse.json();

const exchangeResponse = await fetch(`https://v6.exchangerate-api.com/v6/${encodeURIComponent(process.env.EXCHANGERATE_API_KEY)}/latest/USD`);
if (!exchangeResponse.ok) throw new Error(`ExchangeRate-API request failed (${exchangeResponse.status})`);
const exchangePayload = await exchangeResponse.json();
const rate = Number(exchangePayload?.conversion_rates?.LKR);
if (!Number.isFinite(rate) || rate <= 0) throw new Error('ExchangeRate-API response did not include a valid USD → LKR rate');

const products = normalizeProducts(providerPayload, rate, Number(process.env.PROFIT_USD || 1));
if (!products.length) throw new Error('No paid VPS products found. Inspect the InterServer /vps/order response and update the normalizer if its schema differs.');
await saveCatalog({ updatedAt: new Date().toISOString(), exchangeRate: rate, profitUsd: Number(process.env.PROFIT_USD || 1), products });
console.log(`Imported ${products.length} InterServer VPS products at USD/LKR ${rate}.`);
