/**
 * Scripts principaux
 */

// Fonctions d'utilité centralisées
const Utils = {
    /**
     * Formatte une date de façon conviviale
     * @param {string|Date} date
     * @returns {string}
     */
    formatDate: function(date) {
        if (!date) return 'Jamais';
        
        const timestamp = date instanceof Date ? date.getTime() : new Date(date).getTime();
        const now = Date.now();
        const diff = now - timestamp;
        
        if (diff < 60000) { // moins d'une minute
            return 'À l\'instant';
        } else if (diff < 3600000) { // moins d'une heure
            const minutes = Math.floor(diff / 60000);
            return `Il y a ${minutes} minute${minutes > 1 ? 's' : ''}`;
        } else if (diff < 86400000) { // moins d'un jour
            const hours = Math.floor(diff / 3600000);
            return `Il y a ${hours} heure${hours > 1 ? 's' : ''}`;
        } else if (diff < 172800000) { // moins de deux jours
            const d = new Date(timestamp);
            return `Hier à ${d.getHours().toString().padStart(2, '0')}:${d.getMinutes().toString().padStart(2, '0')}`;
        } else {
            const d = new Date(timestamp);
            return `${d.getDate().toString().padStart(2, '0')}/${(d.getMonth()+1).toString().padStart(2, '0')}/${d.getFullYear()} à ${d.getHours().toString().padStart(2, '0')}:${d.getMinutes().toString().padStart(2, '0')}`;
        }
    },
    
    /**
     * Échappe les caractères HTML
     * @param {string} text - Texte à échapper
     * @returns {string} Texte échappé
     */
    escapeHTML: function(text) {
        if (!text) return '';
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    },
    
    /**
     * Échappe les apostrophes et les guillemets pour les chaînes JavaScript
     * @param {string} text - Texte à échapper
     * @returns {string} Texte échappé
     */
    addSlashes: function(text) {
        if (!text) return '';
        return text
            .replace(/\\/g, '\\\\')
            .replace(/\'/g, '\\\'')
            .replace(/\"/g, '\\"')
            .replace(/\0/g, '\\0');
    },
    
    /**
     * Convertit les retours à la ligne en <br>
     * @param {string} text - Texte à convertir
     * @returns {string} Texte avec des <br>
     */
    nl2br: function(text) {
        if (!text) return '';
        return text.replace(/\n/g, '<br>');
    },
    
    /**
     * Transforme les URLs en liens cliquables
     * @param {string} text
     * @return {string}
     */
    linkify: function(text) {
        if (!text) return '';
        const pattern = '~(https?://[^\\s<]+)~i';
        return text.replace(
            new RegExp(pattern, 'gi'),
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>'
        );
    },
    
    /**
     * Formater la taille des fichiers
     * @param {number} bytes
     * @returns {string}
     */
    formatFileSize: function(bytes) {
        if (bytes < 1024) return bytes + ' B';
        else if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
        else return Math.round(bytes / 1048576 * 10) / 10 + ' MB';
    },
    
    /**
     * Récupère le jeton CSRF du meta tag
     * @returns {string} Jeton CSRF
     */
    getCSRFToken: function() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }
};

// Système de notification centralisé
const Notifications = {
    /**
     * Affiche une notification avec un style adapté au type
     * @param {string} message - Message à afficher
     * @param {number} duration - Durée d'affichage en ms (par défaut 5000ms, 0 pour pas de fermeture auto)
     * @param {string} type - Type de notification: 'error', 'success', 'info', 'warning'
     * @returns {HTMLElement} Élément de notification créé
     */
    show: function(message, duration = 5000, type = 'error') {
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
        
        // Styles basés sur le type
        let backgroundColor, textColor, icon;
        if (type === 'error') {
            backgroundColor = '#f8d7da';
            textColor = '#721c24';
            icon = 'exclamation-circle';
        } else if (type === 'success') {
            backgroundColor = '#d4edda';
            textColor = '#155724';
            icon = 'check-circle';
        } else if (type === 'info') {
            backgroundColor = '#d1ecf1';
            textColor = '#0c5460';
            icon = 'info-circle';
        } else if (type === 'warning') {
            backgroundColor = '#fff3cd';
            textColor = '#856404';
            icon = 'exclamation-triangle';
        }
        
        // Styles de la notification
        notification.style.backgroundColor = backgroundColor;
        notification.style.color = textColor;
        notification.style.padding = '15px 20px';
        notification.style.margin = '10px';
        notification.style.borderRadius = '5px';
        notification.style.boxShadow = '0 4px 10px rgba(0, 0, 0, 0.2)';
        notification.style.display = 'flex';
        notification.style.justifyContent = 'space-between';
        notification.style.alignItems = 'center';
        notification.style.minWidth = '300px';
        notification.style.animation = 'fadeInDown 0.3s ease-out';
        
        // Créer le contenu de la notification
        const content = document.createElement('div');
        content.innerHTML = `<i class="fas fa-${icon}"></i> ${message}`;
        
        // Créer le bouton de fermeture
        const closeBtn = document.createElement('button');
        closeBtn.innerHTML = '&times;';
        closeBtn.style.background = 'none';
        closeBtn.style.border = 'none';
        closeBtn.style.color = textColor;
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
        
        // Fermer automatiquement après la durée spécifiée (sauf si duration = 0)
        if (duration > 0) {
            setTimeout(function() {
                if (notification.parentNode === notifContainer) {
                    notifContainer.removeChild(notification);
                    
                    // Supprimer le conteneur s'il n'y a plus de notifications
                    if (notifContainer.children.length === 0) {
                        document.body.removeChild(notifContainer);
                    }
                }
            }, duration);
        }
        
        return notification;
    },
    
    /**
     * Affiche une notification d'erreur
     * @param {string} message - Message d'erreur
     * @param {number} duration - Durée d'affichage
     * @returns {HTMLElement} Élément de notification
     */
    error: function(message, duration = 5000) {
        return this.show(message, duration, 'error');
    },
    
    /**
     * Affiche une notification de succès
     * @param {string} message - Message de succès
     * @param {number} duration - Durée d'affichage
     * @returns {HTMLElement} Élément de notification
     */
    success: function(message, duration = 5000) {
        return this.show(message, duration, 'success');
    },
    
    /**
     * Affiche une notification d'information
     * @param {string} message - Message d'information
     * @param {number} duration - Durée d'affichage
     * @returns {HTMLElement} Élément de notification
     */
    info: function(message, duration = 5000) {
        return this.show(message, duration, 'info');
    },
    
    /**
     * Affiche une notification d'avertissement
     * @param {string} message - Message d'avertissement
     * @param {number} duration - Durée d'affichage
     * @returns {HTMLElement} Élément de notification
     */
    warning: function(message, duration = 5000) {
        return this.show(message, duration, 'warning');
    }
};

// Pour la compatibilité avec le code existant
function afficherNotificationErreur(message, duration = 5000, type = 'error') {
    return Notifications.show(message, duration, type);
}

function afficherNotificationSucces(message, duration = 5000) {
    return Notifications.show(message, duration, 'success');
}

document.addEventListener('DOMContentLoaded', function() {
    // Initialiser les actions de conversation
    initializeActions();
    
    // Gestion des formulaires
    setupFormValidation();
    
    // Initialisation des fonctionnalités d'action en masse
    setupBulkActions();
    
    // Initialiser les gestionnaires d'erreurs
    initErrorHandlers();
    
    // Ajouter le jeton CSRF aux en-têtes fetch par défaut
    setupFetchWithCSRF();
    
    // Publier un événement pour signaler que l'initialisation est terminée
    if (typeof EventManager !== 'undefined') {
        EventManager.publish('app:initialized', {});
    }
});

/**
 * Initialise les actions principales
 */
function initializeActions() {
    // Gestion du scroll dans les conversations
    const messagesContainer = document.querySelector('.messages-container');
    if (messagesContainer) {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    // Actions sur les conversations et participants
    setupConversationActions();
}

/**
 * Configuration des actions de conversation
 */
function setupConversationActions() {
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
            closeModal(this.closest('.modal'));
        });
    });
    
    // Fermeture du modal en cliquant en dehors
    window.addEventListener('click', function(event) {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            if (event.target === modal) {
                closeModal(modal);
            }
        });
    });
    
    // Gestionnaire de clic pour les menus d'actions rapides
    document.addEventListener('click', function(event) {
        if (!event.target.closest('.quick-actions')) {
            document.querySelectorAll('.quick-actions-menu').forEach(menu => {
                menu.classList.remove('active');
            });
        }
    });
}

/**
 * Configuration des validations de formulaire
 */
function setupFormValidation() {
    // Validation des formulaires de message
    const messageForm = document.getElementById('messageForm');
    if (messageForm) {
        const textArea = messageForm.querySelector('textarea[name="contenu"]');
        const submitBtn = messageForm.querySelector('button[type="submit"]');
        
        if (textArea) {
            // Compteur de caractères
            const counter = document.createElement('div');
            counter.id = 'char-counter';
            counter.className = 'text-muted small';
            counter.style.color = '#6c757d';
            textArea.parentNode.insertBefore(counter, textArea.nextSibling);
            
            const maxLength = 10000;
            
            // Mise à jour en temps réel
            textArea.addEventListener('input', function() {
                const currentLength = this.value.length;
                counter.textContent = `${currentLength}/${maxLength} caractères`;
                
                if (currentLength > maxLength) {
                    counter.style.color = '#dc3545';
                    submitBtn.disabled = true;
                } else {
                    counter.style.color = '#6c757d';
                    submitBtn.disabled = false;
                }
            });
            
            // Déclencher l'événement au chargement
            textArea.dispatchEvent(new Event('input'));
        }
        
        // Empêcher la soumission si vide
        messageForm.addEventListener('submit', function(e) {
            const textareaContent = textArea.value.trim();
            if (textareaContent === '') {
                e.preventDefault();
                Notifications.error('Le message ne peut pas être vide');
            }
        });
    }
    
    // Gestion des pièces jointes
    const fileInput = document.getElementById('attachments');
    if (fileInput) {
        fileInput.addEventListener('change', updateFileList);
    }
}

/**
 * Mise à jour de la liste des fichiers sélectionnés
 */
function updateFileList() {
    const fileList = document.getElementById('file-list');
    if (!fileList) return;
    
    fileList.innerHTML = '';
    
    if (this.files.length > 0) {
        for (let i = 0; i < this.files.length; i++) {
            const file = this.files[i];
            
            // Vérification du type de fichier
            const fileExtension = file.name.split('.').pop().toLowerCase();
            const allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png', 'gif'];
            
            if (!allowedExtensions.includes(fileExtension)) {
                Notifications.error(`Le type de fichier .${fileExtension} n'est pas autorisé`);
                this.value = ''; // Réinitialiser l'input
                return;
            }
            
            // Vérification de la taille
            const maxSize = 10 * 1024 * 1024; // 10 Mo
            if (file.size > maxSize) {
                Notifications.error(`Le fichier ${file.name} est trop volumineux (max: 10 Mo)`);
                this.value = ''; // Réinitialiser l'input
                return;
            }
            
            const fileSize = Utils.formatFileSize(file.size);
            
            const fileInfo = document.createElement('div');
            fileInfo.className = 'file-info';
            fileInfo.innerHTML = `
                <i class="fas fa-file"></i>
                <span>${file.name} (${fileSize})</span>
            `;
            fileList.appendChild(fileInfo);
        }
    }
}

/**
 * Configuration des actions en masse
 */
function setupBulkActions() {
    const selectAllCheckbox = document.getElementById('select-all-conversations');
    const actionButtons = document.querySelectorAll('.bulk-action-btn');
    
    if (selectAllCheckbox) {
        // Sélectionner/désélectionner tous
        selectAllCheckbox.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.conversation-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
                
                // Mettre à jour la classe 'selected' sur l'élément parent
                const conversationItem = checkbox.closest('.conversation-item');
                if (conversationItem) {
                    conversationItem.classList.toggle('selected', checkbox.checked);
                }
            });
            
            updateBulkActionButtons();
        });
        
        // Mettre à jour les boutons d'action lorsqu'une case est cochée/décochée
        document.querySelectorAll('.conversation-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                // Mettre à jour la classe 'selected' sur l'élément parent
                const conversationItem = this.closest('.conversation-item');
                if (conversationItem) {
                    conversationItem.classList.toggle('selected', this.checked);
                }
                
                updateBulkActionButtons();
            });
        });
        
        // Configurer les clics sur les boutons d'action
        actionButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                const action = this.dataset.action;
                if (!action) return;
                
                const selectedIds = Array.from(
                    document.querySelectorAll('.conversation-checkbox:checked')
                ).map(cb => parseInt(cb.value, 10));
                
                if (selectedIds.length === 0) {
                    Notifications.error('Veuillez sélectionner au moins une conversation');
                    return;
                }
                
                performBulkAction(action, selectedIds);
            });
        });
        
        // Exécuter une première fois pour initialiser l'état des boutons
        updateBulkActionButtons();
    }
}

/**
 * Met à jour l'état des boutons d'action en fonction de la sélection
 */
function updateBulkActionButtons() {
    const selectedConvs = document.querySelectorAll('.conversation-checkbox:checked');
    const selectedCount = selectedConvs.length;
    
    // Mettre à jour le texte de tous les boutons avec le nombre sélectionné correct
    const allButtons = document.querySelectorAll('.bulk-action-btn');
    allButtons.forEach(button => {
        const actionText = button.dataset.actionText || 'Appliquer';
        const icon = button.dataset.icon ? `<i class="fas fa-${button.dataset.icon}"></i> ` : '';
        button.innerHTML = `${icon}${actionText} (${selectedCount})`;
        
        // Activer/désactiver les boutons en fonction de la sélection
        button.disabled = selectedCount === 0;
        
        // Afficher/masquer les boutons si rien n'est sélectionné
        if (selectedCount === 0) {
            button.style.display = 'none';
        } else {
            button.style.display = 'inline-flex';
        }
    });
    
    if (selectedCount === 0) return;
    
    // Vérifier si tous les messages sélectionnés sont lus ou non lus
    const btnMarkRead = document.querySelector('button[data-action="mark_read"]');
    const btnMarkUnread = document.querySelector('button[data-action="mark_unread"]');
    
    // Si on est dans un dossier autre que la corbeille, montrer les boutons Lu/Non lu
    const isTrashFolder = window.location.href.includes('folder=corbeille');
    
    if (btnMarkRead && btnMarkUnread && !isTrashFolder) {
        let hasReadMessages = false;
        let hasUnreadMessages = false;
        
        selectedConvs.forEach(checkbox => {
            const conversationItem = checkbox.closest('.conversation-item');
            if (conversationItem) {
                const isRead = !conversationItem.classList.contains('unread');
                
                if (isRead) {
                    hasReadMessages = true;
                } else {
                    hasUnreadMessages = true;
                }
            }
        });
        
        // Ajuster la visibilité des boutons selon la sélection
        if (btnMarkRead) {
            btnMarkRead.disabled = !hasUnreadMessages;
            btnMarkRead.style.display = hasUnreadMessages ? 'inline-flex' : 'none';
        }
        
        if (btnMarkUnread) {
            btnMarkUnread.disabled = !hasReadMessages;
            btnMarkUnread.style.display = hasReadMessages ? 'inline-flex' : 'none';
        }
    }
}

/**
 * Exécute une action en masse sur plusieurs conversations
 * @param {string} action
 * @param {Array} convIds
 */
function performBulkAction(action, convIds) {
    // Messages de confirmation adaptés à chaque action
    const confirmMessages = {
        'delete': `Êtes-vous sûr de vouloir supprimer ${convIds.length} conversation(s) ?`,
        'delete_permanently': `Êtes-vous sûr de vouloir supprimer définitivement ${convIds.length} conversation(s) ? Cette action est irréversible.`,
        'archive': `Êtes-vous sûr de vouloir archiver ${convIds.length} conversation(s) ?`,
        'restore': `Êtes-vous sûr de vouloir restaurer ${convIds.length} conversation(s) ?`,
        'unarchive': `Êtes-vous sûr de vouloir désarchiver ${convIds.length} conversation(s) ?`,
        'mark_read': `Marquer ${convIds.length} conversation(s) comme lues ?`,
        'mark_unread': `Marquer ${convIds.length} conversation(s) comme non lues ?`
    };
    
    const confirmMessage = confirmMessages[action] || `Effectuer l'action "${action}" sur ${convIds.length} conversation(s) ?`;
    
    if (confirm(confirmMessage)) {
        // Préparer les données pour l'envoi
        const data = {
            action: action,
            ids: convIds,
            csrf_token: Utils.getCSRFToken()
        };
        
        // Montrer un indicateur de chargement
        document.body.style.cursor = 'wait';
        
        // Désactiver les boutons pendant le traitement
        document.querySelectorAll('.bulk-action-btn').forEach(btn => {
            btn.disabled = true;
        });
        
        // Afficher un indicateur de chargement
        const loadingIndicator = Notifications.info('Traitement en cours...', 0);
        
        // Envoyer la requête via AjaxClient
        AjaxClient.post('api/conversation.php?action=bulk', data)
            .then(data => {
                // Supprimer l'indicateur de chargement
                if (loadingIndicator && loadingIndicator.parentNode) {
                    loadingIndicator.parentNode.removeChild(loadingIndicator);
                }
                
                if (data.success) {
                    // Afficher un message de succès
                    Notifications.success(`Action réussie sur ${data.count} conversation(s)`, 3000);
                    
                    // Recharger la page
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    console.error('Erreur:', data.error);
                    Notifications.error('Erreur lors de l\'action: ' + data.error, 5000);
                    
                    // Restaurer le curseur et réactiver les boutons
                    document.body.style.cursor = 'default';
                    document.querySelectorAll('.bulk-action-btn').forEach(btn => {
                        btn.disabled = false;
                    });
                    
                    // Mettre à jour l'état des boutons
                    updateBulkActionButtons();
                }
            })
            .catch(error => {
                // Supprimer l'indicateur de chargement
                if (loadingIndicator && loadingIndicator.parentNode) {
                    loadingIndicator.parentNode.removeChild(loadingIndicator);
                }
                
                console.error('Erreur:', error);
                Notifications.error('Une erreur est survenue lors de l\'exécution de l\'action: ' + error.message, 5000);
                
                // Restaurer le curseur et réactiver les boutons
                document.body.style.cursor = 'default';
                document.querySelectorAll('.bulk-action-btn').forEach(btn => {
                    btn.disabled = false;
                });
                
                // Mettre à jour l'état des boutons
                updateBulkActionButtons();
            });
    }
}

/**
 * Bascule l'affichage du menu d'actions rapides
 * @param {number} id
 */
function toggleQuickActions(id) {
    const menu = document.getElementById('quick-actions-' + id);
    if (!menu) return;
    
    // Fermer tous les autres menus
    document.querySelectorAll('.quick-actions-menu').forEach(item => {
        if (item !== menu) {
            item.classList.remove('active');
        }
    });
    
    // Basculer l'état du menu actuel
    menu.classList.toggle('active');
    
    // Mettre la conversation parente en avant-plan pendant que le menu est ouvert
    const conversationItem = menu.closest('.conversation-item');
    if (conversationItem) {
        if (menu.classList.contains('active')) {
            conversationItem.classList.add('active');
        } else {
            conversationItem.classList.remove('active');
        }
    }
    
    // Empêcher la propagation du clic pour éviter la navigation
    event.stopPropagation();
    return false;
}

/**
 * Marque une conversation comme lue
 * @param {number} convId
 */
function markConversationAsRead(convId) {
    AjaxClient.get(`api/conversation.php?id=${convId}&action=mark_read`)
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                Notifications.error('Erreur: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            Notifications.error('Erreur: ' + error.message);
        });
}

/**
 * Marque une conversation comme non lue
 * @param {number} convId
 */
function markConversationAsUnread(convId) {
    AjaxClient.get(`api/conversation.php?id=${convId}&action=mark_unread`)
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                Notifications.error('Erreur: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            Notifications.error('Erreur: ' + error.message);
        });
}

/**
 * Affiche le modal d'ajout de participants
 */
function showAddParticipantModal() {
    const modal = document.getElementById('addParticipantModal');
    if (modal) {
        modal.style.display = 'flex';
        
        // Focus sur le select de type
        const typeSelect = document.getElementById('participant_type');
        if (typeSelect) {
            typeSelect.focus();
        }
    }
}

/**
 * Ferme un modal
 * @param {HTMLElement} modal
 */
function closeModal(modal) {
    if (modal) {
        modal.style.display = 'none';
    }
}

/**
 * Ajouter des gestionnaires d'erreurs globaux
 */
function initErrorHandlers() {
    // Intercepter les erreurs non capturées
    window.addEventListener('error', function(event) {
        Notifications.error('Erreur JavaScript: ' + event.message);
    });
    
    // Intercepter les rejets de promesses non capturés
    window.addEventListener('unhandledrejection', function(event) {
        Notifications.error('Erreur asynchrone: ' + event.reason);
    });
    
    // Intercepter les erreurs de chargement d'image
    document.addEventListener('error', function(event) {
        if (event.target.tagName === 'IMG') {
            event.target.src = 'assets/images/error.png'; // Image par défaut en cas d'erreur
        }
    }, true);
    
    // Écouter les erreurs AJAX
    if (typeof EventManager !== 'undefined') {
        EventManager.subscribe('ajax:error', function(data) {
            Notifications.error('Erreur de communication avec le serveur: ' + data.message);
        });
    }
}

/**
 * Configure Fetch pour inclure automatiquement le jeton CSRF
 */
function setupFetchWithCSRF() {
    // Sauvegarder la méthode fetch originale
    const originalFetch = window.fetch;
    
    // Remplacer par une version qui ajoute le jeton CSRF
    window.fetch = function(url, options = {}) {
        // Si c'est une requête POST
        if (options.method && options.method.toUpperCase() === 'POST') {
            // S'assurer que headers existe
            if (!options.headers) {
                options.headers = {};
            }
            
            // Si c'est un Headers object, convertir en objet simple
            if (options.headers instanceof Headers) {
                const headersObj = {};
                for (const [key, value] of options.headers.entries()) {
                    headersObj[key] = value;
                }
                options.headers = headersObj;
            }
            
            // Ajouter l'en-tête X-CSRF-TOKEN si pas déjà présent
            if (!options.headers['X-CSRF-TOKEN']) {
                options.headers['X-CSRF-TOKEN'] = Utils.getCSRFToken();
            }
        }
        
        // Appeler la méthode fetch originale avec les options modifiées
        return originalFetch(url, options);
    };
}