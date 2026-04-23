# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin (`PromptPay QR Shortcode`) that integrates Thai PromptPay QR payments with WooCommerce. There is no build step, no package manager, and no test suite — it's plain PHP loaded by WordPress. To "run" it you drop the directory into `wp-content/plugins/` of a WordPress install and activate it. The default branch is `master`; ongoing work happens on `deployment`.

External services it talks to:
- `https://promptpay.io/{phone}/{amount}` — generates the QR image (no auth).
- `https://api.slipok.com/api/line/apikey/{key}` — verifies uploaded slips (free 100 calls/month).

## Architecture

`promptpay-qr.php` is the entry point. It registers an `spl_autoload_register` map (class name → `inc/class-*.php`) and boots `PromptPay_Plugin::init` on `plugins_loaded`. **Adding a new class requires adding it to that map** — there is no PSR-4 autoloader.

The plugin exposes the same payment flow through three parallel surfaces, which is important to know before changing slip-handling logic:

1. **WooCommerce Gateway** (`class-gateway.php`) — `PromptPay_Gateway extends WC_Payment_Gateway`, registered via the `woocommerce_payment_gateways` filter. Appears in WooCommerce → Settings → Payments. Settings are stored in `woocommerce_promptpay_qr_settings`.
2. **Shortcode** (`class-shortcode.php`) — `[promptpay_qr]` renders `inc/templates/payment-form.php` standalone (uses cart total if no `amount` attribute).
3. **REST API** (`class-rest-api.php`) — namespace `promptpay/v1`: `/config`, `/qr`, `/verify-slip`, `/slip/{order_id}/{bill}`, `/me`. Used by an external admin UI ("TailAdmin"); permission callback `can_view_slip` accepts both WP nonce **and** Application Password Basic auth.

### Duplicated logic (be careful when editing)

- **Slip-saving** is implemented three times with subtle differences: `class-ajax.php::save_slip`, `class-slip-verify.php::save_for_manual_review`, `class-rest-api.php::save_slip`. All write to `wp-content/uploads/slips/Y/m/slip-{order_id}-bill{N}-{timestamp}.jpg` and store the relative path in post meta `_promptpay_slip_bill{N}`. Each also drops a `.htaccess deny from all` next to the files. Changing the path/filename scheme requires touching all three.
- **Settings** live in two places: the Gateway form fields *and* a standalone Settings page (`class-settings.php` → `promptpay_phone`, `promptpay_slipok_key`). The Gateway's `sync_to_custom_options` mirrors its values into the standalone option keys on save. `PromptPay_Slip_Verify` reads `promptpay_slipok_key`, while the REST `/config` reads from the gateway. Don't break this sync.
- **Order completion** also differs: `class-ajax.php::complete_order` always calls `payment_complete()`. `class-rest-api.php::complete_order` is multi-bill aware: `bill === 1` → `on-hold`, `bill >= 2` → `payment_complete()` + `processing`.

### Slip serving (recently churning)

`PromptPay_REST_API::serve_slip` is the area of recent WIP commits ("res filepath 404", "fix filename slip-"). It reads `_promptpay_slip_bill{N}` post meta, resolves to `wp_upload_dir()['basedir'] . '/' . $relative`, and `readfile()`s with `mime_content_type`. If you see a 404 here, check that the meta value is a relative path (not absolute), that the file actually exists at `basedir + relative`, and that the `bill` number matches what was stored.

### Phone normalization

`PromptPay_QR_Generator::normalize_phone` currently only strips non-digits. The `'66' . ltrim($phone, '0')` country-code prefix is **intentionally commented out** — promptpay.io accepts the local format. Don't re-enable it without testing against the actual QR rendering.

## Conventions

- All PHP files start with `if ( ! defined( 'ABSPATH' ) ) exit;` — keep this guard on any new file.
- All user-facing strings are in Thai. Don't translate them when refactoring.
- Use the `PROMPTPAY_DIR` / `PROMPTPAY_URL` / `PROMPTPAY_VERSION` constants instead of recomputing paths.
