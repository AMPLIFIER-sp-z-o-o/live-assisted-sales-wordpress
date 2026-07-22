# DevOps handoff - AMPER LAS WooCommerce demo store (production)

Goal: stand up a production WordPress + WooCommerce demo store with our private
AMPER LAS plugin, on our own infrastructure (no WordPress.com, no license costs).
Source of truth is the git repo:

**https://github.com/AMPLIFIER-sp-z-o-o/live-assisted-sales-wordpress**

Estimated time: ~15 minutes.

## 1. Prerequisites

- A server with Docker Compose v2 and our standard **nginx-proxy** stack running
  (Let's Encrypt companion, external Docker network named `nginx-proxy`) - same
  setup as the ampli* customer servers.
- **DNS**: `las-demo-wordpress.ampliapps.com` already points at `51.38.157.250`
  (verified; that host's nginx-proxy answers 503 for the vhost, waiting for our
  container). Deploy on that server.
- Read access to the GitHub repo from the server (deploy key or https token).

## 2. Deploy

```sh
# on the server
cd /opt
sudo git clone https://github.com/AMPLIFIER-sp-z-o-o/live-assisted-sales-wordpress.git amper-las-wp
cd amper-las-wp
cp deploy/.env.example deploy/.env
vi deploy/.env
#   WP_DOMAIN=las-demo-wordpress.ampliapps.com
#   DB_PASSWORD / DB_ROOT_PASSWORD      <- generate (e.g. openssl rand -hex 16)
#   WP_ADMIN_PASSWORD                   <- generate; report it back to Adrian
#   WP_CUSTOMER_PASSWORD=klient1234     <- demo account, keep as is
#   LAS_API_KEY                         <- the API key of the "Demo wordpress" store
#                                          in the LAS console (Tomek's account, "Finish
#                                          setup"); leave CHANGE_ME if you don't have
#                                          it - it gets set later from wp-admin
docker compose -f deploy/docker-compose.prod.yml up -d db wordpress
docker compose -f deploy/docker-compose.prod.yml run --rm wpcli sh /provision.sh
```

The provisioning script is idempotent - safe to re-run if anything fails midway
(e.g. transient network error while downloading sample product images).

## 3. Verify

- `https://<WP_DOMAIN>` responds over HTTPS (valid Let's Encrypt cert, no redirect
  loop - X-Forwarded-Proto is already handled in the compose file).
- `https://<WP_DOMAIN>/shop/` shows the sample catalog (17 products, prices in zl).
- `https://<WP_DOMAIN>/wp-admin` login works with `admin` / WP_ADMIN_PASSWORD.

## 4. Updates (after the initial deploy)

The plugin directory is bind-mounted straight from the repo checkout, so:

```sh
cd /opt/amper-las-wp && git pull
```

is a live plugin update - no container rebuild, no downtime. This can be wired
into Jenkins later; until then it is a one-liner.

## 5. Hand back to Adrian

Report: the final store URL, the WP_ADMIN_PASSWORD, and (optionally, if you want
the AI agent to operate the server directly for future maintenance/updates)
authorize this SSH public key for the deploy user and send back `user@host:port`:

```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJdNsUh8GcQIesHHXvKm0AjDjj/y3+NOIEIDSgSr4n+t asynowiecki@amplifier.pl
```

The remaining steps (creating the store in the LAS console, pasting the store API
key into the plugin, end-to-end event/chat testing) are done from the browser and
do not require server access.

## Notes

- Data lives in named volumes `db_data` / `wp_data`; `docker compose down` without
  `-v` keeps the store intact.
- Nothing is exposed except port 80 to nginx-proxy; the DB sits on an internal
  network only.
- Client-facing plugin ZIPs are built with `build-zip.sh` (see `dist/`), not from
  this server.
