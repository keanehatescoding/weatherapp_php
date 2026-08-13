<?php
/**
 * Forecast helper.
 *
 * Builds a responsive HTML card showing the next N days of weather from the
 * OpenWeatherMap 5-day / 3-hour forecast endpoint (free tier = 40 slots = 5
 * days). One slot every 8 (≈24h) is taken as the representative daytime
 * forecast for each day.
 */
require_once __DIR__ . '/Icons.php';
require_once __DIR__ . '/utils.php';

class Forecast {
	/**
	 * Render the daily forecast as HTML.
	 *
	 * @param array $forecastData Decoded OpenWeatherMap forecast payload.
	 *                            Must contain a `list` array of forecast slots.
	 * @return string HTML markup (sanitized).
	 * @throws InvalidArgumentException When the payload is malformed.
	 */
	public static function displayForecast(array $forecastData): string {
		if (!isset($forecastData['list']) || !is_array($forecastData['list'])) {
			throw new InvalidArgumentException('Forecast data is missing the "list" field.');
		}

		$slots   = $forecastData['list'];
		$today   = (int) gmdate('j');
		$daily   = [];
		$count   = count($slots);

		// Every 8th slot ≈ 24h apart. Skip "today" unless a future day exists.
		for ($i = 0; $i < $count && count($daily) < 5; $i += 8) {
			$slot = $slots[$i];
			if (!isset($slot['dt'])) {
				continue;
			}
			if ((int) gmdate('j', (int) $slot['dt']) === $today && count($daily) === 0) {
				continue; // leave today for the current-weather panel
			}
			$daily[] = $slot;
		}

		if ($daily === []) {
			return '';
		}

		$dayNames = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
		$hours    = [];
		$html     = '';
		$html    .= '<div class="forecast-container mt-4">';
		$html    .= '<h3 class="text-center mb-3 text-white">'
			. esc(count($daily)) . '-Day Forecast</h3>';
		$html    .= '<div class="row justify-content-center g-2">';

		foreach ($daily as $slot) {
			$hour   = (int) gmdate('G', (int) $slot['dt']);
			$hours[] = $hour;

			$desc    = $slot['weather'][0]['description'] ?? '';
			$icon    = $slot['weather'][0]['icon'] ?? '';
			$emoji   = Icons::get($icon);
			$temp    = round($slot['main']['temp'] ?? 0);
			$tempMin = round($slot['main']['temp_min'] ?? 0);
			$dayName = $dayNames[(int) gmdate('w', (int) $slot['dt'])];

			$html .= '<div class="col-xl-2 col-lg-2 col-md-4 col-sm-6 col-12 mb-3">';
			$html .= '<div class="forecast-item text-center p-3 border rounded bg-white bg-opacity-90 shadow-sm h-100">';
			$html .= '<h6 class="fw-bold text-dark mb-2">' . esc($dayName) . '</h6>';
			$html .= '<div class="weather-emoji mb-2" style="font-size: 2.5rem;">' . $emoji . '</div>';
			$html .= '<p class="mb-1 text-dark"><strong>'
				. esc((int) $temp) . '°/' . esc((int) $tempMin) . '°</strong></p>';
			$html .= '<p class="small text-muted mb-0">' . esc(ucfirst($desc)) . '</p>';
			$html .= '</div>';
			$html .= '</div>';
		}

		$html .= '</div>'; // /.row
		$html .= '</div>'; // /.forecast-container
		return $html;
	}
}
