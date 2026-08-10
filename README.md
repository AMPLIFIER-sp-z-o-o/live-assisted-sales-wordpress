# AMPER Live Assisted Sales - WordPress / WooCommerce plugin

**English** | [Polski](README.pl.md)

AMPER Live Assisted Sales connects your WooCommerce store to the [AMPER platform](https://live-assisted-sales.com), so you can watch visitors in real time, chat with them and grow your sales.

- **see in real time how many visitors are in your store** - what they view and search for, what they have in the cart and which orders they have placed,
- **know who to help first** - every visit receives a purchase-probability score (low / medium / high),
- **chat with your customers** - a chat bubble in the store, AI assistance, product suggestions and adding products to the cart without leaving the conversation,
- **collect EU visitors' consent** - with the built-in consent banner; without consent, no behavioural data and no personal data are sent to the AMPER platform.

An AMPER Live Assisted Sales account is required - once your store is connected, all features are available right away. The account is free and the first 7 days are a trial.

## What you need

- WordPress 6.2 or newer, PHP 8.0 or newer,
- the **WooCommerce** plugin active (this plugin will not activate without it),
- internet access allowing communication with the AMPER platform,
- the latest plugin version - [amper-live-assisted-sales.zip](https://github.com/AMPLIFIER-sp-z-o-o/live-assisted-sales-wordpress/releases/download/latest/amper-live-assisted-sales.zip).

## Step-by-step installation

1. Download the plugin file: [amper-live-assisted-sales.zip](https://github.com/AMPLIFIER-sp-z-o-o/live-assisted-sales-wordpress/releases/download/latest/amper-live-assisted-sales.zip).
2. Log in to your store's admin panel (`yourstore.com/wp-admin`).
3. Go to **Plugins → Add New Plugin → Upload Plugin**.
4. Choose the `amper-live-assisted-sales.zip` file, click **Install Now**, then **Activate Plugin**.
5. After activation, the plugin opens its settings page for you. If it does not, click the **Settings** link next to the plugin, or go to **WooCommerce → Live Assisted Sales**.
6. Click **Connect to AMPER LAS**. You will be taken to live-assisted-sales.com, where you sign in (or create an account) and confirm the connection with one click. The API key is saved automatically - nothing to copy or paste.
7. Done. The chat bubble appears in your store, and the console at live-assisted-sales.com shows your traffic in real time.

You can also connect the store manually: on the settings page, paste the **Store API key** from your store's page in the console, save, then click **Test connection**.

## Frequently asked questions

**Will the plugin slow my store down?**
No. Data is sent asynchronously and does not block page loads, and sales events (cart, order) go through a local queue with retry - temporary network problems do not cause the loss of events.

**Do I need to keep an eye on updates?**
No. New versions are installed automatically, just like other WordPress plugins.

**How do I pause sending data?**
In **WooCommerce → Live Assisted Sales**, untick **Integration enabled**. Deactivating the plugin also stops everything, and uninstalling it removes its settings and the event queue from your WordPress database.

**Can I hide only the chat bubble?**
Yes. In **WooCommerce → Live Assisted Sales**, untick **Chat widget** - the bubble disappears, while visitor tracking and the live console keep working.

**What about my customers' data?**
EU visitors see a consent banner first - without consent, no behavioural data and no personal data are sent to the AMPER platform. For details, see our [Terms of Service](https://live-assisted-sales.com/terms/) and [Privacy Policy](https://live-assisted-sales.com/privacy/).

## Support

Something not working, or have a question? Write to us: [support@ampliapps.com](mailto:support@ampliapps.com).

---

Technical documentation (development environment, tests, releasing, integration architecture): [DEVELOPMENT.md](DEVELOPMENT.md).
