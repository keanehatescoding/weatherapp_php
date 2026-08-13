<?php
declare(strict_types=1);

/**
 * WeatherApp backend controller.
 *
 * Thin glue layer that receives POST requests, validates input, delegates
 * to the Weather service for rate-limiting + API fetching + caching, and
 * renders the result page.
 */

require_once __DIR__ . '/app/Weather.php';
require_once __DIR__ . '/app/Env.php';
require_once __DIR__ . '/Icons.php';
require_once __DIR__ . '/Forecast.php';

// Load configuration from a local .env file (if present). Real environment
// variables always take precedence over values defined in .env.
loadEnvFile();

/*
 * Session MUST be started before ANY output is emitted, otherwise PHP cannot
 * send the Set-Cookie header and session-backed rate limiting silently breaks.
 */
if (session_status() === PHP_SESSION_NONE) {
	session_start([
		'cookie_httponly' => true,
		'cookie_samesite' => 'Lax',
		'use_strict_mode' => true,
	]);
}

$ip      = Weather::clientIp();
$weather = new Weather($ip);

// --- Request handling ----------------------------------------------------
$content     = '';
$statusCode  = 200;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	$statusCode = 405;
	$content    = Weather::errorAlert('Method ' . $_SERVER['REQUEST_METHOD'] . ' not allowed.');
} else {
	try {
		// Input handling
		$city = trim((string)($_POST['city'] ?? ''));
		if ($city === '') {
			throw new InvalidArgumentException('You must enter a city.');
		}
		// Mirrors sanitize.js — letters (any script, incl. accents),
		// spaces, . ' - ( ). 2–100 chars. 'D' = strict $ anchor.
		if (!preg_match('/^[\p{L}\p{M}\s.\'\-()]{2,100}$/uD', $city)) {
			throw new InvalidArgumentException(
				'Invalid city name — only letters, spaces, and ( ) - . \' are allowed.'
			);
		}

		$weather->enforceRateLimit();

		$apiKey = getenv('OPENWEATHERMAP_API_KEY');
		if ($apiKey === false || $apiKey === '') {
			throw new UserFacingException(
				'Weather API key is not configured. Set the OPENWEATHERMAP_API_KEY environment variable.'
			);
		}

		// --- Current weather ---
		$currentUrl = 'https://api.openweathermap.org/data/2.5/weather'
			. '?q=' . rawurlencode($city)
			. '&appid=' . rawurlencode($apiKey)
			. '&units=metric'
			. '&lang=en';

		[$status, $currentData] = Weather::fetchJson($currentUrl);
		if ($status !== 200) {
			Weather::log("Current weather API HTTP $status: " . ($currentData['message'] ?? ''), $ip, $city);
			if ($status === 401) {
				throw new UserFacingException(
					'Weather API key was rejected by the provider. Verify your OPENWEATHERMAP_API_KEY.'
				);
			}
			if ($status === 404) {
				throw new UserFacingException('City not found. Please check the spelling and try again.');
			}
			throw new UserFacingException('Weather service is temporarily unavailable. Please try again later.');
		}

		$weatherHtml = '';
		$name        = $currentData['name'] ?? '';
		$country     = $currentData['sys']['country'] ?? '';
		$tzOff       = isset($currentData['timezone']) ? (int)$currentData['timezone'] : 0;
		$main        = $currentData['main'] ?? [];
		$wind        = $currentData['wind'] ?? [];
		$weatherArr  = $currentData['weather'][0] ?? [];

		$locNow      = time() + $tzOff;
		$sunriseLocal = !empty($currentData['sys']['sunrise'])
			? $currentData['sys']['sunrise'] + $tzOff : null;
		$sunsetLocal  = !empty($currentData['sys']['sunset'])
			? $currentData['sys']['sunset'] + $tzOff : null;

		$weatherHtml .= '<div class="alert alert-info text-center mb-4 bg-white bg-opacity-90 border-0 shadow-lg" role="alert">';
		if ($name !== '' || $country !== '') {
			$weatherHtml .= '<h4 class="text-primary mb-3 fw-bold">'
				. Weather::esc($name) . ($country !== '' ? ', ' . Weather::esc($country) : '')
				. '</h4>';
		}
		$weatherHtml .= '<div class="row text-center g-0">';
		$weatherHtml .= '<div class="col-6 mb-2">';
		$weatherHtml .= '<p class="mb-1"><strong>Temperature:</strong> ' . Weather::esc(intval($main['temp'] ?? 0)) . '°C</p>';
		$weatherHtml .= '<p class="mb-1"><strong>Feels like:</strong> ' . Weather::esc(intval($main['feels_like'] ?? 0)) . '°C</p>';
		$weatherHtml .= '<p class="mb-1"><strong>Humidity:</strong> ' . Weather::esc(intval($main['humidity'] ?? 0)) . '%</p>';
		$weatherHtml .= '<p class="mb-1"><strong>Weather:</strong> '
			. Weather::esc(ucfirst($weatherArr['description'] ?? '')) . '</p>';
		$weatherHtml .= '</div>';
		$weatherHtml .= '<div class="col-6 mb-2">';
		$weatherHtml .= '<p class="mb-1"><strong>Pressure:</strong> ' . Weather::esc($main['pressure'] ?? '') . ' hPa</p>';
		$weatherHtml .= '<p class="mb-1"><strong>Wind:</strong> '
			. Weather::esc(round((float)($wind['speed'] ?? 0), 1)) . ' m/s</p>';
		$weatherHtml .= '<p class="mb-1"><strong>Sunrise:</strong> '
			. ($sunriseLocal !== null ? Weather::esc(gmdate('g:i a', $sunriseLocal)) : '—') . '</p>';
		$weatherHtml .= '<p class="mb-1"><strong>Sunset:</strong> '
			. ($sunsetLocal !== null ? Weather::esc(gmdate('g:i a', $sunsetLocal)) : '—') . '</p>';
		$weatherHtml .= '</div>';
		$weatherHtml .= '</div>';
		$weatherHtml .= '<div class="mt-3">';
		$weatherHtml .= '<div class="weather-emoji mb-2" style="font-size: 3rem;">'
			. Icons::get($weatherArr['icon'] ?? '') . '</div>';
		$weatherHtml .= '<p class="text-muted mb-0"><strong>Local time:</strong> '
			. Weather::esc(gmdate('F j, Y, g:i a', $locNow)) . '</p>';
		$weatherHtml .= '</div>';
		$weatherHtml .= '</div>';

		$content .= $weatherHtml;

		// --- Forecast (best-effort: do not kill the page if it fails) ---
		$forecastUrl = 'https://api.openweathermap.org/data/2.5/forecast'
			. '?q=' . rawurlencode($city)
			. '&appid=' . rawurlencode($apiKey)
			. '&units=metric'
			. '&lang=en';
		try {
			[$fStatus, $forecastData] = Weather::fetchJson($forecastUrl);
			if ($fStatus !== 200) {
				Weather::log("Forecast API HTTP $fStatus: " . ($forecastData['message'] ?? ''), $ip, $city);
				throw new UserFacingException('Could not load the extended forecast right now. Try again later.');
			}
			$content .= Forecast::displayForecast($forecastData);
		} catch (UserFacingException $e) {
			// Forecast-specific, user-safe message (e.g. unavailable). Keep the
			// current weather panel which already rendered above.
			$content .= '<p class="text-center text-muted mt-3 mb-0">' . Weather::esc($e->getMessage()) . '</p>';
		} catch (Throwable $e) {
			Weather::log('Forecast fetch failed: ' . $e->getMessage(), $ip, $city);
			$content .= '<p class="text-center text-muted mt-3 mb-0">'
				. 'Could not load the extended forecast right now. Try again later.'
				. '</p>';
		}

	} catch (RateLimitExceededException $e) {
		$statusCode = 429;
		Weather::log('Rate limit exceeded', $ip);
		$content .= Weather::errorAlert($e->getMessage());
	} catch (UserFacingException $e) {
		$statusCode = 400;
		Weather::log('User-facing error: ' . $e->getMessage(), $ip, $city ?? '');
		$content .= Weather::errorAlert($e->getMessage());
	} catch (InvalidArgumentException $e) {
		Weather::log('Validation error: ' . $e->getMessage(), $ip, $city ?? '');
		$content .= Weather::errorAlert($e->getMessage());
	} catch (Throwable $e) {
		Weather::log('Unhandled error: ' . $e->getMessage(), $ip);
		$statusCode = ($statusCode === 200) ? 500 : $statusCode;
		$content .= Weather::errorAlert('Something went wrong. Please try again later.');
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Weather App</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
	<link href="https://api.fontshare.com/v2/css?f[]=satoshi@400,500,600,700&display=swap" rel="stylesheet">
	<link href="styles.css" rel="stylesheet">
</head>
<body>
	<div class="container py-4">
		<div class="row justify-content-center">
			<div class="col-md-10 col-lg-8">
				<!-- Back Button -->
				<div class="mb-4">
					<a href="index.html" class="btn btn-outline-primary">
						← Back to Search
					</a>
				</div>

				<?php echo $content; ?>

			</div>
		</div>
	</div>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"></script>
</body>
</html>
<?php http_response_code($statusCode); ?>