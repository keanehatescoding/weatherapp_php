<?php
declare(strict_types=1);

/**
 * Helper process for test_ratelimit.php's concurrency check. Not a
 * standalone test — invoked via proc_open with (ip, attempts) argv.
 * Calls enforceRateLimit() repeatedly and prints how many attempts were
 * accepted vs. rejected, so the parent can sum across concurrent workers.
 */
require_once __DIR__ . '/../app/Weather.php';

$ip       = $argv[1] ?? '';
$attempts = (int)($argv[2] ?? 0);

$weather  = new Weather($ip);
$accepted = 0;
$rejected = 0;

for ($i = 0; $i < $attempts; $i++) {
    try {
        $weather->enforceRateLimit();
        $accepted++;
    } catch (RateLimitExceededException $e) {
        $rejected++;
    }
}

echo "accepted:$accepted rejected:$rejected\n";
