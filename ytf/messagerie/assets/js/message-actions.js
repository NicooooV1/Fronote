/**
 * /assets/js/message-actions.js - Actions sur les messages
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialiser les actions sur les messages
    initMessageActions();
});

/**
 * Initialise les actions sur les messages
 */
function initMessageActions() {
    // Marquer comme lu/non lu
    initMessageReadMarking();
    
    // Actions rapides (archiver, supprimer)
    initQuickMessageActions();
}

/**
 * Initialise le marquage lu/non lu des messages
 */
function initMessageReadMarking() {
    const markReadBtns = document.querySelectorAll('.mark-read-btn');
    const markUnreadBtns = document.querySelectorAll('.mark-unread-btn');
    
    markReadBtns.forEach(btn => {
        const message = btn.closest('.message');
        if (message && message.classList.contains('read')) {
            btn.style.display = 'none';
        }
        
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const messageId = this.dataset.messageId;
            markMessageAsRead(messageId);
        });
    });
    
    markUnreadBtns.forEach(btn => {
        const message = btn.closest('.message');
        if (message && !message.classList.contains('read')) {
            btn.style.display = 'none';
        }
        
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const messageId = this.dataset.messageId;
            markMessageAsUnread(messageId);
        });
    });
}

/**
 * Initialise les actions rapides sur les messages
 */
function initQuickMessageActions() {
    const archiveMessageBtns = document.querySelectorAll('.archive-message-btn');
    const deleteMessageBtns = document.querySelectorAll('.delete-message-btn');
    
    archiveMessageBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const messageId = this.dataset.messageId;
            archiveMessage(messageId);
        });
    });
    
    deleteMessageBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const messageId = this.dataset.messageId;
            deleteMessage(messageId);
        });
    });
}

/**
 * Marque un message comme lu
 * @param {number} messageId - ID du message
 */
function markMessageAsRead(messageId) {
    fetch(`mark_message.php?id=${messageId}&action=mark_read`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Mettre à jour l'interface utilisateur
                const message = document.querySelector(`.message[data-id="${messageId}"]`);
                if (message) {
                    message.classList.add('read');
                    
                    // Mettre à jour l'indicateur de lecture
                    const readIndicator = message.querySelector('.message-read');
                    if (!readIndicator) {
                        const footer = message.querySelector('.message-footer .message-status');
                        if (footer) {
                            const readStatus = document.createElement('div');
                            readStatus.className = 'message-read';
                            readStatus.innerHTML = '<i class="fas fa-check"></i> Vu';
                            footer.appendChild(readStatus);
                        }
                    }
                }
            } else {
                console.error('Erreur:', data.error);
            }
        })
        .catch(error => console.error('Erreur:', error));
}

/**
 * Marque un message comme non lu
 * @param {number} messageId - ID du message
 */
function markMessageAsUnread(messageId) {
    fetch(`mark_message.php?id=${messageId}&action=mark_unread`)
        .then(response => response.json())
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
                }
            } else {
                console.error('Erreur:', data.error);
            }
        })
        .catch(error => console.error('Erreur:', error));
}

/**
 * Archive un message (en fait, sa conversation)
 * @param {number} messageId - ID du message
 */
function archiveMessage(messageId) {
    if (confirm('Êtes-vous sûr de vouloir archiver cette conversation ?')) {
        fetch(`message_action.php?id=${messageId}&action=archive`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Rediriger vers la liste des archives
                    window.location.href = 'index.php?folder=archives';
                } else {
                    console.error('Erreur:', data.error);
                    alert('Erreur lors de l\'archivage: ' + data.error);
                }
            })
            .catch(error => console.error('Erreur:', error));
    }
}

/**
 * Supprime un message (en fait, sa conversation)
 * @param {number} messageId - ID du message
 */
function deleteMessage(messageId) {
    if (confirm('Êtes-vous sûr de vouloir supprimer cette conversation ?')) {
        fetch(`message_action.php?id=${messageId}&action=delete`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Rediriger vers la corbeille
                    window.location.href = 'index.php?folder=corbeille';
                } else {
                    console.error('Erreur:', data.error);
                    alert('Erreur lors de la suppression: ' + data.error);
                }
            })
            .catch(error => console.error('Erreur:', error));
    }
}

/**
 * Supprime plusieurs conversations
 * @param {Array} convIds - Tableau des IDs de conversations
 */
function deleteMultipleConversations(convIds) {
    if (!convIds || convIds.length === 0) {
        alert('Aucune conversation sélectionnée');
        return;
    }
    
    if (confirm(`Êtes-vous sûr de vouloir supprimer ${convIds.length} conversation(s) ?`)) {
        // Préparer les données pour l'envoi
        const data = {
            ids: convIds
        };
        
        // Envoyer la requête
        fetch('delete_multiple.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`${data.count} conversation(s) supprimée(s) avec succès`);
                // Recharger la page
                window.location.reload();
            } else {
                console.error('Erreur:', data.error);
                alert('Erreur lors de la suppression: ' + data.error);
            }
        })
        .catch(error => console.error('Erreur:', error));
    }
}