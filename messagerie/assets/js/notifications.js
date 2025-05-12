/**
 * /assets/js/notifications.js - Gestion des notifications
 * Ce module gère les notifications utilisateur, y compris:
 * - Le polling des notifications depuis le serveur
 * - L'affichage des badges de notification
 * - Les notifications du navigateur (Web Notifications API)
 * - Les sons de notification
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialiser les notifications
    initNotifications();
});

/**
 * Initialise les notifications
 */
function initNotifications() {
    // Configurer les notifications en temps réel via polling AJAX
    setupPollingForNotifications();
    
    // Gérer les clics sur les notifications
    initNotificationClicks();
    
    // Demander la permission pour les notifications du navigateur si nécessaire
    if (!localStorage.getItem('notification_prompt_dismissed')) {
        requestNotificationPermission();
    }
    
    // Publier un événement pour signaler que les notifications sont initialisées
    if (typeof EventManager !== 'undefined') {
        EventManager.publish('notifications:initialized', {});
    }
}

/**
 * Configure le polling AJAX pour les notifications
 */
function setupPollingForNotifications() {
    // Variable globale pour suivre l'état du polling
    window.notificationPollingInterval = null;
    
    // Arrêter le polling existant si présent
    if (window.notificationPollingInterval) {
        clearInterval(window.notificationPollingInterval);
    }
    
    // Récupérer le dernier ID de notification connu
    const lastNotificationId = localStorage.getItem('last_notification_id') || 0;
    
    // Fonction pour vérifier les nouvelles notifications
    function checkForNotifications() {
        AjaxClient.get('api/notifications.php', {
            action: 'check_conditional',
            last_id: lastNotificationId
        })
        .then(data => {
            // Mettre à jour le badge de notification
            updateNotificationBadge(data.count);
            
            // Stocker le dernier ID
            if (data.latest_notification) {
                localStorage.setItem('last_notification_id', data.latest_notification.id);
                
                // Si l'utilisateur a activé les notifications du navigateur
                if (hasNotificationPermission() && data.latest_notification) {
                    showBrowserNotification(data.count, data.latest_notification);
                }
                
                // Publier un événement pour les nouvelles notifications
                if (typeof EventManager !== 'undefined') {
                    EventManager.publish('notifications:new', {
                        count: data.count,
                        notification: data.latest_notification
                    });
                }
            }
        })
        .catch(error => {
            console.error('Erreur lors de la vérification des notifications:', error);
        });
    }
    
    // Vérifier immédiatement puis régulièrement
    checkForNotifications();
    window.notificationPollingInterval = setInterval(checkForNotifications, 15000); // toutes les 15 secondes
    
    // Gérer les événements de visibilité pour optimiser le polling
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden') {
            if (window.notificationPollingInterval) {
                clearInterval(window.notificationPollingInterval);
                window.notificationPollingInterval = null;
            }
        } else if (document.visibilityState === 'visible') {
            if (!window.notificationPollingInterval) {
                checkForNotifications(); // vérifier immédiatement
                window.notificationPollingInterval = setInterval(checkForNotifications, 15000);
            }
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
    AjaxClient.get('api/notifications.php', {
        action: 'mark_read',
        id: notificationId
    })
    .then(data => {
        // Mise à jour réussie, rafraîchir le compteur si nécessaire
        if (data.success) {
            AjaxClient.get('api/notifications.php', {
                action: 'check'
            })
            .then(result => {
                if (result.success) {
                    updateNotificationBadge(result.count);
                    
                    // Publier un événement de notification lue
                    if (typeof EventManager !== 'undefined') {
                        EventManager.publish('notifications:read', { id: notificationId });
                    }
                }
            });
        }
    })
    .catch(error => {
        console.error('Erreur lors du marquage de la notification:', error);
        
        // Afficher une notification d'erreur
        if (typeof Notifications !== 'undefined') {
            Notifications.error(`Erreur lors du marquage de la notification: ${error.message}`);
        }
    });
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
        
        // Mettre à jour le titre de la page
        updatePageTitle(count);
    } else if (badge) {
        // Masquer le badge s'il n'y a pas de notification
        badge.style.display = 'none';
        
        // Restaurer le titre de la page
        updatePageTitle(0);
    }
    
    // Publier un événement de mise à jour du badge
    if (typeof EventManager !== 'undefined') {
        EventManager.publish('notifications:badge_updated', { count });
    }
}

/**
 * Met à jour le titre de la page avec le nombre de notifications
 * @param {number} count - Nombre de notifications non lues
 */
function updatePageTitle(count) {
    const originalTitle = document.title.replace(/^\(\d+\) /, '');
    
    if (count > 0) {
        document.title = `(${count}) ${originalTitle}`;
    } else {
        document.title = originalTitle;
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
                        
                        // Publier un événement de permission accordée
                        if (typeof EventManager !== 'undefined') {
                            EventManager.publish('notifications:permission_granted', {});
                        }
                    } else {
                        // Stocker que l'utilisateur a refusé pour ne plus lui demander
                        localStorage.setItem('notification_prompt_dismissed', 'true');
                        
                        // Publier un événement de permission refusée
                        if (typeof EventManager !== 'undefined') {
                            EventManager.publish('notifications:permission_denied', {});
                        }
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
    
    // Utiliser la classe AjaxClient pour la cohérence
    AjaxClient.postForm('api/notifications.php', formData)
        .then(data => {
            if (data.success) {
                // Préférence mise à jour avec succès
                console.log('Préférence de notification mise à jour');
                
                // Publier un événement de préférence mise à jour
                if (typeof EventManager !== 'undefined') {
                    EventManager.publish('notifications:preference_updated', {
                        preference,
                        value
                    });
                }
            }
        })
        .catch(error => {
            console.error('Erreur lors de la mise à jour de la préférence:', error);
            
            // Afficher une notification d'erreur
            if (typeof Notifications !== 'undefined') {
                Notifications.error(`Erreur lors de la mise à jour de la préférence: ${error.message}`);
            }
        });
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
        icon: '/assets/images/pronote-icon.png',
        badge: '/assets/images/notification-badge.png',
        tag: 'pronote-message', // Regrouper les notifications
        requireInteraction: false, // Ne pas nécessiter d'interaction
        silent: !shouldPlaySound // Respecter la préférence de son
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
            
            // Publier un événement de notification cliquée
            if (typeof EventManager !== 'undefined') {
                EventManager.publish('notifications:clicked', { notification: latestNotification });
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
        
        // Publier un événement de son joué
        if (typeof EventManager !== 'undefined') {
            EventManager.publish('notifications:sound_played', {});
        }
    } catch (e) {
        console.log("Son de notification non supporté:", e);
    }
}

/**
 * Marque toutes les notifications comme lues
 */
function markAllNotificationsAsRead() {
    AjaxClient.get('api/notifications.php', {
        action: 'mark_all_read'
    })
    .then(data => {
        if (data.success) {
            // Mettre à jour le badge
            updateNotificationBadge(0);
            
            // Publier un événement de toutes les notifications lues
            if (typeof EventManager !== 'undefined') {
                EventManager.publish('notifications:all_read', {});
            }
            
            // Afficher un message de succès
            if (typeof Notifications !== 'undefined') {
                Notifications.success('Toutes les notifications ont été marquées comme lues');
            }
        }
    })
    .catch(error => {
        console.error('Erreur lors du marquage de toutes les notifications:', error);
        
        // Afficher une notification d'erreur
        if (typeof Notifications !== 'undefined') {
            Notifications.error(`Erreur: ${error.message}`);
        }
    });
}

// Exposer les fonctions publiques
window.Notifications = window.Notifications || {};
window.Notifications.markAllAsRead = markAllNotificationsAsRead;
window.Notifications.requestPermission = requestNotificationPermission;
window.Notifications.playSound = playNotificationSound;