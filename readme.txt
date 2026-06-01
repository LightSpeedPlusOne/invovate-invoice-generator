=== Invovate Invoice Generator ===
Contributors: invovate
Tags: invoice, pdf invoice, invoice generator, billing, ubl
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 0.4.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Generate professional PDF invoices in 11 languages from WordPress via the Invovate API. Shortcode form + a reusable PHP helper.

== Description ==

Invovate Invoice Generator connects your WordPress site to the [Invovate invoice API](https://invovate.com/api) so you can create professional **PDF invoices in 11 languages** (including right-to-left Arabic, Japanese, Hindi, and Cyrillic).

* **`[invovate_invoice_form]` shortcode** — drop a simple "create invoice" form on any page. Visitors enter a business name, client, and line items and get a downloadable PDF link (valid 7 days).
* **`invovate_generate( $invoice, $args )` helper** — call from your theme or another plugin to generate invoices programmatically (e.g. on a WooCommerce order or form submission).
* **Free API key** — required for the invoice form (it generates a shareable PDF link). Get a free key at invovate.com/auth and set it under **Settings → Invovate**. The `invovate_generate()` helper can still compute JSON totals without one.

Languages: English, Dutch, German, French, Spanish, Italian, Portuguese, Arabic, Japanese, Russian, Hindi. 20+ currencies, per-line tax, 5 templates.

= Configure the form (shortcode options) =

`[invovate_invoice_form]` accepts these attributes:

* `fields` — comma list of inputs/controls to show. Available: `from`, `to`, `items`, `currency`, `language`, `template`, `notes`, `qr`, `link`. Items always show. Default: `from,to,items,currency,language,qr,link`.
* `from`, `to` — prefill (or, if not in `fields`, lock) the business and client name.
* `currency` (default `USD`), `language` (default `en`), `template` (default `classic`).
* `tax` — `true`/`false`: show the per-item Tax % field. Default `true`.
* `qr` — default state of the scan-to-view QR (`true`/`false`). When `link` is in `fields`, users get a checkbox; the QR is disabled while the link is off (it points at the link). Default `true`.
* `link` — default state of the shareable link (`true`/`false`): `true` = a 7-day shareable link; `false` = a direct PDF download with no link or QR. Default `true`.
* `rows` — number of starting line-item rows. Default `1` (an "Add item" button is always shown).
* `button` — submit-button label.

Examples:
`[invovate_invoice_form fields="to,items" from="Acme Studio" currency="EUR" language="de" template="navy" qr="false"]`
`[invovate_invoice_form link="false" button="Download invoice"]`

**Not regulated e-invoicing.** PDF/UBL output is for interoperability and archival only — it does not provide Peppol, Factur-X, ZUGFeRD, XRechnung, or NF-e compliance or government-network delivery.

== External services ==

This plugin connects to the **Invovate invoice API** to generate invoices. It is a first-party integration with a service operated by the plugin author.

**What is sent, and when:** Only when you submit the `[invovate_invoice_form]` form (or call `invovate_generate()` in code), the invoice details you entered — business name, client name, line items (description, quantity, unit price, tax rate), currency, language, and optional notes — are sent over HTTPS to `https://invovate.com/api/generate-invoice`. If you set an API key under Settings → Invovate, it is sent as an `Authorization: Bearer` header. **Nothing is sent on page load or in the background.**

**What is returned:** either a shareable PDF link (the invoice is stored for up to 7 days, then automatically deleted) or the generated PDF file.

* Service & API docs: https://invovate.com/api
* Terms of Service: https://invovate.com/terms
* Privacy Policy: https://invovate.com/privacy

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

= 0.4.2 =
* Turning off "Shareable 7-day link" now produces a clean direct-download PDF with no QR and no link (the scan-to-view QR points at the link, so it can't exist without one). The QR checkbox is disabled while the link is off.

= 0.4.1 =
* Fix: the "Generate PDF" button did nothing on some pages. The form script is now a properly enqueued file instead of inline markup — WordPress content filters were corrupting the inline JavaScript (encoding `&&` to `&#038;&#038;`), which broke the whole script.

= 0.4.0 =
* Form now shows QR + shareable-link toggle checkboxes (control them with `fields="...,qr,link"`; on by default).
* Line items: a row with a price but no description is no longer silently dropped — it shows a clear error; truly-empty rows are skipped.
* Negative quantity / unit price / tax are rejected with a clear message (matches the API).
* Responsive form + settings: inputs and the shortcode example no longer overflow narrow screens.

= 0.3.0 =
* Settings → Invovate: added a "Test API key" button that runs a server-side authenticated call and reports whether the saved key reaches the API (with a hint for WordPress Playground, whose proxy strips the Authorization header).

= 0.2.0 =
* Configurable shortcode: `fields`, `from`/`to` defaults, `currency`/`language`/`template`, `tax`, `qr` (toggle the scan-to-view QR), `link` (shareable link vs direct PDF download), `rows`, `button`.
* "Add item" button for multiple line items; direct-download mode; notes field.

= 0.1.0 =
* Initial release: settings page, `[invovate_invoice_form]` shortcode, and `invovate_generate()` helper.
