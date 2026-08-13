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

	/** Absolute base path used for on-disk rate-limit + cache storage. */
	private static function baseDir(): string {
		return realpath(__DIR__ . '/../') . '/var';
	}

	/** @var int Client IP used for rate limiting. */
	private string $ip;

	public function __construct(string $ip = '') {
		$this->ip = $ip !== '' ? $ip : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
	}

	/**
	 * Log a message with an ISO-8601 timestamp + originating IP/context.
	 */
	public static function log(string $message, string $ip = 'unknown', string $city = ''): void {
		$ctx = $city !== '' ? " city=\"$city\"" : '';
		error_log(sprintf('[%s] [%s]%s %s', date('c'), $ip, $ctx, $message));
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
			$hits = [];
			if (is_file($file)) {
				$raw = @file_get_contents($file);
				$dec = $raw !== false ? json_decode($raw, true) : null;
				if (is_array($dec)) {
					$hits = $dec;
				}
			}
			$hits = array_values(array_filter($hits, function ($ts) use ($now) {
				return ($now - (int)$ts) < self::RATE_LIMIT_WINDOW;
			}));
			if (count($hits) >= self::RATE_LIMIT_MAX) {
				http_response_code(429);
				throw new RateLimitExceededException(
					'We only allow ' . self::RATE_LIMIT_MAX . ' requests per day. Try again later.'
				);
			}
			$hits[] = $now;
			@file_put_contents($file, json_encode($hits), LOCK_EX);
			return;
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
			http_response_code(429);
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
		if ($base === false || !is_dir($base) && !@mkdir($base, 0755, true)) {
			$base = __DIR__ . '/../var';
			if (!is_dir($base)) {
				$base = sys_get_temp_dir() . '/weatherapp_cache';
				@mkdir($base, 0755, true);
			}
			if (!is_dir($base)) {
				return null;
			}
		}
		return $base . '/cache/' . md5($url) . '.json';
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
			isset($apcu) && function_exists('apcu_store') && apcu_store($url, $cached, self::CACHE_TTL);
			return [200, $cached];
		}

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
	}
}
