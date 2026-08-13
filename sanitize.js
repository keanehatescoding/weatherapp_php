(function () {
	'use strict';

	const cityInput = document.getElementById('city');
	const warning = document.getElementById('city-warning');
	const form = cityInput && cityInput.closest('form');
	const latInput = document.getElementById('lat');
	const lonInput = document.getElementById('lon');
	const geoBtn = document.getElementById('geo-btn');
	let geoCoords = null; // {lat, lon} when the user opted into geolocation

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
		// Typing a city cancels any pending geolocation choice.
		if (sanitized !== '') {
			geoCoords = null;
			this.required = true;
			if (latInput) { latInput.value = ''; }
			if (lonInput) { lonInput.value = ''; }
		}
	});

	// Geolocation: ask the browser for coordinates and submit by lat/lon.
	if (geoBtn) {
		geoBtn.addEventListener('click', function () {
			if (!('geolocation' in navigator)) {
				warning.textContent = 'Geolocation is not supported by your browser. Search by city name.';
				warning.style.display = 'block';
				return;
			}
			geoBtn.disabled = true;
			geoBtn.textContent = '📍 Locating…';
			navigator.geolocation.getCurrentPosition(
				function (pos) {
					geoCoords = { lat: pos.coords.latitude, lon: pos.coords.longitude };
					cityInput.required = false;
					cityInput.value = '';
					if (latInput) { latInput.value = pos.coords.latitude; }
					if (lonInput) { lonInput.value = pos.coords.longitude; }
					if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
					else { form.submit(); }
				},
				function () {
					warning.textContent = 'Could not get your location. Allow access or search by city name.';
					warning.style.display = 'block';
					geoBtn.disabled = false;
					geoBtn.textContent = '📍 Use my location';
				},
				{ timeout: 10000, enableHighAccuracy: false }
			);
		});
	}

	const csrfInput = document.getElementById('csrf-token');
	if (csrfInput && 'fetch' in window) {
		fetch('csrf.php', { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data && data.token) { csrfInput.value = data.token; }
			})
			.catch(function () { /* server-side still validates */ });
	}

	// Fetch a CSRF token and embed it in the form before submit.
	const unitRadios = form.querySelectorAll('input[name="unit"]');
	const resultBox = document.getElementById('weather-result');
	const storedUnit = (typeof localStorage !== 'undefined') ? localStorage.getItem('weather_unit') : null;
	unitRadios.forEach(function (r) {
		if (storedUnit && r.value === storedUnit) { r.checked = true; }
	});
	unitRadios.forEach(function (r) {
		r.addEventListener('change', function () {
			if (typeof localStorage !== 'undefined') {
				localStorage.setItem('weather_unit', r.value);
			}
			// Re-run the search with the new units if a result is shown.
			if (resultBox && resultBox.innerHTML.trim() !== '') {
				if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
				else { form.submit(); }
			}
		});
	});
	form.addEventListener('submit', function (e) {
		e.preventDefault();
		const btn = this.querySelector('button[type="submit"]');
		if (btn) {
			btn.disabled = true;
			btn.textContent = 'Fetching… ⏳';
		}

		const latVal = latInput ? latInput.value : '';
		const lonVal = lonInput ? lonInput.value : '';
		const cityVal = cityInput.value;
		const tokenInput = document.getElementById('csrf-token');
		const token = tokenInput ? tokenInput.value : '';

		let body = 'city=' + encodeURIComponent(cityVal) + '&csrf=' + encodeURIComponent(token);
		if (geoCoords || (latVal !== '' && lonVal !== '')) {
			const lat = geoCoords ? geoCoords.lat : latVal;
			const lon = geoCoords ? geoCoords.lon : lonVal;
			body += '&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lon);
		}

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
				if (geoBtn) {
					geoBtn.disabled = false;
					geoBtn.textContent = '📍 Use my location';
				}
			});
	});
})();