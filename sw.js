'use strict';

/**
 * Minimal app-shell service worker: caches the static UI (HTML/CSS/JS/icons)
 * so the search form still loads offline. Weather data itself always needs
 * a live network round-trip (rate limiting, CSRF, fresh data), so logic.php
 * and csrf.php are intentionally left untouched here.
 */

const CACHE_NAME = 'weatherapp-shell-v1';
const SHELL_URLS = [
	'./',
	'./index.html',
	'./styles.css',
	'./sanitize.js',
	'./manifest.webmanifest',
	'./icons/icon-192.png',
	'./icons/icon-512.png',
	'https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css',
	'https://api.fontshare.com/v2/css?f[]=satoshi@400,500,600,700&display=swap',
];

self.addEventListener('install', function (event) {
	event.waitUntil(
		caches.open(CACHE_NAME)
			.then(function (cache) {
				// cache.addAll() is all-or-nothing: one flaky CDN request
				// (Bootstrap/Fontshare) would fail the whole install and
				// silently leave offline support off for that visit. Cache
				// each URL independently so a single miss doesn't sink the
				// rest of the shell.
				return Promise.all(SHELL_URLS.map(function (url) {
					return cache.add(url).catch(function (err) {
						console.warn('[sw] failed to precache', url, err);
					});
				}));
			})
			.then(function () { return self.skipWaiting(); })
	);
});

self.addEventListener('activate', function (event) {
	event.waitUntil(
		caches.keys()
			.then(function (names) {
				return Promise.all(
					names
						.filter(function (name) { return name !== CACHE_NAME; })
						.map(function (name) { return caches.delete(name); })
				);
			})
			.then(function () { return self.clients.claim(); })
	);
});

self.addEventListener('fetch', function (event) {
	const request = event.request;
	if (request.method !== 'GET') {
		return; // logic.php/csrf.php POSTs and GETs go straight to the network
	}

	const isSameOrigin = new URL(request.url).origin === self.location.origin;
	if (isSameOrigin && (request.url.includes('logic.php') || request.url.includes('csrf.php'))) {
		return; // always live: rate-limited, CSRF-bound, needs fresh data
	}

	if (request.mode === 'navigate') {
		event.respondWith(
			fetch(request)
				.then(function (response) {
					if (response.ok) {
						const copy = response.clone();
						caches.open(CACHE_NAME).then(function (cache) { cache.put('./index.html', copy); });
					}
					return response;
				})
				.catch(function () {
					return caches.match('./index.html').then(function (cached) {
						return cached || new Response(
							'<h1>You are offline</h1><p>WeatherApp needs a network connection to fetch weather data.</p>',
							{ headers: { 'Content-Type': 'text/html; charset=utf-8' } }
						);
					});
				})
		);
		return;
	}

	// Static shell assets (same-origin, plus the precached CDN stylesheets):
	// stale-while-revalidate — serve the cached copy immediately when there
	// is one, and refresh it in the background so a later visit picks up a
	// changed asset without needing a manual CACHE_NAME bump.
	event.respondWith(
		caches.match(request).then(function (cached) {
			const networkFetch = fetch(request).then(function (response) {
				if (response.ok && (isSameOrigin || SHELL_URLS.indexOf(request.url) !== -1)) {
					const copy = response.clone();
					// Track the cache write itself via waitUntil — without this,
					// it's a dangling promise the browser can cut short as soon
					// as networkFetch (and, on a cache hit, its own waitUntil
					// below) settle, before the write actually lands.
					event.waitUntil(
						caches.open(CACHE_NAME)
							.then(function (cache) { return cache.put(request, copy); })
							.catch(function () { /* best-effort cache write */ })
					);
				}
				return response;
			});

			if (cached) {
				event.waitUntil(networkFetch.catch(function () { /* offline refresh attempt; ignore */ }));
				return cached;
			}
			return networkFetch;
		})
	);
});
