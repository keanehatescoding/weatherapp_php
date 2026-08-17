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
			.then(function (cache) { return cache.addAll(SHELL_URLS); })
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
					const copy = response.clone();
					caches.open(CACHE_NAME).then(function (cache) { cache.put('./index.html', copy); });
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
	// cache-first, refreshing the cache in the background on a network hit.
	event.respondWith(
		caches.match(request).then(function (cached) {
			if (cached) { return cached; }
			return fetch(request).then(function (response) {
				if (isSameOrigin || SHELL_URLS.indexOf(request.url) !== -1) {
					const copy = response.clone();
					caches.open(CACHE_NAME).then(function (cache) { cache.put(request, copy); });
				}
				return response;
			});
		})
	);
});
