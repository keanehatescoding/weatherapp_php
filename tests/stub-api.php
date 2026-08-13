<?php
/**
 * Stub API for CI testing. Returns canned OpenWeatherMap-shaped responses.
 *
 * Query parameters:
 *   ?ok=1        -> 200 OK with valid weather JSON
 *   ?bad=1       -> 200 OK with malformed JSON (trailing comma)
 *   ?status=404  -> 404 with error JSON (simulates "city not found")
 *   default      -> 200 OK with minimal valid JSON
 */
$ok  = isset($_GET['ok']);
$bad = isset($_GET['bad']);
$status = isset($_GET['status']) ? (int)$_GET['status'] : 200;

if ($bad) {
	http_response_code(200);
	echo '{"main": {"temp": 123}, "weather": [{"description": "test", "icon": "01d"}]'; // missing }
	exit;
}

if ($status !== 200) {
	http_response_code($status);
	echo json_encode(['cod' => (string)$status, 'message' => 'city not found']);
	exit;
}

http_response_code(200);
echo json_encode([
	'name' => 'TestCity',
	'sys'  => ['country' => 'TC', 'sunrise' => time(), 'sunset' => time() + 50000],
	'main' => ['temp' => 21, 'feels_like' => 20, 'humidity' => 60, 'pressure' => 1013],
	'wind' => ['speed' => 3.5],
	'weather' => [['description' => 'clear sky', 'icon' => '01d']],
	'timezone' => 10800, // +3h
]);