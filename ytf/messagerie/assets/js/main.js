/**
 * /assets/js/main.js - Scripts principaux améliorés
 */

// Auto-refresh pour les nouveaux messages toutes les 60 secondes
let autoRefreshInterval;

document.addEventListener('DOMContentLoaded', function() {
    // Démarrer l'auto-refresh
    startAutoRefresh();
    
    // Gestion des menus d'actions rapides
    setupQuickActions();
    
    // Gestion des formulaires pour éviter les soumissions multiples
    setupFormSubmission();
    
    // Gestion des pièces jointes
    setupFileUploads();
    
    // Initialisation des fonctionnalités d'action en masse
    setupBulkActions();
});

/**
 * Démarrer l'auto-refresh pour les nouveaux messages
 */
function startAutoRefresh() {
    // Vérifier si le système d'actualisation avancé est déjà chargé
    if (window.hasAdvancedRefresh) {
        console.log("Système d'actualisation avancé détecté, désactivation du système simple");
        return; // Ne pas initialiser le système simple si le système avancé est présent
    }
    
    // Vérifier toutes les 60 secondes pour les nouveaux messages
    autoRefreshInterval = setInterval(function() {
        // Vérifier s'il n'y a pas de menu ouvert avant de recharger
        const activeMenus = document.querySelectorAll('.quick-actions-menu.active, .message-actions-menu.active');
        const activeModals = document.querySelectorAll('.modal[style*="display: block"]');
        const textareaActive = document.querySelector('textarea:focus');
        
        // Ne pas recharger si un menu est ouvert, un modal est affiché, ou si l'utilisateur est en train d'écrire
        if (activeMenus.length === 0 && activeModals.length === 0 && !textareaActive) {
            location.reload();
        }
    }, 60000);
}

/**
 * Arrêter l'auto-refresh
 */
function stopAutoRefresh() {
    clearInterval(autoRefreshInterval);
}

/**
 * Configuration des actions rapides sur les conversations
 */
function setupQuickActions() {
    // Gestionnaire de clic pour les menus d'actions rapides
    document.addEventListener('click', function(event) {
        if (!event.target.closest('.quick-actions')) {
            document.querySelectorAll('.quick-actions-menu').forEach(menu => {
                menu.classList.remove('active');
            });
        }
    });
    
    // S'assurer que tous les boutons de quick-actions ont le bon event listener
    document.querySelectorAll('.quick-actions-btn').forEach(btn => {
        const onclick = btn.getAttribute('onclick');
        if (onclick) {
            const idMatch = onclick.match(/\d+/);
            if (idMatch) {
                const id = idMatch[0];
                btn.onclick = function(e) {
                    toggleQuickActions(id);
                    e.stopPropagation();
                    return false;
                };
            }
        }
    });
}

/**
 * Bascule l'affichage du menu d'actions rapides
 * @param {number} id - ID de la conversation
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
 * @param {number} convId - ID de la conversation
 */
function markConversationAsRead(convId) {
    fetch(`api/mark_conversation.php?id=${convId}&action=mark_read`)
        .then(response => response.json())
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
 * @param {number} convId - ID de la conversation
 */
function markConversationAsUnread(convId) {
    fetch(`api/mark_conversation.php?id=${convId}&action=mark_unread`)
        .then(response => response.json())
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
 * Configuration des soumissions de formulaire
 */
function setupFormSubmission() {
    // Empêcher la soumission multiple des formulaires
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            // Désactiver le bouton d'envoi après soumission
            const submitButton = this.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
                
                // Ajouter un loader si le bouton n'en a pas déjà un
                if (!submitButton.innerHTML.includes('fa-spinner')) {
                    const originalContent = submitButton.innerHTML;
                    submitButton.dataset.originalContent = originalContent;
                    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Traitement en cours...';
                }
            }
        });
    });
}

/**
 * Configuration des téléchargements de fichiers
 */
function setupFileUploads() {
    // Gestion des pièces jointes
    const fileInputs = document.querySelectorAll('input[type="file"]');
    
    fileInputs.forEach(input => {
        input.addEventListener('change', function(e) {
            const fileListContainer = document.getElementById('file-list');
            if (fileListContainer) {
                fileListContainer.innerHTML = '';
                
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
                        fileListContainer.appendChild(fileInfo);
                    }
                }
            }
        });
    });
}

/**
 * Met à jour l'état des boutons d'action en fonction de la sélection
 */
function updateBulkActionButtons() {
    const selectedConvs = document.querySelectorAll('.conversation-checkbox:checked');
    const selectedCount = selectedConvs.length;
    
    // Référence aux boutons
    const btnMarkRead = document.querySelector('button[data-action="mark_read"]');
    const btnMarkUnread = document.querySelector('button[data-action="mark_unread"]');
    const allButtons = document.querySelectorAll('.bulk-action-btn');
    
    // Mettre à jour le texte de tous les boutons avec le nombre sélectionné correct
    allButtons.forEach(button => {
        const actionText = button.dataset.actionText || 'Appliquer';
        const icon = button.dataset.icon ? `<i class="fas fa-${button.dataset.icon}"></i> ` : '';
        button.innerHTML = `${icon}${actionText} (${selectedCount})`;
    });
    
    // Masquer les boutons par défaut si rien n'est sélectionné
    if (selectedCount === 0) {
        allButtons.forEach(button => {
            button.disabled = true;
            button.style.display = 'none';
        });
        return;
    }
    
    // Vérifier si tous les messages sélectionnés sont lus ou non lus
    let hasReadMessages = false;
    let hasUnreadMessages = false;
    
    selectedConvs.forEach(checkbox => {
        const conversationItem = checkbox.closest('.conversation-item');
        const isRead = conversationItem.getAttribute('data-is-read') === '1';
        
        if (isRead) {
            hasReadMessages = true;
        } else {
            hasUnreadMessages = true;
        }
    });
    
    // Mettre à jour la visibilité des boutons en fonction de la sélection
    if (btnMarkRead) {
        btnMarkRead.disabled = !hasUnreadMessages;
        btnMarkRead.style.display = hasUnreadMessages ? 'inline-flex' : 'none';
    }
    
    if (btnMarkUnread) {
        btnMarkUnread.disabled = !hasReadMessages;
        btnMarkUnread.style.display = hasReadMessages ? 'inline-flex' : 'none';
    }
    
    // Afficher les autres boutons d'action
    document.querySelectorAll('.bulk-action-btn:not([data-action="mark_read"]):not([data-action="mark_unread"])').forEach(button => {
        button.disabled = false;
        button.style.display = 'inline-flex';
    });
}

/**
 * Configuration des actions en masse
 */
function setupBulkActions() {
    const selectAllCheckbox = document.getElementById('select-all-conversations');
    const actionButtons = document.querySelectorAll('.bulk-action-btn');
    
    if (selectAllCheckbox && actionButtons.length > 0) {
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
            button.addEventListener('click', function() {
                const action = this.dataset.action;
                const selectedIds = Array.from(
                    document.querySelectorAll('.conversation-checkbox:checked')
                ).map(cb => parseInt(cb.value, 10));
                
                if (selectedIds.length === 0) return;
                
                // Message de confirmation personnalisé selon l'action
                let confirmMessage = '';
                switch(action) {
                    case 'delete':
                        confirmMessage = `Êtes-vous sûr de vouloir supprimer ${selectedIds.length} conversation(s) ?`;
                        break;
                    case 'delete_permanently':
                        confirmMessage = `Êtes-vous sûr de vouloir supprimer définitivement ${selectedIds.length} conversation(s) ? Cette action est irréversible.`;
                        break;
                    case 'archive':
                        confirmMessage = `Êtes-vous sûr de vouloir archiver ${selectedIds.length} conversation(s) ?`;
                        break;
                    case 'restore':
                        confirmMessage = `Êtes-vous sûr de vouloir restaurer ${selectedIds.length} conversation(s) ?`;
                        break;
                    case 'mark_read':
                        confirmMessage = `Marquer ${selectedIds.length} conversation(s) comme lues ?`;
                        break;
                    case 'mark_unread':
                        confirmMessage = `Marquer ${selectedIds.length} conversation(s) comme non lues ?`;
                        break;
                    default:
                        confirmMessage = `Effectuer l'action "${action}" sur ${selectedIds.length} conversation(s) ?`;
                }
                
                if (confirm(confirmMessage)) {
                    performBulkAction(action, selectedIds);
                }
            });
        });
        
        // Exécuter une première fois pour initialiser l'état des boutons
        updateBulkActionButtons();
    }
}

/**
 * Exécute une action en masse sur plusieurs conversations
 * @param {string} action - Action à effectuer
 * @param {Array} convIds - Tableau des IDs de conversations
 */
function performBulkAction(action, convIds) {
    // Préparer les données pour l'envoi
    const data = {
        action: action,
        ids: convIds
    };
    
    // Envoyer la requête
    fetch('api/bulk_actions.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Afficher un message de succès
            alert(`Action réussie sur ${data.count} conversation(s)`);
            
            // Recharger la page
            window.location.reload();
        } else {
            console.error('Erreur:', data.error);
            alert('Erreur lors de l\'action: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        alert('Une erreur est survenue lors de l\'exécution de l\'action.');
    });
}

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

/**
 * Affiche un message de confirmation avant soumission
 * @param {string} message - Message de confirmation
 * @returns {boolean} True si confirmé, sinon False
 */
function confirmAction(message) {
    return confirm(message);
}

/**
 * AJAX helper pour effectuer des requêtes
 * @param {string} url - URL de la requête
 * @param {Object} options - Options de la requête
 * @returns {Promise} Promesse avec la réponse
 */
function ajax(url, options = {}) {
    const defaultOptions = {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json'
        }
    };
    
    const requestOptions = { ...defaultOptions, ...options };
    
    return fetch(url, requestOptions)
        .then(response => {
            if (!response.ok) {
                throw new Error('Erreur réseau: ' + response.status);
            }
            return response.json();
        });
}