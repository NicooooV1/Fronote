/**
 * /assets/js/notifications.js - Gestion des notifications
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialiser les notifications
    initNotifications();
});

/**
 * Initialise les notifications
 */
function initNotifications() {
    // Configurer les notifications en temps réel
    setupSSEForNotifications();
    
    // Gérer les clics sur les notifications
    initNotificationClicks();
    
    // Demander la permission pour les notifications du navigateur si l'utilisateur n'a pas encore décidé
    requestNotificationPermission();
}

/**
 * Configure la connexion SSE pour les notifications
 */
function setupSSEForNotifications() {
    // Fermer une connexion existante
    if (window.notificationSource) {
        window.notificationSource.close();
        window.notificationSource = null;
    }
    
    // Récupérer le dernier ID de notification connu
    const lastNotificationId = localStorage.getItem('last_notification_id') || 0;
    
    // Récupérer le jeton SSE pour les notifications
    getNotificationToken()
        .then(token => {
            // Créer la connexion SSE
            window.notificationSource = new EventSource(`api/notifications.php?action=stream&last_id=${lastNotificationId}&token=${token}`);
            
            // Événement pour les nouvelles notifications
            window.notificationSource.addEventListener('notification', function(event) {
                const data = JSON.parse(event.data);
                
                // Mettre à jour le badge de notification
                updateNotificationBadge(data.count);
                
                // Stocker le dernier ID
                if (data.latest_notification) {
                    localStorage.setItem('last_notification_id', data.latest_notification.id);
                    
                    // Si l'utilisateur a activé les notifications du navigateur
                    if (hasNotificationPermission() && data.latest_notification) {
                        showBrowserNotification(data.count, data.latest_notification);
                    }
                }
            });
            
            // Gestion des erreurs
            window.notificationSource.addEventListener('error', function(event) {
                console.error('SSE Error: Notification connection failed or closed. Reconnecting...');
                
                // Si la connexion est fermée, tenter de se reconnecter après un délai
                if (this.readyState === EventSource.CLOSED) {
                    setTimeout(setupSSEForNotifications, 5000);
                }
            });
            
            // Ping pour maintenir la connexion
            window.notificationSource.addEventListener('ping', function(event) {
                // Connexion maintenue, rien à faire
            });
        })
        .catch(error => {
            console.error('Erreur lors de la configuration des notifications:', error);
            // Tentative de reconnexion après un délai
            setTimeout(setupSSEForNotifications, 5000);
        });
    
    // Gérer les événements de visibilité pour optimiser les connexions
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden') {
            if (window.notificationSource) {
                window.notificationSource.close();
                window.notificationSource = null;
            }
        } else if (document.visibilityState === 'visible') {
            setupSSEForNotifications();
        }
    });
}

/**
 * Fonction pour récupérer un jeton SSE pour les notifications
 * @returns {Promise<string>} Le jeton SSE
 */
function getNotificationToken() {
    return fetch('api/notification_token.php')
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erreur HTTP: ${response.status}`);
            }
            return response.json().catch(e => {
                console.error("Réponse non-JSON reçue:", e);
                throw new Error("Le serveur a renvoyé une réponse non valide");
            });
        })
        .then(data => {
            if (data.success) {
                return data.token;
            } else {
                throw new Error(data.error || 'Erreur lors de la récupération du jeton');
            }
        });
}

/**
 * Initialise les clics sur les notifications
 */
function initNotificationClicks() {
    // Délègue les événements pour gérer les notifications ajoutées dynamiquement
    document.addEventListener('click', function(e) {
        // Vérifier si le clic était sur une notification
        if (e.target.closest('.notification-item')) {
            const notificationItem = e.target.closest('.notification-item');
            const notificationId = notificationItem.dataset.id;
            const conversationId = notificationItem.dataset.conversationId;
            
            // Marquer comme lu
            markNotificationRead(notificationId);
            
            // Rediriger vers la conversation
            window.location.href = `conversation.php?id=${conversationId}`;
        }
    });
}

/**
 * Marque une notification comme lue
 * @param {number} notificationId - ID de la notification
 */
function markNotificationRead(notificationId) {
    fetch(`api/notifications.php?action=mark_read&id=${notificationId}`)
        .catch(error => console.error('Erreur lors du marquage de la notification:', error));
}

/**
 * Met à jour le badge de notification
 * @param {number} count - Nombre de notifications non lues
 */
function updateNotificationBadge(count) {
    let badge = document.querySelector('.notification-badge');
    
    if (count > 0) {
        // Créer un badge s'il n'existe pas
        if (!badge) {
            const userInfo = document.querySelector('.user-info');
            if (userInfo) {
                const newBadge = document.createElement('span');
                newBadge.className = 'notification-badge';
                newBadge.textContent = count;
                userInfo.appendChild(newBadge);
            }
        } else {
            // Mettre à jour le badge existant
            badge.textContent = count;
            badge.style.display = 'flex';
        }
    } else if (badge) {
        // Masquer le badge s'il n'y a pas de notification
        badge.style.display = 'none';
    }
}

/**
 * Vérifie si les notifications du navigateur sont autorisées
 * @returns {boolean} True si les notifications sont autorisées
 */
function hasNotificationPermission() {
    return window.Notification && Notification.permission === 'granted';
}

/**
 * Demande l'autorisation pour les notifications du navigateur
 */
function requestNotificationPermission() {
    // Vérifier si le navigateur supporte les notifications
    if (window.Notification && Notification.permission === 'default') {
        // Créer un bouton pour demander la permission
        const container = document.querySelector('main');
        if (container) {
            const notificationPrompt = document.createElement('div');
            notificationPrompt.className = 'notification-prompt alert info';
            notificationPrompt.innerHTML = `
                <p>
                    <i class="fas fa-bell"></i> 
                    Voulez-vous recevoir des notifications pour les nouveaux messages ?
                </p>
                <button id="enable-notifications" class="btn primary">
                    <i class="fas fa-check"></i> Activer les notifications
                </button>
                <button id="dismiss-notification-prompt" class="btn cancel">
                    <i class="fas fa-times"></i> Non merci
                </button>
            `;
            
            // Insérer au début du main
            container.insertBefore(notificationPrompt, container.firstChild);
            
            // Gestionnaires d'événements
            document.getElementById('enable-notifications').addEventListener('click', function() {
                Notification.requestPermission().then(function(permission) {
                    notificationPrompt.remove();
                    
                    // Si l'utilisateur accepte, mettre à jour la préférence dans la base de données
                    if (permission === 'granted') {
                        updateNotificationPreference('browser_notifications', true);
                    }
                });
            });
            
            document.getElementById('dismiss-notification-prompt').addEventListener('click', function() {
                notificationPrompt.remove();
                
                // Stocker que l'utilisateur a refusé pour ne plus lui demander
                localStorage.setItem('notification_prompt_dismissed', 'true');
            });
        }
    }
}

/**
 * Met à jour une préférence de notification
 * @param {string} preference - Nom de la préférence
 * @param {boolean} value - Valeur de la préférence
 */
function updateNotificationPreference(preference, value) {
    const formData = new FormData();
    formData.append('action', 'update_preferences');
    formData.append('preferences[' + preference + ']', value ? '1' : '0');
    
    // Récupérer le jeton CSRF
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (csrfToken) {
        formData.append('csrf_token', csrfToken);
    }
    
    fetch('api/notifications.php', {
        method: 'POST',
        body: formData
    }).catch(error => console.error('Erreur lors de la mise à jour de la préférence:', error));
}

/**
 * Affiche une notification dans le navigateur
 * @param {number} count - Nombre de notifications
 * @param {Object} latestNotification - Dernière notification
 */
function showBrowserNotification(count, latestNotification) {
    if (!hasNotificationPermission()) {
        return;
    }
    
    // Vérifier les préférences utilisateur stockées en local
    const shouldPlaySound = localStorage.getItem('notification_sound') !== 'false';
    
    // Créer la notification
    const title = "Pronote - Messagerie";
    let expediteurNom = "Expéditeur";
    
    // Récupérer l'expéditeur
    if (latestNotification && latestNotification.expediteur_nom) {
        expediteurNom = latestNotification.expediteur_nom;
    }
    
    const options = {
        body: count === 1 
            ? `Nouveau message de ${expediteurNom}`
            : `${count} nouveaux messages non lus`,
            // icon: '/assets/images/pronote-icon.png'
    };
    
    try {
        const notification = new Notification(title, options);
        
        // Jouer un son si activé
        if (shouldPlaySound) {
            playNotificationSound();
        }
        
        // Rediriger vers la conversation au clic sur la notification
        notification.onclick = function() {
            window.focus();
            if (latestNotification && latestNotification.conversation_id) {
                window.location.href = `conversation.php?id=${latestNotification.conversation_id}`;
            } else {
                window.location.href = 'index.php';
            }
        };
    } catch (e) {
        console.error('Erreur lors de la création de la notification:', e);
    }
}

/**
 * Joue un son de notification
 */
function playNotificationSound() {
    try {
        // Utiliser l'API Web Audio pour créer un son simple
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioCtx.createOscillator();
        const gainNode = audioCtx.createGain();
        
        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(880, audioCtx.currentTime); // La note "La"
        gainNode.gain.setValueAtTime(0.1, audioCtx.currentTime); // Volume bas
        
        oscillator.connect(gainNode);
        gainNode.connect(audioCtx.destination);
        
        // Jouer un bref son
        oscillator.start();
        
        // Arrêter après 200ms
        setTimeout(() => {
            oscillator.stop();
        }, 200);
    } catch (e) {
        console.log("Son de notification non supporté:", e);
    }
}