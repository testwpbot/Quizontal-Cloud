# Quizontal Cloud Domain Manager (`quizontaldomains`)

Customer self-service DNS record management for domains bought through the
store. Records are read and written **live at the registrar** through the
Quizontal Porkbun adapter, so whatever a customer changes in the billing panel
is changed on the domain's real zone within seconds.

## What customers get

A **DNS Records** tab on the domain manage page
(*Services → domain → DNS Records*) with:

- Live record list (type, name, content, TTL, priority) straight from the
  registrar — refresh button included; nothing is cached or mirrored in the
  billing DB.
- Add / inline edit / delete for `A`, `AAAA`, `CNAME`, `ALIAS`, `MX`, `TXT`,
  `SRV` and `CAA` records. `NS` records stay registrar-owned by design, and
  upstream SOA/default-NS records are never shown as editable.
- Friendly validation per record type (IPv4/IPv6 checks, hostname checks,
  SRV `weight port target`, CAA `flags tag value`), `@`/blank = zone root,
  full hostnames pasted with the zone suffix are auto-shortened.
- TTL clamped to the registrar account range (600–86400s).
- A warning banner when the domain uses custom nameservers, because zone edits
  only affect live DNS while the domain points at registrar DNS.
- Duplicate guard on create (retries/double-clicks return the existing record
  instead of stacking clones) and delete that tolerates repeats.

## Architecture

| Layer | Piece | Role |
|---|---|---|
| Adapter | `library/Registrar/Adapter/Porkbun.php` | `supportsDnsRecords()`, `dnsListRecords()`, `dnsCreateRecord()`, `dnsEditRecord()`, `dnsDeleteRecord()` — idempotency-keyed writes against Porkbun DNS API v3, names normalised to short form (`www.example.com` → `www`). |
| Module | `modules/Quizontaldomains/Service.php` | Re-proves order ownership on every call, resolves the TLD registrar adapter via the stock Servicedomain service, validates input, delegates to the adapter. |
| API | `modules/Quizontaldomains/Api/Client.php` | `supported`, `records`, `create`, `update`, `delete` — client-session gated, `order_id` ownership enforced inside the service. |
| UI | `theme-overrides/mod_servicedomain_manage.html.twig` | Stock manage page + guarded DNS tab. The tab only renders when the module is active **and** the domain's adapter supports DNS; otherwise the page is byte-identical to stock behaviour. |

## Branding engine (v1.1)

Keeps every sold domain visibly "Quizontal" — customers never meet the
upstream registrar's parking assets:

1. **Parking sweep** — deletes the upstream's default parking records, and
   only those: a strict whitelist match on its parking IPs and its own
   hostnames. Customer records can never match the whitelist.
2. **Welcome records** — plants `A` records for the root and the wildcard
   pointing at the store's branded parked page
   (`parked_ip` extension setting, default `216.219.95.93`, see
   `deploy/parked-page/`). Skipped automatically when a real record already
   claims the name, so a customer's own settings are never overwritten.
3. **Nameserver sync** — fills the service `ns1`–`ns4` fields from the
   registrar, so the Nameservers tab shows the current nameservers instead
   of empty fields (common after a manual-domain adoption). Only fills empty
   fields; customer edits always win.

Runs at three moments, always idempotent:

- `onAfterAdminOrderActivate` event hook (auto-registered by the stock Hook
  module — the activation script's `hook/batch_connect` call wires it).
- A throttled auto pass (max once an hour per order) when the customer opens
  the manage page — self-heals domains that were not yet opted into the
  registrar API at activation time.
- On demand via the staff API.

Every pass is fail-soft: partial failures are logged to Admin → Activity and
retried by the next pass; they can never break a page render or an order
activation.

## Client API surface

```
POST /api/client/quizontaldomains/supported  {order_id}
POST /api/client/quizontaldomains/records    {order_id}   (NS rows are hidden — they live in the Nameservers tab)
POST /api/client/quizontaldomains/create     {order_id, type, name, content, ttl, prio}
POST /api/client/quizontaldomains/update     {order_id, record_id, type, name, content, ttl, prio}
POST /api/client/quizontaldomains/delete     {order_id, record_id}
```

## Staff API surface

```
POST /api/admin/quizontaldomains/apply_branding  {order_id}
```

Returns a summary (`ns_synced`, `swept`, `branded`, `deferred`). Use it to
re-brand a domain immediately without waiting for a page view or activation.

## Deploy

The main installer copies the module; the bank-transfer activation script
enables it:

```bash
sudo -E bash deploy/install-interserver-provisioning.sh   # modules + theme override + registrar adapter
bash deploy/activate-quizontal-bank-transfer.sh           # activates quizontaldomains
sudo rm -rf /opt/lampp/htdocs/data/cache/*
```

## Caveats

- Works for any active/late-renewal domain service whose TLD runs on the
  Quizontal Porkbun adapter — including domains **adopted** from manual
  porkbun.com purchases on the same account.
- DNSSEC, URL forwarding and email forwarding exist in the Porkbun API but are
  intentionally not exposed yet.
- If the module is ever deactivated, the DNS tab disappears and no page breaks
  (the theme probes `guest.extension_is_on` before calling the module API).
