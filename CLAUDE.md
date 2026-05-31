# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Architecture

WooCommerce payment gateway plugin. Entry point `promptpay-qr.php` bootstraps a singleton `PromptPay_Plugin::init()` that registers six service classes via `spl_autoload_register` (maps class names to `inc/class-*.php` files).

```
PromptPay_Plugin
├── PromptPay_Settings    → Admin settings UI (phone, slipok_key)
├── PromptPay_Assets      → Enqueue CSS/JS
├── PromptPay_Shortcode   → [promptpay_qr] shortcode → inc/templates/payment-form.php
├── PromptPay_Ajax        → AJAX slip upload handler (wp_ajax_*)
├── PromptPay_REST_API    → REST endpoints (see below)
└── PromptPay_Gateway     → WooCommerce payment method registration
```

**HPOS compatible** — declares `custom_order_tables` feature support.

## REST Endpoints

**Namespace**: `/wp-json/promptpay/v1/`

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/config` | Get phone + slipok_key |
| POST | `/config` | Update settings |
| GET | `/qr?amount=N` | Generate PromptPay QR URL |
| POST | `/verify-slip` | Verify payment slip via SlipOK API |
| GET | `/slip/{id}/{bill}` | Retrieve slip object |

## Key Files

| File | Purpose |
|------|---------|
| `inc/class-qr-generator.php` | Generates PromptPay QR payload/URL |
| `inc/class-slip-verify.php` | Calls SlipOK external API to verify slip images |
| `inc/class-gateway.php` | WooCommerce `WC_Payment_Gateway` subclass |
| `inc/templates/payment-form.php` | Shortcode HTML (QR display + drag-drop upload) |
| `assets/js/promptpay.js` | Frontend: drag-drop, image preview, AJAX submit |

## Conventions

- All AJAX calls use nonce verification (`wp_verify_nonce`)
- SlipOK API key stored in WP options via `PromptPay_Settings`, never hardcoded
- Adding new functionality: create a new `inc/class-*.php` and register it in `PromptPay_Plugin::init()`
