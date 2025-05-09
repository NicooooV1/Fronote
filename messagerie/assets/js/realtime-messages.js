/**
 * realtime-messages.js - Système d'actualisation en temps réel des messages
 * 
 * Ce script fournit une solution robuste pour l'actualisation automatique
 * des messages dans une conversation sans nécessiter de rafraîchissement de page.
 */

// Marquer la présence du système avancé pour éviter les conflits avec le système simple
window.hasAdvancedRefresh = true;

document.addEventListener('DOMContentLoaded', function() {
    console.log("Initialisation du système d'actualisation en temps réel...");
    
    // Détecter la page courante
    const pageUrl = window.location.pathname;
    const isConversationPage = pageUrl.includes('conversation.php');
    const isIndexPage = pageUrl.includes('index.php') || pageUrl.endsWith('/') || pageUrl.endsWith('/messagerie/');
    
    // Configuration
    const REFRESH_INTERVAL = 5000; // 5 secondes entre chaque vérification
    const MAX_RETRIES = 3; // Nombre maximum de tentatives en cas d'échec
    
    // Récupérer l'ID de conversation (uniquement si on est sur la page de conversation)
    const convId = isConversationPage ? new URLSearchParams(window.location.search).get('id') : null;
    
    // Sur la page de conversation, on a besoin d'un ID de conversation
    if (isConversationPage && !convId) {
        console.log("Impossible de trouver l'ID de conversation");
        return;
    }
    
    // Variables d'état
    let lastTimestamp = getCurrentTimestamp();
    let retryCount = 0;
    let isPollingActive = true;
    let updateInterval = null;
    
    // Initialiser le timestamp de départ avec le dernier message
    function getCurrentTimestamp() {
        const lastMessage = document.querySelector('.message:last-child');
        return lastMessage ? parseInt(lastMessage.getAttribute('data-timestamp') || '0', 10) : 0;
    }
    
    // Démarrer la vérification périodique
    function startRealtimeUpdates() {
        console.log("Démarrage de la vérification périodique des messages...");
        
        // Assurer qu'un seul intervalle est actif
        if (updateInterval) {
            clearInterval(updateInterval);
        }
        
        // Définir l'intervalle de vérification périodique
        updateInterval = setInterval(function() {
            // Sur la page de conversation, vérifier les nouveaux messages
            if (isConversationPage) {
                checkForNewMessages();
            }
            
            // Sur toutes les pages, vérifier les notifications générales
            checkForNotifications();
        }, REFRESH_INTERVAL);
        
        // Vérifier immédiatement (pas besoin d'attendre le premier intervalle)
        if (isConversationPage) {
            checkForNewMessages();
        }
        checkForNotifications();
        
        // Gestionnaire d'événements pour les focus/blur et visibilité
        setupVisibilityHandlers();
    }
    
    // Vérifier les nouveaux messages dans une conversation spécifique
    function checkForNewMessages() {
        if (!isPollingActive || !convId) return;
        
        // Éviter les requêtes si l'utilisateur est en train de rédiger
        if (document.querySelector('textarea:focus') || document.querySelector('.modal[style*="display: block"]')) {
            console.log("L'utilisateur est actif, vérification reportée");
            return;
        }
        
        // Requête à l'API avec un timestamp pour éviter la mise en cache
        fetch(`api/get_conversation_updates.php?conv_id=${convId}&last_timestamp=${lastTimestamp}&_=${Date.now()}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Erreur HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                // Réinitialiser le compteur de tentatives
                retryCount = 0;
                
                // Si des mises à jour sont disponibles
                if (data.success && data.hasUpdates) {
                    fetchAndDisplayNewMessages();
                }
                
                // Actualiser la liste des participants si nécessaire
                if (data.success && data.participantsChanged) {
                    refreshParticipantsList();
                }
                
                // Mettre à jour le timestamp
                if (data.success && data.timestamp) {
                    lastTimestamp = data.timestamp;
                }
            })
            .catch(error => {
                console.error("Erreur lors de la vérification des mises à jour:", error);
                
                // Gestion des erreurs avec mécanisme de nouvelle tentative
                retryCount++;
                if (retryCount >= MAX_RETRIES) {
                    console.log(`Échec après ${MAX_RETRIES} tentatives, suspend temporairement les vérifications`);
                    
                    // Suspendre temporairement puis réessayer
                    isPollingActive = false;
                    setTimeout(() => {
                        isPollingActive = true;
                        retryCount = 0;
                    }, REFRESH_INTERVAL * 2);
                }
            });
    }
    
    // Récupérer et afficher les nouveaux messages
    function fetchAndDisplayNewMessages() {
        fetch(`api/get_new_messages.php?conv_id=${convId}&last_timestamp=${lastTimestamp}&_=${Date.now()}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Erreur HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.messages && data.messages.length > 0) {
                    // On était déjà en bas avant les nouveaux messages?
                    const messagesContainer = document.querySelector('.messages-container');
                    const wasAtBottom = isScrolledToBottom(messagesContainer);
                    
                    // Ajouter chaque nouveau message
                    const newMessages = data.messages;
                    
                    newMessages.forEach(message => {
                        appendMessageToDOM(message, messagesContainer);
                        
                        // Mise à jour du timestamp
                        if (message.timestamp > lastTimestamp) {
                            lastTimestamp = message.timestamp;
                        }
                    });
                    
                    // Faire défiler vers le bas si l'utilisateur était déjà en bas
                    if (wasAtBottom) {
                        scrollToBottom(messagesContainer);
                    } else {
                        // Sinon, indiquer qu'il y a de nouveaux messages
                        showNewMessagesIndicator(newMessages.length);
                    }
                    
                    // Son de notification (optionnel)
                    playNotificationSound();
                }
            })
            .catch(error => {
                console.error("Erreur lors de la récupération des nouveaux messages:", error);
            });
    }
    
    // Actualiser la liste des participants
    function refreshParticipantsList() {
        fetch(`api/get_participants_list.php?conv_id=${convId}&_=${Date.now()}`)
            .then(response => response.text())
            .then(html => {
                const participantsList = document.querySelector('.participants-list');
                if (participantsList) {
                    participantsList.innerHTML = html;
                    setupParticipantButtons();
                }
            })
            .catch(error => {
                console.error("Erreur lors de l'actualisation des participants:", error);
            });
    }

    // Configurer les gestionnaires d'événements pour les boutons de participants
    function setupParticipantButtons() {
        document.querySelectorAll('.participants-list .action-btn').forEach(btn => {
            const action = btn.getAttribute('onclick');
            if (action) {
                const funcName = action.split('(')[0];
                const params = action.substring(action.indexOf('(') + 1, action.lastIndexOf(')'));
                
                // Supprimer l'attribut onclick et ajouter un event listener
                btn.removeAttribute('onclick');
                btn.addEventListener('click', function() {
                    // Exécuter la fonction en utilisant window[funcName]
                    if (typeof window[funcName] === 'function') {
                        window[funcName](...params.split(',').map(p => parseInt(p.trim(), 10)));
                    }
                });
            }
        });
    }
    
    // Vérifier si l'élément est défilé jusqu'en bas
    function isScrolledToBottom(element) {
        if (!element) return true;
        return Math.abs(element.scrollHeight - element.scrollTop - element.clientHeight) < 20;
    }
    
    // Faire défiler l'élément jusqu'en bas
    function scrollToBottom(element) {
        if (!element) return;
        element.scrollTop = element.scrollHeight;
    }
    
    // Afficher un indicateur de nouveaux messages
    function showNewMessagesIndicator(count) {
        let indicator = document.getElementById('new-messages-indicator');
        
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.id = 'new-messages-indicator';
            
            // Styles CSS inline pour assurer la compatibilité
            indicator.style.position = 'fixed';
            indicator.style.bottom = '100px';
            indicator.style.right = '20px';
            indicator.style.backgroundColor = '#009b72';
            indicator.style.color = 'white';
            indicator.style.padding = '10px 15px';
            indicator.style.borderRadius = '20px';
            indicator.style.boxShadow = '0 2px 10px rgba(0,0,0,0.2)';
            indicator.style.cursor = 'pointer';
            indicator.style.zIndex = '1000';
            indicator.style.display = 'none';
            
            indicator.addEventListener('click', function() {
                const messagesContainer = document.querySelector('.messages-container');
                scrollToBottom(messagesContainer);
                this.style.display = 'none';
            });
            
            document.body.appendChild(indicator);
        }
        
        indicator.textContent = count === 1 ? "1 nouveau message" : `${count} nouveaux messages`;
        indicator.style.display = 'block';
        
        // Animation de pulsation
        indicator.style.animation = 'pulse 2s infinite';
        
        // Ajouter un style d'animation s'il n'existe pas déjà
        if (!document.getElementById('pulse-animation')) {
            const style = document.createElement('style');
            style.id = 'pulse-animation';
            style.textContent = `
                @keyframes pulse {
                    0% { box-shadow: 0 0 0 0 rgba(0, 155, 114, 0.7); }
                    70% { box-shadow: 0 0 0 10px rgba(0, 155, 114, 0); }
                    100% { box-shadow: 0 0 0 0 rgba(0, 155, 114, 0); }
                }
            `;
            document.head.appendChild(style);
        }
    }
    
    // Jouer un son de notification
    function playNotificationSound() {
        try {
            // Créer un oscillateur avec l'API Web Audio
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();
            
            oscillator.type = 'sine';
            oscillator.frequency.value = 880; // La note "La"
            gainNode.gain.value = 0.1; // Volume bas
            
            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);
            
            // Jouer un bref son
            oscillator.start();
            
            // Arrêter après 200ms
            setTimeout(() => {
                oscillator.stop();
            }, 200);
        } catch (e) {
            console.log("Notification audio non prise en charge:", e);
        }
    }
    
    // Ajouter un message au DOM
    function appendMessageToDOM(message, container) {
        // Créer un nouvel élément div pour le message
        const messageElement = document.createElement('div');
        
        // Déterminer les classes du message
        let classes = ['message'];
        if (message.is_self == 1) classes.push('self');
        if (message.est_lu == 1) classes.push('read');
        if (message.status) classes.push(message.status);
        
        messageElement.className = classes.join(' ');
        messageElement.setAttribute('data-id', message.id);
        messageElement.setAttribute('data-timestamp', message.timestamp);
        
        // Formater la date lisible
        const messageDate = new Date(message.timestamp * 1000);
        const formattedDate = formatMessageDate(messageDate);
        
        // Construction du HTML du message
        let messageHTML = `
            <div class="message-header">
                <div class="sender">
                    <strong>${escapeHTML(message.expediteur_nom)}</strong>
                    <span class="sender-type">${getParticipantType(message.sender_type)}</span>
                </div>
                <div class="message-meta">
        `;
        
        // Ajouter le tag d'importance si non standard
        if (message.status && message.status !== 'normal') {
            messageHTML += `<span class="importance-tag ${message.status}">${message.status}</span>`;
        }
        
        messageHTML += `
                    <span class="date">${formattedDate}</span>
                </div>
            </div>
            <div class="message-content">${linkify(nl2br(escapeHTML(message.body || message.contenu)))}</div>
        `;
        
        // Ajouter les pièces jointes s'il y en a
        if (message.pieces_jointes && message.pieces_jointes.length > 0) {
            messageHTML += '<div class="attachments">';
            
            message.pieces_jointes.forEach(attachment => {
                messageHTML += `
                    <a href="${escapeHTML(attachment.chemin)}" class="attachment" target="_blank">
                        <i class="fas fa-paperclip"></i> ${escapeHTML(attachment.nom_fichier)}
                    </a>
                `;
            });
            
            messageHTML += '</div>';
        }
        
        // Ajouter le footer du message
        messageHTML += `
            <div class="message-footer">
                <div class="message-status">
                    ${message.est_lu == 1 ? '<div class="message-read"><i class="fas fa-check"></i> Vu</div>' : ''}
                </div>
        `;
        
        // Ajouter les actions si ce n'est pas un message de l'utilisateur courant
        if (!message.is_self) {
            messageHTML += `
                <div class="message-actions">
                    ${message.est_lu ? 
                        `<button class="btn-icon mark-unread-btn" data-message-id="${message.id}">
                            <i class="fas fa-envelope"></i> Marquer comme non lu
                        </button>` : 
                        `<button class="btn-icon mark-read-btn" data-message-id="${message.id}">
                            <i class="fas fa-envelope-open"></i> Marquer comme lu
                        </button>`
                    }
                    <button class="btn-icon" onclick="replyToMessage(${message.id}, '${escapeHTML(message.expediteur_nom)}')">
                        <i class="fas fa-reply"></i> Répondre
                    </button>
                </div>
            `;
        }
        
        messageHTML += `
            </div>
        `;
        
        // Définir le HTML du message
        messageElement.innerHTML = messageHTML;
        
        // Ajouter l'événement pour les boutons
        setTimeout(() => {
            const readBtn = messageElement.querySelector('.mark-read-btn');
            const unreadBtn = messageElement.querySelector('.mark-unread-btn');
            
            if (readBtn) {
                readBtn.addEventListener('click', function() {
                    const messageId = this.getAttribute('data-message-id');
                    markMessageAsRead(messageId);
                });
            }
            
            if (unreadBtn) {
                unreadBtn.addEventListener('click', function() {
                    const messageId = this.getAttribute('data-message-id');
                    markMessageAsUnread(messageId);
                });
            }
        }, 100);
        
        // Ajouter le message au conteneur
        container.appendChild(messageElement);
    }
    
    // Formatage de la date d'un message
    function formatMessageDate(date) {
        const now = new Date();
        const diffMs = now - date;
        const diffSecs = Math.floor(diffMs / 1000);
        const diffMins = Math.floor(diffSecs / 60);
        const diffHours = Math.floor(diffMins / 60);
        const diffDays = Math.floor(diffHours / 24);
        
        if (diffSecs < 60) {
            return "À l'instant";
        } else if (diffMins < 60) {
            return `Il y a ${diffMins} minute${diffMins > 1 ? 's' : ''}`;
        } else if (diffHours < 24) {
            return `Il y a ${diffHours} heure${diffHours > 1 ? 's' : ''}`;
        } else if (diffDays < 2) {
            return `Hier à ${date.getHours().toString().padStart(2, '0')}:${date.getMinutes().toString().padStart(2, '0')}`;
        } else {
            return `${date.getDate().toString().padStart(2, '0')}/${(date.getMonth()+1).toString().padStart(2, '0')}/${date.getFullYear()} à ${date.getHours().toString().padStart(2, '0')}:${date.getMinutes().toString().padStart(2, '0')}`;
        }
    }
    
    // Échappe les caractères HTML
    function escapeHTML(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
    
    // Convertit les retours à la ligne en <br>
    function nl2br(text) {
        if (!text) return '';
        return text.replace(/\n/g, '<br>');
    }
    
    // Transforme les URLs en liens cliquables
    function linkify(text) {
        if (!text) return '';
        const urlPattern = /(https?:\/\/[^\s<]+[^<.,:;"')\]\s])/g;
        return text.replace(urlPattern, function(url) {
            return `<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>`;
        });
    }
    
    // Renvoie le libellé du type de participant
    function getParticipantType(type) {
        const types = {
            'eleve': 'Élève',
            'parent': 'Parent',
            'professeur': 'Professeur',
            'vie_scolaire': 'Vie scolaire',
            'administrateur': 'Administrateur'
        };
        return types[type] || type;
    }
    
    // Vérification des notifications (pour toutes les pages)
    function checkForNotifications() {
        if (!isPollingActive) return;
        
        fetch('api/check_notifications.php?_=' + Date.now())
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Erreur HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Mettre à jour le compteur de notifications
                    updateNotificationBadge(data.count);
                    
                    // Si sur la page d'index, actualiser la liste des conversations
                    if (isIndexPage && data.count > 0) {
                        refreshConversationList();
                    }
                    
                    // Si nouvelle notification et pas sur la page de conversation
                    if (data.latest && !isConversationPage && data.count > 0) {
                        showDesktopNotification(data);
                    }
                }
            })
            .catch(error => {
                console.error("Erreur lors de la vérification des notifications:", error);
            });
    }
    
    // Actualiser la liste des conversations (pour la page d'index)
    function refreshConversationList() {
        // Si nous sommes sur la page d'index, actualiser la liste des conversations
        if (!isIndexPage) return;
        
        // Récupérer le dossier courant depuis l'URL
        const currentFolder = new URLSearchParams(window.location.search).get('folder') || 'reception';
        
        // Obtenir un fragment HTML pour la liste des conversations
        fetch(`index.php?folder=${currentFolder}&ajax=1&_=${Date.now()}`)
            .then(response => response.text())
            .then(html => {
                // Créer un DOM temporaire pour extraire la liste des conversations
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newConversationList = doc.querySelector('.conversation-list');
                
                if (newConversationList) {
                    // Remplacer la liste des conversations existante
                    const currentConversationList = document.querySelector('.conversation-list');
                    if (currentConversationList) {
                        currentConversationList.innerHTML = newConversationList.innerHTML;
                        
                        // Réinitialiser les gestionnaires d'événements
                        setupQuickActions();
                    }
                    
                    // S'il y a un badge de notification, le mettre également à jour
                    const bulkActions = doc.querySelector('.bulk-actions');
                    if (bulkActions) {
                        const currentBulkActions = document.querySelector('.bulk-actions');
                        if (currentBulkActions) {
                            currentBulkActions.innerHTML = bulkActions.innerHTML;
                            setupBulkActions();
                        }
                    }
                }
            })
            .catch(error => {
                console.error("Erreur lors de l'actualisation de la liste des conversations:", error);
            });
    }
    
    // Mettre à jour le badge de notification
    function updateNotificationBadge(count) {
        let badge = document.querySelector('.notification-badge');
        
        if (count > 0) {
            // Créer un badge s'il n'existe pas
            if (!badge) {
                const userInfo = document.querySelector('.user-info');
                if (userInfo) {
                    badge = document.createElement('span');
                    badge.className = 'notification-badge';
                    userInfo.appendChild(badge);
                }
            }
            
            // Mettre à jour le texte et afficher le badge
            if (badge) {
                badge.textContent = count;
                badge.style.display = 'flex';
            }
        } else if (badge) {
            // Masquer le badge s'il n'y a pas de notification
            badge.style.display = 'none';
        }
    }
    
    // Afficher une notification de bureau
    function showDesktopNotification(data) {
        // Vérifier si les notifications du navigateur sont supportées et autorisées
        if (!("Notification" in window)) {
            console.log("Ce navigateur ne prend pas en charge les notifications de bureau");
            return;
        }
        
        // Si les notifications sont déjà autorisées
        if (Notification.permission === "granted") {
            createNotification(data);
        }
        // Sinon, demander la permission (uniquement au premier clic utilisateur)
        else if (Notification.permission !== "denied") {
            document.addEventListener('click', function onFirstClick() {
                Notification.requestPermission().then(permission => {
                    if (permission === "granted") {
                        createNotification(data);
                    }
                });
                // Retirer l'écouteur après le premier clic
                document.removeEventListener('click', onFirstClick);
            }, { once: true });
        }
    }
    
    // Créer et afficher une notification de bureau
    function createNotification(data) {
        if (Notification.permission !== "granted") return;
        
        const title = "Nouvelle notification Pronote";
        const options = {
            body: data.count === 1 
                ? `Nouveau message de ${data.latest.expediteur_nom}`
                : `${data.count} nouveaux messages non lus`,
            icon: '/assets/images/icon-notification.png' // Chemin vers une icône appropriée
        };
        
        const notification = new Notification(title, options);
        
        // Rediriger vers la conversation lorsqu'on clique sur la notification
        notification.onclick = function() {
            window.focus();
            if (data.latest && data.latest.conversation_id) {
                window.location.href = `conversation.php?id=${data.latest.conversation_id}`;
            }
        };
    }
    
    // Réutilisation des fonctions externes du site
    function setupQuickActions() {
        // Si la fonction existe globalement
        if (typeof window.toggleQuickActions === 'function') {
            // Réinitialiser les écouteurs d'événements existants
            document.querySelectorAll('.quick-actions-btn').forEach(btn => {
                const onclick = btn.getAttribute('onclick');
                if (onclick) {
                    const id = onclick.match(/\d+/)[0];
                    btn.onclick = function(e) {
                        window.toggleQuickActions(id);
                        e.stopPropagation();
                        return false;
                    };
                }
            });
        }
        
        // Fermeture du menu sur clic hors menu
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.quick-actions')) {
                document.querySelectorAll('.quick-actions-menu.active').forEach(menu => {
                    menu.classList.remove('active');
                });
            }
        });
    }
    
    function setupBulkActions() {
        // Si la fonction existe globalement
        if (typeof window.setupBulkActions === 'function') {
            window.setupBulkActions();
        }
    }
    
    // Gérer les changements de visibilité de la page
    function setupVisibilityHandlers() {
        // Mettre en pause lorsque l'onglet n'est pas actif pour économiser les ressources
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                console.log("Page masquée, pause de l'actualisation");
                isPollingActive = false;
            } else {
                console.log("Page visible, reprise de l'actualisation");
                isPollingActive = true;
                
                // Vérifier immédiatement lors du retour
                if (isConversationPage) {
                    checkForNewMessages();
                }
                checkForNotifications();
            }
        });
        
        // Arrêter la vérification lorsque l'utilisateur quitte la page
        window.addEventListener('beforeunload', function() {
            console.log("Page en cours de déchargement, arrêt de l'actualisation");
            isPollingActive = false;
            if (updateInterval) {
                clearInterval(updateInterval);
            }
        });
    }
    
    // Démarrer le système d'actualisation en temps réel
    startRealtimeUpdates();
});