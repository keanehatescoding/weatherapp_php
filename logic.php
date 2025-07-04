<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
	<link href="styles.css" rel="stylesheet">
</head>
<body>
<?php
require_once "Icons.php";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
	if (!isset($_POST["city"]) || empty(trim($_POST["city"]))) {
		$error = "You must enter a city";
		error_log("[" . date("Y-m-d H:i:s") . "] " . $error);
		die('<div id="city-warning" class="alert alert-danger text-center" role="alert"><p><b>' . $error . '</b></p></div>');
	}
	$city = trim($_POST["city"]);

	// if this finds an illegal character then it dies immediately to avoid sending malicious input therefore preventing XSS 
	if (!preg_match("/^[\p{L}\p{M}\s\.\'\-\(\)]{2,100}$/u", $city)) {
		$error  =  "Inavlid city name, it contains illegal characters";
		error_log("[" . date("Y-m-d H:i:s") . "] " . $error);
		die('<div id="city-warning" class-"alert alert-danger" role="alert"><p><b>' . $error . '</div>');
	}

	// Basic rate limiting, OpenWeatherMap API allows a maximum of 1000 request per day on the free tier, we therefore have to limit the number of requests to 15 for each user per day.

	session_start();
	$ip = $_SERVER["REMOTE_ADDR"];
	$limit = 15;
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
	if (count($requests) >=$limit){
		http_response_code(429);
		error_log('[' . date("Y-m-d H:i:s") . "]" . $_SESSION["REMOTE_ADDR"] . ' has reached maximum number of requests ');
		die("We only allow 15 requests per day. Try again tommorow");
	}
	$requests[] = $now;
	# XSS protection via input sanitzation
	$city = urlencode(htmlspecialchars($city));

	try { 
		// This prevent hardcoding API_KEYS and leaking the OPENWEATHERMAP_API_KEY to production
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

		$weather = '<div class="alert alert-success text-center" role="alert" style="text-align:center; justify-content: center; align-items: center>"';
		$weather .= '<b>'. htmlspecialchars($weather_Array['name']) . ", " . htmlspecialchars($weather_Array['sys']['country']) . '</b><br>';
		$weather .= 'Temperature: <b>' . intval($weather_Array['main']['temp']) . '°C</b><br>';
		$weather .= 'Humidity: <b>' . intval($weather_Array['main']['humidity']) . '%</b><br>';
		$weather .= 'Weather Condition: <b>' . htmlspecialchars($weather_Array['weather']['0']['description'])  . '</b><br>';
		$weather .= 'Atmospheric Pressure <b>' . htmlspecialchars($weather_Array['main']['pressure'])  . ' hPa</b><br>';
		$weather .= 'Icon: ' . htmlspecialchars(Icons::get($weather_Array['weather']['0']['icon'])) . '<br>';

		date_default_timezone_set("Africa/Nairobi");
		$sunrise =  $weather_Array['sys']['sunrise'];
		$sunset = $weather_Array['sys']['sunset'];

		$weather .= "Sunrise: <b>" . date("g:i a", $sunrise) . '</b><br>';
		$weather .= "Sunset: <b>" . date("g:i a", $sunset) . '</b><br>';
		$weather .= "Current Time: <b>" . date("F j, Y, g:i a") . '</b><br>';
		$weather .= '</div>';

		echo $weather;

	} catch(Exception $e) {
		$error =  htmlspecialchars($e->getMessage);
		error_log("[" . date("Y-m-d H:i:s") . "] " . $error);
		die('<div id="city-warning" class="alert alert-danger" role=alert"><p>Error occured: <b>' . $error  . ' </b><br></div>');
	}
} else {
	http_response_code(405);
	$error = $_SERVER["REQUEST_METHOD"] . ' Method not allowed';
	error_log("[" . date("Y-m-d H:i:s") . "] " . $error);
	die('<div class="alert alert-danger" role="alert"><p><b>' . $error . '</b></p></div>');
}
?>
</body>
</html>
