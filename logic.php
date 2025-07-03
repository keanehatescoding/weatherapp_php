<?php
require_once "Icons.php";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
	if (!isset($_POST["city"]) || empty(trim($_POST["city"]))) {
		die('<div id="city-warning" class="alert alert-danger" role="alert"><p>You must enter a city</p></div>');
	}
	$city = trim($_POST["city"]);

	// if this finds an illegal character then it dies immediately to avoid sending malicious input therefore preventing XSS 
	if (!preg_match("/^[\p{L}\p{M}\s\.\'\-\(\)]{2,100}$/u", $city)) {
		die('<div id="city-warning" class-"alert alert-danger" role="alert"><p>Invalid City name, it contains illegal characters</div>');
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
		die("We only allow 15 requests per day. Try again later");
	}
	$requests[] = $now;
	# XSS protection via input sanitzation
	$city = urlencode(htmlspecialchars($city));

	try { 
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

		$weather = '<b>'. htmlspecialchars($weather_Array['name']) . ", " . htmlspecialchars($weather_Array['sys']['country']) . '</b><br>';
		$weather .= 'Temperature: <b>' . intval($weather_Array['main']['temp']) . '°C</b><br>';
		$weather .= 'Humidity: <b>' . intval($weather_Array['main']['humidity']) . '%</b><br>';
		$weather .= 'Weather Condtion: <b>' . htmlspecialchars($weather_Array['weather']['0']['description'])  . '</b><br>';
		$weather .= 'Atmospheric Pressure <b>' . htmlspecialchars($weather_Array['main']['pressure'])  . ' hPa</b><br>';
		$weather .= 'Icon: ' . htmlspecialchars(Icons::get($weather_Array['weather']['0']['icon'])) . '<br>';

		date_default_timezone_set("Africa/Nairobi");
		$sunrise =  $weather_Array['sys']['sunrise'];
		$sunset = $weather_Array['sys']['sunset'];

		$weather .= "Sunrise: <b>" . date("g:i a", $sunrise) . '</b><br>';
		$weather .= "Sunset: <b>" . date("g:i a", $sunset) . '</b><br>';
		$weather .= "Current Time: <b>" . date("F j, Y, g:i a") . '</b><br>';

		$_SESSION["weather_result"] = $weather;
		header("Location: index.php");
		exit;
	} catch(Exception $e) {
		$error = '<div id="city-warning" class="alert alert-danger" role=alert"><p>Error occured: <b>' . htmlspecialchars($e->getMessage()) . ' </b><br></div>';
		die($error);
	}
} else {
	$error =  '<div class="alert alert-danger" role="alert"><p>You must enter a city</p></div>';
	echo $error;
}
?>
