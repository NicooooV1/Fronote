/**
 * Service Worker — Fronote PWA
 * - Pages : réseau d'abord, avec « navigation preload » (la requête part en parallèle du démarrage
 *   du worker : aucun délai ajouté aux clics) ; page « hors ligne » si le réseau manque.
 * - CSS/JS/images : NON interceptés. Le cache HTTP du navigateur s'en charge (versionnés ?v=mtime,
 *   immuables) : plus jamais d'ancienne feuille de style servie après une mise à jour.
 * - Notifications push.
 */
const CACHE_NAME = 'fronote-v2';

self.addEventListener('install', () => self.skipWaiting());

// Activate : purge des anciens caches (v1 servait CSS/JS « cache d'abord », sans fin) + preload
self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)));
        if (self.registration.navigationPreload) {
            try { await self.registration.navigationPreload.enable(); } catch (e) {}
        }
        await self.clients.claim();
    })());
});

const OFFLINE_HTML = '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fronote - Hors ligne</title>' +
    '<style>body{font-family:system-ui;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f7fafc;color:#4a5568;text-align:center}' +
    '.box{padding:40px}h1{font-size:1.4em}p{color:#718096}a{display:inline-block;margin-top:20px;padding:10px 24px;background:#0f4c81;color:#fff;border-radius:8px;text-decoration:none}</style></head>' +
    '<body><div class="box"><h1>Hors ligne</h1><p>Reconnectez-vous à Internet pour accéder à Fronote.</p><a href="">Réessayer</a></div></body></html>';

self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate' || event.request.method !== 'GET') { return; }
    event.respondWith((async () => {
        try {
            const preloaded = await event.preloadResponse;
            if (preloaded) { return preloaded; }
            return await fetch(event.request);
        } catch (e) {
            return new Response(OFFLINE_HTML, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
        }
    })());
});

// ─── Push Notifications ──────────────────────────────────────────
self.addEventListener('push', (event) => {
    let data = { title: 'Fronote', body: 'Nouvelle notification', icon: 'assets/icons/icon-192.png' };

    if (event.data) {
        try {
            data = Object.assign(data, event.data.json());
        } catch (e) {
            data.body = event.data.text();
        }
    }

    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: data.icon || 'assets/icons/icon-192.png',
            badge: 'assets/icons/icon-192.png',
            tag: data.tag || 'fronote-default',
            data: { url: data.url || './' },
            vibrate: [200, 100, 200],
        })
    );
});

// ─── Notification click : ouvrir l'URL ──────────────────────────
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const targetUrl = event.notification.data?.url || './';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((clients) => {
                // Si un onglet Fronote est déjà ouvert, le focus
                for (const client of clients) {
                    if (client.url.includes(self.location.origin) && 'focus' in client) {
                        client.navigate(targetUrl);
                        return client.focus();
                    }
                }
                return self.clients.openWindow(targetUrl);
            })
    );
});
