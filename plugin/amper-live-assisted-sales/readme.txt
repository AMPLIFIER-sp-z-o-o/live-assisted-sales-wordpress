=== AMPER Live Assisted Sales ===
Contributors: amper
Tags: woocommerce, live chat, analytics, sales, assistant
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WooCommerce store to the AMPER Live Assisted Sales platform: live visitor activity, purchase-intent scoring and a chat widget with AI assistance.

== Description ==

AMPER Live Assisted Sales (LAS) gives your store a live sales console:

* **Real-time visitor activity** - see who is browsing, what they view, search for, add to the cart and buy (GA4 e-commerce event taxonomy).
* **Purchase-intent scoring** - LAS scores every visit (low / medium / high intent) so your team helps the right shopper at the right moment.
* **Live chat widget** - a lightweight chat bubble with AI assistance, product suggestions and buy-from-chat (products suggested in chat land straight in the WooCommerce cart).
* **Privacy first** - a built-in GDPR consent banner for EU visitors (prior consent, one-click decline, preferences modal, Global Privacy Control honoured). Without consent, no behavioural data leaves the browser and no PII is forwarded.

The plugin sends business events (add to cart, checkout, purchase) server-side for accuracy, with a durable outbox and automatic retry, so a temporary network problem never loses a conversion event.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install the ZIP via Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to **WooCommerce → Live Assisted Sales**, enter the LAS platform address and the store API key from your store's page in the AMPER LAS console.
4. Click **Test connection** - once it succeeds, the chat widget appears on your storefront automatically.

== Frequently Asked Questions ==

= Where do I get an API key? =

Create a store in your AMPER Live Assisted Sales console; the key is shown on the store's settings page.

= Does the plugin slow my store down? =

No. Telemetry is batched in the browser; server events are sent asynchronously (non-blocking) or after the response has been flushed, and conversion events are queued in a local outbox with retry.

== Changelog ==

= 1.0.0 =
* Initial release: GA4 event tracking, GDPR consent banner, chat widget embed with signed customer identity and buy-from-chat, durable outbox for money events.
