# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WooCommerce payment gateway plugin that accepts USDT/USDC stablecoin payments across 9 blockchains (TRON, Ethereum, BSC, Polygon, Arbitrum, Base, Optimism, Avalanche, Solana) by talking to the Trondealer V2 API at `https://trondealer.com/api/v2`. Plain WordPress plugin: no build step, no package manifest, no test suite, no CI. Editing PHP files is the entire dev loop — install the plugin folder into a WP/WooCommerce site to exercise it.

Targets: WordPress ≥ 6.2, PHP ≥ 7.4, WooCommerce ≥ 7.0. Store currency must be USD (gateway hides itself otherwise — see `TDP_Gateway::is_available`).

## Bootstrap

`trondealer-payments.php` defines `TDP_VERSION`, `TDP_PLUGIN_DIR`, `TDP_PLUGIN_URL`, `TDP_API_BASE`, then registers `TDP_Plugin::instance` on `plugins_loaded` (priority 11, so WooCommerce loads first). `TDP_Plugin` short-circuits with an admin notice if `WooCommerce` class is missing; otherwise it loads every `includes/class-tdp-*.php` file and wires hooks. All classes use the `TDP_` prefix and are loaded via explicit `require_once` (no autoloader).

## Architecture (the parts that span files)

### Money flow per order

1. **Checkout** — `TDP_Gateway::payment_fields` renders a `<select>` of `network:ASSET` combos from `TDP_Networks::combinations()`. `process_payment` parses the choice, calls `TDP_API_Client::assign_wallet($network, TDP_Orders::build_label($order_id))`, and stores the returned address/amount/asset on the order via `TDP_Orders::set_assignment` (`_tdp_*` post-meta keys defined as constants on `TDP_Orders`). Order moves to `pending`.
2. **Thank-you page** — `render_thankyou` includes `templates/checkout-payment.php` with QR + address + a polling endpoint URL (`/wp-json/tdp/v1/order-status/{id}?key=…`). The `key` is `wp_hash('tdp_order_status_' . $id)` and is the *only* gate on that endpoint, so don't change the hashing without updating the JS poller (`assets/js/thankyou-poller.js`).
3. **Webhook** (primary settlement path) — `TDP_Webhook::handle` at `POST /wp-json/tdp/v1/webhook`. Verifies `X-Signature-256: sha256=<hmac>` against `tdp_webhook_secret` using `hash_equals`. Resolves order via `wallet_label` → `TDP_Orders::parse_label` (must start with `wc_` and end with digits). Events: `transaction.incoming` → `on-hold`; `transaction.confirmed` → `payment_complete`; `transaction.swept` → note only. Network/asset mismatches and `underpaid` (per `TDP_Orders::classify_amount` with `tdp_tolerance_pct`) force `on-hold` for manual review.
4. **Cron fallback** — `TDP_Cron_Fallback` registers a custom 5-minute schedule (`tdp_5min`) on the `tdp_reconcile_pending_orders` hook. It scans last-14-days `pending`/`on-hold` orders, calls `get_transactions`, and fires the same status transitions as webhooks. Exists because the V2 backend retries webhooks 3× without backoff (see comment in `class-tdp-cron-fallback.php`).

### Idempotency

Both webhook and cron paths must dedupe. The shared mechanism is a per-order `_tdp_processed_tx_ids` meta array keyed by `TDP_Orders::build_tx_uid($network, $tx) . ':' . $event_name`. The tx_uid composition differs per family (EVM = `tx_hash:log_index`, TRON = `tx_id:event_index`, SOL = `tx_signature:instruction_index`) — when adding a new event type, append a new event suffix rather than reusing one, or the cron path will skip the new transition.

### Network family abstraction

`TDP_Networks` is the single source of truth for chain metadata. It groups chains into three families (`FAMILY_EVM`, `FAMILY_TRON`, `FAMILY_SOL`) which the API client uses to pick endpoints (`/wallets/*` for EVM with a `network` body param, `/tron/wallets/*` for TRON, `/sol/wallets/*` for Solana). Adding a network = adding an entry to `TDP_Networks::all()`; adding a new family means branching `TDP_API_Client::assign_wallet`/`get_transactions`/`get_balance`, `TDP_Orders::build_tx_uid`, and `TDP_Networks::build_payment_uri`.

### Settings storage (note the duplication)

WooCommerce stores gateway settings under `woocommerce_trondealer_settings`. `TDP_Admin::sync_settings` mirrors the API key, base URL, and tolerance into top-level options (`tdp_api_key`, `tdp_api_base`, `tdp_tolerance_pct`) on save. Cron and webhook code reads from the top-level options; the gateway/admin reads from the WC settings array. If you add a new setting that cron/webhook need, also mirror it in `sync_settings` or it won't be visible outside the gateway. `tdp_webhook_secret` is generated lazily on the first connection test in `TDP_Admin::handle_connection_test` via `bin2hex(random_bytes(32))`.

### Checkout integrations (two of them)

- **Classic checkout** — `TDP_Gateway::payment_fields` renders directly into the WooCommerce form.
- **Blocks checkout** — `TDP_Blocks_Integration` registers a Blocks payment method type that loads `assets/js/checkout-blocks.js` (a hand-written non-bundled script using `wc-blocks-registry` + `wp-element`). The selected combo from the React block must be POSTed under the same `tdp_network_choice` name that classic checkout uses, because `process_payment` reads only that key.

### Refunds

`TDP_Refunds::process` always returns `WP_Error` — automated refunds are not implemented (waiting on a V2 withdraw endpoint). The gateway still advertises `'refunds'` in `$this->supports` so the admin button shows up; the error message instructs merchants to refund manually.
