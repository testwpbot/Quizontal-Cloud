import express from 'express';
import { spawn } from 'node:child_process';
import { getCatalog } from './scripts/catalog.js';

const app = express();
const port = Number(process.env.PORT || 3000);
app.disable('x-powered-by');
app.use(express.json({ limit: '16kb' }));
app.use(express.static('public', { extensions: ['html'], maxAge: process.env.NODE_ENV === 'production' ? '1h' : 0 }));

app.get('/api/catalog', async (_req, res, next) => {
  try { res.json(await getCatalog()); } catch (error) { next(error); }
});
app.get('/api/config', (_req, res) => {
  const billing = (process.env.FOSSBILLING_URL || '').replace(/\/$/, '');
  res.json({
    clientAreaUrl: billing ? `${billing}/index.php?_url=/client/login` : '#client-area',
    orderUrl: process.env.FOSSBILLING_ORDER_URL || billing || '#plans'
  });
});
app.get('/api/health', async (_req, res) => res.json({ ok: true, catalog: (await getCatalog()).updatedAt }));

app.post('/api/admin/import', (req, res) => {
  const token = process.env.IMPORT_TOKEN;
  if (!token || req.get('authorization') !== `Bearer ${token}`) return res.status(401).json({ error: 'Unauthorized' });
  const worker = spawn(process.execPath, ['scripts/import-products.js'], { env: process.env, stdio: 'pipe' });
  let output = ''; let errors = '';
  worker.stdout.on('data', chunk => { output += chunk; });
  worker.stderr.on('data', chunk => { errors += chunk; });
  worker.on('close', code => code === 0
    ? res.json({ ok: true, message: output.trim() })
    : res.status(502).json({ error: 'Catalog import failed', detail: errors.trim() || output.trim() }));
});
app.use((error, _req, res, _next) => { console.error(error); res.status(500).json({ error: 'Internal server error' }); });
app.listen(port, '0.0.0.0', () => console.log(`Quizontal Cloud running on port ${port}`));
