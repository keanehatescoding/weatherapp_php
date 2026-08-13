<?php
declare(strict_types=1);

/**
 * Integration test for the One Call 3.0 flow (geocode + one call) without
 * network or real API keys. Boots a local PHP router serving tests/stub-api.php.
 *
 * Run: php tests/test_onecall.php
 */
require_once __DIR__ . '/../app/Weather.php';

$failures = 0;
function check(bool $cond, string $label): void {
    global $failures;
    if ($cond) { echo "ok:   $label\n"; }
    else { echo "FAIL: $label\n"; $failures++; }
}

$port = 8807;
$testsDir = __DIR__;
$base = "http://127.0.0.1:$port";

// Router script makes every request land in stub-api.php.
$proc = proc_open(
    ['php', '-S', "127.0.0.1:$port", '-t', $testsDir, $testsDir . '/stub-api.php'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ],
    $pipes
);
if (!is_resource($proc)) {
    echo "FAIL: could not start stub router\n";
    exit(1);
}
register_shutdown_function(static function () use ($proc) {
    if (is_resource($proc)) {
        @proc_terminate($proc);
        @proc_close($proc);
    }
});

// Wait for readiness.
$ready = false;
for ($i = 0; $i < 25; $i++) {
    $ch = curl_init("$base/geo/1.0/direct?q=Nairobi&appid=x");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
    $body = curl_exec($ch);
    curl_close($ch);
    if ($body !== false) { $ready = true; break; }
    usleep(200000);
}
if (!$ready) {
    echo "FAIL: stub router not reachable\n";
    proc_terminate($proc);
    proc_close($proc);
    exit(1);
}

$weather = new Weather('203.0.113.9', 'TEST_KEY', $base, 'metric');

// 1) URL builders point at the right endpoints + units.
check(str_contains($weather->geocodeUrl('Paris'), '/geo/1.0/direct?q=Paris'), 'geocodeUrl targets geocoding API');
check(str_contains($weather->oneCallUrl(-1.2, 36.8), '/data/3.0/onecall?lat=-1.2&lon=36.8'), 'oneCallUrl targets One Call 3.0');
check(str_contains($weather->oneCallUrl(-1.2, 36.8), 'units=metric'), 'oneCallUrl honours metric units');
$wImp = new Weather('203.0.113.9', 'TEST_KEY', $base, 'imperial');
check(str_contains($wImp->oneCallUrl(0, 0), 'units=imperial'), 'oneCallUrl honours imperial units');

// 2) Happy path: geocode + one call => consolidated payload.
$threw = false;
try {
    $meta = $weather->fetchByCity('Nairobi');
} catch (Throwable $e) {
    $threw = true;
    echo '  unexpected exception: ' . $e->getMessage() . "\n";
}
check(!$threw, 'fetchByCity("Nairobi") returns without throwing');
if (!$threw) {
    check($meta['name'] === 'Nairobi', 'fetchByCity returns geocoded name');
    check(is_array($meta['current']) && isset($meta['current']['temp']), 'fetchByCity returns current weather');
    check($meta['timezone_offset'] === 10800, 'fetchByCity returns timezone offset');
    check(is_array($meta['daily']) && count($meta['daily']) === 8, 'fetchByCity returns 8 daily slots');
    check(isset($meta['daily'][1]['temp']['min'], $meta['daily'][1]['temp']['max']), 'daily slots carry min/max temps');
}

// 3) City not found (404) maps to a user-safe exception.
$cityNotFound = false;
try {
    $weather->fetchByCity('NotFoundCity');
} catch (UserFacingException $e) {
    $cityNotFound = str_contains($e->getMessage(), 'City not found');
}
check($cityNotFound, 'fetchByCity("NotFoundCity") throws user-safe "City not found"');

// 4) Rejected key (401) maps to a user-safe exception.
$keyRejected = false;
try {
    $weather->fetchByCity('BadKey');
} catch (UserFacingException $e) {
    $keyRejected = str_contains($e->getMessage(), 'rejected');
}
check($keyRejected, 'fetchByCity("BadKey") throws user-safe "key rejected"');

// 5) Geolocation: fetchByCoords reverse-geocodes a label and returns weather.
$threw2 = false;
try {
    $meta2 = $weather->fetchByCoords(-1.2868, 36.8172);
} catch (Throwable $e) {
    $threw2 = true;
    echo '  unexpected exception: ' . $e->getMessage() . "\n";
}
check(!$threw2, 'fetchByCoords returns without throwing');
if (!$threw2) {
    check($meta2['name'] === 'Your location', 'fetchByCoords reverse-geocodes a label');
    check(is_array($meta2['daily']) && count($meta2['daily']) === 8, 'fetchByCoords returns 8 daily slots');
}

proc_terminate($proc);
proc_close($proc);

echo "\n" . ($failures === 0 ? "ALL ONE-CALL TESTS PASSED\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
