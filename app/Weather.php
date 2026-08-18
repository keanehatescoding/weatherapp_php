<?php
declare(strict_types=1);

/**
 * Core service layer for the WeatherApp backend.
 *
 * Contains pure(ish) helpers (escaping, HTTP fetching with caching,
 * per-IP rate limiting, structured exceptions) that are independent of any
 * incoming HTTP request, so they can be unit-tested without triggering
 * the request pipeline in logic.php.
 */

require_once __DIR__ . '/../utils.php';

class RateLimitExceededException extends RuntimeException {}

/** Thrown when a CSRF token check fails. */
class CsrfException extends UserFacingException {}

/**
 * An exception whose message is safe to show to the end user.
 * Distinct from a generic Throwable so the controller can surface the
 * message directly instead of a generic "something went wrong" page.
 */
class UserFacingException extends RuntimeException {}

/** Centralised API interaction + caching logic. */
class Weather {
    /** @var int max requests per IP per window */
    const RATE_LIMIT_MAX    = 30;
    /** @var int rate-limit window in seconds (24h) */
    const RATE_LIMIT_WINDOW = 86400;
    /** @var int overall HTTP timeout in seconds */
    const API_TIMEOUT       = 12;
    /** @var int connection timeout in seconds */
    const API_CONNECT_TIMEOUT = 6;
    /** @var int cached-result TTL in seconds */
    const CACHE_TTL         = 600;

    /** Absolute base path for on-disk rate-limit + cache storage. */
    private static function baseDir(): string {
        $dir = __DIR__ . '/../var';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /** @var string Client IP used for rate limiting. */
    private string $ip;

    /** @var string OpenWeatherMap API key (injected; not read from env here). */
    private string $apiKey = '';

    /** @var string OpenWeatherMap base host (overridable for tests). */
    private string $baseUrl = 'https://api.openweathermap.org';

    /** @var string Temperature units passed to the API ('metric'|'imperial'). */
    private string $units = 'metric';

    /**
     * Resolve the real client IP, honouring X-Forwarded-For / X-Real-IP only
     * when the immediate peer (REMOTE_ADDR) is a configured trusted proxy.
     * This keeps per-IP rate limiting correct behind load balancers / CDNs.
     */
    public static function clientIp(): string {
        $server = $_SERVER;
        $remote = $server['REMOTE_ADDR'] ?? 'unknown';

        $trusted = self::trustedProxies();
        if ($trusted === [] || !in_array($remote, $trusted, true)) {
            return $remote;
        }

        $xff = $server['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            // X-Forwarded-For is client, proxy1, proxy2...; the original client
            // is the first public IP walking back from the end.
            $ips = array_reverse(array_map('trim', explode(',', $xff)));
            foreach ($ips as $ip) {
                if (filter_var(
                    $ip,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                ) !== false) {
                    return $ip;
                }
            }
        }

        $real = $server['HTTP_X_REAL_IP'] ?? '';
        if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP) !== false) {
            return $real;
        }

        return $remote;
    }

    /**
     * List of trusted proxy IPs (from TRUSTED_PROXIES env, comma-separated).
     * Empty means REMOTE_ADDR is treated as the client directly.
     *
     * @return string[]
     */
    private static function trustedProxies(): array {
        $raw = getenv('TRUSTED_PROXIES');
        if ($raw === false || $raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn($v) => $v !== ''));
    }

    /**
     * @param string $ip      Client IP (rate limiting). Defaults to clientIp().
     * @param string $apiKey  OpenWeatherMap API key (injected, not from env).
     * @param string $baseUrl Override API base URL (handy for tests/mocks).
     * @param string $units   'metric' or 'imperial'.
     */
    public function __construct(string $ip = '', string $apiKey = '', string $baseUrl = '', string $units = 'metric') {
        $this->ip     = $ip !== '' ? $ip : self::clientIp();
        $this->apiKey = $apiKey;
        if ($baseUrl !== '') {
            $this->baseUrl = rtrim($baseUrl, '/');
        }
        $this->units = $units === 'imperial' ? 'imperial' : 'metric';
    }

    /** Build the geocoding endpoint URL for a city name. */
    public function geocodeUrl(string $city): string {
        return rtrim($this->baseUrl, '/') . '/geo/1.0/direct?q=' . rawurlencode($city)
            . '&limit=1&appid=' . rawurlencode($this->apiKey);
    }

    /** Build the reverse-geocoding endpoint URL for coordinates. */
    public function reverseGeocodeUrl(float $lat, float $lon): string {
        return rtrim($this->baseUrl, '/') . '/geo/1.0/reverse?lat=' . $lat . '&lon=' . $lon
            . '&limit=1&appid=' . rawurlencode($this->apiKey);
    }

    /**
     * Build the One Call 3.0 endpoint URL for coordinates.
     * Excludes only minutely (not useful here); hourly + alerts are kept
     * since the response already includes them at no extra API cost.
     */
    public function oneCallUrl(float $lat, float $lon): string {
        return rtrim($this->baseUrl, '/') . '/data/3.0/onecall?lat=' . $lat . '&lon=' . $lon
            . '&exclude=minutely&units=' . $this->units
            . '&lang=en&appid=' . rawurlencode($this->apiKey);
    }

    /**
     * Resolve a city name to coordinates via the Geocoding API, then fetch
     * current + 7-day daily weather via One Call 3.0. Returns a consolidated
     * array the controller can render directly.
     *
     * @return array{name:string,country:string,state:string,lat:float,lon:float,timezone:string,timezone_offset:int,current:array,hourly:array,daily:array,alerts:array}
     * @throws UserFacingException on API key/quota/city-not-found/transport errors.
     */
    public function fetchByCity(string $city): array {
        [$gStatus, $geo] = self::fetchJson($this->geocodeUrl($city));
        if ($gStatus !== 200) {
            $this->throwApiError($gStatus, $geo, $city, 'geocode');
        }
        if (!is_array($geo) || $geo === [] || !isset($geo[0]['lat'], $geo[0]['lon'])) {
            throw new UserFacingException('City not found. Please check the spelling and try again.');
        }
        $g = $geo[0];
        return $this->fetchOneCall(
            (float)$g['lat'],
            (float)$g['lon'],
            $g['name'] ?? '',
            $g['country'] ?? '',
            $g['state'] ?? '',
            $city
        );
    }

    /**
     * Fetch current + 7-day daily weather for explicit coordinates (used by the
     * geolocation feature). Best-effort reverse geocoding provides a label.
     *
     * @return array{name:string,country:string,state:string,lat:float,lon:float,timezone:string,timezone_offset:int,current:array,hourly:array,daily:array,alerts:array}
     * @throws UserFacingException on API key/quota/transport errors.
     */
    public function fetchByCoords(float $lat, float $lon): array {
        $name = '';
        $country = '';
        // Best-effort: label the result with a human-readable place name.
        try {
            [$rStatus, $rev] = self::fetchJson($this->reverseGeocodeUrl($lat, $lon));
            if ($rStatus === 200 && is_array($rev) && isset($rev[0])) {
                $name    = $rev[0]['name'] ?? '';
                $country = $rev[0]['country'] ?? '';
            }
        } catch (Throwable $e) {
            Weather::log('Reverse geocode failed: ' . $e->getMessage(), $this->ip);
        }
        if ($name === '') {
            $name = 'Your location';
        }
        return $this->fetchOneCall($lat, $lon, $name, $country, '', 'geolocation');
    }

    /**
     * Call One Call 3.0 and consolidate the payload into the shape the
     * controller expects.
     *
     * @throws UserFacingException
     */
    private function fetchOneCall(float $lat, float $lon, string $name, string $country, string $state, string $ctx): array {
        [$oStatus, $one] = self::fetchJson($this->oneCallUrl($lat, $lon));
        if ($oStatus !== 200) {
            $this->throwApiError($oStatus, $one, $ctx, 'onecall');
        }
        if (!is_array($one) || !isset($one['current'], $one['daily'])) {
            throw new UserFacingException('Weather service returned an unexpected response. Please try again later.');
        }
        return [
            'name'            => $name,
            'country'         => $country,
            'state'           => $state,
            'lat'             => $lat,
            'lon'             => $lon,
            'timezone'        => $one['timezone'] ?? '',
            'timezone_offset' => (int)($one['timezone_offset'] ?? 0),
            'current'         => $one['current'],
            'hourly'          => is_array($one['hourly'] ?? null) ? $one['hourly'] : [],
            'daily'           => $one['daily'],
            'alerts'          => is_array($one['alerts'] ?? null) ? $one['alerts'] : [],
        ];
    }

    /**
     * Map an upstream API error status to a user-safe exception.
     *
     * @throws UserFacingException
     */
    private function throwApiError(int $status, array $payload, string $city, string $stage): void {
        Weather::log(ucfirst($stage) . ' API HTTP ' . $status . ': ' . ($payload['message'] ?? ''), $this->ip, $city);
        if ($status === 401) {
            throw new UserFacingException(
                'Weather API key was rejected by the provider. Verify your OPENWEATHERMAP_API_KEY.'
            );
        }
        if ($status === 404 || $status === 400) {
            throw new UserFacingException('City not found. Please check the spelling and try again.');
        }
        throw new UserFacingException('Weather service is temporarily unavailable. Please try again later.');
    }

    public static function log(string $message, string $ip = 'unknown', string $city = ''): void {
        $ctx = $city !== '' ? " city=\"$city\"" : '';
        error_log(sprintf('[%s] [%s]%s %s', date('c'), $ip, $ctx, $message));
    }

    /**
     * Return (generating if needed) the per-session CSRF token.
     */
    public static function csrfToken(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
            ]);
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate a submitted CSRF token against the session token.
     */
    public static function validateCsrf(?string $token): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
            ]);
        }
        $expected = $_SESSION['csrf_token'] ?? '';
        if ($expected === '' || $token === null || $token === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }

    /**
     * Escape a value for safe HTML output (UTF-8).
     */
    public static function esc($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Render a danger alert with a user-safe message.
     */
    public static function errorAlert(string $message): string {
        return '<div class="alert alert-danger text-center" role="alert">'
            . '<p class="mb-0 fw-bold">' . self::esc($message) . '</p>'
            . '</div>';
    }

    /**
     * Send a Retry-After header (seconds) when rate limited.
     */
    private static function sendRetryAfter(int $seconds): void {
        if (!headers_sent() && $seconds > 0) {
            @header('Retry-After: ' . $seconds);
        }
    }

    /**
     * Set the HTTP response code, but only if headers haven't been sent yet
     * (avoids warnings when called from CLI/tests after output).
     */
    private static function sendResponseCode(int $code): void {
        if (!headers_sent()) {
            @http_response_code($code);
        }
    }

    /**
     * Per-IP rate limiting.
     *
     * Uses a file-based store keyed by IP in ./var/ratelimit when writable
     * (the IP-based behaviour advertised in the README); falls back to the
     * PHP session store if the filesystem is read-only.
     *
     * @throws RateLimitExceededException
     */
    public function enforceRateLimit(): void {
        $now  = time();
        $dir  = $this->baseDir() . '/ratelimit';
        $file = null;

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $dir = null;
        }
        if ($dir !== null) {
            $key  = preg_replace('/[^A-Za-z0-9_.-]/', '_', $this->ip);
            $file = $dir . '/' . $key . '.json';
        }

        if ($file !== null) {
            // Hold an exclusive lock across the whole read-modify-write so
            // concurrent requests from the same IP can't both read the same
            // pre-append hit count and both slip under RATE_LIMIT_MAX.
            $handle = @fopen($file, 'c+');
            if ($handle !== false && flock($handle, LOCK_EX)) {
                $raw  = stream_get_contents($handle);
                $dec  = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;
                $hits = is_array($dec) ? $dec : [];
                $hits = array_values(array_filter($hits, function ($ts) use ($now) {
                    return ($now - (int)$ts) < self::RATE_LIMIT_WINDOW;
                }));
                if (count($hits) >= self::RATE_LIMIT_MAX) {
                    $retry = max(1, ((int)$hits[0] + self::RATE_LIMIT_WINDOW) - $now);
                    flock($handle, LOCK_UN);
                    fclose($handle);
                    self::sendRetryAfter($retry);
                    self::sendResponseCode(429);
                    throw new RateLimitExceededException(
                        'We only allow ' . self::RATE_LIMIT_MAX . ' requests per day. Try again later.'
                    );
                }
                $hits[] = $now;
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, json_encode($hits));
                fflush($handle);
                flock($handle, LOCK_UN);
                fclose($handle);
                return;
            }
            if ($handle !== false) {
                fclose($handle);
            }
            // Couldn't open/lock the file — fall through to the session store.
        }

        // Session fallback
        $_SESSION['rate_limit'] = $_SESSION['rate_limit'] ?? [];
        $byIp = &$_SESSION['rate_limit'][$this->ip];
        if (!is_array($byIp)) {
            $byIp = [];
        }
        $byIp = array_values(array_filter($byIp, function ($ts) use ($now) {
            return ($now - (int)$ts) < self::RATE_LIMIT_WINDOW;
        }));
        if (count($byIp) >= self::RATE_LIMIT_MAX) {
            $retry = max(1, ((int)($byIp[0] ?? $now) + self::RATE_LIMIT_WINDOW) - $now);
            self::sendRetryAfter($retry);
            self::sendResponseCode(429);
            throw new RateLimitExceededException(
                'We only allow ' . self::RATE_LIMIT_MAX . ' requests per day. Try again later.'
            );
        }
        $byIp[] = $now;
    }

    /**
     * Return cached decoded payload for $url or null on miss/expiry.
     */
    private static function cacheFetch(string $url) {
        $file = self::cacheFile($url);
        if ($file === null || !is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $blob = json_decode($raw, true);
        if (!is_array($blob) || ($blob['expires'] ?? 0) < time()) {
            @unlink($file);
            return null;
        }
        return $blob['payload'];
    }

    /** Persist a payload to the cache. */
    private static function cacheStore(string $url, $payload): void {
        $file = self::cacheFile($url);
        if ($file === null) {
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return; // cache unavailable — app still works
        }
        $blob = ['expires' => time() + self::CACHE_TTL, 'payload' => $payload];
        @file_put_contents($file, json_encode($blob), LOCK_EX);
    }

    private static function cacheFile(string $url): ?string {
        $base = self::baseDir();
        if (!is_dir($base) && !@mkdir($base, 0755, true)) {
            $base = sys_get_temp_dir() . '/weatherapp_cache';
            @mkdir($base, 0755, true);
        }
        if (!is_dir($base)) {
            return null;
        }
        return $base . '/cache/' . md5($url) . '.json';
    }

    /**
     * Best-effort exclusive lock on the cache slot for $url, used to
     * serialize concurrent cache-miss fetches. Returns null (proceed
     * unlocked) if a lock file can't be opened/acquired.
     *
     * @return resource|null
     */
    private static function acquireFetchLock(string $url) {
        $cacheFile = self::cacheFile($url);
        if ($cacheFile === null) {
            return null;
        }
        $dir = dirname($cacheFile);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        $handle = @fopen($cacheFile . '.lock', 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /** @param resource|null $handle */
    private static function releaseFetchLock($handle): void {
        if ($handle === null) {
            return;
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * HTTP GET returning [http_status, decoded_json].
     * Uses cURL with timeouts + SSL verification and surfaces real API
     * error payloads.
     *
     * @return array{0:int,1:array}
     * @throws RuntimeException on transport failure, bad JSON or non-2xx.
     */
    public static function fetchJson(string $url): array {
        // APCu cache (process-local acceleration) first.
        if (function_exists('apcu_fetch') && function_exists('apcu_store')) {
            $cached = apcu_fetch($url);
            if ($cached !== false && $cached !== null) {
                return [200, $cached];
            }
        }

        // Disk cache.
        $cached = self::cacheFetch($url);
        if ($cached !== null) {
            if (function_exists('apcu_store')) {
                @apcu_store($url, $cached, self::CACHE_TTL);
            }
            return [200, $cached];
        }

        // Serialize concurrent misses for the same URL so a cache expiry
        // doesn't fan out into N simultaneous upstream requests: the first
        // request to acquire the lock fetches and populates the cache,
        // everyone else blocks, then rechecks the (now warm) cache first.
        $lockHandle = self::acquireFetchLock($url);
        if ($lockHandle !== null) {
            $cached = self::cacheFetch($url);
            if ($cached !== null) {
                self::releaseFetchLock($lockHandle);
                if (function_exists('apcu_store')) {
                    @apcu_store($url, $cached, self::CACHE_TTL);
                }
                return [200, $cached];
            }
        }

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => self::API_CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => self::API_TIMEOUT,
                CURLOPT_USERAGENT      => 'WeatherApp/1.0 (+https://github.com/keanehatescoding/weatherapp_php)',
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body   = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err    = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                throw new RuntimeException("Failed to reach weather service: $err");
            }

            $data = json_decode($body, true);
            if (!is_array($data)) {
                throw new RuntimeException('Invalid JSON response from weather API.');
            }

            // Cache 2xx payloads.
            if ($status >= 200 && $status < 300) {
                self::cacheStore($url, $data);
                if (function_exists('apcu_store')) {
                    @apcu_store($url, $data, self::CACHE_TTL);
                }
            }

            return [$status, $data];
        } finally {
            self::releaseFetchLock($lockHandle);
        }
    }
}
