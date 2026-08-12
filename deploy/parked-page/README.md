# Quizontal Cloud — branded parked page

One self-contained file (`index.html`, no external assets) shown for every
freshly sold domain. The billing panel points new domains at your VPS IP
automatically (`Quizontaldomains` module, `parked_ip` extension setting,
default `216.219.95.93`) — this page is what visitors see instead of the
registrar's default parking page. The domain name in the headline is filled
in by the page itself from the browser's address bar.

Your existing website on the same VPS is **not affected**: the catch-all site
below only answers hostnames that no other site on the server claims.

## 1. Copy the file to the VPS

```bash
sudo mkdir -p /var/www/quizontal-parked
# upload index.html there, e.g. from your machine:
#   scp index.html user@216.219.95.93:/tmp/
sudo cp /tmp/index.html /var/www/quizontal-parked/index.html
sudo chown -R www-data:www-data /var/www/quizontal-parked   # nginx (Debian/Ubuntu)
# on some systems the web user is 'apache' — adjust if your distro differs
```

## 2a. If the VPS runs nginx

Create `/etc/nginx/sites-available/quizontal-parked` and enable it
(`ln -s` into `sites-enabled`), or paste into your main config:

```nginx
# Catch-all: answers ANY hostname that has no other server block.
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;

    root /var/www/quizontal-parked;
    index index.html;

    location / { try_files $uri $uri/ /index.html; }
}
```

Then: `sudo nginx -t && sudo systemctl reload nginx`

Only ONE `default_server` may exist per port. If nginx -t complains about a
duplicate, find the other block (`grep -R "default_server" /etc/nginx`) and
remove the keyword there — your named sites keep working exactly as before
because their `server_name` takes priority for their own hostnames.

## 2b. If the VPS runs Apache

Create `/etc/apache2/sites-available/000-quizontal-parked.conf`
(the `000-` prefix makes it load FIRST — the first vhost is Apache's default):

```apache
<VirtualHost *:80>
    ServerName catch-all.local
    DocumentRoot /var/www/quizontal-parked
    <Directory /var/www/quizontal-parked>
        AllowOverride None
        Require all granted
    </Directory>
</VirtualHost>
```

Then: `sudo a2ensite 000-quizontal-parked && sudo systemctl reload apache2`

## 3. Verify

```bash
curl -H "Host: some-test-domain.xyz" http://216.219.95.93/ | grep -o "Something great is coming."
curl http://quizontal.site/    # once the panel's branding pass has run
```

## Notes

- **HTTP only is expected.** You cannot get TLS certificates for customer
  domains you don't control yet, so `https://brand-new-domain` shows a
  browser warning until the owner sets up their own site. That's how every
  hosting company's parked page works; the swept zone only carries HTTP
  A records.
- **HTTPS, optional:** if some parked domains should work over HTTPS before
  their owners set up sites, set up a reverse proxy with on-demand TLS
  (e.g. Caddy's `on_demand_tls`) — out of scope for this file.
- The panel automation never touches the parked page: it only creates the
  `@` and `*` A records pointing at this IP when a domain is activated, and
  only when no other record already claims those names.
