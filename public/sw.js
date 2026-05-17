/* eslint-disable no-restricted-globals */
/**
 * CIHRMS service worker
 * ─────────────────────
 *  - Pre-caches the offline shell on install
 *  - cache-first for static assets (CSS/JS/img)
 *  - network-first with offline fallback for navigations
 *  - Background Sync for queued clock-in punches
 *
 * Versioning: bump CACHE_VERSION when shell assets change so old caches
 * are reaped and clients pull a fresh shell.
 */

const CACHE_VERSION = 'cihrms-v1';
const SHELL_CACHE   = `${CACHE_VERSION}-shell`;
const ASSET_CACHE   = `${CACHE_VERSION}-assets`;
const RUNTIME_CACHE = `${CACHE_VERSION}-runtime`;

const SHELL_URLS = [
    '/',
    '/offline',
    '/manifest.webmanifest',
];

// ── Install: pre-cache the offline shell ───────────────────────────────
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE)
            .then((cache) => cache.addAll(SHELL_URLS).catch(() => {
                // If any URL fails (e.g. /offline doesn't exist yet) we still
                // want install to succeed. Individual failures are best-effort.
            }))
            .then(() => self.skipWaiting()),
    );
});

// ── Activate: prune old caches ─────────────────────────────────────────
self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys
            .filter((k) => !k.startsWith(CACHE_VERSION))
            .map((k) => caches.delete(k))
        );
        await self.clients.claim();
    })());
});

// ── Fetch: pick a strategy based on the request type ───────────────────
self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Don't touch non-GET (POST/PATCH/DELETE go straight to network).
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Same-origin only — never intercept cross-origin (e.g. Google Fonts).
    if (url.origin !== self.location.origin) return;

    // Skip Inertia API + Laravel API — those should be live and respect auth.
    if (url.pathname.startsWith('/api/') ||
        url.pathname.startsWith('/sanctum/') ||
        url.pathname.startsWith('/livewire/')) return;

    // 1. Navigations → network-first, fall back to cached / offline shell.
    if (request.mode === 'navigate') {
        event.respondWith(handleNavigation(request));
        return;
    }

    // 2. Static assets (Vite build / public/img) → cache-first.
    if (/\.(?:js|css|woff2?|ttf|otf|png|jpg|jpeg|svg|webp|gif|ico)$/i.test(url.pathname)) {
        event.respondWith(cacheFirst(request, ASSET_CACHE));
        return;
    }

    // 3. Everything else → stale-while-revalidate.
    event.respondWith(staleWhileRevalidate(request, RUNTIME_CACHE));
});

async function handleNavigation(request) {
    try {
        const network = await fetch(request);
        const cache   = await caches.open(RUNTIME_CACHE);
        cache.put(request, network.clone());
        return network;
    } catch (e) {
        const cached = await caches.match(request);
        if (cached) return cached;
        const offline = await caches.match('/offline');
        if (offline) return offline;
        return new Response('Offline. Please reconnect to load this page.', {
            status: 503,
            headers: { 'Content-Type': 'text/plain' },
        });
    }
}

async function cacheFirst(request, cacheName) {
    const cached = await caches.match(request);
    if (cached) return cached;
    try {
        const network = await fetch(request);
        if (network.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, network.clone());
        }
        return network;
    } catch (e) {
        return new Response('', { status: 504 });
    }
}

async function staleWhileRevalidate(request, cacheName) {
    const cache  = await caches.open(cacheName);
    const cached = await cache.match(request);
    const networkPromise = fetch(request).then((resp) => {
        if (resp.ok) cache.put(request, resp.clone());
        return resp;
    }).catch(() => cached || new Response('', { status: 504 }));
    return cached || networkPromise;
}

// ── Background Sync: replay queued clock-in punches ────────────────────
self.addEventListener('sync', (event) => {
    if (event.tag === 'cihrms-sync-punches') {
        event.waitUntil(syncQueuedPunches());
    }
});

async function syncQueuedPunches() {
    // The actual queue lives in IndexedDB on the page side via
    // useOfflineQueue.js. We just ping the page so it can do the replay
    // (gives us access to authenticated session cookies + CSRF token).
    const clients = await self.clients.matchAll({ includeUncontrolled: true });
    for (const client of clients) {
        client.postMessage({ type: 'CIHRMS_SYNC_PUNCHES' });
    }
}

// ── Skip-waiting message for "update available, reload now" flow ───────
self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
});
