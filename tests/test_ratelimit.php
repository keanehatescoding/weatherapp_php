<?php
declare(strict_types=1);

/**
 * Unit tests for Weather rate limiting and URL builders.
 *
 * Run: php tests/test_ratelimit.php
 */
require_once __DIR__ . '/../app/Weather.php';

$failures = 0;
function check(bool $cond, string $label): void {
	global $failures;
	if ($cond) { echo "ok:   $label\n"; }
	else { echo "FAIL: $label\n"; $failures++; }
}

// --- URL builders ---
$w = new Weather('203.0.113.9', 'SECRET_KEY', 'https://example.com/api', 'metric');
$cur = $w->currentWeatherUrl('New York');
check(str_contains($cur, 'https://example.com/api/weather?q=New%20York'), 'currentWeatherUrl encodes city + base');
check(str_contains($cur, 'appid=SECRET_KEY'), 'currentWeatherUrl includes api key');
check(str_contains($cur, 'units=metric'), 'currentWeatherUrl uses metric units');

$wImp = new Weather('203.0.113.9', 'SECRET_KEY', 'https://example.com/api', 'imperial');
check(str_contains($wImp->forecastUrl('Paris'), 'units=imperial'), 'forecastUrl honours imperial units');
check(str_contains($wImp->forecastUrl('Paris'), 'https://example.com/api/forecast?q=Paris'), 'forecastUrl builds path');

// --- Rate limiting: 30 allowed, 31st throws ---
$ip = '198.51.100.23'; // TEST-NET-2, safe fake IP
$rlFile = __DIR__ . '/../var/ratelimit/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $ip) . '.json';
@unlink($rlFile);

$w2 = new Weather($ip);
$threw = false;
try {
	for ($i = 0; $i < 30; $i++) {
		$w2->enforceRateLimit();
	}
	check(true, '30 requests allowed');
} catch (Throwable $e) {
	check(false, '30 requests allowed (threw early: ' . get_class($e) . ')');
}

try {
	$w2->enforceRateLimit(); // 31st
} catch (RateLimitExceededException $e) {
	$threw = true;
}
check($threw, '31st request throws RateLimitExceededException');

// Clean up the rate-limit file we created.
@unlink($rlFile);

echo "\n" . ($failures === 0 ? "ALL RATE-LIMIT TESTS PASSED\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);