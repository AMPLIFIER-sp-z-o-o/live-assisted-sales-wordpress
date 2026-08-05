# Development

Technical documentation for the repo. If you just want to install the plugin on your store, see [README.md](README.md).

The plugin replicates the amper-b2c reference integration contract 1:1: GA4 storefront events, GDPR consent banner, same-origin browser-event proxy, durable outbox for money events, chat widget with signed customer identity and buy-from-chat.

## Layout

- `plugin/amper-live-assisted-sales/` - the plugin (mount or zip this).
- `demo-plugins/amper-demo-language/` - demo-store helper: serves the storefront in Polish or
  English (browser language by default, PL/EN switcher in the Storefront header). Interface only -
  the catalog stays WooCommerce's English sample data, so no content duplication and no paid
  multilingual add-on.
- `build-zip.ps1` / `build-zip.sh` - build `dist/*.zip` for Plugins -> Add New -> Upload (CI: Jenkins).
  Also emits `dist/amper-live-assisted-sales-wporg.zip`, the wordpress.org submission build: identical
  code minus `class-alas-updater.php` and the `Update URI:` header (directory guideline 8 forbids
  third-party update servers, and a foreign `Update URI` would block catalog-served updates). The
  mainline code guards every updater reference with `class_exists`/`file_exists`, so the two builds
  share one source tree - nothing is forked.
- `dev/docker-compose.yml` - MariaDB + WordPress (port **8003**) + wp-cli, with the plugin bind-mounted.
- `dev/provision.sh` - idempotent store setup (WooCommerce + Storefront + sample catalog + PLN/pl_PL + shipping/payments/customer/coupon + plugin config + LAS connection test).
- `dev/tests/` - backend test suites (78 wp-cli unit tests, 28 updater checks, 11 connect-handshake checks, 12 REST edge cases, 20 LAS-parity checks).
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

## Updates

The plugin is not on wordpress.org, so it carries its own updater
(`includes/class-alas-updater.php`) pointed at this repository.

**Releasing is one line: bump `Version:` in `amper-live-assisted-sales.php` and push to `main`.**
Pushing without touching that header changes nothing on any store; bumping it hands the new code to
every install. Updates install **unattended** - a store that wants the button back adds
`add_filter( 'amper_las_auto_update', '__return_false' );`.

Stores check for a new version on exactly the ladder core uses for wordpress.org plugins
(`wp-includes/update.php`): **1 minute** on Dashboard -> Updates, **1 hour** on the Plugins screen,
**2 hours** under cron, **12 hours** otherwise. So 12 hours is the idle worst case, not the wait an
admin sitting in wp-admin sees, and "Check again" on the Updates screen is immediate. Storefront
requests never fetch - a shopper's page load may not block on GitHub - so the check happens on the
next admin page load or cron tick. WooCommerce -> Live Assisted Sales has a **"Check for updates every few
minutes"** box for staging and demo stores, which drops that to two minutes; leave it off on real
shops. Either way WP-Cron has no daemon - it rides on page loads - so a shop with no visitors
updates when its next visitor arrives.

No tags, releases, build step or CI are involved. The version is read from the `Version:` header via
GitHub's Contents API (`/repos/.../contents/...?ref=main`), and the package is GitHub's own
`zipball/main`. Not raw.githubusercontent.com: raw is served with `max-age=300` and ignores
query-string cache busting, so it can answer with the previous version for five minutes after a push.
`upgrader_source_selection` points the installer at the `plugin/amper-live-assisted-sales`
subdirectory inside the archive - without that filter WordPress installs the archive under its own
folder name and leaves a second copy of the plugin beside the original. `dist/*.zip` from
`build-zip.sh` is only for the first install on a store (and for hosts where uploading a zip is the
only way in).

> The obvious alternative is the standard library, [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker).
> It does not fit this repo: it resolves the version file as `basename($pluginFile)` at the
> **repository root**, and our plugin lives in a subdirectory. The class above deliberately mirrors
> its proven behaviour (same three filters, Contents API, zipball, mock `no_update` entry) rather
> than inventing another approach. If the plugin ever gets its own root-level repository, swapping in
> the library is a two-line change.

Before bumping the version, run the change through the local Docker store - the plugin is
bind-mounted there, so the file you edit is the file that executes:

```sh
docker exec amper-las-wp-wordpress-1 php -l <changed file>   # WSL has no PHP CLI
curl -s -o /dev/null -w '%{http_code}' http://localhost:8003/   # 500 means fatal
docker compose -f dev/docker-compose.yml run --rm -v "$PWD/dev/tests:/tests:ro" wpcli \
  wp --path=/var/www/html eval-file /tests/test-plugin.php     # 78 unit tests
docker compose -f dev/docker-compose.yml run --rm -v "$PWD/dev/tests:/tests:ro" wpcli \
  wp --path=/var/www/html eval-file /tests/test-updater.php    # 28 updater checks (hits GitHub)
sh dev/tests/test-rest.sh                                      # 12 REST edge cases
```

Updates cannot be rolled back from here - a bad version is fixed by shipping the next one, so the
gate above is the last chance to catch it. WordPress does protect itself: core refuses an update
whose `Requires PHP` / `Requires at least` / `Requires Plugins` the store fails (the updater passes
all three through), and restores the previous version if the new one fatals on activation.

## Connecting a store

`Connect to AMPER LAS` on the settings page runs an OAuth-style PKCE handshake, so a merchant never
copies a key between two tabs:

1. The plugin sends the merchant to `{LAS}/integrations/wordpress/connect/` with a `state`, a
   `challenge` = base64url(sha256(verifier)), the store URL and a return URL.
2. They sign in (or sign up) on LAS and confirm. LAS registers the store and redirects back with
   `state` and a single-use `code`.
3. The plugin checks `state`, then trades the `code` plus the `verifier` for the write key over a
   direct server-to-server POST to `{LAS}/api/integrations/wordpress/exchange/`.

The write key never travels through the browser - only the code does, and a code is worthless
without the verifier, which never leaves the store's server. LAS refuses a return URL on a different
host than the store being connected, so a code cannot be redirected to a third party, and it burns a
code on the first attempt whether or not that attempt succeeded.

Reconnecting a store you already own hands the same key back rather than creating a second row;
a domain registered under someone else's account is refused outright.

## Public demo store (production)

| What | Value |
|---|---|
| Storefront | https://las-wordpress-demo.ampliapps.com (WordPress 7.0, PHP 8.5, managed hosting - **not** the `deploy/` compose stack) |
| wp-admin | `las-admin` (login link is guarded by a host-side token URL) |
| LAS console | https://live-assisted-sales.com, store **Demo WooCommerce** (id 2) on the `tenant1@example.com` workspace |
| Plugin install | `dist/*.zip` via Plugins -> Add New -> Upload (no SSH/wp-cli on that host, so `provision-prod.sh` does not apply there) |
| Store API key | wp-admin -> WooCommerce -> Live Assisted Sales; source of truth is the store's settings page in the console |

Provisioned to match the local store 1:1 (pl_PL + Europe/Warsaw, Storefront, PLN with space/comma
separators, taxes off, 18 sample products + 7 variations, classic shortcode cart/checkout, zone
Polska with Kurier 15 zl + free over 200 zl, COD + bank transfer, `klient@example.com`, coupon
`las10`), plus two deliberate demo touches now also in both provisioning scripts: front page = Shop
and no "Hello world!" starter content.

## Logins / credentials (local)

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
