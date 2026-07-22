const CACHE_NAME = 'nwssu-foodcourt-v3';
const OFFLINE_URL = 'offline.php';

const STATIC_ASSETS = [
    'manifest.json',
    'assets/images/nwssu-logo.png',
    'assets/images/nwssu.png',
    'assets/images/icon-192.png',
    'assets/images/icon-512.png',
    OFFLINE_URL
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== CACHE_NAME) {
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

function isStaticAsset(url) {
    return url.pathname.includes('/assets/');
}

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') return;

    const url = new URL(event.request.url);

    if (isStaticAsset(url)) {
        event.respondWith(
            caches.match(event.request).then((cachedResponse) => {
                if (cachedResponse) {
                    return cachedResponse;
                }
                return fetch(event.request).then((networkResponse) => {
                    if (networkResponse && networkResponse.status === 200) {
                        const responseToCache = networkResponse.clone();
                        caches.open(CACHE_NAME)
                            .then((cache) => cache.put(event.request, responseToCache));
                    }
                    return networkResponse;
                });
            })
        );
        return;
    }

    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(async () => {
                const cached = await caches.match(OFFLINE_URL);
                return cached || new Response('You are offline.', { status: 503, headers: { 'Content-Type': 'text/plain' } });
            })
        );
        return;
    }

    event.respondWith(
        fetch(event.request).catch(async () => {
            const cached = await caches.match(event.request);
            return cached || new Response('', { status: 504, statusText: 'Gateway Timeout' });
        })
    );
});

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'NwSSU Food Court', message: event.data ? event.data.text() : '' };
    }

    const title = data.title || 'NwSSU Food Court';
    const options = {
        body: data.message || '',
        icon: 'assets/images/icon-192.png',
        badge: 'assets/images/icon-192.png',
        data: { link: data.link || '/' },
        vibrate: [100, 50, 100]
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const link = event.notification.data && event.notification.data.link ? event.notification.data.link : '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            for (const client of clientList) {
                if (client.url === link && 'focus' in client) {
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(link);
            }
        })
    );
});