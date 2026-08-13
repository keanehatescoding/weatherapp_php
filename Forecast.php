<?php
/**
 * Forecast helper.
 *
 * Builds a responsive HTML card showing the next 7 days of weather from the
 * OpenWeatherMap One Call 3.0 `daily` array. The API returns 8 slots (today
 * plus the following 7 days); we skip "today" (index 0) since the
 * current-weather panel already covers it.
 */
require_once __DIR__ . '/Icons.php';
require_once __DIR__ . '/utils.php';

class Forecast {
	/**
	 * Render the daily forecast as HTML.
	 *
	 * @param array $daily OpenWeatherMap One Call 3.0 `daily` array. Each entry
	 *                     has `dt`, `temp` (min/max) and `weather` (icon/desc).
	 * @return string HTML markup (sanitized).
	 */
	public static function displayForecast(array $daily): string {
		if (!is_array($daily) || $daily === []) {
			return '';
		}

		// Skip "today" (index 0) so the panel shows the next 7 days.
		$daily = array_slice($daily, 1, 7);
		if ($daily === []) {
			return '';
		}

		$dayNames = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
		$html     = '';
		$html    .= '<div class="forecast-container mt-4">';
		$html    .= '<h3 class="text-center mb-3 text-white">' . esc(7) . '-Day Forecast</h3>';
		$html    .= '<div class="row justify-content-center g-2">';

		foreach ($daily as $day) {
			$dt     = (int)($day['dt'] ?? time());
			$desc    = $day['weather'][0]['description'] ?? '';
			$icon    = $day['weather'][0]['icon'] ?? '';
			$emoji   = Icons::get($icon);
			$tempMax = round((float)($day['temp']['max'] ?? 0));
			$tempMin = round((float)($day['temp']['min'] ?? 0));
			$dayName = $dayNames[(int) gmdate('w', $dt)];

			$html .= '<div class="col-xl-2 col-lg-2 col-md-4 col-sm-6 col-12 mb-3">';
			$html .= '<div class="forecast-item text-center p-3 border rounded bg-white bg-opacity-90 shadow-sm h-100">';
			$html .= '<h6 class="fw-bold text-dark mb-2">' . esc($dayName) . '</h6>';
			$html .= '<div class="weather-emoji mb-2" style="font-size: 2.5rem;">' . $emoji . '</div>';
			$html .= '<p class="mb-1 text-dark"><strong>'
				. esc((int) $tempMax) . '°/' . esc((int) $tempMin) . '°</strong></p>';
			$html .= '<p class="small text-muted mb-0">' . esc(ucfirst($desc)) . '</p>';
			$html .= '</div>';
			$html .= '</div>';
		}

		$html .= '</div>'; // /.row
		$html .= '</div>'; // /.forecast-container
		return $html;
	}
}
