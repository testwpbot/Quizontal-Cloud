# Quizontal Cloud

A dark/light VPS storefront for **Quizontal Cloud**, built to display InterServer VPS catalog prices in Sri Lankan Rupees (LKR), after a fixed USD profit markup. It links customers to FossBilling for sign-in and checkout.

## Quick start

```bash
cp .env.example .env
npm install
npm run dev
```

Open `http://localhost:3000`. The site initially displays the clearly-labelled demo catalog in `data/catalog.sample.json` so it remains usable before the first import.

## Production configuration

1. Create a FossBilling installation (normally at `billing.your-domain.com`). Set `FOSSBILLING_URL` to it. The **Client area** link uses its normal client-login route. Set `FOSSBILLING_ORDER_URL` to your exact FossBilling order URL after you have created matching products there.
2. Set the InterServer API URL, API key, ExchangeRate-API key, a long random `IMPORT_TOKEN`, and `PROFIT_USD=1` in `.env`. Keep `.env` outside git.
3. Import catalog data with either:

   ```bash
   npm run import:products
   # or
   curl -X POST https://your-domain/api/admin/import -H "Authorization: Bearer YOUR_IMPORT_TOKEN"
   ```

   The importer requests InterServer's VPS ordering-information endpoint (`/vps/order`) with `X-API-KEY`, obtains the USD→LKR rate from ExchangeRate-API, then saves catalog prices as `(provider monthly USD + PROFIT_USD) × USD/LKR`. It supports several common response shapes but does not create provider orders.
4. Review `data/products.json` after importing, then create the equivalent products in FossBilling. Set an order URL that maps product IDs to your FossBilling product/order flow. This separation is intentional: billing should collect payment before a provider order is placed.

## Security and fulfilment

- The browser never sees provider or exchange-rate API keys. The import runs server-side only and its HTTP trigger is bearer-token protected.
- Restrict `/api/admin/import` at your reverse proxy as well, and run it from a scheduled job (e.g. daily) rather than exposing it widely.
- FossBilling and InterServer provisioning require a server-side module/webhook and FossBilling API credentials, which are not included here. Do **not** issue InterServer provisioning requests from this public storefront.
- Make sure your advertised rate, $1 markup policy, taxes, payment gateway charges, refund policy, and LKR rounding comply with your business requirements.

## Routes

- `GET /api/catalog` — public normalized catalog
- `GET /api/health` — service health
- `POST /api/admin/import` — refresh catalog; `Authorization: Bearer <IMPORT_TOKEN>`
