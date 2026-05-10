# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WooCommerce payment gateway plugin that accepts USDT/USDC stablecoin payments across 9 blockchains (TRON, Ethereum, BSC, Polygon, Arbitrum, Base, Optimism, Avalanche, Solana) by talking to the Trondealer V2 API at `https://trondealer.com/api/v2`. Maintained by QvaPay. Plain WordPress plugin: no PHP build step, no `package.json`, no test suite. Editing PHP/JS/CSS files is the entire dev loop — install the plugin folder into a WP/WooCommerce site to exercise it.

Targets: WordPress ≥ 6.2, PHP ≥ 7.4, WooCommerce ≥ 7.0. Store currency must be USD (gateway hides itself otherwise — see `TDP_Gateway::is_available`).

## Bootstrap

`trondealer-payments.php` defines `TDP_VERSION`, `TDP_PLUGIN_DIR`, `TDP_PLUGIN_URL`, `TDP_API_BASE`, then registers `TDP_Plugin::instance` on `plugins_loaded` (priority 11, so WooCommerce loads first). It also declares HPOS (`custom_order_tables`) and `cart_checkout_blocks` compatibility on `before_woocommerce_init` — without those declarations WC ≥ 8.x flags the plugin as incompatible even though it is HPOS-safe.

`TDP_Plugin` short-circuits with an admin notice if `WooCommerce` class is missing; otherwise it loads every `includes/class-tdp-*.php` file and wires hooks. All classes use the `TDP_` prefix and are loaded via explicit `require_once` (no autoloader). Exception: `TDP_Blocks_Method` is loaded **lazily** — see "Checkout integrations" below.

## Architecture (the parts that span files)

### Money flow per order

1. **Checkout** — `TDP_Gateway::payment_fields` (classic) or the Blocks JS (`assets/js/checkout-blocks.js`) renders a card grid grouped by network. Both surfaces submit the chosen combo as `tdp_network_choice = "<network>:<ASSET>"`. `process_payment` parses the choice, calls `TDP_API_Client::assign_wallet($network, TDP_Orders::build_label($order_id))`, and stores the returned address/amount/asset on the order via `TDP_Orders::set_assignment` (`_tdp_*` post-meta keys defined as constants on `TDP_Orders`). Order moves to `pending`.
2. **Thank-you page** — `render_thankyou` includes `templates/checkout-payment.php` with QR + address + a polling endpoint URL (`/wp-json/tdp/v1/order-status/{id}?key=…`). The `key` is `wp_hash('tdp_order_status_' . $id)` and is the *only* gate on that endpoint, so don't change the hashing without updating the JS poller (`assets/js/thankyou-poller.js`). `render_thankyou` early-returns if the order is already paid, so refreshing the thank-you URL after settlement falls through to WooCommerce's default "Thank you" message instead of re-rendering the QR.
3. **Webhook** (primary settlement path) — `TDP_Webhook::handle` at `POST /wp-json/tdp/v1/webhook`. Verifies `X-Signature-256: sha256=<hmac>` against `tdp_webhook_secret` using `hash_equals`. Resolves order via `wallet_label` → `TDP_Orders::parse_label` (must start with `wc_` and end with digits). Events: `transaction.incoming` → `on-hold`; `transaction.confirmed` → `payment_complete`; `transaction.swept` → note only. Network/asset mismatches and `underpaid` (per `TDP_Orders::classify_amount` with `tdp_tolerance_pct`) force `on-hold` for manual review.
4. **Cron fallback** — `TDP_Cron_Fallback` registers a custom 5-minute schedule (`tdp_5min`) on the `tdp_reconcile_pending_orders` hook. It scans last-14-days `pending`/`on-hold` orders, calls `get_transactions`, and fires the same status transitions as webhooks. Exists because the V2 backend retries webhooks 3× without backoff (see comment in `class-tdp-cron-fallback.php`).

### Idempotency

Both webhook and cron paths must dedupe. The shared mechanism is a per-order `_tdp_processed_tx_ids` meta array keyed by `TDP_Orders::build_tx_uid($network, $tx) . ':' . $event_name`. The tx_uid composition differs per family (EVM = `tx_hash:log_index`, TRON = `tx_id:event_index`, SOL = `tx_signature:instruction_index`) — when adding a new event type, append a new event suffix rather than reusing one, or the cron path will skip the new transition.

### Network family abstraction

`TDP_Networks` is the single source of truth for chain metadata. It groups chains into three families (`FAMILY_EVM`, `FAMILY_TRON`, `FAMILY_SOL`) which the API client uses to pick endpoints (`/wallets/*` for EVM with a `network` body param, `/tron/wallets/*` for TRON, `/sol/wallets/*` for Solana). It also exposes `network_icon_url($key)` / `asset_icon_url($symbol)` which resolve to bundled SVGs in `assets/images/networks/` and `assets/images/coins/` and fall back to `''` if the file is missing — both the admin matrix and the checkout grid degrade gracefully (text-only pill / fallback initials in a colored circle).

Adding a network = adding an entry to `TDP_Networks::all()` plus dropping `assets/images/networks/<key>.svg`. Adding a new family means branching `TDP_API_Client::assign_wallet` / `get_transactions` / `get_balance`, `TDP_Orders::build_tx_uid`, and `TDP_Networks::build_payment_uri`.

### Settings storage (note the duplication)

WooCommerce stores gateway settings under `woocommerce_trondealer_settings`. `TDP_Admin::sync_settings` mirrors the API key, base URL, and tolerance into top-level options (`tdp_api_key`, `tdp_api_base`, `tdp_tolerance_pct`) on save. Cron and webhook code reads from the top-level options; the gateway/admin reads from the WC settings array. If you add a new setting that cron/webhook need, also mirror it in `sync_settings` or it won't be visible outside the gateway. `tdp_webhook_secret` is generated lazily on the first connection test in `TDP_Admin::handle_connection_test` via `bin2hex(random_bytes(32))`.

### Admin form (custom field type)

The "Enabled Networks" field is **not** the standard WooCommerce `multiselect`. It uses a custom field type `tdp_network_matrix` rendered by `TDP_Gateway::generate_tdp_network_matrix_html` (one row per network with USDT/USDC checkbox pills) and validated by `validate_tdp_network_matrix_field`. WC's `WC_Settings_API` discovers both methods by name from the field's `type` value; renaming the type means renaming both methods. The persisted value remains an array of `network:ASSET` strings, identical to what the old multiselect produced — the cron/webhook side never sees the change.

`TDP_Admin::plugin_action_links` adds the `Settings` link next to `Deactivate` on the plugins list (the standard `plugin_action_links_<basename>` filter pattern).

### Checkout integrations (two of them)

- **Classic checkout** — `TDP_Gateway::payment_fields` renders the card grid directly. Each card has hidden `<input type="radio" name="tdp_network_choice">`s; CSS uses `:has(input:checked)` to highlight the selected pill/card (no JS state).
- **Blocks checkout** — `TDP_Blocks_Integration::register` is hooked **directly** to `woocommerce_blocks_payment_method_type_registration` (priority 5) — going through `woocommerce_blocks_loaded` is unreliable because Blocks may have already fired the registration phase by the time our `woocommerce_blocks_loaded` callback runs. The `TDP_Blocks_Method` subclass is in its own file `class-tdp-blocks-method.php` and is `require_once`'d from inside `register()`, **not** at plugins_loaded. This matters because `Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType` may not exist yet when `class-tdp-plugin.php::load_dependencies()` runs at plugins_loaded:11 — defining the subclass eagerly there silently no-ops and the entire Blocks integration disappears with no PHP error.

  The Blocks JS (`assets/js/checkout-blocks.js`) renders a React card grid with `useState` for the current selection and an `eventRegistration.onPaymentSetup` callback that returns `{ paymentMethodData: { tdp_network_choice: choice } }`. WC Blocks transforms `paymentMethodData` into `$_POST` keys server-side, which is how the same `process_payment` handles both surfaces. Without the `onPaymentSetup` hook the value never reaches the server and `validate_fields` throws "Please choose a network and asset.". `canMakePayment` returns `true` unconditionally — gateway visibility is decided server-side by `is_active()`/`is_available()`, doing it again in JS just causes silent hides when the data wiring breaks.

  Required script deps: `wc-blocks-registry`, `wc-settings`, `wp-element`, `wp-html-entities`, `wp-i18n`. Missing `wc-settings` is what makes `getSetting('trondealer_data', {})` silently return empty.

### Refunds

`TDP_Refunds::process` always returns `WP_Error` — automated refunds are not implemented (waiting on a V2 withdraw endpoint). The gateway still advertises `'refunds'` in `$this->supports` so the admin button shows up; the error message instructs merchants to refund manually.

## Distribution

The plugin ships in two places: GitHub Releases and (planned) the WordPress.org plugin directory.

- `build.sh` produces `dist/trondealer-payments-<version>.zip` from the runtime files only (the `SHIP` array at the top of the script is the source of truth — anything not listed is excluded). The zip's top-level dir is `trondealer-payments/` so it can be uploaded directly via wp-admin → Add New → Upload.
- `.github/workflows/release.yml` runs `build.sh` on tag push (`v*`), validates the tag matches the plugin header `Version:`, and attaches the zip to the GitHub Release.
- `.gitattributes` `export-ignore` list mirrors the runtime exclusions so `git archive` produces the same set of files.
- `.wordpress-org/` holds the wordpress.org plugin directory assets (banner-1544x500.png, banner-772x250.png, icon-256x256.png, icon-128x128.png). These do **not** ship with the plugin — they go into the SVN repo's separate `/assets/` path when the plugin is approved. Source for the rendered images is `assets/images/trondealer.svg` rasterized via `qlmanage` (because ImageMagick mishandles the linearGradient on macOS).
- `LICENSE` is the full GPL-2.0 text. `NOTICE.md` documents the MIT license + trademark caveat for the bundled web3icons SVGs. Both are required for wordpress.org review.
