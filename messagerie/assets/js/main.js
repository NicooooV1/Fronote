/**
 * /assets/js/main.js - Scripts principaux
 */

// Auto-refresh pour les nouveaux messages toutes les 3 secondes
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
    
    // Initialisation des fonctionnalités de suppression en masse
    setupBulkDelete();
});

/**
 * Démarrer l'auto-refresh pour les nouveaux messages
 */
function startAutoRefresh() {
    // Vérifier toutes les 3 secondes pour les nouveaux messages (plus réactif)
    autoRefreshInterval = setInterval(function() {
        // Vérifier s'il n'y a pas de menu ouvert avant de recharger
        const activeMenus = document.querySelectorAll('.quick-actions-menu.active, .message-actions-menu.active');
        const activeModals = document.querySelectorAll('.modal[style*="display: block"]');
        const textareaActive = document.querySelector('textarea:focus');
        
        // Ne pas recharger si un menu est ouvert, un modal est affiché, ou si l'utilisateur est en train d'écrire
        if (activeMenus.length === 0 && activeModals.length === 0 && !textareaActive) {
            location.reload();
        }
    }, 3000); // 3 secondes au lieu de 30 pour une meilleure réactivité
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
}

/**
 * Bascule l'affichage du menu d'actions rapides
 * @param {number} id - ID de la conversation
 */
function toggleQuickActions(id) {
    const menu = document.getElementById('quick-actions-' + id);
    
    // Fermer tous les autres menus
    document.querySelectorAll('.quick-actions-menu').forEach(item => {
        if (item !== menu) {
            item.classList.remove('active');
        }
    });
    
    // Basculer l'état du menu actuel
    menu.classList.toggle('active');
    
    // Empêcher la propagation du clic pour éviter la navigation
    event.stopPropagation();
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
 * Fonctionnalité de suppression en masse pour la corbeille
 */
function setupBulkDelete() {
    const selectAllCheckbox = document.getElementById('select-all-conversations');
    const deleteButton = document.getElementById('delete-selected');
    
    if (selectAllCheckbox && deleteButton) {
        // Sélectionner/désélectionner tous
        selectAllCheckbox.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.conversation-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            
            updateDeleteButton();
        });
        
        // Mettre à jour le bouton de suppression
        document.querySelectorAll('.conversation-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                // Toggle la classe 'selected' sur l'élément parent
                this.closest('.conversation-item').classList.toggle('selected', this.checked);
                updateDeleteButton();
            });
        });
        
        // Action de suppression
        deleteButton.addEventListener('click', function() {
            const selectedIds = Array.from(
                document.querySelectorAll('.conversation-checkbox:checked')
            ).map(cb => parseInt(cb.value));
            
            if (selectedIds.length === 0) return;
            
            if (confirm(`Êtes-vous sûr de vouloir supprimer définitivement ${selectedIds.length} conversation(s) ?`)) {
                deleteMultipleConversations(selectedIds);
            }
        });
    }
    
    function updateDeleteButton() {
        const selectedCount = document.querySelectorAll('.conversation-checkbox:checked').length;
        deleteButton.disabled = selectedCount === 0;
        // Mettre à jour le texte du bouton avec le nombre d'éléments sélectionnés
        deleteButton.innerHTML = `<i class="fas fa-trash-alt"></i> Supprimer les éléments sélectionnés (${selectedCount})`;
    }
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

/**
 * Supprimer plusieurs conversations
 * @param {Array} convIds - Tableau des IDs de conversations
 */
function deleteMultipleConversations(convIds) {
    if (!convIds || convIds.length === 0) {
        alert('Aucune conversation sélectionnée');
        return;
    }
    
    // Préparer les données pour l'envoi
    const data = {
        ids: convIds
    };
    
    // Envoyer la requête
    fetch('api/delete_multiple.php', {
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