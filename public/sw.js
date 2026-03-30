const CACHE_NAME = 'stopclope-v5';
const urlsToCache = [
    '/',
    '/stats',
    '/history',
    '/settings',
    '/manifest.json',
    '/icons/icon-192.png',
    '/icons/icon-512.png'
];

// Install event
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('Opened cache');
                return cache.addAll(urlsToCache);
            })
    );
    self.skipWaiting();
});

// Fetch event - Network first, fallback to cache
self.addEventListener('fetch', event => {
    // Skip non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }

    // Skip non-http(s) requests (chrome-extension, etc.)
    if (!event.request.url.startsWith('http')) {
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then(response => {
                // Check if we received a valid response
                if (!response || response.status !== 200 || response.type !== 'basic') {
                    return response;
                }

                // Clone the response
                const responseToCache = response.clone();

                caches.open(CACHE_NAME)
                    .then(cache => {
                        cache.put(event.request, responseToCache);
                    });

                return response;
            })
            .catch(() => {
                // Network failed, try cache
                return caches.match(event.request);
            })
    );
});

// Activate event - Clean up old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    self.clients.claim();
});

// Background sync for offline cigarette logging
self.addEventListener('sync', event => {
    if (event.tag === 'sync-cigarettes') {
        event.waitUntil(syncOfflineCigarettes());
    }
});

// --- Notification prochaine clope ---
let dismissTimer = null;

self.addEventListener('message', event => {
    const data = event.data;

    if (data.type === 'SHOW_TARGET') {
        // Annuler le timer précédent
        if (dismissTimer) {
            clearTimeout(dismissTimer);
            dismissTimer = null;
        }

        // Afficher la notification persistante
        self.registration.showNotification('Prochaine clope : ' + data.targetTime, {
            tag: 'next-cig',
            silent: true,
            icon: '/icons/icon-192.png',
            badge: '/icons/icon-192.png',
            requireInteraction: false
        });

        // Programmer la fermeture automatique à l'heure cible
        if (data.delayMs > 0) {
            dismissTimer = setTimeout(() => {
                self.registration.getNotifications({ tag: 'next-cig' }).then(notifications => {
                    notifications.forEach(n => n.close());
                });
                dismissTimer = null;
            }, data.delayMs);
        }
    }

    if (data.type === 'DISMISS_TARGET') {
        if (dismissTimer) {
            clearTimeout(dismissTimer);
            dismissTimer = null;
        }
        self.registration.getNotifications({ tag: 'next-cig' }).then(notifications => {
            notifications.forEach(n => n.close());
        });
    }
});

// Clic sur la notification : ouvrir l'app
self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.waitUntil(
        clients.matchAll({ type: 'window' }).then(windowClients => {
            // Focus sur un onglet existant ou en ouvrir un nouveau
            for (const client of windowClients) {
                if (client.url.includes('/') && 'focus' in client) {
                    return client.focus();
                }
            }
            return clients.openWindow('/');
        })
    );
});

async function syncOfflineCigarettes() {
    // This will be called when back online
    const clients = await self.clients.matchAll();
    clients.forEach(client => {
        client.postMessage({ type: 'SYNC_OFFLINE_DATA' });
    });
}
