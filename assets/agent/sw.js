/*! Hilo Chat — agent service worker (served dynamically, placeholders replaced by PHP) */
'use strict';

var VERSION = '__HILO_VERSION__';
var APP_URL = '__HILO_APP_URL__';
var ICON = '__HILO_ICON__';
var BADGE = '__HILO_BADGE__';
var CACHE = 'hilo-chat-' + VERSION;

self.addEventListener('install', function (event) {
	self.skipWaiting();
});

self.addEventListener('activate', function (event) {
	event.waitUntil(
		caches.keys().then(function (keys) {
			return Promise.all(keys.filter(function (k) { return k.indexOf('hilo-chat-') === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
		}).then(function () { return self.clients.claim(); })
	);
});

// Network-first for the app shell so it opens (with last known UI) on flaky connections.
// API calls are never cached.
self.addEventListener('fetch', function (event) {
	var req = event.request;
	if (req.method !== 'GET') return;
	var url = new URL(req.url);
	if (url.pathname.indexOf('/wp-json/') !== -1 || url.pathname.indexOf('admin-ajax') !== -1) return;

	var isShell = req.mode === 'navigate' && req.url.indexOf(APP_URL) === 0;
	var isAsset = url.pathname.indexOf('/hilo-chat/assets/agent/') !== -1;
	if (!isShell && !isAsset) return;

	event.respondWith(
		fetch(req).then(function (res) {
			// Only cache successful, non-redirected responses (redirect = login page).
			if (res.ok && !res.redirected && res.type === 'basic') {
				var copy = res.clone();
				caches.open(CACHE).then(function (c) { c.put(isShell ? APP_URL : req, copy); });
			}
			return res;
		}).catch(function () {
			return caches.match(isShell ? APP_URL : req).then(function (hit) {
				return hit || new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><body style="font-family:system-ui;display:grid;place-items:center;height:100vh;margin:0;color:#475467"><p>Sin conexión. Reintenta en un momento.</p></body>', { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
			});
		})
	);
});

self.addEventListener('push', function (event) {
	var data = {};
	try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: 'Nuevo mensaje', body: event.data ? event.data.text() : '' }; }
	var title = data.title || 'Nuevo mensaje';
	var options = {
		body: data.body || '',
		icon: ICON,
		badge: BADGE,
		tag: data.tag || 'hilo-chat',
		renotify: true,
		timestamp: Date.now(),
		data: { url: data.url || APP_URL, conv: data.conv || 0 },
		vibrate: [120, 60, 120]
	};

	event.waitUntil(
		self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clients) {
			// Keep the badge in sync even when the app is closed.
			if (self.navigator && 'setAppBadge' in self.navigator && data.conv) {
				try { self.navigator.setAppBadge(); } catch (e) {}
			}
			return self.registration.showNotification(title, options);
		})
	);
});

self.addEventListener('notificationclick', function (event) {
	event.notification.close();
	var target = (event.notification.data && event.notification.data.url) || APP_URL;
	var conv = event.notification.data && event.notification.data.conv;

	event.waitUntil(
		self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clients) {
			for (var i = 0; i < clients.length; i++) {
				var c = clients[i];
				if (c.url.indexOf(APP_URL) === 0 && 'focus' in c) {
					if (conv) c.postMessage({ type: 'open', conv: conv });
					return c.focus();
				}
			}
			return self.clients.openWindow(target);
		})
	);
});

// Browsers may rotate push subscriptions; re-subscribe and let the app sync it on next open.
self.addEventListener('pushsubscriptionchange', function (event) {
	event.waitUntil(
		self.registration.pushManager.subscribe(event.oldSubscription ? event.oldSubscription.options : { userVisibleOnly: true }).catch(function () {})
	);
});
