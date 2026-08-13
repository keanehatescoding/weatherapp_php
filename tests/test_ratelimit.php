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
$geo = $w->geocodeUrl('New York');
check(str_contains($geo, 'https://example.com/api/geo/1.0/direct?q=New%20York'), 'geocodeUrl encodes city + base');
check(str_contains($geo, 'appid=SECRET_KEY'), 'geocodeUrl includes api key');

$oc = $w->oneCallUrl(-1.28, 36.81);
check(str_contains($oc, 'https://example.com/api/data/3.0/onecall?lat=-1.28&lon=36.81'), 'oneCallUrl builds One Call path');
check(str_contains($oc, 'units=metric'), 'oneCallUrl uses metric units');
check(str_contains($oc, 'appid=SECRET_KEY'), 'oneCallUrl includes api key');

$wImp = new Weather('203.0.113.9', 'SECRET_KEY', 'https://example.com/api', 'imperial');
check(str_contains($wImp->oneCallUrl(0, 0), 'units=imperial'), 'oneCallUrl honours imperial units');

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