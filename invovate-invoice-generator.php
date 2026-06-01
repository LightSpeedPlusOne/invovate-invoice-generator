<?php
/**
 * Plugin Name:       Invovate Invoice Generator
 * Plugin URI:        https://invovate.com/api
 * Description:        Generate PDF invoices in 11 languages via the Invovate API. Adds a [invovate_invoice_form] shortcode and a reusable helper for themes/plugins.
 * Version:           0.1.0
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
 * @param array $invoice  Invoice fields (from, to, items, currency, language, …).
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
	add_options_page(
		'Invovate',
		'Invovate',
		'manage_options',
		'invovate-settings',
		'invovate_render_settings_page'
	);
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
		<p>Add a front-end invoice form to any page or post with:</p>
		<p><code>[invovate_invoice_form]</code></p>
		<p>Or call <code>invovate_generate( $invoice, [ 'hosted_link' =&gt; true ] )</code> from your theme/plugin.</p>
		<p style="color:#666;font-size:12px;">Not a regulated e-invoicing service (no Peppol/Factur-X/XRechnung/NF-e).</p>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Front-end shortcode: a simple invoice form → PDF hosted link
 * ---------------------------------------------------------------------- */

add_shortcode( 'invovate_invoice_form', function () {
	$nonce = wp_create_nonce( 'invovate_generate' );
	$ajax  = esc_url( admin_url( 'admin-ajax.php' ) );
	ob_start();
	?>
	<form class="invovate-form" onsubmit="return false;" style="max-width:560px;display:grid;gap:.6rem;">
		<input type="hidden" class="inv-nonce" value="<?php echo esc_attr( $nonce ); ?>" />
		<input type="text" class="inv-from" placeholder="Your business name" required />
		<input type="text" class="inv-to" placeholder="Client name" required />
		<div class="inv-items" style="display:grid;gap:.4rem;">
			<div class="inv-item" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:.4rem;">
				<input type="text" class="d" placeholder="Description" />
				<input type="number" class="q" placeholder="Qty" value="1" step="any" />
				<input type="number" class="p" placeholder="Unit price" step="any" />
				<input type="number" class="t" placeholder="Tax %" step="any" />
			</div>
		</div>
		<div style="display:flex;gap:.5rem;">
			<input type="text" class="inv-currency" placeholder="Currency (USD)" value="USD" style="width:120px;" />
			<select class="inv-language">
				<?php foreach ( array( 'en', 'nl', 'de', 'fr', 'es', 'it', 'pt', 'ar', 'ja', 'ru', 'hi' ) as $l ) {
					echo '<option value="' . esc_attr( $l ) . '">' . esc_html( $l ) . '</option>';
				} ?>
			</select>
		</div>
		<button type="submit" class="inv-go">Generate PDF</button>
		<div class="inv-result" style="font-size:.95rem;"></div>
	</form>
	<script>
	(function(){
		var ajax = <?php echo wp_json_encode( $ajax ); ?>;
		document.querySelectorAll('.invovate-form').forEach(function(form){
			form.querySelector('.inv-go').addEventListener('click', function(){
				var res = form.querySelector('.inv-result');
				res.textContent = 'Generating…';
				var items = Array.prototype.map.call(form.querySelectorAll('.inv-item'), function(row){
					return { description: row.querySelector('.d').value, quantity: parseFloat(row.querySelector('.q').value)||1,
						unit_price: parseFloat(row.querySelector('.p').value)||0, tax_rate: parseFloat(row.querySelector('.t').value)||0 };
				});
				var fd = new FormData();
				fd.append('action', 'invovate_generate');
				fd.append('_nonce', form.querySelector('.inv-nonce').value);
				fd.append('from', form.querySelector('.inv-from').value);
				fd.append('to', form.querySelector('.inv-to').value);
				fd.append('currency', form.querySelector('.inv-currency').value || 'USD');
				fd.append('language', form.querySelector('.inv-language').value || 'en');
				fd.append('items', JSON.stringify(items));
				fetch(ajax, { method:'POST', body: fd, credentials:'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(j){
						if (j && j.success && j.data && j.data.hosted_url) {
							res.innerHTML = '✓ <a href="'+j.data.hosted_url+'" target="_blank" rel="noopener">Download your invoice PDF</a> (link valid 7 days)';
						} else {
							res.textContent = '✗ ' + ((j && j.data && j.data.message) || 'Could not generate the invoice.');
						}
					})
					.catch(function(){ res.textContent = '✗ Network error.'; });
			});
		});
	})();
	</script>
	<?php
	return ob_get_clean();
} );

/* AJAX handler (logged-in + anonymous) */
function invovate_ajax_generate() {
	check_ajax_referer( 'invovate_generate', '_nonce' );

	$from     = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
	$to       = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
	$currency = isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : 'USD';
	$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : 'en';
	$items_in = isset( $_POST['items'] ) ? json_decode( wp_unslash( $_POST['items'] ), true ) : array();

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

	$result = invovate_generate(
		array(
			'from'     => array( 'name' => $from ),
			'to'       => array( 'name' => $to ),
			'currency' => $currency,
			'language' => $language,
			'items'    => $items,
		),
		array( 'output' => 'json', 'hosted_link' => true )
	);

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	$invoice    = isset( $result['invoice'] ) ? $result['invoice'] : $result;
	$hosted_url = isset( $invoice['hosted_url'] ) ? $invoice['hosted_url'] : '';
	wp_send_json_success(
		array(
			'hosted_url'  => $hosted_url,
			'grand_total' => isset( $invoice['grand_total'] ) ? $invoice['grand_total'] : null,
		)
	);
}
add_action( 'wp_ajax_invovate_generate', 'invovate_ajax_generate' );
add_action( 'wp_ajax_nopriv_invovate_generate', 'invovate_ajax_generate' );
