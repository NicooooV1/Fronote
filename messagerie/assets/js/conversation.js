/**
 * /assets/js/conversation.js - Scripts pour les conversations
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialiser les actions de conversation
    initConversationActions();
    
    // Initialisation du système de lecture des messages
    initReadTracker();
    
    // Actualisation en temps réel pour les modifications de conversation
    setupSSEForConversation();
    
    // Validation du formulaire de message
    setupMessageValidation();
    
    // Initialisation de l'envoi AJAX
    setupAjaxMessageSending();
    
    // Initialiser la sidebar rétractable
    initSidebarCollapse();
    
    // Nettoyage des ressources lors de la navigation
    setupBeforeUnloadHandler();
});

/**
 * Configuration des SSE pour la conversation
 */
function setupSSEForConversation() {
    // Variable globale pour suivre l'état des connexions
    window.sseConnections = {
        messageSource: null,
        readStatusSource: null
    };
    
    // Configurer les connexions SSE
    setupSSEForMessages();
    setupSSEForReadStatus();
    
    // Gérer la visibilité de la page pour optimiser les connexions
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden') {
            closeAllSSEConnections();
        } else if (document.visibilityState === 'visible') {
            setupSSEForMessages();
            setupSSEForReadStatus();
        }
    });
}

/**
 * Configure la connexion SSE pour les mises à jour des messages
 */
function setupSSEForMessages() {
    const convId = new URLSearchParams(window.location.search).get('id');
    if (!convId) return;
    
    // Fermer une éventuelle connexion existante
    if (window.sseConnections.messageSource) {
        window.sseConnections.messageSource.close();
    }
    
    // Récupérer le timestamp du dernier message
    const lastMessage = document.querySelector('.message:last-child');
    const lastTimestamp = lastMessage ? parseInt(lastMessage.dataset.timestamp || '0', 10) : 0;
    
    // Récupérer le jeton SSE
    getSSEToken(convId)
        .then(token => {
            // Créer la connexion SSE
            const messageSource = new EventSource(`api/messages.php?action=stream&conv_id=${convId}&last_timestamp=${lastTimestamp}&token=${token}`);
            
            // Stocker la référence
            window.sseConnections.messageSource = messageSource;
            
            // Événement pour les nouveaux messages
            messageSource.addEventListener('message', function(event) {
                try {
                    const messages = JSON.parse(event.data);
                    
                    // Ajouter les messages à l'interface
                    const messagesContainer = document.querySelector('.messages-container');
                    const wasAtBottom = isScrolledToBottom(messagesContainer);
                    
                    messages.forEach(message => {
                        appendMessageToDOM(message, messagesContainer);
                    });
                    
                    if (wasAtBottom) {
                        scrollToBottom(messagesContainer);
                    } else {
                        showNewMessagesIndicator(messages.length);
                    }
                    
                    // Lire audio pour notification (optionnelle)
                    playNotificationSound();
                } catch (e) {
                    console.error('Erreur lors du traitement des messages:', e);
                }
            });
            
            // Événement pour les changements de participants
            messageSource.addEventListener('participants_changed', function(event) {
                refreshParticipantsList();
            });
            
            // Gestion des erreurs
            messageSource.addEventListener('error', function(event) {
                console.error('SSE Error: Connection failed or closed. Reconnecting...');
                
                // Si la connexion est fermée, tenter de se reconnecter après un délai
                if (this.readyState === EventSource.CLOSED) {
                    setTimeout(setupSSEForMessages, 5000);
                }
            });
            
            // Ping pour maintenir la connexion
            messageSource.addEventListener('ping', function(event) {
                // Connexion maintenue, rien à faire
            });
        })
        .catch(error => {
            console.error('Erreur lors de la configuration SSE pour les messages:', error);
            // Réessayer après un délai
            setTimeout(setupSSEForMessages, 5000);
        });
}

/**
 * Fonction pour récupérer un jeton SSE
 * @param {number} convId - ID de la conversation
 * @returns {Promise<string>} Le jeton SSE
 */
function getSSEToken(convId) {
    return fetch(`api/sse_token.php?conv_id=${convId}`)
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
 * Configure la connexion SSE pour les statuts de lecture
 */
function setupSSEForReadStatus() {
    const convId = new URLSearchParams(window.location.search).get('id');
    if (!convId) return;
    
    // Fermer une éventuelle connexion existante
    if (window.sseConnections.readStatusSource) {
        window.sseConnections.readStatusSource.close();
    }
    
    // Récupérer le dernier message ID
    let lastReadMessageId = 0;
    const messageElements = document.querySelectorAll('.message');
    if (messageElements.length > 0) {
        const lastMessage = messageElements[messageElements.length - 1];
        lastReadMessageId = parseInt(lastMessage.dataset.id || '0', 10);
    }
    
    // Récupérer le jeton SSE
    getSSEToken(convId)
        .then(token => {
            // Créer la connexion SSE
            const readStatusSource = new EventSource(`api/read_status.php?action=stream&conv_id=${convId}&since=${lastReadMessageId}&version=0&token=${token}`);
            
            // Stocker la référence
            window.sseConnections.readStatusSource = readStatusSource;
            
            // Événement d'initialisation
            readStatusSource.addEventListener('init', function(event) {
                try {
                    const data = JSON.parse(event.data);
                    // Stocker la version initiale si nécessaire
                } catch (e) {
                    console.error('Erreur lors du traitement de l\'initialisation:', e);
                }
            });
            
            // Événement pour l'état initial
            readStatusSource.addEventListener('initial_state', function(event) {
                try {
                    const data = JSON.parse(event.data);
                    
                    // Mettre à jour tous les statuts
                    Object.entries(data).forEach(([messageId, readStatus]) => {
                        updateReadStatus(readStatus);
                    });
                } catch (e) {
                    console.error('Erreur lors du traitement de l\'état initial:', e);
                }
            });
            
            // Événement pour les mises à jour
            readStatusSource.addEventListener('read_status', function(event) {
                try {
                    const data = JSON.parse(event.data);
                    
                    data.updates.forEach(update => {
                        updateReadStatus(update.read_status);
                        
                        // Mettre à jour lastReadMessageId si nécessaire
                        if (update.messageId > lastReadMessageId) {
                            lastReadMessageId = update.messageId;
                        }
                    });
                } catch (e) {
                    console.error('Erreur lors du traitement des mises à jour de lecture:', e);
                }
            });
            
            // Gestion des erreurs
            readStatusSource.addEventListener('error', function(event) {
                console.error('SSE Error: Read status connection failed or closed. Reconnecting...');
                
                // Si la connexion est fermée, tenter de se reconnecter après un délai
                if (this.readyState === EventSource.CLOSED) {
                    setTimeout(setupSSEForReadStatus, 5000);
                }
            });
            
            // Ping pour maintenir la connexion
            readStatusSource.addEventListener('ping', function(event) {
                // Connexion maintenue, rien à faire
            });
        })
        .catch(error => {
            console.error('Erreur lors de la configuration SSE pour les statuts de lecture:', error);
            // Réessayer après un délai
            setTimeout(setupSSEForReadStatus, 5000);
        });
}

/**
 * Ferme toutes les connexions SSE
 */
function closeAllSSEConnections() {
    if (window.sseConnections.messageSource) {
        window.sseConnections.messageSource.close();
        window.sseConnections.messageSource = null;
    }
    
    if (window.sseConnections.readStatusSource) {
        window.sseConnections.readStatusSource.close();
        window.sseConnections.readStatusSource = null;
    }
}

/**
 * Configure un gestionnaire pour nettoyer les ressources avant la navigation
 */
function setupBeforeUnloadHandler() {
    // Gestionnaire d'événement pour la navigation
    window.addEventListener('beforeunload', cleanupResources);
    window.addEventListener('pagehide', cleanupResources);
    
    // Fonction pour nettoyer les ressources
    function cleanupResources() {
        // Fermer les connexions SSE
        closeAllSSEConnections();
    }
}

/**
 * Initialise le système de détection et de suivi des messages lus
 */
function initReadTracker() {
    // Configuration améliorée de l'IntersectionObserver
    const messageObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting && entry.intersectionRatio >= 0.7) {
                const messageEl = entry.target;
                const messageId = parseInt(messageEl.dataset.id, 10);
                
                // Éviter les requêtes inutiles pour les messages déjà lus ou envoyés par l'utilisateur
                if (!messageEl.classList.contains('read') && !messageEl.classList.contains('self')) {
                    markMessageAsRead(messageId);
                }
            }
        });
    }, {
        root: document.querySelector('.messages-container'),
        threshold: 0.7, // 70% visible pour être considéré comme lu
        rootMargin: '0px 0px -20% 0px' // Ignorer le bas de l'écran
    });
    
    // Observer tous les messages qui ne sont pas de l'utilisateur
    document.querySelectorAll('.message:not(.self)').forEach(message => {
        messageObserver.observe(message);
    });
    
    // Ajouter des gestionnaires d'événements pour les boutons de marquage
    document.addEventListener('click', function(e) {
        // Bouton "Marquer comme lu"
        if (e.target.closest('.mark-read-btn')) {
            const btn = e.target.closest('.mark-read-btn');
            const messageId = btn.dataset.messageId;
            markMessageAsRead(messageId);
        }
        
        // Bouton "Marquer comme non lu"
        if (e.target.closest('.mark-unread-btn')) {
            const btn = e.target.closest('.mark-unread-btn');
            const messageId = btn.dataset.messageId;
            markMessageAsUnread(messageId);
        }
    });
}

/**
 * Met à jour l'affichage du statut de lecture d'un message
 */
function updateReadStatus(readStatus) {
    if (!readStatus || !readStatus.message_id) return;
    
    const messageEl = document.querySelector(`.message[data-id="${readStatus.message_id}"]`);
    if (!messageEl) return;
    
    const statusEl = messageEl.querySelector('.message-read-status');
    if (!statusEl) return;
    
    // Mettre à jour le contenu selon l'état de lecture
    if (readStatus.all_read) {
        statusEl.innerHTML = `
            <div class="all-read">
                <i class="fas fa-check-double"></i> Vu
            </div>
        `;
        // Ajouter la classe 'read' au message
        messageEl.classList.add('read');
    } else if (readStatus.read_by_count > 0) {
        // Créer la liste des noms des lecteurs
        const readerNames = readStatus.readers && readStatus.readers.length > 0 
            ? readStatus.readers.map(r => r.nom_complet).join(', ')
            : 'Personne';
        
        statusEl.innerHTML = `
            <div class="partial-read">
                <i class="fas fa-check"></i>
                <span class="read-count">${readStatus.read_by_count}/${readStatus.total_participants - 1}</span>
                <span class="read-tooltip" title="${readerNames}">
                    <i class="fas fa-info-circle"></i>
                </span>
            </div>
        `;
    }
}

/**
 * Marque un message comme lu via l'API
 */
function markMessageAsRead(messageId) {
    const convId = new URLSearchParams(window.location.search).get('id');
    
    // Obtenir le jeton CSRF
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    
    fetch(`api/read_status.php?action=read&conv_id=${convId}`, {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken
        },
        body: JSON.stringify({ messageId, csrf_token: csrfToken })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`Erreur HTTP: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Mettre à jour l'interface avec le nouveau statut
            const messageEl = document.querySelector(`.message[data-id="${messageId}"]`);
            if (messageEl) {
                messageEl.classList.add('read');
                
                // Mettre à jour le bouton si présent
                const readBtn = messageEl.querySelector('.mark-read-btn');
                if (readBtn) {
                    const unreadBtn = document.createElement('button');
                    unreadBtn.className = 'btn-icon mark-unread-btn';
                    unreadBtn.setAttribute('data-message-id', messageId);
                    unreadBtn.innerHTML = '<i class="fas fa-envelope"></i> Marquer comme non lu';
                    
                    readBtn.parentNode.replaceChild(unreadBtn, readBtn);
                }
            }
            
            // Mettre à jour le statut de lecture
            if (data.read_status) {
                updateReadStatus(data.read_status);
            }
        }
    })
    .catch(error => {
        console.error('Erreur lors du marquage comme lu:', error);
        afficherNotificationErreur("Une erreur est survenue lors du marquage du message comme lu");
    });
}

/**
 * Marque un message comme non lu
 */
function markMessageAsUnread(messageId) {
    fetch(`api/messages.php?id=${messageId}&action=mark_unread`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erreur HTTP: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Mettre à jour l'interface utilisateur
                const message = document.querySelector(`.message[data-id="${messageId}"]`);
                if (message) {
                    message.classList.remove('read');
                    
                    // Mettre à jour le bouton
                    const unreadBtn = message.querySelector('.mark-unread-btn');
                    if (unreadBtn) {
                        const readBtn = document.createElement('button');
                        readBtn.className = 'btn-icon mark-read-btn';
                        readBtn.setAttribute('data-message-id', messageId);
                        readBtn.innerHTML = '<i class="fas fa-envelope-open"></i> Marquer comme lu';
                        
                        unreadBtn.parentNode.replaceChild(readBtn, unreadBtn);
                    }
                }
                
                // Mettre à jour le statut de lecture
                if (data.readStatus) {
                    updateReadStatus(data.readStatus);
                }
            } else {
                afficherNotificationErreur("Erreur: " + (data.error || "Une erreur est survenue"));
                console.error('Erreur:', data.error);
            }
        })
        .catch(error => {
            afficherNotificationErreur("Erreur: " + error.message);
            console.error('Erreur:', error);
        });
}

/**
 * Rafraîchit la liste des participants
 */
function refreshParticipantsList() {
    const convId = new URLSearchParams(window.location.search).get('id');
    
    fetch(`api/participants.php?conv_id=${convId}&action=get_list`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erreur HTTP: ${response.status}`);
            }
            return response.text();
        })
        .then(html => {
            const participantsList = document.querySelector('.participants-list');
            if (participantsList) {
                participantsList.innerHTML = html;
            }
        })
        .catch(error => {
            console.error('Erreur lors de l\'actualisation des participants:', error);
        });
}

/**
 * Affiche un indicateur de nouveaux messages
 * @param {number} count - Nombre de nouveaux messages
 */
function showNewMessagesIndicator(count) {
    // Créer ou mettre à jour un indicateur flottant
    let indicator = document.getElementById('new-messages-indicator');
    
    if (!indicator) {
        indicator = document.createElement('div');
        indicator.id = 'new-messages-indicator';
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
        
        indicator.addEventListener('click', function() {
            const messagesContainer = document.querySelector('.messages-container');
            scrollToBottom(messagesContainer);
            this.style.display = 'none';
        });
        
        document.body.appendChild(indicator);
    }
    
    indicator.textContent = `${count} nouveau(x) message(s)`;
    indicator.style.display = 'block';
    
    // Masquer après un délai si non cliqué
    setTimeout(() => {
        if (indicator) indicator.style.display = 'none';
    }, 5000);
}

/**
 * Joue un son de notification (optionnel)
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

/**
 * Ajoute un message au DOM
 * @param {Object} message - Objet message à ajouter
 * @param {HTMLElement} container - Conteneur où ajouter le message
 */
function appendMessageToDOM(message, container) {
    // Créer un nouvel élément div pour le message
    const messageElement = document.createElement('div');
    
    // Déterminer les classes du message
    let classes = ['message'];
    if (message.is_self) classes.push('self');
    if (message.est_lu === 1 || message.est_lu === true) classes.push('read');
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
        <div class="message-content">${nl2br(escapeHTML(message.body || message.contenu))}</div>
    `;
    
    // Ajouter les pièces jointes si présentes
    if (message.pieces_jointes && message.pieces_jointes.length > 0) {
        messageHTML += `<div class="attachments">`;
        message.pieces_jointes.forEach(piece => {
            messageHTML += `
                <a href="${piece.chemin}" class="attachment" target="_blank">
                    <i class="fas fa-paperclip"></i> ${escapeHTML(piece.nom_fichier)}
                </a>
            `;
        });
        messageHTML += `</div>`;
    }
    
    messageHTML += `<div class="message-footer">`;
    
    // Ajouter le statut de lecture pour les propres messages de l'utilisateur
    if (message.is_self) {
        messageHTML += `
            <div class="message-status">
                <div class="message-read-status" data-message-id="${message.id}">
                    ${(message.est_lu === 1 || message.est_lu === true) ? 
                        '<div class="all-read"><i class="fas fa-check-double"></i> Vu</div>' : 
                        '<div class="partial-read"><i class="fas fa-check"></i> <span class="read-count">0/' + 
                        (document.querySelectorAll('.participants-list li:not(.left)').length - 1) + 
                        '</span></div>'}
                </div>
            </div>
        `;
    } else {
        // Ajouter les actions pour les messages des autres
        messageHTML += `
            <div class="message-actions">
                ${(message.est_lu === 1 || message.est_lu === true) ? 
                    `<button class="btn-icon mark-unread-btn" data-message-id="${message.id}">
                        <i class="fas fa-envelope"></i> Marquer comme non lu
                    </button>` : 
                    `<button class="btn-icon mark-read-btn" data-message-id="${message.id}">
                        <i class="fas fa-envelope-open"></i> Marquer comme lu
                    </button>`
                }
                <button class="btn-icon" onclick="replyToMessage(${message.id}, '${escapeHTML(addSlashes(message.expediteur_nom))}')">
                    <i class="fas fa-reply"></i> Répondre
                </button>
            </div>
        `;
    }
    
    messageHTML += `</div>`;
    
    // Définir le HTML du message
    messageElement.innerHTML = messageHTML;
    
    // Ajouter le message au conteneur
    container.appendChild(messageElement);
    
    // Observer le nouveau message si ce n'est pas un message de l'utilisateur
    if (!message.is_self) {
        const messageObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && entry.intersectionRatio >= 0.7) {
                    const messageEl = entry.target;
                    const messageId = parseInt(messageEl.dataset.id, 10);
                    
                    if (!messageEl.classList.contains('read')) {
                        markMessageAsRead(messageId);
                        messageObserver.unobserve(messageEl);
                    }
                }
            });
        }, {
            root: document.querySelector('.messages-container'),
            threshold: 0.7,
            rootMargin: '0px 0px -20% 0px'
        });
        
        messageObserver.observe(messageElement);
    }
}

/**
 * Fait défiler un élément jusqu'en bas
 * @param {HTMLElement} element - Élément à faire défiler jusqu'en bas
 */
function scrollToBottom(element) {
    if (element) {
        element.scrollTop = element.scrollHeight;
    }
}

/**
 * Vérifie si l'élément est défilé jusqu'en bas
 * @param {HTMLElement} element - Élément à vérifier
 * @returns {boolean} True si l'élément est défilé jusqu'en bas
 */
function isScrolledToBottom(element) {
    if (!element) return false;
    return Math.abs(element.scrollHeight - element.scrollTop - element.clientHeight) < 20;
}

/**
 * Initialise les actions principales de conversation
 */
function initConversationActions() {
    // Gestion du scroll dans les conversations
    const messagesContainer = document.querySelector('.messages-container');
    if (messagesContainer) {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    // Actions sur les conversations et participants
    // Archiver une conversation
    const archiveBtn = document.getElementById('archive-btn');
    if (archiveBtn) {
        archiveBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (confirm('Êtes-vous sûr de vouloir archiver cette conversation ?')) {
                document.getElementById('archiveForm').submit();
            }
        });
    }
    
    // Supprimer une conversation
    const deleteBtn = document.getElementById('delete-btn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (confirm('Êtes-vous sûr de vouloir supprimer cette conversation ?')) {
                document.getElementById('deleteForm').submit();
            }
        });
    }
    
    // Restaurer une conversation
    const restoreBtn = document.getElementById('restore-btn');
    if (restoreBtn) {
        restoreBtn.addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('restoreForm').submit();
        });
    }
    
    // Gestion du modal pour l'ajout de participants
    const addParticipantBtn = document.getElementById('add-participant-btn');
    if (addParticipantBtn) {
        addParticipantBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showAddParticipantModal();
        });
    }
    
    // Gestion de la fermeture du modal
    const closeModalBtns = document.querySelectorAll('.close');
    closeModalBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            closeAddParticipantModal();
        });
    });
    
    // Fermeture du modal en cliquant en dehors
    window.addEventListener('click', function(event) {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });
    });
}

/**
 * Initialise la fonctionnalité de sidebar rétractable
 */
function initSidebarCollapse() {
    // Créer le bouton de toggle s'il n'existe pas déjà
    let sidebarToggle = document.getElementById('sidebar-toggle');
    const conversationPage = document.querySelector('.conversation-page');
    
    if (!conversationPage) return;
    
    // Créer le bouton s'il n'existe pas
    if (!sidebarToggle) {
        sidebarToggle = document.createElement('button');
        sidebarToggle.id = 'sidebar-toggle';
        sidebarToggle.className = 'sidebar-toggle';
        sidebarToggle.title = 'Afficher/masquer la liste des participants';
        sidebarToggle.innerHTML = '<i class="fas fa-bars"></i>';
        sidebarToggle.style.display = 'flex'; // Assurer que le bouton est visible
        sidebarToggle.style.zIndex = '1200';  // Mettre au premier plan
        
        // Insérer le bouton comme premier enfant de conversation-page
        conversationPage.prepend(sidebarToggle);
    }
    
    // Vérifier s'il y a une préférence sauvegardée
    const sidebarCollapsed = localStorage.getItem('conversation_sidebar_collapsed') === 'true';
    
    // Initialiser l'état en fonction de la préférence
    if (sidebarCollapsed) {
        conversationPage.setAttribute('data-sidebar-collapsed', 'true');
        sidebarToggle.innerHTML = '<i class="fas fa-bars"></i>';
    } else {
        conversationPage.setAttribute('data-sidebar-collapsed', 'false');
        sidebarToggle.innerHTML = '<i class="fas fa-times"></i>';
    }
    
    // Toggle la visibilité de la sidebar
    sidebarToggle.addEventListener('click', function() {
        const isCurrentlyCollapsed = conversationPage.getAttribute('data-sidebar-collapsed') === 'true';
        const newState = !isCurrentlyCollapsed;
        
        conversationPage.setAttribute('data-sidebar-collapsed', newState);
        
        // Mettre à jour l'icône du bouton
        this.innerHTML = newState ? 
            '<i class="fas fa-bars"></i>' : 
            '<i class="fas fa-times"></i>';
        
        // Sauvegarder la préférence
        localStorage.setItem('conversation_sidebar_collapsed', newState);
        
        // Déclencher un événement resize pour ajuster les composants
        window.dispatchEvent(new Event('resize'));
    });
}

/**
 * Affiche le modal d'ajout de participants
 */
function showAddParticipantModal() {
    const modal = document.getElementById('addParticipantModal');
    if (modal) {
        modal.style.display = 'block';
    }
}

/**
 * Ferme le modal d'ajout de participants
 */
function closeAddParticipantModal() {
    const modal = document.getElementById('addParticipantModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

/**
 * Répond à un message spécifique
 * @param {number} messageId - ID du message
 * @param {string} senderName - Nom de l'expéditeur
 */
function replyToMessage(messageId, senderName) {
    // Montrer l'interface de réponse
    const replyInterface = document.getElementById('reply-interface');
    const replyTo = document.getElementById('reply-to');
    const textarea = document.querySelector('textarea[name="contenu"]');
    
    if (replyInterface && replyTo && textarea) {
        replyInterface.style.display = 'block';
        replyTo.textContent = 'Répondre à ' + senderName;
        
        // Stocker l'ID du message parent
        document.getElementById('parent-message-id').value = messageId;
        
        // Faire défiler vers le bas et mettre le focus sur le textarea
        textarea.focus();
        window.scrollTo(0, document.body.scrollHeight);
    }
}

/**
 * Annule une réponse à un message spécifique
 */
function cancelReply() {
    const replyInterface = document.getElementById('reply-interface');
    if (replyInterface) {
        replyInterface.style.display = 'none';
        document.getElementById('parent-message-id').value = '';
    }
}

/**
 * Promeut un participant au rôle de modérateur
 * @param {number} participantId - ID du participant
 */
function promoteToModerator(participantId) {
    if (confirm('Êtes-vous sûr de vouloir promouvoir ce participant en modérateur ?')) {
        document.getElementById('promote_participant_id').value = participantId;
        document.getElementById('promoteForm').submit();
    }
}

/**
 * Rétrograde un modérateur
 * @param {number} participantId - ID du participant
 */
function demoteFromModerator(participantId) {
    if (confirm('Êtes-vous sûr de vouloir rétrograder ce modérateur ?')) {
        document.getElementById('demote_participant_id').value = participantId;
        document.getElementById('demoteForm').submit();
    }
}

/**
 * Supprime un participant de la conversation
 * @param {number} participantId - ID du participant
 */
function removeParticipant(participantId) {
    if (confirm('Êtes-vous sûr de vouloir supprimer ce participant de la conversation ? Il n\'aura plus accès à cette conversation.')) {
        document.getElementById('remove_participant_id').value = participantId;
        document.getElementById('removeForm').submit();
    }
}

/**
 * Charge les participants disponibles selon le type sélectionné
 */
function loadParticipants() {
    const type = document.getElementById('participant_type').value;
    const select = document.getElementById('participant_id');
    const convId = new URLSearchParams(window.location.search).get('id');
    
    if (!type || !select || !convId) return;
    
    // Vider la liste actuelle
    select.innerHTML = '<option value="">Chargement...</option>';
    
    // Faire une requête AJAX pour récupérer les participants
    fetch(`api/participants.php?type=${type}&conv_id=${convId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erreur HTTP: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            select.innerHTML = '';
            
            if (data.length === 0) {
                select.innerHTML = '<option value="">Aucun participant disponible</option>';
                return;
            }
            
            select.innerHTML = '<option value="">Sélectionner un participant</option>';
            
            data.forEach(participant => {
                const option = document.createElement('option');
                option.value = participant.id;
                option.textContent = participant.nom_complet;
                select.appendChild(option);
            });
        })
        .catch(error => {
            select.innerHTML = '<option value="">Erreur lors du chargement</option>';
            console.error('Erreur:', error);
        });
}

/**
 * Échappe les caractères HTML
 * @param {string} text - Texte à échapper
 * @returns {string} Texte échappé
 */
function escapeHTML(text) {
    if (!text) return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

/**
 * Échappe les apostrophes et les guillemets pour les chaînes JavaScript
 * @param {string} text - Texte à échapper
 * @returns {string} Texte échappé
 */
function addSlashes(text) {
    if (!text) return '';
    return text
        .replace(/\\/g, '\\\\')
        .replace(/\'/g, '\\\'')
        .replace(/\"/g, '\\"')
        .replace(/\0/g, '\\0');
}

/**
 * Convertit les retours à la ligne en <br>
 * @param {string} text - Texte à convertir
 * @returns {string} Texte avec des <br>
 */
function nl2br(text) {
    if (!text) return '';
    return text.replace(/\n/g, '<br>');
}

/**
 * Renvoie le libellé du type de participant
 * @param {string} type - Type de participant
 * @returns {string} Libellé formaté
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
 * Formater la date d'un message
 * @param {Date} date - Date à formater
 * @returns {string} Date formatée
 */
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

/**
 * Validation du formulaire de message
 */
function setupMessageValidation() {
    const form = document.getElementById('messageForm');
    if (!form) return;
    
    const textarea = form.querySelector('textarea[name="contenu"]');
    const submitBtn = form.querySelector('button[type="submit"]');
    
    if (textarea) {
        textarea.addEventListener('input', function() {
            const isEmpty = this.value.trim() === '';
            if (submitBtn) {
                submitBtn.disabled = isEmpty;
            }
        });
        
        // Vérifier l'état initial
        textarea.dispatchEvent(new Event('input'));
    }
}

/**
 * Configure l'envoi AJAX des messages
 */
function setupAjaxMessageSending() {
    const form = document.getElementById('messageForm');
    if (!form) return;
    
    form.addEventListener('submit', function(e) {
        e.preventDefault(); // Empêcher la soumission normale
        
        // Vérifier le contenu du message
        const textarea = form.querySelector('textarea[name="contenu"]');
        const messageContent = textarea.value.trim();
        if (messageContent === '') {
            afficherNotificationErreur('Le message ne peut pas être vide');
            return;
        }
        
        // Récupérer l'ID de conversation de l'URL
        const urlParams = new URLSearchParams(window.location.search);
        const convId = urlParams.get('id');
        
        // Créer un objet FormData pour l'envoi des données, y compris les fichiers
        const formData = new FormData(form);
        formData.append('conversation_id', convId);
        formData.append('action', 'send_message');
        
        // Afficher un indicateur de chargement
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalBtnText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi en cours...';
        
        // Envoyer la requête AJAX
        fetch('api/messages.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            // Vérifier si la réponse est ok avant de continuer
            if (!response.ok) {
                throw new Error(`Erreur HTTP: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Vider le formulaire
                textarea.value = '';
                
                // Vider l'aperçu des pièces jointes
                const fileList = document.getElementById('file-list');
                if (fileList) fileList.innerHTML = '';
                
                // Réinitialiser l'input de fichiers
                const fileInput = document.getElementById('attachments');
                if (fileInput) fileInput.value = '';
                
                // Mettre à jour l'interface utilisateur
                if (data.message) {
                    // Ajouter le nouveau message à la conversation
                    const messagesContainer = document.querySelector('.messages-container');
                    if (messagesContainer) {
                        appendMessageToDOM(data.message, messagesContainer);
                        scrollToBottom(messagesContainer);
                    }
                }
                
                // Réinitialiser le formulaire de réponse si c'est une réponse
                const replyInterface = document.getElementById('reply-interface');
                if (replyInterface && replyInterface.style.display !== 'none') {
                    document.getElementById('parent-message-id').value = '';
                    replyInterface.style.display = 'none';
                }
            } else {
                // Afficher l'erreur
                afficherNotificationErreur('Erreur lors de l\'envoi du message: ' + (data.error || 'Erreur inconnue'));
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            afficherNotificationErreur('Erreur lors de l\'envoi du message. Veuillez réessayer.');
        })
        .finally(() => {
            // Réactiver le bouton quoi qu'il arrive
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
        });
    });
}

/**
 * Utilitaire pour afficher des notifications d'erreur
 * @param {string} message - Message d'erreur
 * @param {number} duration - Durée d'affichage
 */
function afficherNotificationErreur(message, duration = 5000) {
    // Créer la div de notification si elle n'existe pas
    let notifContainer = document.getElementById('error-notification-container');
    
    if (!notifContainer) {
        notifContainer = document.createElement('div');
        notifContainer.id = 'error-notification-container';
        
        // Styles pour centrer la notification
        notifContainer.style.position = 'fixed';
        notifContainer.style.top = '50%';
        notifContainer.style.left = '50%';
        notifContainer.style.transform = 'translate(-50%, -50%)';
        notifContainer.style.zIndex = '10000';
        notifContainer.style.width = 'auto';
        notifContainer.style.maxWidth = '80%';
        
        document.body.appendChild(notifContainer);
    }
    
    // Créer la notification
    const notification = document.createElement('div');
    notification.className = 'error-notification';
    
    // Styles de la notification
    notification.style.backgroundColor = '#f8d7da';
    notification.style.color = '#721c24';
    notification.style.padding = '15px 20px';
    notification.style.margin = '10px';
    notification.style.borderRadius = '5px';
    notification.style.boxShadow = '0 4px 10px rgba(0, 0, 0, 0.2)';
    notification.style.display = 'flex';
    notification.style.justifyContent = 'space-between';
    notification.style.alignItems = 'center';
    notification.style.minWidth = '300px';
    
    // Créer le contenu de la notification
    const content = document.createElement('div');
    content.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
    
    // Créer le bouton de fermeture
    const closeBtn = document.createElement('button');
    closeBtn.innerHTML = '&times;';
    closeBtn.style.background = 'none';
    closeBtn.style.border = 'none';
    closeBtn.style.color = '#721c24';
    closeBtn.style.fontSize = '20px';
    closeBtn.style.cursor = 'pointer';
    closeBtn.style.marginLeft = '15px';
    
    // Ajouter le contenu et le bouton à la notification
    notification.appendChild(content);
    notification.appendChild(closeBtn);
    
    // Ajouter la notification au conteneur
    notifContainer.appendChild(notification);
    
    // Fermer la notification quand on clique sur le bouton
    closeBtn.addEventListener('click', function() {
        notifContainer.removeChild(notification);
        
        // Supprimer le conteneur s'il n'y a plus de notifications
        if (notifContainer.children.length === 0) {
            document.body.removeChild(notifContainer);
        }
    });
    
    // Fermer automatiquement après la durée spécifiée
    setTimeout(function() {
        if (notification.parentNode === notifContainer) {
            notifContainer.removeChild(notification);
            
            // Supprimer le conteneur s'il n'y a plus de notifications
            if (notifContainer.children.length === 0) {
                document.body.removeChild(notifContainer);
            }
        }
    }, duration);
    
    return notification;
}