(function () {
	'use strict';

	const cityInput = document.getElementById('city');
	const warning = document.getElementById('city-warning');
	const form = cityInput && cityInput.closest('form');

	if (!cityInput || !warning || !form) {
		return; // page structure missing; server-side validation still protects us
	}

	/**
	 * Sanitize a city name client-side.
	 * Mirrors the server-side regex in logic.php:
	 *   /^[\p{L}\p{M}\s.'\-()]{2,100}$/uD
	 * Allows letters in any script (incl. accents & combining marks), spaces,
	 * periods, apostrophes, hyphens and parentheses. Strips everything else.
	 */
	function sanitizeCityName(input) {
		return input
			.normalize('NFD')
			.replace(/[^\p{L}\p{M}\s.'\-()]/gu, '') // keep letters, marks, space, . ' - ( )
			.replace(/\s{2,}/g, ' ');               // collapse multiple spaces
	}

	cityInput.addEventListener('input', function () {
		const original = this.value;
		const sanitized = sanitizeCityName(original);
		warning.style.display = original === sanitized ? 'none' : 'block';
		this.value = sanitized;
	});

	// Fetch a CSRF token and embed it in the form before submit.
	const csrfInput = document.getElementById('csrf-token');
	if (csrfInput && 'fetch' in window) {
		fetch('csrf.php', { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data && data.token) { csrfInput.value = data.token; }
			})
			.catch(function () { /* server-side still validates */ });
	}
	form.addEventListener('submit', function (e) {
		e.preventDefault();
		const btn = this.querySelector('button[type="submit"]');
		if (btn) {
			btn.disabled = true;
			btn.textContent = 'Fetching… ⏳';
		}

		const cityVal = cityInput.value;
		const tokenInput = document.getElementById('csrf-token');
		const token = tokenInput ? tokenInput.value : '';

		fetch('logic.php', {
			method: 'POST',
			headers: {
				'X-Requested-With': 'XMLHttpRequest',
				'Content-Type': 'application/x-www-form-urlencoded'
			},
			body: 'city=' + encodeURIComponent(cityVal) + '&csrf=' + encodeURIComponent(token),
			credentials: 'same-origin'
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				const box = document.getElementById('weather-result');
				if (box && data && typeof data.html === 'string') {
					box.innerHTML = data.html;
					box.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}
			})
			.catch(function () {
				// Network/JSON failure: fall back to a normal full-page POST.
				form.submit();
			})
			.finally(function () {
				if (btn) {
					btn.disabled = false;
					btn.textContent = 'Get Weather 🔍';
				}
			});
	});
})();