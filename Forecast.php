<?php
require_once "Icons.php";
/**
 * Forecast Class
 * 
 * Handles the generation and display of weather forecast data
 * 
 */
Class Forecast {
/**
 * @return string HTML string containing the formatted 7-day forecast
 * Display a 7-day weather forecast in HTML format
 * 
 * Takes forecast data from OpenWeatherMap API and generates a responsive
 * HTML layout showing the next 7 days of weather information including
 * temperature, weather conditions, and weather icons.
 * 
 * @param array $forecastData The forecast data array from OpenWeatherMap API
 *                           Expected structure: ['list' => [array of forecast items]]
 *                           Each forecast item should contain:
 *                           - 'dt': Unix timestamp
 *                           - 'main': ['temp', 'temp_min', 'humidity', etc.]
 *                           - 'weather': [['description', 'icon']]
 * 
 * 
 * @throws InvalidArgumentException If forecast data is invalid or missing required fields
 * 
 * @example
 * 
 * ```php
 * $forecastData = json_decode($apiResponse, true);
 * $forecastHtml = Forecast::displayForecast($forecastData);
 * echo $forecastHtml;
 * ```
 */
public static function displayForecast($forecastData) : String{
	$forecastHtml = '';

	// Get daily forecasts (every 8th item represents roughly 24 hours since data is every 3 hours)
	$dailyForecasts = [];
	$today = date('j'); // Get current day of month

	for ($i = 0; $i < count($forecastData['list']); $i += 8) {
		if (count($dailyForecasts) >= 7) break;

		$forecast = $forecastData['list'][$i];
		$date = date('j', $forecast['dt']);

		// Skip today's forecast to show next 7 days
		if ($date !== $today || count($dailyForecasts) > 0) {
			$dailyForecasts[] = $forecast;
		}
	}

	$dayNames = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];

	// Create the html structure
	$forecastHtml .= '<div class="forecast-container mt-4">';
	$forecastHtml .= '<h3 class="text-center mb-3 text-white">5-Day Forecast</h3>';
	$forecastHtml .= '<div class="row justify-content-center g-2">';

	foreach ($dailyForecasts as $forecast) {
		$dayName = $dayNames[date('w', $forecast['dt'])];
		$temp = round($forecast['main']['temp']);
		$tempMin = round($forecast['main']['temp_min']);
		$description = $forecast['weather'][0]['description'];
		$iconCode = $forecast['weather'][0]['icon'];
		$emoji = Icons::get($iconCode);

		// Updated responsive column classes to prevent squishing
		$forecastHtml .= '<div class="col-xl-2 col-lg-2 col-md-4 col-sm-6 col-12 mb-3">';
		$forecastHtml .= '<div class="forecast-item text-center p-3 border rounded bg-white bg-opacity-90 shadow-sm h-100">';
		$forecastHtml .= '<h6 class="fw-bold text-dark mb-2">' . htmlspecialchars($dayName) . '</h6>';
		$forecastHtml .= '<div class="weather-emoji mb-2" style="font-size: 2.5rem;">' . $emoji . '</div>';
		$forecastHtml .= '<p class="mb-1 text-dark"><strong>' . intval($temp) . '°/' . intval($tempMin) . '°</strong></p>';
		$forecastHtml .= '<p class="small text-muted mb-0">' . htmlspecialchars(ucfirst($description)) . '</p>';
		$forecastHtml .= '</div>';
		$forecastHtml .= '</div>';
	}

	$forecastHtml .= '</div>';
	$forecastHtml .= '</div>';

	return $forecastHtml;
}
}