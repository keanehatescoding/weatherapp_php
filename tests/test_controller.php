<?php
declare(strict_types=1);

/**
 * Controller-level integration test for logic.php using the built-in PHP
 * server. Exercises routing, CSRF, validation and config-error paths without
 * touching the real OpenWeatherMap API.
 *
 * Run: php tests/test_controller.php
 */

$failures = 0;
function check(bool $cond, string $label): void {
    global $failures;
    if ($cond) { echo "ok:   $label\n"; }
    else { echo "FAIL: $label\n"; $failures++; }
}

// Start a throwaway server (array command => no shell, so proc_terminate
// kills it directly and no zombie php -S is left running).
$port = 8851;
$root = dirname(__DIR__); // project root (not tests/)
$proc = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root],
    [
        0 => ['pipe', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ],
    $pipes
);
if (!is_resource($proc)) {
    echo "FAIL: could not start server\n";
    exit(1);
}
register_shutdown_function(static function () use ($proc) {
    if (is_resource($proc)) {
        @proc_terminate($proc);
        @proc_close($proc);
    }
});

// Wait for the server to accept connections (max ~5s).
$ready = false;
for ($i = 0; $i < 25; $i++) {
    $ch = curl_init("http://127.0.0.1:$port/csrf.php");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
    $out = curl_exec($ch);
    curl_close($ch);
    if ($out !== false) { $ready = true; break; }
    usleep(200000);
}
if (!$ready) {
    echo "FAIL: server did not start\n";
    proc_terminate($proc);
    proc_close($proc);
    exit(1);
}

$base = "http://127.0.0.1:$port";
$cookie = sys_get_temp_dir() . '/weatherapp_ctrl_cookie.txt';
@unlink($cookie);

function req(string $base, string $cookie, string $method, string $path, array $post = []): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $cookie,
        CURLOPT_COOKIEFILE     => $cookie,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    // Split headers/body on first blank line.
    $parts = explode("\r\n\r\n", $resp, 2);
    $body  = $parts[1] ?? $parts[0];
    return [$code, $body];
}

function tokenFor(string $base, string $cookie): ?string {
    $ch = curl_init($base . '/csrf.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie]);
    $json = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($json, true);
    return $data['token'] ?? null;
}

// 1) GET -> 405
[$code, $body] = req($base, $cookie, 'GET', '/logic.php');
check($code === 405, 'GET /logic.php returns 405');

// 2) POST without CSRF -> 403
[$code, $body] = req($base, $cookie, 'POST', '/logic.php', ['city' => 'Nairobi']);
check($code === 403, 'POST without CSRF token returns 403');

// Get a valid token for the rest.
$token = tokenFor($base, $cookie);

// 3) POST empty city -> 400 + message
[$code, $body] = req($base, $cookie, 'POST', '/logic.php', ['city' => '', 'csrf' => $token]);
check($code === 400 && str_contains($body, 'You must enter a city'), 'empty city rejected with 400');

// 4) POST invalid city (digits) -> 400 + message
[$code, $body] = req($base, $cookie, 'POST', '/logic.php', ['city' => 'City123', 'csrf' => $token]);
check($code === 400 && str_contains($body, 'Invalid city name'), 'invalid city rejected with 400');

// 5) POST valid city, no API key -> 400 + config message (no key leaked)
[$code, $body] = req($base, $cookie, 'POST', '/logic.php', ['city' => 'Nairobi', 'csrf' => $token]);
check($code === 400 && str_contains($body, 'API key is not configured'), 'missing API key surfaced safely');
check(!str_contains($body, '* your API key'), 'API key placeholder not echoed to user');

// 6) POST lat/lon (geolocation) without a city -> reaches API-key check,
//    not the "enter a city" requirement.
[$code, $body] = req($base, $cookie, 'POST', '/logic.php', ['lat' => '12.34', 'lon' => '56.78', 'csrf' => $token]);
check($code === 400 && str_contains($body, 'API key is not configured'), 'geolocation (lat/lon) skips city requirement');
check(!str_contains($body, 'You must enter a city'), 'geolocation path does not ask for a city');

// 7) POST out-of-range coords -> falls back to the city requirement.
[$code, $body] = req($base, $cookie, 'POST', '/logic.php', ['lat' => '999', 'lon' => '999', 'csrf' => $token]);
check($code === 400 && str_contains($body, 'You must enter a city'), 'invalid coordinates fall back to city requirement');

proc_terminate($proc);
proc_close($proc);
@unlink($cookie);

echo "\n" . ($failures === 0 ? "ALL CONTROLLER TESTS PASSED\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);