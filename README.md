# live-assisted-sales-wordpress

WordPress/WooCommerce plugin for the AMPER Live Assisted Sales (LAS) platform plus a
local Docker test store and production deployment. Repo:
https://github.com/AMPLIFIER-sp-z-o-o/live-assisted-sales-wordpress The plugin replicates the amper-b2c reference integration
contract 1:1: GA4 storefront events, GDPR consent banner, same-origin browser-event
proxy, durable outbox for money events, chat widget with signed customer identity and
buy-from-chat.

## Layout

- `plugin/amper-live-assisted-sales/` - the plugin (mount or zip this).
- `build-zip.ps1` / `build-zip.sh` - build `dist/amper-live-assisted-sales.zip` for Plugins -> Add New -> Upload (CI: Jenkins).
- `dev/docker-compose.yml` - MariaDB + WordPress (port **8003**) + wp-cli, with the plugin bind-mounted.
- `dev/provision.sh` - idempotent store setup (WooCommerce + Storefront + sample catalog + PLN/pl_PL + shipping/payments/customer/coupon + plugin config + LAS connection test).
- `dev/tests/` - backend test suites (78 wp-cli unit tests, 12 REST edge cases, 20 LAS-parity checks).
- `deploy/` - production deployment (nginx-proxy + Let's Encrypt): `DEVOPS-HANDOFF.md` is the step-by-step runbook.

## Local test environment

Prereqs: Docker, las-backend running on :8001 (`make dev` in `las-backend/`), and a
TrackedSite for `http://localhost:8003` (write_key below was created via shell under
tenant1, store id 27, pinned so re-provisioning never rotates it).

```sh
cd dev
docker compose up -d db wordpress
LAS_API_KEY='wpwoo-local-demo-write-key-Zt8mKq4Pv1Rx7Ln2Sd9Bc6Fh3Jg5Wy0AeUiOpQaTsX' \
docker compose run --rm -e LAS_API_KEY \
  -e LAS_SERVER_URL=http://host.docker.internal:8001 \
  -e LAS_PUBLIC_URL=http://localhost:8001 \
  wpcli sh /provision.sh
```

(Git Bash on Windows: prefix the `run` command with `MSYS_NO_PATHCONV=1`.)

## Logins / credentials

| What | URL | Login | Password |
|---|---|---|---|
| Store (frontend) | http://localhost:8003 | - | - |
| WordPress admin | http://localhost:8003/wp-admin | `admin` | `admin` |
| Test customer (storefront "My account") | http://localhost:8003/my-account | `klient@example.com` | `klient1234` |
| LAS console (store "Demo WooCommerce") | http://localhost:8001 | `tenant1@example.com` | `demo1234` |

Coupon: `las10` (-10%). Store API key (TrackedSite write_key) - see the provisioning
command above; the plugin settings page shows it under WooCommerce -> Live Assisted Sales.

## Plugin settings (WooCommerce -> Live Assisted Sales)

- **LAS platform address** - server-side URL (in Docker: `http://host.docker.internal:8001`).
- **Public widget address** - browser-side URL when it differs (in Docker: `http://localhost:8001`); empty in production.
- **Store API key** - the TrackedSite write_key; "Test connection" fetches and stores the
  public widget key (`site_pk_...`) - the widget appears only after a successful test.

## Event taxonomy (GA4, same as b2c)

Server-side (accurate, durable): `view_item_list`, `view_item`, `search` (with
`results_count`), `view_cart`, `begin_checkout`, `add_shipping_info`, `add_payment_info`,
`add_to_cart`, `remove_from_cart`, `purchase` (with `metadata.order`), optional
`add_to_wishlist` (YITH hook). Browser-side via tracker.js + REST proxy
(`/wp-json/amper-las/v1/events`): `session_start`, `select_item`, `scroll_depth`,
`page_ping`, `session_end`. Money events (`add_to_cart`, `remove_from_cart`,
`begin_checkout`, `purchase`) go through the `{prefix}amper_las_outbox` table with a
WP-Cron relay every minute (max 8 attempts); delivery is attempted immediately at
request shutdown.

Consent (GDPR): EU visitors get a prior-consent banner (opt-in); non-EU is opt-out; GPC
honoured. Without consent no browser telemetry is sent and server events carry no
email/IP. LAS visitor/session ids live in `las_visitor_id` / `las_session_id` cookies;
purchase attribution is pinned into order meta.
