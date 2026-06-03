<?php
/**
 * Plugin Name:       Invovate Invoice Generator
 * Plugin URI:        https://invovate.com/api
 * Description:        Generate PDF invoices in 11 languages via the Invovate API. Adds a configurable [invovate_invoice_form] shortcode and a reusable helper for themes/plugins.
 * Version:           0.4.3
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
define( 'INVOVATE_VER', '0.4.3' );

/**
 * Register the front-end form script. It is ENQUEUED (never inlined into the
 * shortcode HTML) because WordPress content filters mangle inline <script> that
 * contains `&&` next to `<`/`>` (turning `&&` into `&#038;&#038;`).
 */
add_action( 'wp_enqueue_scripts', function () {
	wp_register_script( 'invovate-form', plugins_url( 'assets/invovate-form.js', __FILE__ ), array(), INVOVATE_VER, true );
	wp_localize_script( 'invovate-form', 'INVOVATE_CFG', array( 'ajax' => admin_url( 'admin-ajax.php' ) ) );
	wp_register_style( 'invovate-form', plugins_url( 'assets/invovate-form.css', __FILE__ ), array(), INVOVATE_VER );
} );

/**
 * Admin: enqueue the settings-page "Check key" script (only on our options page),
 * instead of inlining a <script> tag.
 */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'settings_page_invovate-settings' !== $hook ) {
		return;
	}
	wp_enqueue_script( 'invovate-admin', plugins_url( 'assets/invovate-admin.js', __FILE__ ), array(), INVOVATE_VER, true );
	wp_localize_script( 'invovate-admin', 'INVOVATE_ADMIN', array(
		'ajax'  => admin_url( 'admin-ajax.php' ),
		'nonce' => wp_create_nonce( 'invovate_test_key' ),
	) );
} );

/**
 * Core client: send an invoice payload to the Invovate API.
 * Returns array on success, or WP_Error on failure.
 *
 * @param array $invoice  Invoice fields (from, to, items, currency, language, features, …).
 * @param array $args     Optional: ['output' => 'json'|'pdf'|'ubl', 'hosted_link' => bool,
 *                         'api_key' => string (override the saved option, e.g. for a validity check)].
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

	$headers  = array( 'Content-Type' => 'application/json' );
	$override = isset( $args['api_key'] ) ? trim( (string) $args['api_key'] ) : '';
	$key      = '' !== $override ? $override : trim( (string) get_option( INVOVATE_OPT_KEY, '' ) );
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
						<button type="button" class="button button-secondary" id="invovate-check-btn" style="margin-left:.4rem;">Check key</button>
						<span id="invovate-check-result" style="margin-left:.6rem;font-weight:600;"></span>
						<p class="description">
							<strong>Required for the invoice form.</strong> Free key (starts with <code>inv_</code>) from
							<a href="https://invovate.com/auth" target="_blank" rel="noopener">invovate.com/auth</a>.
							The <code>[invovate_invoice_form]</code> shortcode generates a shareable PDF link, which needs a key.
							(The <code>invovate_generate()</code> helper can still compute JSON totals without one.)
							<br /><strong>Check key</strong> validates the value above against the Invovate API from this server.
							On <a href="https://playground.wordpress.net" target="_blank" rel="noopener">WordPress&nbsp;Playground</a> it can report a failure even for a valid key —
							its in-browser proxy strips the <code>Authorization</code> header; test on a real host.
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
		<pre style="background:#f6f7f7;padding:10px;max-width:100%;overflow-x:auto;box-sizing:border-box;white-space:pre;">[invovate_invoice_form
  fields="from,to,items,currency,language,qr,link"
  from="" to=""
  currency="USD" language="en" template="classic"
  tax="true"   rows="1"   button="Generate PDF"
  qr="true"    link="true"]</pre>
		<table class="form-table" role="presentation" style="max-width:100%;">
			<tr><th scope="row" style="width:90px;">fields</th><td>Which inputs to show. Options: <code>from, to, items, currency, language, template, notes, qr, link</code> (the items table always shows). Add <code>qr</code> / <code>link</code> to let users toggle them on the form.</td></tr>
			<tr><th scope="row">from / to</th><td>Prefill (or, if not in <code>fields</code>, lock) the business + client name.</td></tr>
			<tr><th scope="row">tax</th><td><code>true</code> shows the per-item Tax&nbsp;% field.</td></tr>
			<tr><th scope="row">qr</th><td>Default for the scan-to-view QR in the PDF (<code>true</code>/<code>false</code>).</td></tr>
			<tr><th scope="row">link</th><td><code>true</code> = a 7-day shareable link; <code>false</code> = direct PDF download.</td></tr>
			<tr><th scope="row">rows / button</th><td>Number of starting line-item rows; the submit button label.</td></tr>
		</table>
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
			'fields'   => 'from,to,items,currency,language,qr,link',
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
	$grid  = $show_tax ? '2fr 1fr 1fr 1fr' : '2fr 1fr 1fr';

	wp_enqueue_script( 'invovate-form' ); // loaded in the footer; never inlined
	wp_enqueue_style( 'invovate-form' );  // assets/invovate-form.css (not inlined)

	ob_start();
	?>
	<form class="invovate-form" onsubmit="return false;"
		data-qr="<?php echo esc_attr( $qr_flag ); ?>" data-link="<?php echo esc_attr( $link_flag ); ?>"
		data-tax="<?php echo esc_attr( $show_tax ? 1 : 0 ); ?>"
		data-template="<?php echo esc_attr( $tpl ); ?>">
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

		<?php if ( $has( 'link' ) || $has( 'qr' ) ) : ?>
			<div style="display:flex;gap:1rem;flex-wrap:wrap;font-size:.9rem;">
				<?php if ( $has( 'link' ) ) : ?>
					<label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;">
						<input type="checkbox" class="inv-link" <?php checked( $link_flag, 1 ); ?> /> Shareable 7-day link <span style="color:#777;">(off = direct PDF download)</span>
					</label>
				<?php endif; ?>
				<?php if ( $has( 'qr' ) ) : ?>
					<label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;">
						<input type="checkbox" class="inv-qr" <?php checked( $qr_flag, 1 ); ?> /> Add scan-to-view QR
					</label>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<button type="submit" class="inv-go"><?php echo esc_html( $a['button'] ); ?></button>
		<div class="inv-result" style="font-size:.95rem;"></div>
	</form>
	<?php
	// All behaviour lives in the enqueued assets/invovate-form.js (see top of file
	// for why it is NOT inlined). Initial rows are server-rendered above; the
	// script reads data-* attributes + the nonce hidden input.
	return ob_get_clean();
} );

/* AJAX handler (logged-in + anonymous visitors of the page) */
function invovate_ajax_generate() {
	check_ajax_referer( 'invovate_generate', '_nonce' );

	// The form always needs a key (shareable links AND direct PDFs require auth).
	// Surface a clear, actionable message instead of the raw API "auth required" error.
	if ( '' === trim( (string) get_option( INVOVATE_OPT_KEY, '' ) ) ) {
		wp_send_json_error( array( 'message' => 'No Invovate API key is set. Add a free key under Settings → Invovate (and click Save Changes), then try again.' ) );
	}

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

	$items        = array();
	$missing_desc = false;
	foreach ( $items_in as $it ) {
		$desc  = isset( $it['description'] ) ? sanitize_text_field( $it['description'] ) : '';
		$qty   = isset( $it['quantity'] ) ? floatval( $it['quantity'] ) : 1;
		$price = isset( $it['unit_price'] ) ? floatval( $it['unit_price'] ) : 0;
		$tax   = ! empty( $it['tax_rate'] ) ? floatval( $it['tax_rate'] ) : 0;

		// Skip a truly-empty row, but never silently drop a priced row.
		if ( '' === $desc && 0.0 === $price ) {
			continue;
		}
		if ( '' === $desc ) {
			$missing_desc = true;
			continue;
		}
		if ( $qty < 0 || $price < 0 || $tax < 0 ) {
			wp_send_json_error( array( 'message' => 'Quantity, unit price and tax must be positive numbers.' ) );
		}
		$line = array( 'description' => $desc, 'quantity' => $qty, 'unit_price' => $price );
		if ( $tax > 0 ) {
			$line['tax_rate'] = $tax;
		}
		$items[] = $line;
	}
	if ( $missing_desc ) {
		wp_send_json_error( array( 'message' => 'Each item needs a description. Add one (or clear the empty row).' ) );
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

/* Admin-only: test that the saved key authenticates against the Invovate API. */
function invovate_test_key() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ) );
	}
	check_ajax_referer( 'invovate_test_key', '_nonce' );

	// Prefer the value typed in the box (lets you check before saving); fall back
	// to the saved option.
	$posted = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	$key    = '' !== $posted ? $posted : trim( (string) get_option( INVOVATE_OPT_KEY, '' ) );

	if ( '' === $key ) {
		wp_send_json_error( array( 'message' => 'No key to check. Paste your inv_ key in the box, then click Check key.' ) );
	}
	if ( 0 !== strpos( $key, 'inv_' ) ) {
		wp_send_json_error( array( 'message' => 'That does not look like an Invovate key — it should start with "inv_".' ) );
	}

	// A minimal call on the SAME authenticated path the form uses (a shareable
	// link requires auth — so this verifies the key authenticates end-to-end).
	$invoice = array(
		'from'     => array( 'name' => 'Connection Test' ),
		'to'       => array( 'name' => 'Connection Test' ),
		'currency' => 'USD',
		'language' => 'en',
		'template' => 'classic',
		'items'    => array( array( 'description' => 'Test', 'quantity' => 1, 'unit_price' => 1 ) ),
		'features' => array( 'hosted_link' => true ),
	);
	$result = invovate_generate( $invoice, array( 'output' => 'json', 'api_key' => $key ) );

	if ( is_wp_error( $result ) ) {
		$msg = $result->get_error_message();
		// "auth_required" came back even though we sent a key → the header was
		// stripped in transit (classic WordPress Playground proxy behaviour).
		if ( false !== stripos( $msg, 'free API key' ) || false !== stripos( $msg, 'sign-in' ) ) {
			$msg = 'The API received the request without the key (Authorization header was dropped in transit). '
				. 'On WordPress Playground its proxy strips that header — test on a real host. Original: ' . $msg;
		} elseif ( false !== stripos( $msg, 'invalid' ) || false !== stripos( $msg, 'unverified' ) ) {
			$msg = 'Key rejected by the API: ' . $msg . ' Generate/confirm a key at invovate.com/auth.';
		}
		wp_send_json_error( array( 'message' => $msg ) );
	}

	$inv = isset( $result['invoice'] ) ? $result['invoice'] : $result;
	if ( ! empty( $inv['hosted_url'] ) ) {
		wp_send_json_success( array( 'message' => 'API key is valid — authenticated request succeeded.' ) );
	}
	wp_send_json_success( array( 'message' => 'Key accepted (JSON totals OK), but no shareable link was returned.' ) );
}
add_action( 'wp_ajax_invovate_test_key', 'invovate_test_key' );
