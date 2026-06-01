<?php
/**
 * Plugin Name:       Invovate Invoice Generator
 * Plugin URI:        https://invovate.com/api
 * Description:        Generate PDF invoices in 11 languages via the Invovate API. Adds a configurable [invovate_invoice_form] shortcode and a reusable helper for themes/plugins.
 * Version:           0.2.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            Invovate
 * Author URI:        https://invovate.com
 * License:           MIT
 * Text Domain:       invovate-invoice-generator
 *
 * Not regulated e-invoicing: UBL/PDF output is for interoperability/archival only
 * (no Peppol/Factur-X/XRechnung/NF-e compliance).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'INVOVATE_API_URL', 'https://invovate.com/api/generate-invoice' );
define( 'INVOVATE_OPT_KEY', 'invovate_api_key' );

/**
 * Core client: send an invoice payload to the Invovate API.
 * Returns array on success, or WP_Error on failure.
 *
 * @param array $invoice  Invoice fields (from, to, items, currency, language, features, …).
 * @param array $args     Optional: ['output' => 'json'|'pdf'|'ubl', 'hosted_link' => bool].
 * @return array|WP_Error
 */
function invovate_generate( $invoice, $args = array() ) {
	$output      = isset( $args['output'] ) ? $args['output'] : 'json';
	$hosted_link = ! empty( $args['hosted_link'] );

	$body = is_array( $invoice ) ? $invoice : array();
	$body['output'] = $output;
	if ( $hosted_link ) {
		$body['features'] = array_merge(
			isset( $body['features'] ) && is_array( $body['features'] ) ? $body['features'] : array(),
			array( 'hosted_link' => true )
		);
	}

	$headers = array( 'Content-Type' => 'application/json' );
	$key     = trim( (string) get_option( INVOVATE_OPT_KEY, '' ) );
	if ( '' !== $key ) {
		$headers['Authorization'] = 'Bearer ' . $key;
	}

	$response = wp_remote_post(
		INVOVATE_API_URL,
		array(
			'timeout' => 30,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$raw  = wp_remote_retrieve_body( $response );

	if ( $code < 200 || $code >= 300 ) {
		$msg  = 'Invovate API error (HTTP ' . $code . ')';
		$json = json_decode( $raw, true );
		if ( is_array( $json ) && isset( $json['error']['message'] ) ) {
			$msg = $json['error']['message'];
		}
		return new WP_Error( 'invovate_http_' . $code, $msg );
	}

	if ( 'pdf' === $output || 'ubl' === $output ) {
		return array( 'raw' => $raw ); // binary/text payload
	}

	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'invovate_bad_json', 'Unexpected API response.' );
	}
	return $data;
}

/* -------------------------------------------------------------------------
 * Admin settings — Settings → Invovate
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	add_options_page( 'Invovate', 'Invovate', 'manage_options', 'invovate-settings', 'invovate_render_settings_page' );
} );

add_action( 'admin_init', function () {
	register_setting(
		'invovate_settings_group',
		INVOVATE_OPT_KEY,
		array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' )
	);
} );

function invovate_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1>Invovate Invoice Generator</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'invovate_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="invovate_api_key">API Key</label></th>
					<td>
						<input type="password" id="invovate_api_key" name="<?php echo esc_attr( INVOVATE_OPT_KEY ); ?>"
							value="<?php echo esc_attr( get_option( INVOVATE_OPT_KEY, '' ) ); ?>" class="regular-text" autocomplete="off" />
						<p class="description">
							<strong>Required for the invoice form.</strong> Free key (starts with <code>inv_</code>) from
							<a href="https://invovate.com/auth" target="_blank" rel="noopener">invovate.com/auth</a>.
							The <code>[invovate_invoice_form]</code> shortcode generates a shareable PDF link, which needs a key.
							(The <code>invovate_generate()</code> helper can still compute JSON totals without one.)
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<hr />
		<h2>Shortcode</h2>
		<p>Basic: <code>[invovate_invoice_form]</code></p>
		<p>All options (with defaults):</p>
		<pre style="background:#f6f7f7;padding:10px;overflow:auto;">[invovate_invoice_form
  fields="from,to,items,currency,language"   <?php echo esc_html( 'which inputs to show (also: template, notes; items always shown)' ); ?>
  from="" to=""                              <?php echo esc_html( 'prefill / lock the business + client name' ); ?>
  currency="USD" language="en" template="classic"
  tax="true"                                 <?php echo esc_html( 'show the per-item Tax % field' ); ?>
  qr="true"                                  <?php echo esc_html( 'embed a scan-to-view QR in the PDF' ); ?>
  link="true"                                <?php echo esc_html( 'true = show a 7-day shareable link; false = direct PDF download' ); ?>
  rows="1"                                   <?php echo esc_html( 'number of starting line-item rows' ); ?>
  button="Generate PDF"]</pre>
		<p>Or call <code>invovate_generate( $invoice, [ 'output' =&gt; 'pdf' ] )</code> from your theme/plugin.</p>
		<p style="color:#666;font-size:12px;">Not a regulated e-invoicing service (no Peppol/Factur-X/XRechnung/NF-e).</p>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Front-end shortcode: a configurable invoice form
 * ---------------------------------------------------------------------- */

add_shortcode( 'invovate_invoice_form', function ( $atts ) {
	$a = shortcode_atts(
		array(
			'fields'   => 'from,to,items,currency,language',
			'from'     => '',
			'to'       => '',
			'currency' => 'USD',
			'language' => 'en',
			'template' => 'classic',
			'tax'      => 'true',
			'qr'       => 'true',
			'link'     => 'true',
			'rows'     => '1',
			'button'   => 'Generate PDF',
		),
		$atts,
		'invovate_invoice_form'
	);

	$truthy = function ( $v ) { return in_array( strtolower( (string) $v ), array( 'true', '1', 'yes', 'on' ), true ); };
	$show   = array_map( 'trim', explode( ',', strtolower( (string) $a['fields'] ) ) );
	$has    = function ( $f ) use ( $show ) { return in_array( $f, $show, true ); };

	$show_tax = $truthy( $a['tax'] );
	$qr_flag  = $truthy( $a['qr'] ) ? 1 : 0;
	$link_flag = $truthy( $a['link'] ) ? 1 : 0;
	$rows     = max( 1, min( 20, (int) $a['rows'] ) );

	$langs = array( 'en', 'nl', 'de', 'fr', 'es', 'it', 'pt', 'ar', 'ja', 'ru', 'hi' );
	$tpls  = array( 'classic', 'modern', 'bold', 'minimal', 'navy' );
	$tpl   = in_array( $a['template'], $tpls, true ) ? $a['template'] : 'classic';
	$lang  = in_array( $a['language'], $langs, true ) ? $a['language'] : 'en';
	$nonce = wp_create_nonce( 'invovate_generate' );
	$ajax  = esc_url( admin_url( 'admin-ajax.php' ) );

	// One item row (reused by the "Add item" button). Static HTML, no user input.
	$grid     = $show_tax ? '2fr 1fr 1fr 1fr' : '2fr 1fr 1fr';
	$row_html = '<div class="inv-item" style="display:grid;grid-template-columns:' . $grid . ';gap:.4rem;">'
		. '<input type="text" class="d" placeholder="Description" />'
		. '<input type="number" class="q" placeholder="Qty" value="1" step="any" />'
		. '<input type="number" class="p" placeholder="Unit price" step="any" />'
		. ( $show_tax ? '<input type="number" class="t" placeholder="Tax %" step="any" />' : '' )
		. '</div>';

	ob_start();
	?>
	<form class="invovate-form" onsubmit="return false;"
		data-qr="<?php echo esc_attr( $qr_flag ); ?>" data-link="<?php echo esc_attr( $link_flag ); ?>"
		data-template="<?php echo esc_attr( $tpl ); ?>"
		style="max-width:560px;display:grid;gap:.6rem;">
		<input type="hidden" class="inv-nonce" value="<?php echo esc_attr( $nonce ); ?>" />

		<?php if ( $has( 'from' ) ) : ?>
			<input type="text" class="inv-from" placeholder="Your business name" value="<?php echo esc_attr( $a['from'] ); ?>" required />
		<?php else : ?>
			<input type="hidden" class="inv-from" value="<?php echo esc_attr( $a['from'] ); ?>" />
		<?php endif; ?>

		<?php if ( $has( 'to' ) ) : ?>
			<input type="text" class="inv-to" placeholder="Client name" value="<?php echo esc_attr( $a['to'] ); ?>" required />
		<?php else : ?>
			<input type="hidden" class="inv-to" value="<?php echo esc_attr( $a['to'] ); ?>" />
		<?php endif; ?>

		<div class="inv-items" style="display:grid;gap:.4rem;">
			<?php for ( $i = 0; $i < $rows; $i++ ) : ?>
				<div class="inv-item" style="display:grid;grid-template-columns:<?php echo esc_attr( $grid ); ?>;gap:.4rem;">
					<input type="text" class="d" placeholder="Description" />
					<input type="number" class="q" placeholder="Qty" value="1" step="any" />
					<input type="number" class="p" placeholder="Unit price" step="any" />
					<?php if ( $show_tax ) : ?><input type="number" class="t" placeholder="Tax %" step="any" /><?php endif; ?>
				</div>
			<?php endfor; ?>
		</div>
		<button type="button" class="inv-add" style="justify-self:start;font-size:.85rem;background:none;border:1px dashed #bbb;border-radius:6px;padding:.25rem .6rem;cursor:pointer;">+ Add item</button>

		<div style="display:flex;gap:.5rem;flex-wrap:wrap;">
			<?php if ( $has( 'currency' ) ) : ?>
				<input type="text" class="inv-currency" placeholder="Currency" value="<?php echo esc_attr( $a['currency'] ); ?>" style="width:110px;" />
			<?php else : ?>
				<input type="hidden" class="inv-currency" value="<?php echo esc_attr( $a['currency'] ); ?>" />
			<?php endif; ?>

			<?php if ( $has( 'language' ) ) : ?>
				<select class="inv-language">
					<?php foreach ( $langs as $l ) {
						echo '<option value="' . esc_attr( $l ) . '"' . selected( $lang, $l, false ) . '>' . esc_html( $l ) . '</option>';
					} ?>
				</select>
			<?php else : ?>
				<input type="hidden" class="inv-language" value="<?php echo esc_attr( $lang ); ?>" />
			<?php endif; ?>

			<?php if ( $has( 'template' ) ) : ?>
				<select class="inv-template">
					<?php foreach ( $tpls as $t ) {
						echo '<option value="' . esc_attr( $t ) . '"' . selected( $tpl, $t, false ) . '>' . esc_html( $t ) . '</option>';
					} ?>
				</select>
			<?php endif; ?>
		</div>

		<?php if ( $has( 'notes' ) ) : ?>
			<textarea class="inv-notes" placeholder="Notes (optional)" rows="2"></textarea>
		<?php endif; ?>

		<button type="submit" class="inv-go"><?php echo esc_html( $a['button'] ); ?></button>
		<div class="inv-result" style="font-size:.95rem;"></div>
	</form>
	<script>
	(function () {
		var ajax = <?php echo wp_json_encode( $ajax ); ?>;
		var ROW  = <?php echo wp_json_encode( $row_html ); ?>;
		document.querySelectorAll('.invovate-form').forEach(function (form) {
			var add = form.querySelector('.inv-add');
			if (add) add.addEventListener('click', function () {
				var tmp = document.createElement('div'); tmp.innerHTML = ROW;
				form.querySelector('.inv-items').appendChild(tmp.firstChild);
			});
			form.querySelector('.inv-go').addEventListener('click', function () {
				var res = form.querySelector('.inv-result');
				res.textContent = 'Generating…';
				var items = Array.prototype.map.call(form.querySelectorAll('.inv-item'), function (row) {
					var t = row.querySelector('.t');
					var o = {
						description: (row.querySelector('.d') || {}).value || '',
						quantity: parseFloat((row.querySelector('.q') || {}).value) || 1,
						unit_price: parseFloat((row.querySelector('.p') || {}).value) || 0
					};
					if (t && parseFloat(t.value)) o.tax_rate = parseFloat(t.value);
					return o;
				}).filter(function (i) { return i.description; });

				var pick = function (sel) { var el = form.querySelector(sel); return el ? el.value : ''; };
				var fd = new FormData();
				fd.append('action', 'invovate_generate');
				fd.append('_nonce', pick('.inv-nonce'));
				fd.append('from', pick('.inv-from'));
				fd.append('to', pick('.inv-to'));
				fd.append('currency', pick('.inv-currency') || 'USD');
				fd.append('language', pick('.inv-language') || 'en');
				fd.append('template', form.querySelector('.inv-template') ? pick('.inv-template') : form.dataset.template);
				if (form.querySelector('.inv-notes')) fd.append('notes', pick('.inv-notes'));
				fd.append('items', JSON.stringify(items));
				fd.append('qr', form.dataset.qr);
				fd.append('link', form.dataset.link);

				fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (j) {
						if (!j || !j.success || !j.data) {
							res.textContent = '✗ ' + ((j && j.data && j.data.message) || 'Could not generate the invoice.');
							return;
						}
						if (j.data.pdf_base64) {
							var bin = atob(j.data.pdf_base64), arr = new Uint8Array(bin.length);
							for (var k = 0; k < bin.length; k++) arr[k] = bin.charCodeAt(k);
							var url = URL.createObjectURL(new Blob([arr], { type: 'application/pdf' }));
							var a = document.createElement('a'); a.href = url; a.download = j.data.filename || 'invoice.pdf';
							document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
							res.textContent = '✓ PDF downloaded.';
						} else if (j.data.hosted_url) {
							res.innerHTML = '✓ <a href="' + j.data.hosted_url + '" target="_blank" rel="noopener">Download your invoice PDF</a> (link valid 7 days)';
						} else {
							res.textContent = '✓ Done.';
						}
					})
					.catch(function () { res.textContent = '✗ Network error.'; });
			});
		});
	})();
	</script>
	<?php
	return ob_get_clean();
} );

/* AJAX handler (logged-in + anonymous visitors of the page) */
function invovate_ajax_generate() {
	check_ajax_referer( 'invovate_generate', '_nonce' );

	$from     = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
	$to       = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
	$currency = isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : 'USD';
	$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : 'en';
	$template = isset( $_POST['template'] ) ? sanitize_text_field( wp_unslash( $_POST['template'] ) ) : 'classic';
	$notes    = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
	$qr       = isset( $_POST['qr'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['qr'] ) );
	$link     = ! isset( $_POST['link'] ) || '0' !== sanitize_text_field( wp_unslash( $_POST['link'] ) ); // default true
	$items_raw = isset( $_POST['items'] ) ? sanitize_text_field( wp_unslash( $_POST['items'] ) ) : '';
	$items_in  = json_decode( $items_raw, true );

	if ( '' === $from || '' === $to || ! is_array( $items_in ) || empty( $items_in ) ) {
		wp_send_json_error( array( 'message' => 'Please provide a business name, a client name, and at least one item.' ) );
	}

	$items = array();
	foreach ( $items_in as $it ) {
		$desc = isset( $it['description'] ) ? sanitize_text_field( $it['description'] ) : '';
		if ( '' === $desc ) {
			continue;
		}
		$line = array(
			'description' => $desc,
			'quantity'    => isset( $it['quantity'] ) ? floatval( $it['quantity'] ) : 1,
			'unit_price'  => isset( $it['unit_price'] ) ? floatval( $it['unit_price'] ) : 0,
		);
		if ( ! empty( $it['tax_rate'] ) ) {
			$line['tax_rate'] = floatval( $it['tax_rate'] );
		}
		$items[] = $line;
	}
	if ( empty( $items ) ) {
		wp_send_json_error( array( 'message' => 'Please add at least one line item with a description.' ) );
	}

	$invoice = array(
		'from'     => array( 'name' => $from ),
		'to'       => array( 'name' => $to ),
		'currency' => $currency,
		'language' => $language,
		'template' => $template,
		'items'    => $items,
		// features control the shareable link + the scan-to-view QR (both default true server-side).
		'features' => array( 'hosted_link' => (bool) $link, 'qr' => (bool) $qr ),
	);
	if ( '' !== $notes ) {
		$invoice['notes'] = $notes;
	}

	if ( $link ) {
		// Shareable 7-day link.
		$result = invovate_generate( $invoice, array( 'output' => 'json' ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$inv = isset( $result['invoice'] ) ? $result['invoice'] : $result;
		wp_send_json_success(
			array(
				'hosted_url'  => isset( $inv['hosted_url'] ) ? $inv['hosted_url'] : '',
				'grand_total' => isset( $inv['grand_total'] ) ? $inv['grand_total'] : null,
			)
		);
	}

	// Direct PDF download (link disabled).
	$result = invovate_generate( $invoice, array( 'output' => 'pdf' ) );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}
	$raw = isset( $result['raw'] ) ? $result['raw'] : '';
	if ( '' === $raw || '%PDF' !== substr( $raw, 0, 4 ) ) {
		wp_send_json_error( array( 'message' => 'PDF generation failed. Make sure an API key is set under Settings → Invovate.' ) );
	}
	wp_send_json_success( array( 'pdf_base64' => base64_encode( $raw ), 'filename' => 'invoice.pdf' ) );
}
add_action( 'wp_ajax_invovate_generate', 'invovate_ajax_generate' );
add_action( 'wp_ajax_nopriv_invovate_generate', 'invovate_ajax_generate' );
