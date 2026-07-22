#!/bin/sh
# Idempotent PRODUCTION provisioning of the WooCommerce demo store (run inside the
# wpcli container). Mirrors dev/provision.sh but takes everything from env:
#   WP_DOMAIN, WP_ADMIN_PASSWORD, WP_CUSTOMER_PASSWORD,
#   LAS_SERVER_URL, LAS_PUBLIC_URL (usually same), LAS_API_KEY.
set -eu

SITE_URL="https://${WP_DOMAIN}"
ADMIN_USER="admin"
ADMIN_EMAIL="asynowiecki@amplifier.pl"

wp() { command wp --path=/var/www/html "$@"; }

echo "== Waiting for WordPress files =="
i=0
until [ -f /var/www/html/wp-load.php ] || [ "$i" -ge 60 ]; do i=$((i+1)); sleep 2; done

if ! wp core is-installed 2>/dev/null; then
  echo "== Installing WordPress core =="
  wp core install --url="$SITE_URL" --title="Demo WooCommerce" \
    --admin_user="$ADMIN_USER" --admin_password="$WP_ADMIN_PASSWORD" --admin_email="$ADMIN_EMAIL" \
    --skip-email
fi

echo "== Language: Polish =="
wp language core install pl_PL 2>/dev/null || true
wp site switch-language pl_PL 2>/dev/null || wp option update WPLANG pl_PL

echo "== Permalinks =="
wp rewrite structure '/%postname%/' --hard || wp rewrite structure '/%postname%/'

echo "== WooCommerce + Storefront =="
wp plugin install woocommerce --activate 2>/dev/null || wp plugin activate woocommerce
wp theme install storefront --activate 2>/dev/null || wp theme activate storefront
wp language plugin install woocommerce pl_PL 2>/dev/null || true
wp language theme install storefront pl_PL 2>/dev/null || true

echo "== Store basics =="
wp option update woocommerce_store_address "Marszalkowska 1"
wp option update woocommerce_store_city "Warszawa"
wp option update woocommerce_store_postcode "00-001"
wp option update woocommerce_default_country "PL"
wp option update woocommerce_currency "PLN"
wp option update woocommerce_price_thousand_sep " "
wp option update woocommerce_price_decimal_sep ","
wp option update woocommerce_calc_taxes "no"
wp option update woocommerce_enable_guest_checkout "yes"
wp option update woocommerce_enable_signup_and_login_from_checkout "yes"
wp option update woocommerce_enable_myaccount_registration "yes"
wp option update woocommerce_enable_reviews "yes"
wp option update woocommerce_onboarding_opt_in "no" 2>/dev/null || true
wp option update woocommerce_task_list_hidden "yes" 2>/dev/null || true
wp option update woocommerce_coming_soon "no" 2>/dev/null || true

echo "== Classic cart/checkout pages (shortcodes) =="
CART_ID=$(wp option get woocommerce_cart_page_id)
CHECKOUT_ID=$(wp option get woocommerce_checkout_page_id)
[ -n "$CART_ID" ] && wp post update "$CART_ID" --post_content='<!-- wp:shortcode -->[woocommerce_cart]<!-- /wp:shortcode -->' >/dev/null
[ -n "$CHECKOUT_ID" ] && wp post update "$CHECKOUT_ID" --post_content='<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->' >/dev/null

echo "== Sample products =="
PRODUCT_COUNT=$(wp post list --post_type=product --format=count)
if [ "$PRODUCT_COUNT" -lt 5 ]; then
  wp plugin install wordpress-importer --activate 2>/dev/null || true
  SAMPLE=/var/www/html/wp-content/plugins/woocommerce/sample-data/sample_products.xml
  if [ -f "$SAMPLE" ]; then
    wp import "$SAMPLE" --authors=create || echo "!! sample import failed"
  fi
fi

echo "== Shipping: zone Polska (Kurier 15 zl + free over 200 zl) =="
ZONE_EXISTS=$(wp wc shipping_zone list --user="$ADMIN_USER" --format=csv --fields=id,name 2>/dev/null | grep -c "Polska" || true)
if [ "$ZONE_EXISTS" = "0" ]; then
  ZONE_ID=$(wp wc shipping_zone create --name="Polska" --user="$ADMIN_USER" --porcelain)
  wp wc shipping_zone_location update "$ZONE_ID" --location='[{"code":"PL","type":"country"}]' --user="$ADMIN_USER" >/dev/null 2>&1 || true
  METHOD_ID=$(wp wc shipping_zone_method create "$ZONE_ID" --method_id=flat_rate --user="$ADMIN_USER" --porcelain)
  wp wc shipping_zone_method update "$ZONE_ID" "$METHOD_ID" --settings='{"title":"Kurier","cost":"15"}' --user="$ADMIN_USER" >/dev/null
  FLAT_OPT=$(wp db query "SELECT option_name FROM wp_options WHERE option_name LIKE 'woocommerce_flat_rate_%_settings'" --skip-column-names | head -n1)
  [ -n "$FLAT_OPT" ] && wp eval "\$o=get_option('$FLAT_OPT'); \$o['title']='Kurier'; update_option('$FLAT_OPT', \$o);" >/dev/null 2>&1 || true
  FREE_ID=$(wp wc shipping_zone_method create "$ZONE_ID" --method_id=free_shipping --user="$ADMIN_USER" --porcelain)
  wp wc shipping_zone_method update "$ZONE_ID" "$FREE_ID" --settings='{"title":"Darmowa wysyłka","requires":"min_amount","min_amount":"200"}' --user="$ADMIN_USER" >/dev/null
fi

echo "== Payments: COD + bank transfer =="
wp option update --format=json woocommerce_cod_settings '{"enabled":"yes","title":"Za pobraniem","description":"Zaplac przy odbiorze.","instructions":"","enable_for_methods":[],"enable_for_virtual":"yes"}'
wp option update --format=json woocommerce_bacs_settings '{"enabled":"yes","title":"Przelew bankowy","description":"Zaplac przelewem na nasze konto.","instructions":""}'

echo "== Customer account + coupon =="
if ! wp user get klient --field=ID >/dev/null 2>&1; then
  wp user create klient klient@example.com --role=customer --user_pass="$WP_CUSTOMER_PASSWORD" \
    --first_name=Jan --last_name=Kowalski >/dev/null
fi
if ! wp post list --post_type=shop_coupon --title=las10 --format=count | grep -q '^[1-9]'; then
  wp wc shop_coupon create --code=las10 --amount=10 --discount_type=percent \
    --description="Kupon testowy LAS -10%" --user="$ADMIN_USER" >/dev/null 2>&1 || true
fi

echo "== AMPER LAS plugin =="
wp plugin activate amper-live-assisted-sales
wp option update amper_las_enabled "yes"
wp option update amper_las_base_url "$LAS_SERVER_URL"
wp option update amper_las_public_base_url "${LAS_PUBLIC_URL:-}"
if [ -n "$LAS_API_KEY" ]; then
  wp option update amper_las_api_key "$LAS_API_KEY"
fi
echo "== LAS connection test =="
wp eval 'var_export( ALAS_Settings::run_connection_test() );' || true
echo ""
echo "== Done: $SITE_URL (wp-admin: $ADMIN_USER) =="
