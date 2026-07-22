"""LAS-side parity tests: the WooCommerce plugin must speak EXACTLY the b2c dialect.

Run from las-backend: uv run python <this file>
Verifies: event taxonomy vs b2c source, envelope/order-metadata key sets, HMAC
identity accepted by las-backend's real verifier, ingest auth edge cases, event_id
dedupe, sensitive-key redaction, PII-gate results in stored rows, heartbeat.
"""
import ast
import json
import os
import re
import sys
import urllib.error
import urllib.request

sys.path.insert(0, r"C:\AMPER-FULL\las-backend")
os.environ.setdefault("DJANGO_SETTINGS_MODULE", "las.settings")

import django

django.setup()

from django_tenants.utils import schema_context

from apps.live.chat import verify_customer_identity
from apps.live.models import StorefrontEvent
from apps.tenants.models import TrackedSite

PASS = FAIL = 0


def ok(cond, label, detail=""):
    global PASS, FAIL
    if cond:
        PASS += 1
        print(f"PASS  {label}")
    else:
        FAIL += 1
        print(f"FAIL  {label}  [{detail}]")


B2C_EVENTS = open(r"C:\AMPER-FULL\amper-b2c\apps\live_assisted_sales\events.py", encoding="utf-8").read()
PLUGIN_PAYLOADS = open(
    r"C:\AMPER-FULL\amper-las-woocommerce\plugin\amper-live-assisted-sales\includes\class-alas-payloads.php",
    encoding="utf-8",
).read()

# --- 1. Event taxonomy parity (parsed from both sources) -------------------
m = re.search(r"SUPPORTED_EVENT_TYPES = \{(.*?)\}", B2C_EVENTS, re.S)
b2c_types = set(re.findall(r'"([a-z_]+)"', m.group(1)))
m = re.search(r"SUPPORTED_EVENT_TYPES = array\((.*?)\);", PLUGIN_PAYLOADS, re.S)
plugin_types = set(re.findall(r"'([a-z_]+)'", m.group(1)))
ok(plugin_types == b2c_types, "event taxonomy identical to b2c", f"diff={plugin_types ^ b2c_types}")

m = re.search(r"CART_CONTEXT_EXCLUDED_EVENT_TYPES = \{(.*?)\}", B2C_EVENTS, re.S)
b2c_excl = set(re.findall(r'"([a-z_]+)"', m.group(1)))
m = re.search(r"CART_CONTEXT_EXCLUDED_EVENT_TYPES = array\((.*?)\);", PLUGIN_PAYLOADS, re.S)
plugin_excl = set(re.findall(r"'([a-z_]+)'", m.group(1)))
ok(plugin_excl == b2c_excl, "cart-context exclusions identical to b2c", f"diff={plugin_excl ^ b2c_excl}")

# LAS-side model must accept every plugin type.
las_types = {c[0] for c in StorefrontEvent.EventType.choices}
ok(plugin_types <= las_types, "all plugin types known to las-backend", f"unknown={plugin_types - las_types}")

# Durable (outbox) sets match b2c client.py.
b2c_client = open(r"C:\AMPER-FULL\amper-b2c\apps\live_assisted_sales\client.py", encoding="utf-8").read()
b2c_durable = set(re.findall(r'"([a-z_]+)"', re.search(r"DURABLE_EVENT_TYPES = \{(.*?)\}", b2c_client, re.S).group(1)))
plugin_dispatch = open(
    r"C:\AMPER-FULL\amper-las-woocommerce\plugin\amper-live-assisted-sales\includes\class-alas-dispatch.php",
    encoding="utf-8",
).read()
plugin_durable = set(
    re.findall(r"'([a-z_]+)'", re.search(r"DURABLE_EVENT_TYPES = array\((.*?)\);", plugin_dispatch, re.S).group(1))
)
ok(plugin_durable == b2c_durable, "durable/outbox event set identical to b2c", f"diff={plugin_durable ^ b2c_durable}")

with schema_context("public"):
    store = TrackedSite.objects.get(normalized_domain="localhost:8003")

    # --- 2. Envelope key parity on REAL stored events ----------------------
    B2C_ENVELOPE_KEYS = {
        "event_id", "event_type", "visitor_id", "session_id", "occurred_at",
        "url", "page", "product", "category", "search", "cart", "cursor", "metadata",
    }
    ORDER_KEYS = {
        "order_id", "tracking_token", "status", "subtotal", "discount_total",
        "delivery_cost", "total", "currency", "coupon_code",
    }
    purchase = StorefrontEvent.objects.filter(tracked_site=store, event_type="purchase").order_by("occurred_at").first()
    ok(purchase is not None, "purchase event stored")
    if purchase:
        ok(set((purchase.metadata or {}).get("order", {}).keys()) == ORDER_KEYS,
           "metadata.order keys == b2c order_metadata",
           str(set((purchase.metadata or {}).get("order", {}).keys()) ^ ORDER_KEYS))
        item_keys = {"product_id", "name", "sku", "url", "quantity", "price", "line_total", "currency"}
        ok(all(set(i.keys()) == item_keys for i in (purchase.cart or {}).get("items", [])),
           "purchase cart item keys == b2c")
        v = sum(float(i["price"]) * i["quantity"] for i in (purchase.cart or {}).get("items", []))
        ok(abs(v - 45.0) < 0.01, "funnel value invariant sum(price*qty)", str(v))

    # --- 3. HMAC identity verified by las-backend's real code path ---------
    signed = json.loads(sys.argv[1]) if len(sys.argv) > 1 else None
    if signed:
        customer = {
            "external_id": "3", "email": "klient@example.com", "name": "Jan Kowalski",
            "exp": signed["exp"], "sig": signed["sig"],
        }
        # Success = the sanitized identity dict keeps the claims; with enforcement on,
        # an invalid signature demotes the claim to anonymous (external_id/email empty).
        identity = verify_customer_identity(store, customer)
        ok(identity.get("external_id") == "3" and identity.get("email") == "klient@example.com",
           "PHP HMAC signature accepted by verify_customer_identity", repr(identity))
        forged = verify_customer_identity(store, dict(customer, sig="0" * 64))
        ok(not forged.get("external_id") and not forged.get("email"), "forged signature demoted to anonymous")
        expired = verify_customer_identity(store, dict(customer, exp=1000000))
        ok(not expired.get("external_id") and not expired.get("email"), "expired signature demoted to anonymous")

    # --- 4. Ingest API edge cases (server-to-server, like the plugin) ------
    INGEST = "http://localhost:8001/api/ingest/store-events/"

    def post(payload, key=store.write_key):
        req = urllib.request.Request(
            INGEST, data=json.dumps(payload).encode(),
            headers={"Content-Type": "application/json", "Authorization": f"Bearer {key}"},
        )
        try:
            with urllib.request.urlopen(req, timeout=10) as r:
                return r.status, json.loads(r.read().decode() or "{}")
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read().decode() or "{}")

    code, _ = post({"event_type": "page_ping", "visitor_id": "parity-v", "session_id": "parity-s",
                    "event_id": "11111111-1111-1111-1111-111111111111", "url": "http://localhost:8003/x"})
    ok(code in (200, 201, 202), "ingest accepts plugin-style event", str(code))

    code2, _ = post({"event_type": "page_ping", "visitor_id": "parity-v", "session_id": "parity-s",
                     "event_id": "11111111-1111-1111-1111-111111111111", "url": "http://localhost:8003/x"})
    dup_count = StorefrontEvent.objects.filter(tracked_site=store, event_id="11111111-1111-1111-1111-111111111111").count()
    ok(dup_count == 1, "same event_id ingested twice -> stored once", f"count={dup_count} code2={code2}")

    code, _ = post({"event_type": "page_ping", "visitor_id": "x"}, key="totally-wrong-key")
    ok(code == 403, "wrong write_key -> 403", str(code))

    code, _ = post({"event_type": "not_a_real_type", "visitor_id": "x"})
    ok(code == 400, "unknown event type -> 400 at LAS", str(code))

    # --- 5. Redaction of sensitive keys ------------------------------------
    post({"event_type": "page_ping", "visitor_id": "parity-redact", "session_id": "parity-s",
          "event_id": "22222222-2222-2222-2222-222222222222", "url": "http://localhost:8003/x",
          "metadata": {"api_key": "SECRET-LEAK", "note": "keepme"}})
    red = StorefrontEvent.objects.filter(tracked_site=store, event_id="22222222-2222-2222-2222-222222222222").first()
    ok(red is not None and (red.metadata or {}).get("api_key") == "[redacted]",
       "sensitive metadata keys redacted by LAS", repr((red.metadata or {}).get("api_key") if red else None))

    # --- 6. PII gate visible in stored rows --------------------------------
    no_consent = StorefrontEvent.objects.filter(
        tracked_site=store, metadata__consent=False).order_by("-occurred_at").first()
    if no_consent:
        u = (no_consent.metadata or {}).get("user", {})
        ok("email" not in u, "consent=false rows carry no email")
        ip = (no_consent.metadata or {}).get("client_ip", "")
        ok(ip in ("", "127.0.0.1"), "consent=false rows carry only sender IP backfill", ip)
    consent_yes = StorefrontEvent.objects.filter(
        tracked_site=store, metadata__consent=True, metadata__user__authenticated=True).first()
    purchase_auth = StorefrontEvent.objects.filter(
        tracked_site=store, metadata__user__email="jan.testowy@example.com").exists()

    # --- 7. Heartbeat / verification ---------------------------------------
    store.refresh_from_db()
    ok(store.is_verified, "store verified by plugin traffic")
    ok(store.connection_state == TrackedSite.ConnectionState.CONNECTED, "connection state CONNECTED (<48h heartbeat)")

print(f"\nRESULT: {PASS} passed, {FAIL} failed")
sys.exit(1 if FAIL else 0)
