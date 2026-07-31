/* OASIS online-first service worker.
 *
 * Cache policy (conservative):
 * - Only static public assets are stored: hashed /build/* Vite files (cache-first),
 *   plus a small precached core set (offline page, manifest, icons).
 * - Navigation responses (authenticated CRM HTML, login, maintenance 503) are NEVER stored.
 * - Same-origin non-navigation GET (JSON, AJAX, exports, downloads, sync) is network-first and never stored.
 * - POST/PUT/PATCH/DELETE are never intercepted (network-only).
 * - Only response.ok (2xx) may enter a cache. 401/403/419/503 are never cached.
 */

const CORE_CACHE = 'oasis-core-v1';
const BUILD_CACHE = 'oasis-build-v1';
const CACHE_PREFIX = 'oasis-';

const PRECACHE_URLS = [
    '/offline.html',
    '/manifest.webmanifest',
    '/icon-192.png',
    '/icon-512.png',
    '/icon-maskable-192.png',
    '/icon-maskable-512.png',
    '/apple-touch-icon.png',
    '/favicon.svg',
    '/favicon.ico',
];

const MAX_BUILD_CACHE_ENTRIES = 80;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CORE_CACHE)
            .then((cache) => cache.addAll(PRECACHE_URLS))
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keys = await caches.keys();
            await Promise.all(
                keys
                    .filter((key) => key.startsWith(CACHE_PREFIX) && key !== CORE_CACHE && key !== BUILD_CACHE)
                    .map((key) => caches.delete(key))
            );
            await self.clients.claim();
        })()
    );
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET') {
        return;
    }
    if (url.origin !== self.location.origin) {
        return;
    }

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request));
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(networkFirstNavigate(request));
        return;
    }

    event.respondWith(fetch(request));
});

async function cacheFirst(request) {
    const cached = await caches.match(request);
    if (cached) {
        return cached;
    }
    const response = await fetch(request);
    if (response.ok) {
        const cache = await caches.open(BUILD_CACHE);
        await cache.put(request, response.clone());
        await boundCache(cache);
    }
    return response;
}

async function networkFirstNavigate(request) {
    try {
        return await fetch(request);
    } catch (error) {
        const offline = await caches.match('/offline.html');
        if (offline) {
            return offline;
        }
        throw error;
    }
}

async function boundCache(cache) {
    const keys = await cache.keys();
    if (keys.length > MAX_BUILD_CACHE_ENTRIES) {
        await cache.delete(keys[0]);
    }
}
