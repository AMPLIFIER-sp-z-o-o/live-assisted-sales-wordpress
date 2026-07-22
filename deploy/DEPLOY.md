# Production deployment - WooCommerce demo store + AMPER LAS plugin

Package follows the `amper-customers` conventions (host-level nginx-proxy + Let's
Encrypt, external `nginx-proxy` network). No licensing costs: WordPress, WooCommerce
and Storefront are free; the plugin is ours.

## Prerequisites (one-time, admin side)

1. **Server** with Docker and a running nginx-proxy (like the ampli* servers).
2. **DNS**: `las-demo-wordpress.ampliapps.com` -> `51.38.157.250` (already in place).
3. **SSH access** for the deploy operator.

## Steps

```sh
# on the server:
cd /opt
git clone https://github.com/AMPLIFIER-sp-z-o-o/live-assisted-sales-wordpress.git amper-las-wp
cd amper-las-wp
cp deploy/.env.example deploy/.env && $EDITOR deploy/.env   # domain, passwords, LAS_API_KEY
docker compose -f deploy/docker-compose.prod.yml up -d db wordpress
docker compose -f deploy/docker-compose.prod.yml run --rm wpcli sh /provision.sh
```

`provision-prod.sh` is idempotent - safe to re-run any number of times.
Plugin updates after the initial deploy: `git pull` in `/opt/amper-las-wp`
(the plugin is bind-mounted from the checkout).

## Store API key (LAS_API_KEY)

In the production LAS console (https://live-assisted-sales.com, owner account):
Stores -> Add store -> name "Demo WooCommerce", address `https://demo-wp.ampliapps.com`
-> copy the API key from the store's settings page -> paste into `.env` before
provisioning (or later in wp-admin: WooCommerce -> Live Assisted Sales -> Test
connection).

## Plugin distribution to clients (private)

Clients install the `dist/amper-live-assisted-sales.zip` file via
Plugins -> Add New -> Upload Plugin. The zip is shared with our clients only
(download link; eventually a "WordPress integration" card on the store page in the
LAS console, next to the API key). The plugin is NOT published to the wordpress.org
directory.

## Post-deploy verification

1. `https://<domain>` loads over https with no mixed content (X-Forwarded-Proto is
   handled in WORDPRESS_CONFIG_EXTRA).
2. wp-admin -> WooCommerce -> Live Assisted Sales: "Connection ... works correctly",
   status Connected.
3. Storefront: consent banner + chat bubble; a COD test purchase.
4. LAS console: store "Demo WooCommerce" shows Connected, events in the event history,
   revenue in PLN, chat works (requires the Celery worker with `-Q default` + realtime
   queues on the production las-backend - see the LAS deploy notes).
