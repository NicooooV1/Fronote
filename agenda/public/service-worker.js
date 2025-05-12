// Version du cache
const CACHE_VERSION = 'v1';
const CACHE_NAME = `pronote-agenda-${CACHE_VERSION}`;

// Ressources à mettre en cache
const CACHE_RESOURCES = [
    '/',
    '/index.php',
    '/public/css/agenda.css',
    '/public/css/responsive.css',
    '/public/js/agenda.js',
    '/public/js/notifications.js',
    '/public/js/ical_manager.js'
];

// Installation du service worker
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                return cache.addAll(CACHE_RESOURCES);
            })
            .then(() => {
                return self.skipWaiting();
            })
    );
});

// Activation du service worker
self.addEventListener('activate', event => {
    // Nettoyer les anciens caches
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.filter(cacheName => {
                    return cacheName.startsWith('pronote-agenda-') && cacheName !== CACHE_NAME;
                }).map(cacheName => {
                    return caches.delete(cacheName);
                })
            );
        }).then(() => {
            return self.clients.claim();
        })
    );
});

// Interception des requêtes
self.addEventListener('fetch', event => {
    // Ne pas intercepter les requêtes d'API
    if (event.request.url.includes('/api/') || 
        event.request.url.includes('getEvents') ||
        event.request.url.includes('export_ics.php')) {
        return;
    }
    
    event.respondWith(
        caches.match(event.request)
            .then(response => {
                // Renvoyer la réponse du cache si elle existe
                if (response) {
                    return response;
                }
                
                // Cloner la requête car elle ne peut être utilisée qu'une fois
                const fetchRequest = event.request.clone();
                
                return fetch(fetchRequest).then(response => {
                    // Vérifier que la réponse est valide
                    if (!response || response.status !== 200 || response.type !== 'basic') {
                        return response;
                    }
                    
                    // Cloner la réponse car elle ne peut être utilisée qu'une fois
                    const responseToCache = response.clone();
                    
                    // Mettre en cache la nouvelle ressource
                    caches.open(CACHE_NAME)
                        .then(cache => {
                            cache.put(event.request, responseToCache);
                        });
                    
                    return response;
                });
            })
    );
});

// Gestion des notifications push
self.addEventListener('push', event => {
    if (!event.data) return;
    
    const data = event.data.json();
    
    const title = data.title || 'Pronote Agenda';
    const options = {
        body: data.body || 'Notification de votre agenda',
        icon: '/public/images/icon.png',
        badge: '/public/images/badge.png',
        data: data.data || {}
    };
    
    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

// Gestion des clics sur les notifications
self.addEventListener('notificationclick', event => {
    event.notification.close();
    
    // Récupérer les données de la notification
    const data = event.notification.data;
    
    // Ouvrir une fenêtre spécifique si un URL est fourni
    let url = '/';
    
    if (data.url) {
        url = data.url;
    } else if (data.eventId) {
        url = `/?event=${data.eventId}`;
    }
    
    event.waitUntil(
        clients.matchAll({
            type: 'window'
        }).then(windowClients => {
            // Vérifier si une fenêtre est déjà ouverte
            for (const client of windowClients) {
                if (client.url === url && 'focus' in client) {
                    return client.focus();
                }
            }
            
            // Sinon, ouvrir une nouvelle fenêtre
            if (clients.openWindow) {
                return clients.openWindow(url);
            }
        })
    );
});