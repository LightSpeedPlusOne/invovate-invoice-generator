/* Invovate Invoice Generator — front-end form logic.
 * Loaded as an enqueued script (NOT inline) so WordPress content filters never
 * mangle the JS (inline <script> in shortcode output gets `&&` entity-encoded). */
(function () {
	function cfgAjax() {
		if (window.INVOVATE_CFG && window.INVOVATE_CFG.ajax) { return window.INVOVATE_CFG.ajax; }
		if (window.ajaxurl) { return window.ajaxurl; }
		return '/wp-admin/admin-ajax.php';
	}

	// Build a fresh line-item row in JS (no HTML template in the page → nothing to filter).
	function makeRow(form) {
		var tax  = form.getAttribute('data-tax') === '1';
		var div  = document.createElement('div');
		div.className = 'inv-item';
		div.style.display = 'grid';
		div.style.gridTemplateColumns = tax ? '2fr 1fr 1fr 1fr' : '2fr 1fr 1fr';
		div.style.gap = '.4rem';
		var html = '<input type="text" class="d" placeholder="Description" />'
			+ '<input type="number" class="q" placeholder="Qty" value="1" step="any" />'
			+ '<input type="number" class="p" placeholder="Unit price" step="any" />';
		if (tax) { html += '<input type="number" class="t" placeholder="Tax %" step="any" />'; }
		div.innerHTML = html;
		return div;
	}

	function pickVal(form, sel) { var el = form.querySelector(sel); return el ? el.value : ''; }

	function collectItems(form) {
		var items = [], missingDesc = false, badNumber = false;
		Array.prototype.forEach.call(form.querySelectorAll('.inv-item'), function (row) {
			var desc  = (((row.querySelector('.d') || {}).value) || '').trim();
			var price = parseFloat((row.querySelector('.p') || {}).value);
			var qty   = parseFloat((row.querySelector('.q') || {}).value);
			var t     = row.querySelector('.t');
			var tax   = t ? parseFloat(t.value) : NaN;
			var hasPrice = isFinite(price) && price !== 0;
			var hasQty   = isFinite(qty) && qty !== 1 && qty !== 0; // qty defaults to 1
			if (!desc && !hasPrice && !hasQty) { return; }          // empty row → skip
			if (!desc) { missingDesc = true; return; }              // priced, no desc → flag
			if ((isFinite(price) && price < 0) || (isFinite(qty) && qty < 0) || (isFinite(tax) && tax < 0)) { badNumber = true; return; }
			var o = { description: desc, quantity: isFinite(qty) ? qty : 1, unit_price: isFinite(price) ? price : 0 };
			if (isFinite(tax) && tax > 0) { o.tax_rate = tax; }
			items.push(o);
		});
		return { items: items, missingDesc: missingDesc, badNumber: badNumber };
	}

	function submit(form) {
		var res = form.querySelector('.inv-result');
		var c = collectItems(form);
		if (c.badNumber)   { res.style.color = '#b32d2e'; res.textContent = '✗ Quantity, unit price and tax must be positive numbers.'; return; }
		if (c.missingDesc) { res.style.color = '#b32d2e'; res.textContent = '✗ Each item needs a description.'; return; }
		if (!c.items.length) { res.style.color = '#b32d2e'; res.textContent = '✗ Add at least one item (with a description).'; return; }
		res.style.color = ''; res.textContent = 'Generating…';

		var qrEl = form.querySelector('.inv-qr'), linkEl = form.querySelector('.inv-link');
		var fd = new FormData();
		fd.append('action', 'invovate_generate');
		fd.append('_nonce', pickVal(form, '.inv-nonce'));
		fd.append('from', pickVal(form, '.inv-from'));
		fd.append('to', pickVal(form, '.inv-to'));
		fd.append('currency', pickVal(form, '.inv-currency') || 'USD');
		fd.append('language', pickVal(form, '.inv-language') || 'en');
		fd.append('template', form.querySelector('.inv-template') ? pickVal(form, '.inv-template') : form.getAttribute('data-template'));
		if (form.querySelector('.inv-notes')) { fd.append('notes', pickVal(form, '.inv-notes')); }
		fd.append('items', JSON.stringify(c.items));
		var linkOn = linkEl ? linkEl.checked : (form.getAttribute('data-link') === '1');
		var qrOn   = qrEl ? qrEl.checked : (form.getAttribute('data-qr') === '1');
		if (!linkOn) { qrOn = false; } // the scan-to-view QR points at the hosted link — no link, no QR
		fd.append('qr', qrOn ? '1' : '0');
		fd.append('link', linkOn ? '1' : '0');

		fetch(cfgAjax(), { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (!j || !j.success || !j.data) {
					res.style.color = '#b32d2e';
					res.textContent = '✗ ' + ((j && j.data && j.data.message) || 'Could not generate the invoice.');
					return;
				}
				res.style.color = '';
				if (j.data.pdf_base64) {
					var bin = atob(j.data.pdf_base64), arr = new Uint8Array(bin.length);
					for (var k = 0; k < bin.length; k++) { arr[k] = bin.charCodeAt(k); }
					var url = URL.createObjectURL(new Blob([arr], { type: 'application/pdf' }));
					var a = document.createElement('a'); a.href = url; a.download = j.data.filename || 'invoice.pdf';
					document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
					res.textContent = '✓ PDF downloaded.';
				} else if (j.data.hosted_url) {
					var link = document.createElement('a');
					link.href = j.data.hosted_url; link.target = '_blank'; link.rel = 'noopener';
					link.textContent = 'Download your invoice PDF';
					res.textContent = '✓ ';
					res.appendChild(link);
					res.appendChild(document.createTextNode(' (link valid 7 days)'));
				} else {
					res.textContent = '✓ Done.';
				}
			})
			.catch(function () { res.style.color = '#b32d2e'; res.textContent = '✗ Network error.'; });
	}

	function init(form) {
		if (form.getAttribute('data-inv-init')) { return; }
		form.setAttribute('data-inv-init', '1');
		var add = form.querySelector('.inv-add');
		if (add) { add.addEventListener('click', function () { form.querySelector('.inv-items').appendChild(makeRow(form)); }); }
		var go = form.querySelector('.inv-go');
		if (go) { go.addEventListener('click', function () { submit(form); }); }

		// The QR is "scan to view the link" — disable it when the link is off.
		var linkEl = form.querySelector('.inv-link'), qrEl = form.querySelector('.inv-qr');
		if (linkEl && qrEl) {
			var qrLabel = qrEl.parentNode;
			var sync = function () {
				qrEl.disabled = !linkEl.checked;
				if (qrLabel && qrLabel.style) { qrLabel.style.opacity = linkEl.checked ? '' : '.45'; }
			};
			linkEl.addEventListener('change', sync);
			sync();
		}
	}

	function boot() { Array.prototype.forEach.call(document.querySelectorAll('.invovate-form'), init); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
