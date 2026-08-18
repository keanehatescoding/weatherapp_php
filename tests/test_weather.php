<?php
declare(strict_types=1);

/**
 * Unit tests for the Weather core service (app/Weather.php).
 *
 * Run: php tests/test_weather.php
 */
require_once __DIR__ . '/../app/Weather.php';
require_once __DIR__ . '/../Icons.php';
require_once __DIR__ . '/../Forecast.php';

$failures = 0;
function check(bool $cond, string $label): void {
    global $failures;
    if ($cond) {
        echo "ok:   $label\n";
    } else {
        echo "FAIL: $label\n";
        $failures++;
    }
}

// --- Icons ---
check(Icons::get('02d') === '🌤️', 'few clouds day icon');
check(Icons::get('01d') === '☀️', 'clear sky day');
check(Icons::get('zzz') === '🌡️', 'unknown icon falls back');

// --- Forecast validation (empty/non-array input) ---
try {
    Forecast::displayForecast([]);
    check(true, 'forecast handles empty array');
} catch (Throwable $e) {
    check(false, 'forecast handles empty array (threw: ' . get_class($e) . ')');
}

// --- Forecast renders One Call 3.0 daily data ---
$tomorrow  = strtotime('+1 day', time());
$dayAfter = strtotime('+2 days', time());
$sample = [
    ['dt' => $tomorrow, 'temp' => ['min' => 18, 'max' => 22], 'weather' => [['description' => 'clear sky', 'icon' => '01d']]],
    ['dt' => $dayAfter, 'temp' => ['min' => 19, 'max' => 23], 'weather' => [['description' => 'few clouds', 'icon' => '02d']]],
];
$out = Forecast::displayForecast($sample);
check(str_contains($out, '7-Day Forecast'), 'forecast contains 7-day heading');
check(str_contains($out, '🌤️') || str_contains($out, '☀️'), 'forecast contains an emoji');
check(str_contains($out, '°/'), 'forecast shows temp range');

// --- Forecast day-of-week label honours tzOffset (regression: a location's
// UTC offset can push `dt` across the UTC day boundary, e.g. late-UTC
// timestamps at UTC+14 — the label must reflect the *local* day). ---
$lateUtc      = gmmktime(23, 30, 0, (int)gmdate('n'), (int)gmdate('j'), (int)gmdate('Y'));
$tzOffset     = 14 * 3600; // UTC+14 (e.g. Kiribati)
$dayNames     = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
$expectedDay  = $dayNames[(int) gmdate('w', $lateUtc + $tzOffset)];
$unshiftedDay = $dayNames[(int) gmdate('w', $lateUtc)];
$tzSample = [
    ['dt' => 0, 'temp' => ['min' => 0, 'max' => 0], 'weather' => [['description' => '', 'icon' => '01d']]], // "today", dropped
    ['dt' => $lateUtc, 'temp' => ['min' => 10, 'max' => 20], 'weather' => [['description' => 'clear sky', 'icon' => '01d']]],
];
$tzOut = Forecast::displayForecast($tzSample, $tzOffset);
check($expectedDay !== $unshiftedDay, 'test fixture crosses a UTC day boundary (sanity check)');
check(str_contains($tzOut, '>' . $expectedDay . '<'), "forecast day label honours tzOffset (expected $expectedDay)");

// --- Weather::esc() ---
$escapedScript = '&lt;script&gt;';  // literal <script>
check(Weather::esc('<script>') === $escapedScript, 'esc escapes angle brackets');
check(str_contains(Weather::esc('é'), 'é'), 'esc keeps accented letters');

// --- City regex (mirror of logic.php) ---
$regex = '/^[\p{L}\p{M}\s.\'\-()]{2,100}$/uD';
foreach (['Nairobi', 'São Paulo', 'København', "O'Brien", 'St. Louis', 'Washington D.C.'] as $city) {
    check(preg_match($regex, $city) === 1, "city accepts '$city'");
}
foreach (['', 'a', 'City7', 'x<>;', 'SELECT * FROM users', 'a' . str_repeat('b', 200)] as $bad) {
    check(preg_match($regex, $bad) === 0, "city rejects '$bad'");
}
// Chinese city names ARE accepted by \p{L} — this is intentional
check(preg_match($regex, '北京') === 1, "city accepts '北京' (CJK letters are \p{L})");

// --- Weather::log() just exercises the code path (output goes to error_log) ---
Weather::log('test message', '127.0.0.1', 'TestCity');
check(true, 'log() runs without throwing');

// --- Weather::errorAlert() ---
$alert = Weather::errorAlert('Test error');
check(str_contains($alert, 'Test error'), 'errorAlert includes message');
check(str_contains($alert, 'alert-danger'), 'errorAlert has danger class');

echo "\n" . ($failures === 0 ? "ALL UNIT TESTS PASSED\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);