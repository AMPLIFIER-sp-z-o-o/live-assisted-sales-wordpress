# Development

This document describes the development workflow, local environment, architecture and release process for the WordPress plugin. To install the plugin on a store, see [README.md](README.md).

The plugin mirrors the amper-b2c reference integration contract 1:1: GA4 storefront events, GDPR consent banner, same-origin browser-event proxy, durable outbox for money events, chat widget with signed customer identity and buy-from-chat.

## Architecture

The plugin is intentionally thin - it adapts WooCommerce to the LAS ingest/widget contract and keeps no state beyond its options and the outbox table.

```
shopper's browser                        store's WordPress (PHP)
┌─────────────────────┐                 ┌──────────────────────────────────┐
│ tracker.js          │── batched ─────▶│ REST proxy                       │── server-to-server ──▶
│ chat widget         │   telemetry     │ /wp-json/amper-las/v1/events     │
│ consent banner      │                 ├──────────────────────────────────┤        LAS API
└─────────────────────┘                 │ WooCommerce hooks ─▶ payloads    │
                                        │   money events ─▶ outbox ─retry─ │── server-to-server ──▶
                                        └──────────────────────────────────┘
```

Component map (`includes/`):

- `class-alas-tracking.php` + `class-alas-payloads.php` - WooCommerce hooks -> GA4 event payloads; business/money events are emitted server-side as the source of truth.
- `class-alas-dispatch.php` + `class-alas-outbox.php` - central dispatch: money events go through the durable outbox, everything else is sent best-effort.
- `class-alas-rest.php` - same-origin proxy for browser telemetry; the browser never talks to LAS directly and never sees the API key.
- `class-alas-frontend.php` + `class-alas-consent.php` - tracker/widget embed, signed customer identity (`window.LAS_CUSTOMER`), GDPR consent banner.
- `class-alas-client.php` - HTTP client for the LAS ingest API and the HMAC identity signer.
- `class-alas-connect.php` + `class-alas-onboarding.php` + `class-alas-settings.php` - one-click PKCE connect, activation-to-connected flow, settings page.
- `class-alas-updater.php` - self-updates from this repository.

## Repository layout

- `plugin/amper-live-assisted-sales/` - the plugin (mount or zip this).
- `demo-plugins/amper-demo-language/` - demo-store helper: PL/EN storefront interface (browser language + header switcher); the catalog stays WooCommerce's English sample data.
- `build-zip.ps1` / `build-zip.sh` - build `dist/*.zip` archives. CI (`.github/workflows/build-zips.yml`) rebuilds them on every push to main and refreshes the rolling `latest` release, so the README's download link (`releases/download/latest/amper-live-assisted-sales.zip`) always serves the current build. Also emits `dist/amper-live-assisted-sales-wporg.zip` for the wordpress.org submission: same code minus the self-updater and the `Update URI:` header (directory guideline 8), guarded by `class_exists`/`file_exists` so both builds share one source tree.
- `dev/docker-compose.yml` - MariaDB + WordPress (port **8003**) + wp-cli, plugin bind-mounted.
- `dev/provision.sh` - idempotent store setup (WooCommerce + Storefront + sample catalog + PLN/pl_PL + shipping/payments/customer/coupon + plugin config + LAS connection test).
- `dev/tests/` - test suites: wp-cli unit tests, updater checks, connect-handshake checks, REST edge cases and LAS-parity checks.
- `deploy/` - production compose stack (nginx-proxy + Let's Encrypt); `DEVOPS-HANDOFF.md` is the runbook.

## Local test environment

Prereqs: Docker, las-backend on :8001 (`make dev` in `las-backend/`), and a TrackedSite for `http://localhost:8003` (the key below is pinned to tenant1 store id 27 - re-provisioning never rotates it).

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

### Logins

| Resource | URL | Login | Password |
|---|---|---|---|
| WordPress admin | http://localhost:8003/wp-admin | `admin` | `admin` |
| Test customer | http://localhost:8003/my-account | `klient@example.com` | `klient1234` |
| LAS console | http://localhost:8001 | `tenant1@example.com` | `demo1234` |

Coupon: `las10` (-10%).

## Releasing

**Bump `Version:` in `amper-live-assisted-sales.php` and push to `main`.** No tags, manual builds or release workflows are required. Pushing without touching the header changes nothing on any store. Updates install unattended; a store can opt out with `add_filter( 'amper_las_auto_update', '__return_false' );`.

Test gate before bumping - the plugin is bind-mounted into the Docker store, so the file you edit is the file that executes:

```sh
docker exec amper-las-wp-wordpress-1 php -l <changed file>   # WSL has no PHP CLI
curl -s -o /dev/null -w '%{http_code}' http://localhost:8003/   # 500 means fatal
docker compose -f dev/docker-compose.yml run --rm -v "$PWD/dev/tests:/tests:ro" wpcli \
  wp --path=/var/www/html eval-file /tests/test-plugin.php     # unit tests
docker compose -f dev/docker-compose.yml run --rm -v "$PWD/dev/tests:/tests:ro" wpcli \
  wp --path=/var/www/html eval-file /tests/test-updater.php    # updater checks (hits GitHub)
sh dev/tests/test-rest.sh                                      # REST edge cases
```

There is no automated rollback - a bad version is fixed by shipping the next one, so this gate is the last chance to catch it. Core's own safety nets still apply: `Requires PHP` / `Requires at least` / `Requires Plugins` are enforced, and a version that fatals on activation is rolled back by WordPress.

### How the updater works

`includes/class-alas-updater.php` (the plugin is not on wordpress.org, so it updates itself from this repo):

- Reads `Version:` from `main` via GitHub's Contents API; the package is GitHub's `zipball/main`. (Not raw.githubusercontent.com - raw caches for 5 minutes and would serve the previous version right after a push.)
- `upgrader_source_selection` points the installer at the `plugin/amper-live-assisted-sales` subdirectory inside the archive; without it WordPress would install a second copy beside the original.
- Stores check for updates according to WordPress core's built-in schedule (`wp-includes/update.php`): 1 min on Dashboard -> Updates, 1 h on the Plugins screen, 2 h under cron, 12 h otherwise. Storefront requests never trigger a check, and WP-Cron rides on page loads, so a shop with no visitors updates when its next visitor arrives. The settings page has a "Check for updates every few minutes" box for staging/demo stores - leave it off on real shops.
- The standard library (plugin-update-checker) was rejected only because it expects the plugin file at the repo root; the class mirrors its behaviour, so if the plugin ever gets its own root-level repo, swapping it in is a two-line change.

## Connect handshake

`Connect to AMPER LAS` runs an OAuth-style PKCE flow so the merchant never copies a key:

1. The plugin sends the merchant to `{LAS}/integrations/wordpress/connect/` with a `state`, a `challenge` = base64url(sha256(verifier)), the store URL and a return URL.
2. The merchant signs in and confirms; LAS registers the store and redirects back with `state` and a single-use `code`.
3. The plugin trades the `code` plus the `verifier` for the write key, server-to-server, at `{LAS}/api/integrations/wordpress/exchange/`.

The write key never passes through the browser. LAS refuses a return URL on a different host than the store, burns the `code` on first use (successful or not), hands the same key back when a store reconnects, and refuses a domain owned by another account.

## Public demo store (production)

| Resource | Value |
|---|---|
| Storefront | https://las-wordpress-demo.ampliapps.com (WordPress 7.0, PHP 8.5, managed hosting - **not** the `deploy/` stack) |
| wp-admin | `las-admin` (login link guarded by a host-side token URL) |
| LAS console | https://live-assisted-sales.com, store **Demo WooCommerce** (id 2), workspace `tenant1@example.com` |
| Plugin install | zip upload only - no SSH/wp-cli on that host |
| Store API key | source of truth: the store's settings page in the console |

Provisioned 1:1 like the local store (same catalog, shipping, payments, coupon, front page = Shop).

## Plugin settings (WooCommerce -> Live Assisted Sales)

- **LAS platform address** - server-side URL (Docker: `http://host.docker.internal:8001`).
- **Public widget address** (optional) - browser-side URL when it differs (Docker: `http://localhost:8001`); empty in production.
- **Store API key** - the TrackedSite write_key; "Test connection" fetches the public widget key (`site_pk_...`) - the widget appears only after a successful test.
- **Chat widget** (since 1.0.18) - hides only the chat bubble; visitor tracking and the live console keep working.
- **Proxy / CDN** (since 1.0.18) - trust the X-Forwarded-For chain for shopper IPs (`amper_las_trust_proxy`); the `amper_las_trust_forwarded_for` filter still takes precedence for existing installs. Off by default because the header is client-supplied on a store without a rewriting proxy.

## Event taxonomy (GA4, same as b2c)

- **Server-side events** (accurate, durable): `view_item_list`, `view_item`, `search` (with `results_count`), `view_cart`, `begin_checkout`, `add_shipping_info`, `add_payment_info`, `add_to_cart`, `remove_from_cart`, `purchase` (with `metadata.order`), optional `add_to_wishlist` (YITH hook).
- **Browser-side events** via tracker.js + REST proxy (`/wp-json/amper-las/v1/events`): `session_start`, `select_item`, `scroll_depth`, `page_ping`, `session_end`.
- **Money events** (`add_to_cart`, `remove_from_cart`, `begin_checkout`, `purchase`) go through the `{prefix}amper_las_outbox` table: delivery is first attempted during request shutdown, then a WP-Cron relay retries every minute, up to 8 attempts.
- **Consent (GDPR)**: EU opt-in banner, non-EU opt-out, GPC honoured. Without consent, no browser events are collected and server events carry no email/IP. Visitor/session ids live in the `las_visitor_id` / `las_session_id` cookies; purchase attribution is pinned into order meta.
