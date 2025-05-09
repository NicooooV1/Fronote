/**
 * lightweight-realtime.js - Solution légère pour l'actualisation en temps réel
 * Basée sur le polling AJAX optimisé avec ETag
 */

document.addEventListener('DOMContentLoaded', function() {
    console.log('Initialisation du système de messagerie instantanée légère');
    
    // Configuration
    const CONFIG = {
        // Intervalle de base pour le polling (en ms)
        baseInterval: 3000,
        // Intervalle maximum (en cas d'inactivité)
        maxInterval: 10000,
        // Facteur de backoff: augmente l'intervalle progressivement
        backoffFactor: 1.5,
        // Nombre maximum de tentatives avant de ralentir
        maxRetries: 3
    };
    
    // Variables d'état
    let messageEtag = null;
    let notificationEtag = null;
    let currentInterval = CONFIG.baseInterval;
    let retryCount = 0;
    let isActive = true;
    let messageTimer = null;
    let notificationTimer = null;
    
    // Détecter la page courante
    const pageUrl = window.location.pathname;
    const isConversationPage = pageUrl.includes('conversation.php');
    const isIndexPage = pageUrl.includes('index.php') || pageUrl.endsWith('/') || pageUrl.endsWith('/messagerie/');
    
    // Récupérer l'ID de conversation si on est sur la page de conversation
    const urlParams = new URLSearchParams(window.location.search);
    const convId = isConversationPage ? urlParams.get('id') : null;
    
    /**
     * Fonction de polling pour les messages
     */
    async function pollMessages() {
        if (!isActive || !convId) {
            scheduleNextPoll();
            return;
        }
        
        try {
            // Éviter les requêtes si l'utilisateur est en train d'écrire
            if (document.querySelector('textarea:focus') || document.querySelector('.modal[style*="display: block"]')) {
                scheduleNextPoll(true);
                return;
            }
            
            const headers = {};
            if (messageEtag) {
                headers['If-None-Match'] = messageEtag;
            }
            
            const response = await fetch(`api/conditional_messages.php?conv_id=${convId}&_=${Date.now()}`, {
                headers: headers
            });
            
            // Si 304 Not Modified, ne rien faire
            if (response.status === 304) {
                // Réussite, mais pas de nouveaux messages
                retryCount = 0;
                // Augmenter progressivement l'intervalle
                currentInterval = Math.min(currentInterval * CONFIG.backoffFactor, CONFIG.maxInterval);
                scheduleNextPoll();
                return;
            }
            
            // Vérifier si la réponse est OK
            if (!response.ok) {
                throw new Error(`Erreur réseau: ${response.status} ${response.statusText}`);
            }
            
            // Récupérer le texte de la réponse d'abord pour vérifier s'il est bien au format JSON
            const responseText = await response.text();
            
            try {
                // Tenter de parser le JSON
                const data = JSON.parse(responseText);
                
                // Réinitialiser le compteur d'erreurs et l'intervalle si on a réussi à parser le JSON
                retryCount = 0;
                currentInterval = CONFIG.baseInterval;
                
                // Récupérer l'ETag
                messageEtag = response.headers.get('ETag');
                
                if (data.success && data.messages && Array.isArray(data.messages) && data.messages.length > 0) {
                    // Détecter si on était en bas de la conversation
                    const messagesContainer = document.querySelector('.messages-container');
                    const wasAtBottom = isScrolledToBottom(messagesContainer);
                    
                    // Mémoriser les IDs des messages déjà affichés
                    const existingMessageIds = new Set(
                        Array.from(document.querySelectorAll('.message'))
                        .map(el => el.getAttribute('data-id'))
                        .filter(id => id) // Filtrer les éléments null ou undefined
                    );
                    
                    // Ajouter uniquement les nouveaux messages et mettre à jour les statuts de lecture des messages existants
                    let hasNewMessages = false;
                    let newMessagesCount = 0;
                    
                    data.messages.forEach(message => {
                        // Si le message existe déjà, vérifier s'il faut mettre à jour son statut de lecture
                        if (message && message.id && existingMessageIds.has(message.id.toString())) {
                            updateMessageReadStatus(message);
                        } 
                        // Sinon, ajouter le nouveau message
                        else if (message && message.id) {
                            appendMessageToDOM(message, messagesContainer);
                            hasNewMessages = true;
                            newMessagesCount++;
                        }
                    });
                    
                    // Faire défiler vers le bas si on était déjà en bas
                    if (hasNewMessages && wasAtBottom) {
                        scrollToBottom(messagesContainer);
                    } else if (hasNewMessages) {
                        // Sinon, indiquer qu'il y a de nouveaux messages
                        showNewMessagesIndicator(newMessagesCount);
                    }
                }
            } catch (jsonError) {
                console.error('Erreur de parsing JSON:', jsonError);
                console.warn('Réponse reçue du serveur:', responseText.substring(0, 200) + (responseText.length > 200 ? '...' : ''));
                throw new Error('Réponse non-JSON reçue du serveur');
            }
            
            // Planifier la prochaine vérification
            scheduleNextPoll();
            
        } catch (error) {
            console.error('Erreur lors du polling des messages:', error);
            
            // Augmenter le compteur de tentatives
            retryCount++;
            
            // Si nous atteignons le maximum de tentatives, augmenter l'intervalle
            if (retryCount >= CONFIG.maxRetries) {
                currentInterval = Math.min(currentInterval * CONFIG.backoffFactor, CONFIG.maxInterval);
                console.log(`Augmentation de l'intervalle à ${currentInterval}ms après ${retryCount} tentatives échouées`);
                retryCount = 0;
            }
            
            // Planifier la prochaine vérification
            scheduleNextPoll();
        }
    }

    /**
     * Met à jour le statut de lecture d'un message existant dans le DOM
     * @param {Object} message Message avec l'état à jour
     */
    function updateMessageReadStatus(message) {
        if (!message || !message.id) return;
        
        const messageElement = document.querySelector(`.message[data-id="${message.id}"]`);
        if (!messageElement) return;
        
        const isSelf = messageElement.classList.contains('self');
        const isCurrentlyMarkedAsRead = messageElement.classList.contains('read');
        const shouldBeMarkedAsRead = message.est_lu == 1 || message.est_lu === true;
        
        // Si le statut a changé et que c'est un message envoyé par moi-même
        if (isSelf && shouldBeMarkedAsRead !== isCurrentlyMarkedAsRead) {
            if (shouldBeMarkedAsRead) {
                // Marquer comme lu
                messageElement.classList.add('read');
                
                // Ajouter l'indicateur "Vu" si c'est mon message
                const statusDiv = messageElement.querySelector('.message-status');
                if (statusDiv && !statusDiv.querySelector('.message-read')) {
                    const readStatus = document.createElement('div');
                    readStatus.className = 'message-read';
                    readStatus.innerHTML = '<i class="fas fa-check"></i> Vu';
                    statusDiv.appendChild(readStatus);
                }
            } else {
                // Marquer comme non lu
                messageElement.classList.remove('read');
                
                // Supprimer l'indicateur "Vu"
                const readIndicator = messageElement.querySelector('.message-read');
                if (readIndicator) {
                    readIndicator.remove();
                }
            }
        }
    }
    
    /**
     * Fonction de polling pour les notifications
     */
    async function pollNotifications() {
        if (!isActive) {
            scheduleNextNotificationPoll();
            return;
        }
        
        try {
            const headers = {};
            if (notificationEtag) {
                headers['If-None-Match'] = notificationEtag;
            }
            
            const response = await fetch(`api/conditional_notifications.php?_=${Date.now()}`, {
                headers: headers
            });
            
            // Si 304 Not Modified, ne rien faire
            if (response.status === 304) {
                scheduleNextNotificationPoll();
                return;
            }
            
            // Vérifier si la réponse est OK
            if (!response.ok) {
                throw new Error(`Erreur réseau: ${response.status} ${response.statusText}`);
            }
            
            try {
                // Récupérer le texte de la réponse
                const responseText = await response.text();
                
                // Tenter de parser le JSON
                const data = JSON.parse(responseText);
                
                // Récupérer l'ETag
                notificationEtag = response.headers.get('ETag');
                
                if (data.success) {
                    // Mettre à jour le badge de notification
                    updateNotificationBadge(data.count || 0);
                    
                    // Si sur la page d'index, actualiser la liste des conversations
                    if (isIndexPage && (data.count > 0)) {
                        refreshConversationList();
                    }
                    
                    // Si nouvelle notification et pas sur la page de conversation
                    if (data.latest_notification && !isConversationPage && (data.count > 0)) {
                        showDesktopNotification(data);
                    }
                }
            } catch (jsonError) {
                console.error('Erreur de parsing JSON pour les notifications:', jsonError);
                throw new Error('Réponse non-JSON reçue du serveur pour les notifications');
            }
            
            // Planifier la prochaine vérification
            scheduleNextNotificationPoll();
            
        } catch (error) {
            console.error('Erreur lors du polling des notifications:', error);
            scheduleNextNotificationPoll();
        }
    }
    
    /**
     * Planifie la prochaine vérification des messages
     * @param {boolean} immediate Si true, utilise l'intervalle de base
     */
    function scheduleNextPoll(immediate = false) {
        clearTimeout(messageTimer);
        
        const interval = immediate ? CONFIG.baseInterval : currentInterval;
        messageTimer = setTimeout(pollMessages, interval);
    }
    
    /**
     * Planifie la prochaine vérification des notifications
     */
    function scheduleNextNotificationPoll() {
        clearTimeout(notificationTimer);
        notificationTimer = setTimeout(pollNotifications, CONFIG.baseInterval);
    }
    
    /**
     * Vérifie si l'élément est défilé jusqu'en bas
     * @param {HTMLElement} element Élément à vérifier
     * @returns {boolean} True si l'élément est défilé jusqu'en bas
     */
    function isScrolledToBottom(element) {
        if (!element) return true;
        return Math.abs(element.scrollHeight - element.scrollTop - element.clientHeight) < 20;
    }
    
    /**
     * Fait défiler l'élément jusqu'en bas
     * @param {HTMLElement} element Élément à faire défiler
     */
    function scrollToBottom(element) {
        if (!element) return;
        element.scrollTop = element.scrollHeight;
    }
    
    /**
     * Affiche un indicateur de nouveaux messages
     * @param {number} count Nombre de nouveaux messages
     */
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
    
    /**
     * Met à jour le badge de notification
     * @param {number} count Nombre de notifications
     */
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
    
    /**
     * Actualise la liste des conversations
     */
    function refreshConversationList() {
        if (!isIndexPage) return;
        
        // Récupérer le dossier courant
        const folder = new URLSearchParams(window.location.search).get('folder') || 'reception';
        
        // Actualiser la liste
        fetch(`index.php?folder=${folder}&ajax=1&_=${Date.now()}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Erreur lors de la récupération des conversations: ${response.status}`);
                }
                return response.text();
            })
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newList = doc.querySelector('.conversation-list');
                
                if (newList) {
                    const currentList = document.querySelector('.conversation-list');
                    if (currentList) {
                        currentList.innerHTML = newList.innerHTML;
                        
                        // Réinitialiser les gestionnaires d'événements
                        if (typeof window.setupQuickActions === 'function') {
                            window.setupQuickActions();
                        }
                    }
                }
            })
            .catch(error => {
                console.error('Erreur lors de l\'actualisation de la liste :', error);
            });
    }
    
    /**
     * Affiche une notification de bureau
     * @param {Object} data Données de notification
     */
    function showDesktopNotification(data) {
        // Vérifier si les notifications sont supportées
        if (!("Notification" in window)) return;
        
        // Si les notifications sont autorisées
        if (Notification.permission === "granted") {
            createNotification(data);
        }
        // Sinon, demander la permission
        else if (Notification.permission !== "denied") {
            Notification.requestPermission().then(permission => {
                if (permission === "granted") {
                    createNotification(data);
                }
            });
        }
    }
    
    /**
     * Crée une notification de bureau
     * @param {Object} data Données de notification
     */
    function createNotification(data) {
        if (Notification.permission !== "granted") return;
        
        const title = "Nouvelle notification";
        let expediteurNom = "Expéditeur";
        let count = 0;
        
        // Récupérer le nombre de notifications
        if (data && typeof data.count === 'number') {
            count = data.count;
        }
        
        // Récupérer l'expéditeur
        if (data && data.latest_notification && data.latest_notification.expediteur_nom) {
            expediteurNom = data.latest_notification.expediteur_nom;
        }
        
        const options = {
            body: count === 1 
                ? `Nouveau message de ${expediteurNom}`
                : `${count} nouveaux messages non lus`,
            icon: '/assets/images/icon-notification.png' // Remplacer par le bon chemin
        };
        
        try {
            const notification = new Notification(title, options);
            
            // Rediriger vers la conversation au clic
            notification.onclick = function() {
                window.focus();
                if (data.latest_notification && data.latest_notification.conversation_id) {
                    window.location.href = `conversation.php?id=${data.latest_notification.conversation_id}`;
                }
            };
        } catch (e) {
            console.error('Erreur lors de la création de la notification:', e);
        }
    }
    
    /**
     * Ajoute un message au DOM
     * @param {Object} message Objet message à ajouter
     * @param {HTMLElement} container Conteneur où ajouter le message
     */
    function appendMessageToDOM(message, container) {
        if (!container) return;
        
        // Vérifier si le message est valide
        if (!message || !message.id) {
            console.warn('Message invalide, impossible de l\'ajouter au DOM', message);
            return;
        }
        
        // Vérifier si le message n'est pas déjà affiché
        if (document.querySelector(`.message[data-id="${message.id}"]`)) {
            return;
        }
        
        // Créer un nouvel élément pour le message
        const messageElement = document.createElement('div');
        
        // Déterminer les classes du message
        let classes = ['message'];
        
        // Si c'est moi qui ai envoyé ce message
        const isSelf = message.is_self == 1 || message.is_self === true;
        if (isSelf) {
            classes.push('self');
        }
        
        // Si le message a été lu par le destinataire (pertinent seulement pour mes propres messages)
        const isRead = message.est_lu == 1 || message.est_lu === true;
        if (isRead && isSelf) {
            classes.push('read');
        }
        
        if (message.status) {
            classes.push(message.status);
        }
        
        messageElement.className = classes.join(' ');
        messageElement.setAttribute('data-id', message.id);
        messageElement.setAttribute('data-timestamp', message.timestamp || Date.now()/1000);
        
        // S'assurer que les propriétés nécessaires existent
        const expediteurNom = message.expediteur_nom || 'Inconnu';
        const senderType = message.sender_type || 'inconnu';
        const messageBody = message.body || message.contenu || '';
        
        // Formater la date
        let formattedDate = 'Date inconnue';
        if (message.timestamp) {
            const messageDate = new Date(message.timestamp * 1000);
            formattedDate = formatMessageDate(messageDate);
        } else if (message.created_at) {
            const messageDate = new Date(message.created_at);
            formattedDate = formatMessageDate(messageDate);
        }
        
        // Construire le HTML du message
        let messageHTML = `
            <div class="message-header">
                <div class="sender">
                    <strong>${escapeHTML(expediteurNom)}</strong>
                    <span class="sender-type">${getParticipantType(senderType)}</span>
                </div>
                <div class="message-meta">
        `;
        
        // Ajouter l'importance si nécessaire
        if (message.status && message.status !== 'normal') {
            messageHTML += `<span class="importance-tag ${message.status}">${message.status}</span>`;
        }
        
        messageHTML += `
                    <span class="date">${formattedDate}</span>
                </div>
            </div>
            <div class="message-content">${linkify(nl2br(escapeHTML(messageBody)))}</div>
        `;
        
        // Ajouter les pièces jointes s'il y en a
        if (message.pieces_jointes && message.pieces_jointes.length > 0) {
            messageHTML += '<div class="attachments">';
            
            message.pieces_jointes.forEach(attachment => {
                if (attachment && attachment.chemin && attachment.nom_fichier) {
                    messageHTML += `
                        <a href="${escapeHTML(attachment.chemin)}" class="attachment" target="_blank">
                            <i class="fas fa-paperclip"></i> ${escapeHTML(attachment.nom_fichier)}
                        </a>
                    `;
                }
            });
            
            messageHTML += '</div>';
        }
        
        // Ajouter le footer du message avec le "Vu" seulement pour mes propres messages
        messageHTML += `
            <div class="message-footer">
                <div class="message-status">
                    ${(isRead && isSelf) ? '<div class="message-read"><i class="fas fa-check"></i> Vu</div>' : ''}
                </div>
        `;
        
        // Ajouter les actions si ce n'est pas un message de l'utilisateur
        if (!isSelf) {
            messageHTML += `
                <div class="message-actions">
                    ${isRead ? 
                        `<button class="btn-icon mark-unread-btn" data-message-id="${message.id}">
                            <i class="fas fa-envelope"></i> Marquer comme non lu
                        </button>` : 
                        `<button class="btn-icon mark-read-btn" data-message-id="${message.id}">
                            <i class="fas fa-envelope-open"></i> Marquer comme lu
                        </button>`
                    }
                    <button class="btn-icon" onclick="replyToMessage(${message.id}, '${escapeHTML(expediteurNom)}')">
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
        
        // Ajouter les gestionnaires d'événements pour les boutons
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
    
    /**
     * Formate une date pour l'affichage
     * @param {Date} date Date à formater
     * @returns {string} Date formatée
     */
    function formatMessageDate(date) {
        if (!date || !(date instanceof Date) || isNaN(date.getTime())) {
            return "Date inconnue";
        }
        
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
    
    /**
     * Échappe les caractères HTML
     * @param {string} text Texte à échapper
     * @returns {string} Texte échappé
     */
    function escapeHTML(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
    
    /**
     * Convertit les retours à la ligne en <br>
     * @param {string} text Texte à convertir
     * @returns {string} Texte avec balises <br>
     */
    function nl2br(text) {
        if (!text) return '';
        return text.replace(/\n/g, '<br>');
    }
    
    /**
     * Transforme les URLs en liens cliquables
     * @param {string} text Texte à transformer
     * @returns {string} Texte avec liens cliquables
     */
    function linkify(text) {
        if (!text) return '';
        const urlPattern = /(https?:\/\/[^\s<]+[^<.,:;"')\]\s])/g;
        return text.replace(urlPattern, function(url) {
            return `<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>`;
        });
    }
    
    /**
     * Renvoie le libellé du type de participant
     * @param {string} type Type de participant
     * @returns {string} Libellé
     */
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
    
    /**
     * Marque un message comme lu
     * @param {number} messageId ID du message
     */
    function markMessageAsRead(messageId) {
        fetch(`api/mark_message.php?id=${messageId}&action=mark_read`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Mettre à jour l'interface
                    const message = document.querySelector(`.message[data-id="${messageId}"]`);
                    if (message) {
                        // La classe 'read' ne devrait être ajoutée que si c'est un message que *j'ai* envoyé
                        if (message.classList.contains('self')) {
                            message.classList.add('read');
                            
                            // Ajouter l'indicateur "Vu" - seulement pour nos propres messages
                            const footer = message.querySelector('.message-footer .message-status');
                            if (footer && !footer.querySelector('.message-read')) {
                                const readStatus = document.createElement('div');
                                readStatus.className = 'message-read';
                                readStatus.innerHTML = '<i class="fas fa-check"></i> Vu';
                                footer.appendChild(readStatus);
                            }
                        }
                        
                        // Remplacer le bouton
                        const readBtn = message.querySelector('.mark-read-btn');
                        if (readBtn) {
                            const unreadBtn = document.createElement('button');
                            unreadBtn.className = 'btn-icon mark-unread-btn';
                            unreadBtn.setAttribute('data-message-id', messageId);
                            unreadBtn.innerHTML = '<i class="fas fa-envelope"></i> Marquer comme non lu';
                            unreadBtn.addEventListener('click', function() {
                                markMessageAsUnread(messageId);
                            });
                            
                            readBtn.parentNode.replaceChild(unreadBtn, readBtn);
                        }
                    }
                    
                    // Forcer une actualisation des messages et notifications
                    messageEtag = null; // Forcer la prochaine requête à ignorer l'ETag
                    notificationEtag = null;
                    pollMessages(); // Actualiser immédiatement les messages pour voir le status "Vu"
                    pollNotifications();
                }
            })
            .catch(error => console.error('Erreur:', error));
    }
    
    /**
     * Marque un message comme non lu
     * @param {number} messageId ID du message
     */
    function markMessageAsUnread(messageId) {
        fetch(`api/mark_message.php?id=${messageId}&action=mark_unread`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Mettre à jour l'interface
                    const message = document.querySelector(`.message[data-id="${messageId}"]`);
                    if (message) {
                        if (message.classList.contains('self')) {
                            message.classList.remove('read');
                            
                            // Supprimer l'indicateur de lecture si c'est notre propre message
                            const readIndicator = message.querySelector('.message-read');
                            if (readIndicator) {
                                readIndicator.remove();
                            }
                        }
                        
                        // Remplacer le bouton
                        const unreadBtn = message.querySelector('.mark-unread-btn');
                        if (unreadBtn) {
                            const readBtn = document.createElement('button');
                            readBtn.className = 'btn-icon mark-read-btn';
                            readBtn.setAttribute('data-message-id', messageId);
                            readBtn.innerHTML = '<i class="fas fa-envelope-open"></i> Marquer comme lu';
                            readBtn.addEventListener('click', function() {
                                markMessageAsRead(messageId);
                            });
                            
                            unreadBtn.parentNode.replaceChild(readBtn, unreadBtn);
                        }
                    }
                    
                    // Forcer une actualisation des notifications
                    messageEtag = null; // Forcer la prochaine requête à ignorer l'ETag
                    notificationEtag = null;
                    pollMessages(); // Actualiser immédiatement les messages pour voir le status "Vu"
                    pollNotifications();
                }
            })
            .catch(error => console.error('Erreur:', error));
    }
    
    /**
     * Gestion des changements de visibilité de la page
     */
    function setupVisibilityHandlers() {
        // Mettre en pause lorsque l'onglet n'est pas actif
        document.addEventListener('visibilitychange', function() {
            isActive = !document.hidden;
            
            if (isActive) {
                console.log('Page visible, reprise des vérifications');
                // Réinitialiser l'intervalle et vérifier immédiatement
                currentInterval = CONFIG.baseInterval;
                
                // Forcer une actualisation en ignorant les ETags
                messageEtag = null;
                notificationEtag = null;
                
                // Démarrer immédiatement les vérifications
                if (isConversationPage) {
                    pollMessages();
                }
                pollNotifications();
            } else {
                console.log('Page masquée, pause des vérifications');
                // Arrêter les vérifications en cours
                clearTimeout(messageTimer);
                clearTimeout(notificationTimer);
            }
        });
        
        // Lors de la sortie de la page
        window.addEventListener('beforeunload', function() {
            isActive = false;
            clearTimeout(messageTimer);
            clearTimeout(notificationTimer);
        });
        
        // Gestion des reconnexions réseau
        window.addEventListener('online', function() {
            console.log('Connexion réseau rétablie, reprise des vérifications');
            isActive = true;
            currentInterval = CONFIG.baseInterval;
            
            // Reset des ETags pour forcer une vérification complète
            messageEtag = null;
            notificationEtag = null;
            
            // Vérifier immédiatement
            if (isConversationPage) {
                pollMessages();
            }
            pollNotifications();
        });
        
        window.addEventListener('offline', function() {
            console.log('Connexion réseau perdue, pause des vérifications');
            isActive = false;
            clearTimeout(messageTimer);
            clearTimeout(notificationTimer);
        });
    }
    
    // Exposer les fonctions dans l'espace global
    window.markMessageAsRead = markMessageAsRead;
    window.markMessageAsUnread = markMessageAsUnread;
    
    // Initialiser les gestionnaires de visibilité
    setupVisibilityHandlers();
    
    // Démarrer les vérifications si nécessaire
    if (isConversationPage && convId) {
        console.log('Page de conversation détectée, démarrage du polling des messages');
        pollMessages();
    }
    
    console.log('Démarrage du polling des notifications');
    pollNotifications();
    
    // Indiquer que ce système est actif
    window.hasLightweightRefresh = true;
});