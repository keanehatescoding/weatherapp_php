<?php declare(strict_types=1);
/**
 * Stub API for CI testing. Returns canned OpenWeatherMap-shaped responses.
 *
 * Routes by request path so the same file works both as a directly-served
 * script (tests/test_http.php) and as a PHP built-in-server router script
 * (tests/test_onecall.php), which forwards every path here.
 *
 *   /geo/1.0/direct     -> geocoding result (city -> coords)
 *   /geo/1.0/reverse    -> reverse geocoding result
 *   /data/3.0/onecall   -> One Call 3.0 (current + daily)
 *   /stub-api.php?ok=1  -> legacy valid current-weather JSON (test_http)
 *   /stub-api.php?bad=1 -> malformed JSON
 *   /stub-api.php?status=404 -> non-2xx status
 *
 * Special city names let tests exercise error mapping:
 *   NotFoundCity -> 404 (city not found)
 *   BadKey       -> 401 (rejected API key)
 */

$uri  = $_SERVER['REQUEST_URI'] ?? '/stub-api.php';
$path = parse_url($uri, PHP_URL_PATH) ?: '/stub-api.php';

function geoResponse(string $city): array {
    return [
        ['name' => $city, 'country' => 'KE', 'state' => '', 'lat' => -1.2868, 'lon' => 36.8172],
    ];
}

function oneCallResponse(): array {
    $daily = [];
    $base  = strtotime('today midnight');
    for ($i = 0; $i < 8; $i++) {
        $daily[] = [
            'dt'    => $base + $i * 86400,
            'temp'  => ['day' => 24 + $i, 'min' => 15 + $i, 'max' => 28 + $i],
            'weather' => [['description' => 'clear sky', 'icon' => '01d']],
        ];
    }
    return [
        'timezone'        => 'Africa/Nairobi',
        'timezone_offset' => 10800,
        'current'         => [
            'dt'         => time(),
            'sunrise'    => time() - 20000,
            'sunset'     => time() + 20000,
            'temp'       => 21.4,
            'feels_like' => 20.1,
            'pressure'   => 1013,
            'humidity'   => 60,
            'wind_speed' => 3.5,
            'weather'    => [['description' => 'clear sky', 'icon' => '01d']],
        ],
        'daily' => $daily,
    ];
}

if (str_contains($path, '/geo/1.0/direct')) {
    $city = $_GET['q'] ?? '';
    if ($city === 'NotFoundCity') {
        http_response_code(404);
        echo json_encode(['cod' => '404', 'message' => 'city not found']);
        exit;
    }
    if ($city === 'BadKey') {
        http_response_code(401);
        echo json_encode(['cod' => 401, 'message' => 'Invalid API key']);
        exit;
    }
    echo json_encode(geoResponse($city));
    exit;
}

if (str_contains($path, '/geo/1.0/reverse')) {
    echo json_encode([['name' => 'Your location', 'country' => 'KE', 'state' => '', 'lat' => -1.28, 'lon' => 36.81]]);
    exit;
}

if (str_contains($path, '/data/3.0/onecall')) {
    echo json_encode(oneCallResponse());
    exit;
}

// --- Legacy paths used by tests/test_http.php ---
$bad    = isset($_GET['bad']);
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
    'timezone' => 10800,
]);
