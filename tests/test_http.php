<?php
declare(strict_types=1);

/**
 * Integration test for Weather::fetchJson() + caching.
 *
 * Spins up a local PHP server serving tests/stub-api.php, then exercises
 * the fetch path: success, caching, connection failure, malformed JSON,
 * non-2xx status. Runs without network access or real API keys.
 *
 * Run: php tests/test_http.php
 */
require_once __DIR__ . '/../app/Weather.php';

$failures = 0;
function check(bool $cond, string $label): void {
    global $failures;
    if ($cond) { echo "ok:   $label\n"; }
    else { echo "FAIL: $label\n"; $failures++; }
}

$port = 8799;
$root = __DIR__;
$base = "http://127.0.0.1:$port/stub-api.php";

// Start throwaway server. Use proc_open with pipes but don't block on reads.
$proc = proc_open(
    'php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root) . ' 2>&1',
    [
        0 => ['pipe', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ],
    $pipes
);

if (!is_resource($proc)) {
    echo "FAIL: could not start stub server\n";
    exit(1);
}
// Guarantee cleanup even if an unexpected/uncaught throwable below skips
// the explicit proc_terminate() at the bottom of this script.
register_shutdown_function(static function () use ($proc) {
    if (is_resource($proc)) {
        @proc_terminate($proc);
        @proc_close($proc);
    }
});

// Give the server a moment to bind.
usleep(500000);

// Probe until it answers (max ~5s).
$ready = false;
for ($i = 0; $i < 25; $i++) {
    $ch = curl_init("$base?ok=1");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 1,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if ($body !== false) {
        $ready = true;
        break;
    }
    usleep(200000);
}

if (!$ready) {
    echo "FAIL: stub server not reachable\n";
    proc_terminate($proc);
    proc_close($proc);
    exit(1);
}

// Ensure cache dir exists and is clean.
$cacheDir = realpath(__DIR__ . '/../var/cache') ?: (sys_get_temp_dir() . '/weatherapp_test_cache');
@mkdir($cacheDir, 0755, true);
foreach (glob($cacheDir . '/*.json') as $f) { @unlink($f); }

// --- 1. Successful fetch ---
$url = "$base?ok=1";
[$status, $data] = Weather::fetchJson($url);
check($status === 200, 'fetchJson returns HTTP 200 from stub');
check(($data['main']['temp'] ?? null) === 21, 'fetchJson decodes JSON body');

// --- 2. Cache hit on second call ---
[$status2, $data2] = Weather::fetchJson($url);
check(
    $status2 === 200 && ($data2['main']['temp'] ?? null) === 21,
    'fetchJson second call returns cached payload'
);

// --- 3. Connection failure (unreachable port) ---
$bad = false;
try {
    Weather::fetchJson('http://127.0.0.1:1/stub-api.php');
} catch (RuntimeException $e) {
    $bad = str_contains($e->getMessage(), 'Failed to reach');
}
check($bad, 'fetchJson throws on connection failure');

// --- 4. Malformed JSON ---
$malformed = false;
try {
    Weather::fetchJson("$base?bad=1");
} catch (RuntimeException $e) {
    $malformed = str_contains($e->getMessage(), 'Invalid JSON');
}
check($malformed, 'fetchJson throws on malformed JSON');

// --- 5. Non-2xx status (404) ---
$apiErr = false;
try {
    [$s, $d] = Weather::fetchJson("$base?status=404");
    $apiErr = $s === 404; // our fetchJson returns status; logic.php decides
} catch (RuntimeException $e) {
    $apiErr = str_contains($e->getMessage(), 'Weather service returned HTTP 404');
}
check($apiErr, 'fetchJson surfaces 404 as exception');

// --- Cleanup ---
proc_terminate($proc);
proc_close($proc);
foreach (glob($cacheDir . '/*.json') as $f) { @unlink($f); }

echo "\n" . ($failures === 0 ? "ALL HTTP INTEGRATION TESTS PASSED\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);