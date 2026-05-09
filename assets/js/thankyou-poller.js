(function () {
	'use strict';

	var root = document.querySelector('.tdp-payment-instructions');
	if (!root) return;

	var statusUrl = root.getAttribute('data-status-url');
	var statusKey = root.getAttribute('data-status-key');
	if (!statusUrl || !statusKey) return;

	var statusText = root.querySelector('.tdp-status-text');
	var statusDot = root.querySelector('.tdp-status-dot');

	function poll() {
		var url = statusUrl + (statusUrl.indexOf('?') === -1 ? '?' : '&') + 'key=' + encodeURIComponent(statusKey);
		fetch(url, { credentials: 'same-origin' })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) {
				if (!data) return;
				if (data.status === 'on-hold') {
					if (statusDot) statusDot.classList.add('tdp-pending');
					if (statusText) statusText.textContent = 'Pago detectado, esperando confirmación...';
				}
				if (data.is_paid) {
					if (statusDot) statusDot.classList.add('tdp-paid');
					if (statusText) statusText.textContent = 'Pago confirmado. Recargando...';
					setTimeout(function () { window.location.reload(); }, 1500);
				}
			})
			.catch(function () { /* ignore */ });
	}

	var copyBtn = root.querySelector('.tdp-copy');
	if (copyBtn) {
		copyBtn.addEventListener('click', function () {
			var sel = copyBtn.getAttribute('data-target');
			var node = root.querySelector(sel);
			if (!node) return;
			var text = node.textContent.trim();
			if (navigator.clipboard) {
				navigator.clipboard.writeText(text);
			} else {
				var ta = document.createElement('textarea');
				ta.value = text;
				document.body.appendChild(ta);
				ta.select();
				document.execCommand('copy');
				document.body.removeChild(ta);
			}
			var prev = copyBtn.textContent;
			copyBtn.textContent = '✓';
			setTimeout(function () { copyBtn.textContent = prev; }, 1500);
		});
	}

	poll();
	setInterval(poll, 10000);
})();
