/**
 * Scripts pour les conversations
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialiser les actions spécifiques aux conversations
    initConversationFeatures();
    
    // Actualisation en temps réel pour les modifications de conversation
    setupRealTimeUpdates();
    
    // Initialisation de l'envoi AJAX
    setupAjaxMessageSending();
});

/**
 * Initialise les fonctionnalités spécifiques aux conversations
 */
function initConversationFeatures() {
    // Faire défiler automatiquement vers le bas au chargement
    const messagesContainer = document.querySelector('.messages-container');
    if (messagesContainer) {
        setTimeout(() => {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }, 100);
    }
    
    // Initialiser les actions sur les messages
    initMessageActions();
    
    // Initialiser le chargement des participants
    document.getElementById('participant_type')?.addEventListener('change', loadParticipants);
}

/**
 * Initialise les actions sur les messages
 */
function initMessageActions() {
    // Boutons pour marquer comme lu/non lu
    document.querySelectorAll('.mark-read-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const messageId = this.getAttribute('data-message-id');
            markMessageAsRead(messageId);
        });
    });
    
    document.querySelectorAll('.mark-unread-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const messageId = this.getAttribute('data-message-id');
            markMessageAsUnread(messageId);
        });
    });
}

/**
 * Configure l'actualisation en temps réel
 */
function setupRealTimeUpdates() {
    // Variables pour la gestion des mises à jour
    const convId = new URLSearchParams(window.location.search).get('id');
    const refreshInterval = 5000; // 5 secondes entre chaque vérification
    let lastTimestamp = getCurrentTimestamp();
    let isCheckingForUpdates = false;
    
    if (!convId) return;
    
    /**
     * Récupère le timestamp actuel du dernier message
     */
    function getCurrentTimestamp() {
        const lastMessage = document.querySelector('.message:last-child');
        return lastMessage ? parseInt(lastMessage.getAttribute('data-timestamp') || '0', 10) : 0;
    }
    
    /**
     * Vérifie s'il y a des mises à jour pour la conversation
     */
    function checkForUpdates() {
        // Éviter les requêtes concurrentes
        if (isCheckingForUpdates) return;
        
        // Vérifier si l'utilisateur est actif
        const textareaActive = document.querySelector('textarea:focus');
        const modalOpen = document.querySelector('.modal[style*="display: block"]');
        
        if (textareaActive || modalOpen) {
            // Remettre la vérification à plus tard
            setTimeout(checkForUpdates, refreshInterval);
            return;
        }
        
        isCheckingForUpdates = true;
        
        // Requête de vérification
        fetch(`api/messages.php?conv_id=${convId}&action=check_updates&last_timestamp=${lastTimestamp}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Si des mises à jour sont disponibles, les récupérer
                    if (data.hasUpdates) {
                        fetchNewMessages();
                    }
                    
                    // Vérifier les changements de participants
                    if (data.participantsChanged) {
                        refreshParticipantsList();
                    }
                    
                    // Mettre à jour le timestamp
                    if (data.timestamp) {
                        lastTimestamp = data.timestamp;
                    }
                }
                
                isCheckingForUpdates = false;
                setTimeout(checkForUpdates, refreshInterval);
            })
            .catch(error => {
                console.error('Erreur lors de la vérification des mises à jour:', error);
                isCheckingForUpdates = false;
                setTimeout(checkForUpdates, refreshInterval);
            });
    }
    
    /**
     * Récupère et affiche les nouveaux messages
     */
    function fetchNewMessages() {
        fetch(`api/messages.php?conv_id=${convId}&action=get_new&last_timestamp=${lastTimestamp}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.messages && data.messages.length > 0) {
                    const messagesContainer = document.querySelector('.messages-container');
                    const wasAtBottom = isScrolledToBottom(messagesContainer);
                    
                    // Ajouter chaque nouveau message
                    data.messages.forEach(message => {
                        appendMessageToDOM(message, messagesContainer);
                    });
                    
                    // Faire défiler vers le bas si l'utilisateur était déjà en bas
                    if (wasAtBottom) {
                        scrollToBottom(messagesContainer);
                    } else {
                        // Sinon, indiquer qu'il y a de nouveaux messages
                        showNewMessagesIndicator(data.messages.length);
                    }
                    
                    // Mettre à jour le timestamp
                    lastTimestamp = getCurrentTimestamp();
                }
            })
            .catch(error => {
                console.error('Erreur lors de la récupération des nouveaux messages:', error);
            });
    }
    
    /**
     * Actualise la liste des participants
     */
    function refreshParticipantsList() {
        fetch(`api/participants.php?conv_id=${convId}&action=get_list`)
            .then(response => response.text())
            .then(html => {
                const participantsList = document.querySelector('.participants-list');
                if (participantsList) {
                    participantsList.outerHTML = html;
                    initConversationFeatures();
                }
            })
            .catch(error => {
                console.error('Erreur lors de l\'actualisation des participants:', error);
            });
    }
    
    // Démarrer la vérification des mises à jour
    setTimeout(checkForUpdates, refreshInterval);
}

/**
 * Configure l'envoi AJAX des messages
 */
function setupAjaxMessageSending() {
    const form = document.getElementById('messageForm');
    if (!form) return;
    
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        // Vérifier le contenu du message
        const textarea = form.querySelector('textarea[name="contenu"]');
        const messageContent = textarea.value.trim();
        if (messageContent === '') {
            alert('Le message ne peut pas être vide');
            return;
        }
        
        // Créer un objet FormData pour l'envoi des données
        const formData = new FormData(form);
        
        // Afficher un indicateur de chargement
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalBtnText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi en cours...';
        
        // Envoyer la requête AJAX
        fetch('api/messages.php?action=send_message', {
            method: 'POST',
            body: formData
        })
        .then(response => {
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
                alert('Erreur lors de l\'envoi du message: ' + (data.error || 'Erreur inconnue'));
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            alert('Erreur lors de l\'envoi du message. Veuillez réessayer.');
        })
        .finally(() => {
            // Réactiver le bouton quoi qu'il arrive
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
        });
    });
}

/**
 * Vérifie si l'élément est défilé jusqu'en bas
 * @param {HTMLElement} element
 * @returns {boolean}
 */
function isScrolledToBottom(element) {
    if (!element) return true;
    return Math.abs(element.scrollHeight - element.scrollTop - element.clientHeight) < 20;
}

/**
 * Fait défiler l'élément jusqu'en bas
 * @param {HTMLElement} element
 */
function scrollToBottom(element) {
    if (!element) return;
    element.scrollTop = element.scrollHeight;
}

/**
 * Affiche un indicateur de nouveaux messages
 * @param {number} count
 */
function showNewMessagesIndicator(count) {
    let indicator = document.getElementById('new-messages-indicator');
    
    if (!indicator) {
        indicator = document.createElement('div');
        indicator.id = 'new-messages-indicator';
        
        // Styles CSS inline
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
 * Ajoute un message au DOM
 * @param {Object} message
 * @param {HTMLElement} container
 */
function appendMessageToDOM(message, container) {
    if (!container) return;
    
    // Créer un élément pour le message
    const messageElement = document.createElement('div');
    
    // Déterminer les classes du message
    let classes = ['message'];
    if (message.is_self == 1 || message.is_self === true) classes.push('self');
    if (message.est_lu == 1 || message.est_lu === true) classes.push('read');
    if (message.status) classes.push(message.status);
    
    messageElement.className = classes.join(' ');
    messageElement.setAttribute('data-id', message.id);
    messageElement.setAttribute('data-timestamp', message.timestamp || Math.floor(Date.now()/1000));
    
    // Formater la date
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
        <div class="message-content">${nl2br(escapeHTML(message.contenu))}</div>
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
                ${(message.est_lu == 1 || message.est_lu === true) ? '<div class="message-read"><i class="fas fa-check"></i> Vu</div>' : ''}
            </div>
    `;
    
    // Ajouter les actions si ce n'est pas un message de l'utilisateur
    if (!(message.is_self == 1 || message.is_self === true)) {
        messageHTML += `
            <div class="message-actions">
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
    
    // Ajouter le message au conteneur
    container.appendChild(messageElement);
}

/**
 * Formatage de la date d'un message
 * @param {Date} date
 * @returns {string}
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
 * Échappe les caractères HTML
 * @param {string} text
 * @returns