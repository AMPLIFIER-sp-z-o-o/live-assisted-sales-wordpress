/**
 * Buy-from-chat bridge. The LAS chat widget is a cross-origin iframe and cannot touch
 * this store's cart, so its "Add to cart" button asks the host page to do it via a
 * `las:add-to-cart` DOM event. We claim the event (preventDefault), add the suggested
 * product through the WooCommerce AJAX endpoint and acknowledge the result so the
 * widget button can show success. If we can't (endpoint missing / product needs
 * options), we either leave the event unclaimed (widget falls back to opening the
 * product page) or report failure honestly.
 */
(function () {
  "use strict";

  var config = window.amperLasCartBridge || {};
  var ajaxUrl = String(config.ajaxUrl || "");

  window.addEventListener("las:add-to-cart", function (event) {
    var detail = event.detail || {};
    var product = detail.product || {};
    var respond = typeof detail.respond === "function" ? detail.respond : function () {};
    var productId = String(product.id || "").trim();
    if (!productId || !ajaxUrl) {
      return; // unclaimed -> widget opens the product page instead
    }
    event.preventDefault();

    var body = new URLSearchParams();
    body.set("product_id", productId);
    body.set("quantity", "1");

    fetch(ajaxUrl, { method: "POST", credentials: "same-origin", body: body })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (data && data.error) {
          // Product needs options (variable product etc.) - open its page so the
          // shopper can still buy, and report a graceful failure to the widget.
          if (data.product_url) {
            try { window.open(data.product_url, "_blank", "noopener"); } catch (e) {}
          }
          respond({ ok: false });
          return;
        }
        // Refresh mini-cart fragments so the header cart count updates in place.
        if (data && data.fragments) {
          try {
            Object.keys(data.fragments).forEach(function (selector) {
              document.querySelectorAll(selector).forEach(function (el) {
                el.outerHTML = data.fragments[selector];
              });
            });
          } catch (e) {}
          if (window.jQuery) {
            try { window.jQuery(document.body).trigger("added_to_cart", [data.fragments, data.cart_hash]); } catch (e) {}
          }
        }
        respond({ ok: true });
      })
      .catch(function () { respond({ ok: false }); });
  });
})();
