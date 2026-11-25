<?php
declare(strict_types=1);

// Container/platform health check. Deliberately dependency-free: it answers
// only for the PHP process itself, so a broken API key does not take the
// service out of rotation. nginx serves this file directly.
header('Content-Type: application/json');
http_response_code(200);
echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
