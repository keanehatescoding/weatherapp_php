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

// --- Concurrency: N parallel processes hammering the same IP must never
// let more than RATE_LIMIT_MAX total requests through (regression test for
// the read-then-write race in enforceRateLimit()'s file-based counter). ---
$concurrentIp = '198.51.100.99'; // TEST-NET-2, distinct from the IP above
$rlFile2 = __DIR__ . '/../var/ratelimit/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $concurrentIp) . '.json';
@unlink($rlFile2);

$workers        = 8;
$attemptsEach   = 6; // 8 * 6 = 48 attempts, comfortably over the 30 limit
$procs          = [];
$pipes          = [];
for ($i = 0; $i < $workers; $i++) {
    $proc = proc_open(
        ['php', __DIR__ . '/_rate_limit_worker.php', $concurrentIp, (string)$attemptsEach],
        [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
        $procPipes
    );
    if (is_resource($proc)) {
        $procs[] = $proc;
        $pipes[] = $procPipes[1];
    }
}

$totalAccepted = 0;
foreach ($pipes as $i => $pipe) {
    $out = stream_get_contents($pipe);
    fclose($pipe);
    proc_close($procs[$i]);
    if (preg_match('/accepted:(\d+)/', (string)$out, $m)) {
        $totalAccepted += (int)$m[1];
    }
}

check(count($procs) === $workers, 'all concurrent workers launched');
check(
    $totalAccepted === Weather::RATE_LIMIT_MAX,
    "concurrent requests across $workers processes accept exactly " . Weather::RATE_LIMIT_MAX
        . ' total (got ' . $totalAccepted . ')'
);

@unlink($rlFile2);

echo "\n" . ($failures === 0 ? "ALL RATE-LIMIT TESTS PASSED\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);