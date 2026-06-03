/* Invovate Invoice Generator — admin settings "Check key" button.
 * Enqueued (not inline) per the WordPress.org plugin guidelines. Config (ajax URL
 * + nonce) is passed via wp_localize_script as window.INVOVATE_ADMIN. */
(function () {
	var cfg = window.INVOVATE_ADMIN || {};
	var btn = document.getElementById('invovate-check-btn');
	var out = document.getElementById('invovate-check-result');
	var inp = document.getElementById('invovate_api_key');
	if (!btn || !out || !cfg.ajax) { return; }
	btn.addEventListener('click', function () {
		out.style.color = '#646970'; out.textContent = 'Checking…'; btn.disabled = true;
		var fd = new FormData();
		fd.append('action', 'invovate_test_key');
		fd.append('_nonce', cfg.nonce || '');
		fd.append('key', inp ? inp.value : '');
		fetch(cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				btn.disabled = false;
				if (j && j.success) {
					out.style.color = '#1a7f37';
					out.textContent = '✓ ' + ((j.data && j.data.message) || 'API key is valid.');
				} else {
					out.style.color = '#b32d2e';
					out.textContent = '✗ ' + ((j.data && j.data.message) || 'Check failed.');
				}
			})
			.catch(function () {
				btn.disabled = false; out.style.color = '#b32d2e'; out.textContent = '✗ Network error.';
			});
	});
})();
