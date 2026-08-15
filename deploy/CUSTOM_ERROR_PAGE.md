# Quizontal Cloud branded error page

FOSSBilling ships two error surfaces that both carry FOSSBilling branding out of
the box. This repository replaces both with an on-brand Quizontal Cloud page
(dark theme, purple→cyan gradient, Space Grotesk / DM Sans typography, Quizontal
logo and a link back to the storefront):

| Surface | Stock file | What renders it | How it is replaced |
|---|---|---|---|
| Hardcoded error page | `library/FOSSBilling/ErrorPage.php` (`renderPage()`) | Uncaught exceptions in production (the dark "Powered by FOSSBilling" page) | Drop-in `ErrorPage.php` shipped under `deploy/fossbilling/error-page/` |
| Client-area error template | `themes/huraga/html/error.html.twig` | 404s and app-level errors (`show404()` / `errorResponse()`) | `error.html.twig` shipped under `deploy/fossbilling/theme-overrides/` |

> The client-area template already inherits the Quizontal `layout_default.html.twig`
> override, so it only needs its body swapped — the header/footer/fonts stay branded
> automatically.

## Install

On the production PHP host (same machine as FOSSBilling):

```bash
cd /var/www/quizontal-cloud
sudo -E FOSSBILLING_DIR=/var/www/billing bash deploy/install-custom-error-page.sh
```

The installer:

1. Backs up the original to `ErrorPage.php.fossbilling-original` (once, so a later
   rollback returns true stock).
2. Replaces `ErrorPage.php` with the branded copy (after verifying it really is a
   FOSSBilling `ErrorPage` class, so it never clobbers a foreign file).
3. Copies `error.html.twig` into every **client** theme's `html_custom/` directory
   and removes any stale copy from **admin** themes (which must never receive client
   templates).
4. Injects the storefront URL into the "Back to Quizontal Cloud" button (see below).
5. Clears FOSSBilling's on-disk cache.

### Storefront link

The "Back to Quizontal Cloud" button resolves its URL in this order:

1. `STOREFRONT_URL` environment variable, e.g.
   `sudo -E STOREFRONT_URL=https://quizontalcloud.example bash deploy/install-custom-error-page.sh`
2. `APP_URL` from the Laravel `.env`.
3. Otherwise the button is omitted and the page only links back to the client area.

`localhost` / `127.0.0.1` values are ignored so a dev `APP_URL` never becomes a broken
production link.

## DirectAdmin / no SSH (manual install)

You don't need shell access. Both files can be placed with the **DirectAdmin File
Manager** (or any FTP client). This is the same result as running the installer.

> The two files are already in this repository:
> - `deploy/fossbilling/error-page/ErrorPage.php`
> - `deploy/fossbilling/theme-overrides/error.html.twig`
>
> Download them from GitHub (Code ▸ Download ZIP, or open each file ▸ "Raw" ▸ save),
> then follow the steps below.

**1. Locate your FOSSBilling folder.**

In File Manager, open `domains/` → your billing domain (or wherever FOSSBilling was
installed) → the folder that contains `index.php`, a `library/` folder and a
`themes/` folder (commonly `public_html`, or a subfolder like `public_html/billing`).

**2. Back up and replace the core error page.**

- Go into `library/FOSSBilling/`.
- Right-click `ErrorPage.php` → **Rename** → `ErrorPage.php.backup` (this is your backup).
- Click **Upload**, and upload the new `ErrorPage.php` (from `deploy/fossbilling/error-page/`).
- Optional — add the storefront link: click the uploaded `ErrorPage.php` → **Edit**, and
  set the URL near the top:

  ```php
  private static string $storefrontUrl = 'https://quizontalcloud.lk';
  ```

  (Leave it as `''` if you don't want the "Back to Quizontal Cloud" button.)

**3. Add the client-area error template.**

- Go into `themes/huraga/`.
- If there is no `html_custom` folder, create it (**New folder** → `html_custom`).
- Enter `html_custom` and upload `error.html.twig` (from
  `deploy/fossbilling/theme-overrides/`).
- Optional — set the storefront link: **Edit** `error.html.twig` and change this line:

  ```twig
  {% set qc_store_url = 'https://quizontalcloud.lk' %}
  ```

  (Leave it as `''` to hide the button.)

**4. Clear FOSSBilling's cache.**

- Go into the `data/cache/` folder (next to `library/` and `themes/`).
- Select all files inside and delete them (leave the `.gitignore` file if there is one).

Done — no shell required. Skip step 2's "replace" after a FOSSBilling upgrade: the
upgrade overwrites `ErrorPage.php`, so just re-upload it (your `.backup` stays intact).

## Roll back

```bash
sudo -E FOSSBILLING_DIR=/var/www/billing bash deploy/install-custom-error-page.sh --restore
```

This restores `ErrorPage.php` from the backup, removes the theme override, and clears
the cache.

Manual equivalent (File Manager): rename `ErrorPage.php.backup` back to `ErrorPage.php`,
delete `themes/*/html_custom/error.html.twig`, and clear `data/cache/`.

## Upgrades

A FOSSBilling upgrade overwrites `library/FOSSBilling/ErrorPage.php`. Re-run the
installer after every upgrade:

```bash
sudo -E FOSSBILLING_DIR=/var/www/billing bash deploy/install-custom-error-page.sh
```

If a future FOSSBilling release changes the internals of the `ErrorPage` class (the
error-code tables, category ranges, or the `renderPage()` signature), the branded copy
kept in this repository may need to be re-synced with the new upstream file — only the
HTML inside `renderPage()` is customized, so the diff is intentionally small.

## Notes

- The `error.html.twig` theme override is *also* copied by the existing
  `deploy/install-quizontal-bank-transfer.sh` (it installs every
  `deploy/fossbilling/theme-overrides/*.twig`). Both scripts are idempotent.
- Both files ship with the storefront URL set to `''` so a manual install is always
  safe; the installer injects the real URL when one is configured, and manual users
  edit the single clearly-marked line in each file.
- Error classification, Sentry "report" flags, the instance ID and the
  "Show original message" debug toggle are all preserved — only the HTML and links
  change.
