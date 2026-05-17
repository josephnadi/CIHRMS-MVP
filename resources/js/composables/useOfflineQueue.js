/**
 * IndexedDB-backed queue for offline-tolerant operations.
 *
 *  enqueue({ endpoint, body })   — push a fetch payload while offline
 *  flush()                       — POST every queued item; remove on success
 *
 * Designed for clock-in/out from rural Ghana where connectivity is bursty.
 * The Vue component listens for `cihrms:sync-punches` (fired by the SW or
 * by the `online` event) and calls `flush()`.
 *
 * Concurrency: a single in-memory `flushing` lock prevents two concurrent
 * `flush()` calls from posting the same row twice.
 */

const DB_NAME    = 'cihrms-queue';
const STORE_NAME = 'punches';
const VERSION    = 1;

let flushing = false;
let dbPromise = null;

function openDb() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
        if (typeof indexedDB === 'undefined') return reject(new Error('IndexedDB unavailable'));
        const req = indexedDB.open(DB_NAME, VERSION);
        req.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                db.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror   = () => reject(req.error);
    });
    return dbPromise;
}

async function txStore(mode = 'readonly') {
    const db = await openDb();
    return db.transaction(STORE_NAME, mode).objectStore(STORE_NAME);
}

export async function enqueue({ endpoint, method = 'POST', body, csrfToken }) {
    try {
        const store = await txStore('readwrite');
        return new Promise((resolve, reject) => {
            const req = store.add({
                endpoint,
                method,
                body,
                csrfToken,
                enqueuedAt: Date.now(),
            });
            req.onsuccess = () => resolve(req.result);
            req.onerror   = () => reject(req.error);
        });
    } catch (e) {
        console.warn('[offline-queue] enqueue failed', e);
        return null;
    }
}

export async function size() {
    try {
        const store = await txStore('readonly');
        return new Promise((resolve, reject) => {
            const req = store.count();
            req.onsuccess = () => resolve(req.result);
            req.onerror   = () => reject(req.error);
        });
    } catch (e) {
        return 0;
    }
}

async function getAll() {
    const store = await txStore('readonly');
    return new Promise((resolve, reject) => {
        const req = store.getAll();
        req.onsuccess = () => resolve(req.result || []);
        req.onerror   = () => reject(req.error);
    });
}

async function remove(id) {
    const store = await txStore('readwrite');
    return new Promise((resolve, reject) => {
        const req = store.delete(id);
        req.onsuccess = () => resolve();
        req.onerror   = () => reject(req.error);
    });
}

/**
 * Replay every queued operation. Returns counts of attempts/successes/failures.
 * Safe to call repeatedly — single in-memory lock prevents reentrancy.
 */
export async function flush() {
    if (flushing) return { attempted: 0, succeeded: 0, failed: 0, skipped: true };
    flushing = true;

    let attempted = 0, succeeded = 0, failed = 0;

    try {
        const items = await getAll();
        for (const item of items) {
            attempted++;
            try {
                const resp = await fetch(item.endpoint, {
                    method:      item.method ?? 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type':     'application/json',
                        'Accept':           'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(item.csrfToken ? { 'X-CSRF-TOKEN': item.csrfToken } : {}),
                    },
                    body: typeof item.body === 'string' ? item.body : JSON.stringify(item.body ?? {}),
                });
                if (resp.ok || resp.status === 302 || resp.status === 422) {
                    // 422 = validation rejection — don't keep retrying a bad payload.
                    await remove(item.id);
                    succeeded++;
                } else {
                    failed++;
                }
            } catch (e) {
                failed++;
            }
        }
    } finally {
        flushing = false;
    }

    return { attempted, succeeded, failed, skipped: false };
}

/**
 * Try to register a background-sync tag so the SW will replay even after
 * the page closes. Falls back to manual flush on the next `online` event.
 */
export async function requestBackgroundSync(tag = 'cihrms-sync-punches') {
    if (!('serviceWorker' in navigator)) return false;
    try {
        const reg = await navigator.serviceWorker.ready;
        if ('sync' in reg) {
            await reg.sync.register(tag);
            return true;
        }
    } catch (e) { /* fall through */ }
    return false;
}
