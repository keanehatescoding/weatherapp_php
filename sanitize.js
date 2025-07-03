const cityInput = document.getElementById('city');
const warning = document.getElementById('city-warning');

cityInput.addEventListener('input', function () {
	const original = this.value;
	const sanitized = sanitizeCityName(original);

	if (original !== sanitized) {
		warning.style.display = 'block';
	} else {
		warning.style.display = 'none';
	}

	this.value = sanitized;
});

function sanitizeCityName(input) {
	return input
		.normalize("NFD")
		.replace(/[^A-Za-z\u00C0-\u017F\s.'\-]/g, '') // allow letters (incl. accents), spaces, period, apostrophe, hyphen
			.replace(/\s{2,}/g, ' ')
		}
