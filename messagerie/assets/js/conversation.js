/**
 * /assets/js/conversation.js - Scripts pour les conversations
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialiser les actions de conversation
    initConversationActions();
    
    // Actualisation en temps réel pour les modifications de conversation
    setupRealTimeUpdates();
    
    // Validation du formulaire de message
    setupMessageValidation();
    
    // Initialisation de l'envoi AJAX
    setupAjaxMessageSending();
});

/**
 * Initialise les actions principales de conversation
 */
function initConversationActions() {
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
 * Configure la validation du formulaire de message
 */
function setupMessageValidation() {
    const messageForm = document.getElementById('messageForm');
    const textArea = document.querySelector('textarea[name="contenu"]');
    
    if (messageForm && textArea) {
        // Créer un compteur de caractères
        const counter = document.createElement('div');
        counter.id = 'char-counter';
        counter.className = 'text-muted small mt-1';
        counter.style.fontSize = '12px';
        counter.style.color = '#6c757d';
        counter.style.marginTop = '5px';
        textArea.parentNode.insertBefore(counter, textArea.nextSibling);
        
        // Mettre à jour le compteur en temps réel
        textArea.addEventListener('input', function() {
            const maxLength = 10000;
            const currentLength = this.value.length;
            const remaining = maxLength - currentLength;
            
            counter.textContent = `${currentLength}/${maxLength} caractères`;
            
            // Visualisation du dépassement
            if (currentLength > maxLength) {
                counter.style.color = '#dc3545';
                document.querySelector('button[type="submit"]').disabled = true;
            } else {
                counter.style.color = '#6c757d';
                document.querySelector('button[type="submit"]').disabled = false;
            }
        });
        
        // Déclencher l'événement d'entrée pour mettre à jour le compteur immédiatement
        const inputEvent = new Event('input');
        textArea.dispatchEvent(inputEvent);
    }
}

/**
 * Configure les mises à jour en temps réel pour la conversation
 * Implémentation prioritaire utilisant l'approche Long polling / AJAX poll
 */
function setupRealTimeUpdates() {
    // Variables pour la gestion des mises à jour
    const convId = new URLSearchParams(window.location.search).get('id');
    const refreshInterval = 10000; // 10 secondes entre chaque vérification
    let lastTimestamp = 0;
    let isCheckingForUpdates = false; // Flag pour éviter les requêtes concurrentes
    
    // Initialiser le timestamp de départ avec le dernier message
    const lastMessage = document.querySelector('.message:last-child');
    if (lastMessage) {
        lastTimestamp = parseInt(lastMessage.getAttribute('data-timestamp') || '0', 10);
    }
    
    // Ne pas continuer si on n'est pas sur une page de conversation
    if (!convId) return;
    
    // Fonction de vérification des mises à jour
    function checkForUpdates() {
        // Éviter les requêtes concurrentes
        if (isCheckingForUpdates) return;
        
        // Vérifier si l'utilisateur a le focus sur l'onglet et n'est pas en train d'écrire
        const textareaActive = document.querySelector('textarea:focus');
        const modalOpen = document.querySelector('.modal[style*="display: block"]');
        
        if (textareaActive || modalOpen) {
            // L'utilisateur est en train d'écrire ou un modal est ouvert, on reporte la vérification
            setTimeout(checkForUpdates, refreshInterval);
            return;
        }
        
        isCheckingForUpdates = true;
        
        // Requête de vérification
        fetch(`api/messages.php?conv_id=${convId}&action=check_updates&last_timestamp=${lastTimestamp}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.hasUpdates) {
                    // Si des mises à jour sont disponibles, les récupérer
                    fetchNewMessages();
                }
                
                // Vérifier les changements de participants
                if (data.success && data.participantsChanged) {
                    refreshParticipantsList();
                }
                
                isCheckingForUpdates = false;
                
                // Programmer la prochaine vérification
                setTimeout(checkForUpdates, refreshInterval);
            })
            .catch(error => {
                console.error('Erreur lors de la vérification des mises à jour:', error);
                isCheckingForUpdates = false;
                
                // Réessayer après un délai en cas d'erreur
                setTimeout(checkForUpdates, refreshInterval);
            });
    }
    
    /**
     * Vérifie si l'élément est défilé jusqu'en bas
     * @param {HTMLElement} element - Élément conteneur à vérifier
     * @returns {boolean} True si l'élément est défilé jusqu'en bas
     */
    function isScrolledToBottom(element) {
        return element.scrollHeight - element.scrollTop - element.clientHeight < 20;
    }
    
    /**
     * Fait défiler l'élément jusqu'en bas
     * @param {HTMLElement} element - Élément à faire défiler
     */
    function scrollToBottom(element) {
        element.scrollTop = element.scrollHeight;
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
        // On pourrait implémenter un son de notification ici
        // Par exemple:
        // const audio = new Audio('/assets/sounds/notification.mp3');
        // audio.play();
    }
    
    /**
     * Récupère et ajoute les nouveaux messages à la conversation
     */
    function fetchNewMessages() {
        fetch(`api/messages.php?conv_id=${convId}&action=get_new&last_timestamp=${lastTimestamp}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.messages && data.messages.length > 0) {
                    // Mettre à jour la référence du dernier timestamp
                    const messages = data.messages;
                    const messagesContainer = document.querySelector('.messages-container');
                    
                    // On était déjà en bas avant les nouveaux messages?
                    const wasAtBottom = isScrolledToBottom(messagesContainer);
                    
                    // Ajouter chaque nouveau message
                    messages.forEach(message => {
                        appendMessageToDOM(message, messagesContainer);
                        
                        // Mise à jour du lastTimestamp avec le plus récent
                        if (message.timestamp > lastTimestamp) {
                            lastTimestamp = message.timestamp;
                        }
                    });
                    
                    // Faire défiler vers le bas si l'utilisateur était déjà en bas
                    if (wasAtBottom) {
                        scrollToBottom(messagesContainer);
                    } else {
                        // Sinon, indiquer qu'il y a de nouveaux messages
                        showNewMessagesIndicator(messages.length);
                    }
                    
                    // Lecture audio pour notification (optionnelle)
                    playNotificationSound();
                }
            })
            .catch(error => {
                console.error('Erreur lors de la récupération des nouveaux messages:', error);
            });
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
            <div class="message-footer">
                <div class="message-status">
                    ${(message.est_lu === 1 || message.est_lu === true) ? '<div class="message-read"><i class="fas fa-check"></i> Vu</div>' : ''}
                </div>
        `;
        
        // Ajouter les actions si ce n'est pas un message de l'utilisateur courant
        if (!message.is_self) {
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
    
    /**
     * Formatage de la date d'un message
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
     * Actualise la liste des participants
     */
    function refreshParticipantsList() {
        fetch(`api/participants.php?conv_id=${convId}&action=get_list`)
            .then(response => response.text())
            .then(html => {
                const participantsList = document.querySelector('.participants-list');
                if (participantsList) {
                    participantsList.innerHTML = html;
                }
            })
            .catch(error => console.error('Erreur lors de l\'actualisation des participants:', error));
    }
    
    // Démarrer la vérification des mises à jour
    setTimeout(checkForUpdates, refreshInterval);
    
    // Arrêter les mises à jour lorsque l'utilisateur quitte la page
    window.addEventListener('beforeunload', () => {
        isCheckingForUpdates = true; // Empêcher de nouvelles requêtes
    });
    
    // Gestion du scroll - si l'utilisateur fait défiler vers le bas, masquer l'indicateur
    const messagesContainer = document.querySelector('.messages-container');
    if (messagesContainer) {
        messagesContainer.addEventListener('scroll', function() {
            if (isScrolledToBottom(this)) {
                const indicator = document.getElementById('new-messages-indicator');
                if (indicator) indicator.style.display = 'none';
            }
        });
    }
}

/**
 * Envoie un message via AJAX - Version corrigée
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
            alert('Le message ne peut pas être vide');
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
        .then(response => response.json())
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
 * Marque un message comme lu
 * @param {number} messageId - ID du message
 */
function markMessageAsRead(messageId) {
    fetch(`api/messages.php?id=${messageId}&action=mark_read`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Mettre à jour l'interface utilisateur
                const message = document.querySelector(`.message[data-id="${messageId}"]`);
                if (message) {
                    message.classList.add('read');
                    
                    // Mettre à jour l'indicateur de lecture
                    const messageStatus = message.querySelector('.message-status');
                    if (messageStatus && !message.querySelector('.message-read')) {
                        const readStatus = document.createElement('div');
                        readStatus.className = 'message-read';
                        readStatus.innerHTML = '<i class="fas fa-check"></i> Vu';
                        messageStatus.appendChild(readStatus);
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
            }
        })
        .catch(error => console.error('Erreur:', error));
}

/**
 * Marque un message comme non lu
 * @param {number} messageId - ID du message
 */
function markMessageAsUnread(messageId) {
    fetch(`api/messages.php?id=${messageId}&action=mark_unread`) // Correction du chemin API
        .then(response => {
            if (!response.ok) {
                throw new Error(`Erreur réseau: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Mettre à jour l'interface utilisateur
                const message = document.querySelector(`.message[data-id="${messageId}"]`);
                if (message) {
                    message.classList.remove('read');
                    
                    // Supprimer l'indicateur de lecture
                    const readIndicator = message.querySelector('.message-read');
                    if (readIndicator) {
                        readIndicator.remove();
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

// Gestion des pièces jointes
document.addEventListener('DOMContentLoaded', function() {
    const attachmentsInput = document.getElementById('attachments');
    if (attachmentsInput) {
        attachmentsInput.addEventListener('change', function(e) {
            const fileList = document.getElementById('file-list');
            fileList.innerHTML = '';
            
            if (this.files.length > 0) {
                for (let i = 0; i < this.files.length; i++) {
                    const file = this.files[i];
                    const fileSize = formatFileSize(file.size);
                    
                    const fileInfo = document.createElement('div');
                    fileInfo.className = 'file-info';
                    fileInfo.innerHTML = `
                        <i class="fas fa-file"></i>
                        <span>${file.name} (${fileSize})</span>
                    `;
                    fileList.appendChild(fileInfo);
                }
            }
        });
    }
});

/**
 * Formater la taille des fichiers
 * @param {number} bytes - Taille en octets
 * @returns {string} Taille formatée avec unité
 */
function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    else if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
    else return Math.round(bytes / 1048576 * 10) / 10 + ' MB';
}