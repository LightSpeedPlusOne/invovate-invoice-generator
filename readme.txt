=== Invovate Invoice Generator ===
Contributors: invovate
Tags: invoice, pdf invoice, invoice generator, billing, ubl
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Generate professional PDF invoices in 11 languages from WordPress via the Invovate API. Shortcode form + a reusable PHP helper.

== Description ==

Invovate Invoice Generator connects your WordPress site to the [Invovate invoice API](https://invovate.com/api) so you can create professional **PDF invoices in 11 languages** (including right-to-left Arabic, Japanese, Hindi, and Cyrillic).

* **`[invovate_invoice_form]` shortcode** — drop a simple "create invoice" form on any page. Visitors enter a business name, client, and line items and get a downloadable PDF link (valid 7 days).
* **`invovate_generate( $invoice, $args )` helper** — call from your theme or another plugin to generate invoices programmatically (e.g. on a WooCommerce order or form submission).
* **Free API key** — required for the invoice form (it generates a shareable PDF link). Get a free key at invovate.com/auth and set it under **Settings → Invovate**. The `invovate_generate()` helper can still compute JSON totals without one.

Languages: English, Dutch, German, French, Spanish, Italian, Portuguese, Arabic, Japanese, Russian, Hindi. 20+ currencies, per-line tax, 5 templates.

**Not regulated e-invoicing.** PDF/UBL output is for interoperability and archival only — it does not provide Peppol, Factur-X, ZUGFeRD, XRechnung, or NF-e compliance or government-network delivery.

== Installation ==

1. Upload the `invovate-invoice-generator` folder to `/wp-content/plugins/`, or install the ZIP via Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to **Settings → Invovate** and paste a free API key from https://invovate.com/auth (required for the invoice form).
4. Add `[invovate_invoice_form]` to a page, or call `invovate_generate()` in your code.

== Frequently Asked Questions ==

= Do I need an API key? =
Yes for the invoice form — it generates a shareable PDF link, which requires a free key (set it under Settings → Invovate). The `invovate_generate()` helper can still compute JSON totals without a key.

= Is my data private? =
Invoice data is sent to the Invovate API over HTTPS. Shareable PDF links are stored for up to 7 days, then deleted. See https://invovate.com/privacy.

= Is this regulated e-invoicing? =
No. It generates invoice documents but is not a Peppol/Factur-X/XRechnung/NF-e transmission service.

== Changelog ==

= 0.1.0 =
* Initial release: settings page, `[invovate_invoice_form]` shortcode, and `invovate_generate()` helper.
