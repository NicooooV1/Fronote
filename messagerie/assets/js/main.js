/**
 * Scripts principaux
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialiser les actions de conversation
    initializeActions();
    
    // Gestion des formulaires
    setupFormValidation();
    
    // Initialisation des fonctionnalités d'action en masse
    setupBulkActions();
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
                alert('Le message ne peut pas être vide');
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
                    alert('Veuillez sélectionner au moins une conversation');
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
    // Demander confirmation
    let confirmMessage = '';
    switch(action) {
        case 'delete':
            confirmMessage = `Êtes-vous sûr de vouloir supprimer ${convIds.length} conversation(s) ?`;
            break;
        case 'delete_permanently':
            confirmMessage = `Êtes-vous sûr de vouloir supprimer définitivement ${convIds.length} conversation(s) ? Cette action est irréversible.`;
            break;
        case 'archive':
            confirmMessage = `Êtes-vous sûr de vouloir archiver ${convIds.length} conversation(s) ?`;
            break;
        case 'restore':
            confirmMessage = `Êtes-vous sûr de vouloir restaurer ${convIds.length} conversation(s) ?`;
            break;
        case 'mark_read':
            confirmMessage = `Marquer ${convIds.length} conversation(s) comme lues ?`;
            break;
        case 'mark_unread':
            confirmMessage = `Marquer ${convIds.length} conversation(s) comme non lues ?`;
            break;
        default:
            confirmMessage = `Effectuer l'action "${action}" sur ${convIds.length} conversation(s) ?`;
    }
    
    if (confirm(confirmMessage)) {
        // Préparer les données pour l'envoi
        const data = {
            action: action,
            ids: convIds
        };
        
        // Montrer un indicateur de chargement
        document.body.style.cursor = 'wait';
        
        // Désactiver les boutons pendant le traitement
        document.querySelectorAll('.bulk-action-btn').forEach(btn => {
            btn.disabled = true;
        });
        
        // Envoyer la requête
        fetch('api/conversation.php?action=bulk', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Erreur réseau: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Afficher un message de succès
                alert(`Action réussie sur ${data.count} conversation(s)`);
                
                // Recharger la page
                window.location.reload();
            } else {
                console.error('Erreur:', data.error);
                alert('Erreur lors de l\'action: ' + data.error);
                
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
            console.error('Erreur:', error);
            alert('Une erreur est survenue lors de l\'exécution de l\'action: ' + error.message);
            
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
    fetch(`api/conversation.php?id=${convId}&action=mark_read`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Erreur réseau: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                alert('Erreur: ' + data.error);
            }
        })
        .catch(error => console.error('Erreur:', error));
}

/**
 * Marque une conversation comme non lue
 * @param {number} convId
 */
function markConversationAsUnread(convId) {
    fetch(`api/conversation.php?id=${convId}&action=mark_unread`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Erreur réseau: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                alert('Erreur: ' + data.error);
            }
        })
        .catch(error => console.error('Erreur:', error));
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
 * Ferme un modal
 * @param {HTMLElement} modal
 */
function closeModal(modal) {
    if (modal) {
        modal.style.display = 'none';
    }
}

/**
 * Formater la taille des fichiers
 * @param {number} bytes
 * @returns {string}
 */
function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    else if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
    else return Math.round(bytes / 1048576 * 10) / 10 + ' MB';
}