# WeatherApp

A lightweight, responsive weather application written in PHP. It uses the
[OpenWeatherMap One Call 3.0 API](https://openweathermap.org/api) to display
current conditions and a 7‑day forecast for any supported location. Search by
city name, or use the **📍 Use my location** button to fetch weather for your
current coordinates.

---

## Features

- **Current weather & 7‑day forecast** — temperature, "feels like", humidity,
  pressure, wind, sunrise/sunset, and an emoji icon, followed by a 7‑day outlook
  rendered from One Call 3.0 `daily` data.
- **Hourly forecast** — a scrollable strip of the next 12 hours (temperature,
  icon, and chance of precipitation) from One Call 3.0 `hourly` data.
- **Weather alerts** — active severe‑weather warnings from national weather
  agencies, sourced from One Call 3.0 `alerts` data, shown above the current
  conditions when present.
- **Geolocation** — request the browser's current position and look up weather
  by latitude/longitude (with a best‑effort reverse‑geocoded place name).
- **°C / °F toggle** — unit preference is remembered across visits via
  `localStorage`.
- **Progressive enhancement** — results are fetched with `fetch()` and rendered
  inline; the form still works as a full‑page POST when JavaScript is disabled.
- **Input validation** — city names are validated on both the client
  (sanitize.js) and the server (logic.php) using the same regular expression,
  and all output is HTML‑escaped to prevent XSS.
- **CSRF protection** — state‑changing requests require a session‑bound CSRF
  token (`csrf.php`).
- **Security headers** — `Content-Security-Policy`, `X-Content-Type-Options`,
  and `X-Frame-Options`, plus `Cache-Control: no-store`.
- **Rate limiting** — per‑IP limits (30 requests / 24h) enforced by a
  file‑based counter in `./var/ratelimit`, with a session‑backed fallback when
  the filesystem is read‑only. Exceeding the limit returns HTTP 429 with a
  `Retry-After` header.
- **Response caching** — successful API responses are cached on disk (and in
  APCu when available) for ~10 minutes to minimise quota usage.
- **Configuration flexibility** — the API key can be supplied via an environment
  variable or a local `.env` file (see `.env.example`).
- **Installable / offline‑capable (PWA)** — a web app manifest and a service
  worker (`sw.js`) cache the static UI shell, so the search form still loads
  (and the browser's "Add to Home Screen" / install prompt works) without a
  network connection. Weather data itself always requires a live request.

---

## Requirements

- PHP **8.1+** with the `curl`, `json`, and `session` extensions.
- An [OpenWeatherMap](https://openweathermap.org) API key.
  > **Note:** One Call 3.0 (used for the 7‑day forecast) is free on the standard
  > tier, but you must subscribe the key to the **One Call API** once in the
  > OpenWeatherMap dashboard.

---

## Installation

```bash
git clone https://github.com/keanehatescoding/weatherapp_php.git
cd weatherapp_php
```

### Configure the API key

Set the key as an environment variable named `OPENWEATHERMAP_API_KEY`.

**Linux / macOS**

```bash
export OPENWEATHERMAP_API_KEY="your_api_key_here"
```

**Windows (PowerShell)**

```powershell
[Environment]::SetEnvironmentVariable("OPENWEATHERMAP_API_KEY", "YOUR_API_KEY", "User")
```

Alternatively, copy `.env.example` to `.env` and set the value there; the `.env`
file is loaded automatically and is git‑ignored.

---

## Running the application

### Built‑in PHP server

```bash
php -S localhost:8000
```

Then open <http://localhost:8000> in your browser.

### Docker

```bash
docker compose up --build
```

The service is exposed on <http://localhost:8000>. Provide the API key through
your environment (or a `.env` file) as described above.

---

## Project structure

| Path | Purpose |
|------|---------|
| `index.html` | Search UI and unit toggle. |
| `sanitize.js` | Client‑side input sanitisation, CSRF token fetch, geolocation, AJAX. |
| `logic.php` | Request controller (validation, rate limiting, rendering). |
| `csrf.php` | Issues the per‑session CSRF token. |
| `app/Weather.php` | API client: geocoding, One Call 3.0, caching, rate limiting. |
| `app/Env.php` | Dependency‑free `.env` loader. |
| `Forecast.php` | Renders the 7‑day forecast markup. |
| `Hourly.php` | Renders the hourly forecast strip. |
| `Alerts.php` | Renders active severe‑weather alerts. |
| `Icons.php` | Maps OpenWeatherMap icon codes to emoji. |
| `utils.php` | Shared HTML‑escaping helper. |
| `manifest.webmanifest` | PWA metadata (name, icons, theme colour). |
| `sw.js` | Service worker: caches the static UI shell for offline use. |
| `icons/` | App icons used by the manifest and `apple-touch-icon`. |
| `tests/` | Unit and integration tests (no network or real key required). |
| `.github/workflows/ci.yml` | Continuous integration pipeline. |

---

## Security model

- **No secret leakage** — API keys are read server‑side only and are never
  echoed to the client. Transport/API errors show generic, user‑safe messages.
- **Defence in depth** — input is validated on the client and again on the
  server, and all rendered values are HTML‑escaped.
- **Hardened headers** — a restrictive CSP, `X-Content-Type-Options: nosniff`,
  and `X-Frame-Options: DENY` are emitted on every response.
- **Trusted‑proxy aware** — the real client IP for rate limiting honours
  `X-Forwarded-For` / `X-Real-IP` only when the immediate peer is listed in
  `TRUSTED_PROXIES`.

---

## Development

Install development dependencies (PHPStan, PHP‑CS‑Fixer):

```bash
composer install
```

Run the test suite (no network or real API key required — a local stub server
is used):

```bash
composer test
# or individually:
php tests/test_weather.php
php tests/test_ratelimit.php
php tests/test_onecall.php
php tests/test_http.php
php tests/test_controller.php
```

Static analysis and code style:

```bash
php vendor/bin/phpstan analyse -c phpstan.neon.dist
php vendor/bin/php-cs-fixer fix --dry-run
```

Continuous integration runs linting, all tests, PHPStan, and PHP‑CS‑Fixer on
every push and pull request.

---

## License

Distributed under the **GNU General Public License v2.1 (GPL‑2.1)**. See the
[LICENSE](LICENSE) file for the full text. By using, modifying, or distributing
this software you agree to the terms of that license.

---

## Acknowledgements

Weather data provided by [OpenWeatherMap](https://openweathermap.org).

Contributors: [@keanehatescoding](https://github.com/keanehatescoding),
[@easter-m](https://github.com/easter-m),
[yo-yo-05](https://github.com/yo-yo-05),
[@Hopeyriizeis7](https://github.com/Hopeyriizeis7),
[@mulle-emmanuel](https://github.com/mulle-emmanuel).
