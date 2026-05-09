=== Trondealer Payments ===
Contributors: trondealer
Tags: woocommerce, payments, cryptocurrency, usdt, usdc, stablecoin, tron, solana, ethereum, polygon, bsc
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept USDT and USDC stablecoin payments in WooCommerce across 9 blockchains via the Trondealer V2 API.

== Description ==

Trondealer Payments is a WooCommerce payment gateway that accepts USDT and USDC stablecoins across 9 blockchains: TRON, Ethereum, BSC, Polygon, Arbitrum, Base, Optimism, Avalanche, and Solana.

**Key features:**

* 9 blockchains supported out of the box
* USDT and USDC, paid 1:1 against USD order totals (no rate engine, no slippage)
* Per-order deposit address generation
* HMAC-signed webhook delivery for instant order updates
* Polling fallback via WP-Cron in case webhooks are missed
* Support for both classic checkout and WooCommerce Blocks
* White-label option in settings
* Connection test built into the admin panel

**Requirements:**

* A Trondealer account at https://trondealer.com (free signup)
* WooCommerce 7.0+
* Store currency set to USD

== Installation ==

1. Install and activate the plugin.
2. Sign up at https://trondealer.com to obtain an API key.
3. Go to WooCommerce > Settings > Payments > Trondealer.
4. Paste your API key and run the connection test.
5. Choose which networks and assets to enable.

== Frequently Asked Questions ==

= Do I need to run my own blockchain node? =

No. Trondealer handles all blockchain interactions through its V2 API.

= Which currencies are supported? =

Currently the plugin only supports stores priced in USD. EUR support is on the roadmap.

= How are refunds handled? =

Refunds will be available in version 0.2.0, pending a backend release.

== Changelog ==

= 0.1.0 =
* Initial release.
