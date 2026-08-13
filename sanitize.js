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

	// Prevent double submissions (and thus duplicate API calls)
	form.addEventListener('submit', function () {
		const btn = this.querySelector('button[type="submit"]');
		if (btn) {
			btn.disabled = true;
			btn.textContent = 'Fetching… ⏳';
		}
	});
})();