<?php
declare(strict_types=1);

/**
 * Issues a CSRF token (JSON) for the current session.
 * index.html fetches this on load and embeds the token in the form.
 */
require_once __DIR__ . '/app/Weather.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
http_response_code(200);
echo json_encode(['token' => Weather::csrfToken()]);
