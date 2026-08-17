(function () {
	'use strict';

	// PWA basics: register the app-shell service worker and surface an
	// offline indicator. Independent of the search form below.
	if ('serviceWorker' in navigator) {
		window.addEventListener('load', function () {
			navigator.serviceWorker.register('sw.js').catch(function () { /* offline support is best-effort */ });
		});
	}
	const offlineBanner = document.getElementById('offline-banner');
	if (offlineBanner) {
		function updateOfflineBanner() {
			offlineBanner.style.display = navigator.onLine ? 'none' : 'block';
		}
		window.addEventListener('online', updateOfflineBanner);
		window.addEventListener('offline', updateOfflineBanner);
		updateOfflineBanner();
	}

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

	// --- Recent searches (localStorage-backed, city-name searches only) ---
	const RECENT_KEY = 'weather_recent_cities';
	const RECENT_MAX = 5;
	const recentBox = document.getElementById('recent-searches');

	function loadRecent() {
		if (typeof localStorage === 'undefined') { return []; }
		let raw;
		try {
			raw = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
		} catch (e) {
			return [];
		}
		if (!Array.isArray(raw)) { return []; }

		const seen = new Set();
		const list = [];
		raw.forEach(function (c) {
			if (typeof c !== 'string') { return; }
			const trimmed = c.trim();
			const key = trimmed.toLowerCase();
			if (trimmed === '' || seen.has(key)) { return; }
			seen.add(key);
			list.push(trimmed);
		});
		return list.slice(0, RECENT_MAX);
	}

	function addRecent(city) {
		const trimmed = city.trim();
		if (trimmed === '' || typeof localStorage === 'undefined') { return; }
		let list = loadRecent().filter(function (c) { return c.toLowerCase() !== trimmed.toLowerCase(); });
		list.unshift(trimmed);
		list = list.slice(0, RECENT_MAX);
		try { localStorage.setItem(RECENT_KEY, JSON.stringify(list)); } catch (e) { /* storage full/disabled */ }
		renderRecent();
	}

	function renderRecent() {
		if (!recentBox) { return; }
		const list = loadRecent();
		recentBox.querySelectorAll('button').forEach(function (btn) { btn.remove(); });
		if (list.length === 0) {
			recentBox.style.display = 'none';
			return;
		}
		recentBox.style.display = 'flex';
		list.forEach(function (city) {
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'btn btn-sm btn-outline-primary recent-chip';
			btn.textContent = city;
			btn.addEventListener('click', function () {
				cityInput.value = city;
				geoCoords = null;
				cityInput.required = true;
				if (latInput) { latInput.value = ''; }
				if (lonInput) { lonInput.value = ''; }
				warning.style.display = 'none';
				if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
				else { form.submit(); }
			});
			recentBox.appendChild(btn);
		});
	}

	renderRecent();

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
		const checkedUnit = form.querySelector('input[name="unit"]:checked');
		const unitVal = checkedUnit ? checkedUnit.value : 'metric';

		let body = 'city=' + encodeURIComponent(cityVal)
			+ '&csrf=' + encodeURIComponent(token)
			+ '&unit=' + encodeURIComponent(unitVal);
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
			body: body,
			credentials: 'same-origin'
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				const box = document.getElementById('weather-result');
				if (box && data && typeof data.html === 'string') {
					box.innerHTML = data.html;
					box.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}
				if (data && data.status === 200 && cityVal.trim() !== '') {
					addRecent(cityVal);
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