<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Weather App</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
	<link href="styles.css" rel="stylesheet">
</head>
<body>
	<div class="container py-4">
		<div class="row justify-content-center">
			<div class="col-md-10 col-lg-8">
<?php
require_once "Icons.php";
require_once "Forecast.php";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
	if (!isset($_POST["city"]) || empty(trim($_POST["city"]))) {
		$error = "You must enter a city";
		error_log("[" . date("Y-m-d H:i:s") . "] " . $error);
		die('<div id="city-warning" class="alert alert-danger text-center" role="alert"><p><b>' . $error . '</b></p></div>');
	}
	$city = trim($_POST["city"]);

	// if this finds an illegal character then it dies immediately to avoid sending malicious input therefore preventing XSS 
	if (!preg_match("/^[\p{L}\p{M}\s\.\'\-\(\)]{2,100}$/u", $city)) {
		$error = "Invalid city name, it contains illegal characters";
		error_log("[" . date("Y-m-d H:i:s") . "] " . $error);
		die('<div id="city-warning" class="alert alert-danger text-center" role="alert"><p><b>' . $error . '</b></p></div>');
	}

	// Basic rate limiting, OpenWeatherMap API allows a maximum of 1000 request per day on the free tier, we therefore have to limit the number of requests to 15 for each user per day.
	session_start();
	$ip = $_SERVER["REMOTE_ADDR"];
	$limit = 30;
	$interval = 86400; // 24 hours in seconds

	if (!isset($_SESSION["rate_limit"])){
		$_SESSION["rate_limit"] = [];
	}

	$requests = &$_SESSION["rate_limit"][$ip];
	if(!is_array($requests)){
		$requests = [];
	}
	$now = time();

	$requests = array_filter($requests ?? [], function ($timestamp) use ($now, $interval) {
		return ($now - $timestamp) < $interval;
	});
	if (count($requests) >= $limit){
		http_response_code(429);
		error_log('[' . date("Y-m-d H:i:s") . "] " . $_SERVER["REMOTE_ADDR"] . ' has reached maximum number of requests ');
		die('<div class="alert alert-danger text-center" role="alert"><p><b>We only allow 20 requests per day. Try again tomorrow</b></p></div>');
	}
	$requests[] = $now;

	# XSS protection via input sanitization
	$city = urlencode(htmlspecialchars($city));

	try { 
		// This prevents hardcoding API_KEYS and leaking the OPENWEATHERMAP_API_KEY to production
		$apiKey = getenv('OPENWEATHERMAP_API_KEY');
		if(!$apiKey){
			throw new Exception("API KEY not configured");
		}

		$apiData = file_get_contents("https://api.openweathermap.org/data/2.5/weather?q=$city&appid=$apiKey&units=metric");
		if($apiData == false){
			throw new Exception("Failed to Fetch Weather Data");
		}

		$weather_Array = json_decode($apiData,true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception("Invalid JSON response from weather API");
		}
		if (!isset($weather_Array['main']) || !isset($weather_Array['name'])) {
			throw new Exception("Invalid weather data received from API");
		}

		$forecastData = file_get_contents("https://api.openweathermap.org/data/2.5/forecast?q=$city&appid=$apiKey&units=metric");
		if($forecastData == false){
			throw new Exception("Failed to Fetch Forecast Data");
		}

		$forecast_Array = json_decode($forecastData, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception("Invalid JSON response from forecast API");
		}

		// Current weather display
		$weather = '<div class="alert alert-info text-center mb-4 bg-white bg-opacity-90 border-0 shadow" role="alert">';
		$weather .= '<h4 class="text-primary mb-3">'. htmlspecialchars($weather_Array['name']) . ", " . htmlspecialchars($weather_Array['sys']['country']) . '</h4>';
		$weather .= '<div class="row text-center">';
		$weather .= '<div class="col-md-6 mb-2">';
		$weather .= '<p class="mb-1"><strong>Temperature:</strong> ' . intval($weather_Array['main']['temp']) . '°C</p>';
		$weather .= '<p class="mb-1"><strong>Humidity:</strong> ' . intval($weather_Array['main']['humidity']) . '%</p>';
		$weather .= '<p class="mb-1"><strong>Weather:</strong> ' . htmlspecialchars(ucfirst($weather_Array['weather']['0']['description'])) . '</p>';
		$weather .= '</div>';
		$weather .= '<div class="col-md-6 mb-2">';
		$weather .= '<p class="mb-1"><strong>Pressure:</strong> ' . htmlspecialchars($weather_Array['main']['pressure']) . ' hPa</p>';

		date_default_timezone_set("Africa/Nairobi");
		$sunrise = $weather_Array['sys']['sunrise'];
		$sunset = $weather_Array['sys']['sunset'];

		$weather .= '<p class="mb-1"><strong>Sunrise:</strong> ' . date("g:i a", $sunrise) . '</p>';
		$weather .= '<p class="mb-1"><strong>Sunset:</strong> ' . date("g:i a", $sunset) . '</p>';
		$weather .= '</div>';
		$weather .= '</div>';
		$weather .= '<div class="mt-3">';
		$weather .= '<div class="weather-emoji mb-2" style="font-size: 3rem;">' . htmlspecialchars(Icons::get($weather_Array['weather']['0']['icon'])) . '</div>';
		$weather .= '<p class="text-muted mb-0"><strong>Current Time:</strong> ' . date("F j, Y, g:i a") . '</p>';
		$weather .= '</div>';
		$weather .= '</div>';

		echo $weather;
		echo Forecast::displayForecast($forecast_Array);

	} catch(Exception $e) {
		$error = htmlspecialchars($e->getMessage());
		error_log("[" . date("Y-m-d H:i:s") . "] " . $error);
		die('<div id="city-warning" class="alert alert-danger text-center" role="alert"><p><b>Error occurred: ' . $error . '</b></p></div>');
	}
} else {
	http_response_code(405);
	$error = $_SERVER["REQUEST_METHOD"] . ' Method not allowed';
	error_log("[" . date("Y-m-d H:i:s") . "] " . $error);
	die('<div class="alert alert-danger text-center" role="alert"><p><b>' . $error . '</b></p></div>');
}
?>
			</div>
		</div>
	</div>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"></script>
</body>
</html>
