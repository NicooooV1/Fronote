/**
 * /assets/js/forms.js - Gestion des formulaires
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialisation des validations de formulaire
    initFormValidation();
    
    // Initialisation de la sélection des destinataires
    initRecipientSelection();
    
    // Gestion des pièces jointes
    initFileUpload();
});

/**
 * Initialise les validations de formulaire
 */
function initFormValidation() {
    const titleInput = document.getElementById('titre');
    const contentTextarea = document.getElementById('contenu');
    
    // Validation du titre
    if (titleInput) {
        const titleCounter = document.getElementById('title-counter');
        
        titleInput.addEventListener('input', function() {
            const maxLength = 100;
            const currentLength = this.value.length;
            
            if (titleCounter) {
                titleCounter.textContent = `${currentLength}/${maxLength} caractères`;
                
                if (currentLength > maxLength) {
                    titleCounter.style.color = '#dc3545';
                    document.querySelector('button[type="submit"]').disabled = true;
                } else {
                    titleCounter.style.color = '#6c757d';
                    // Ne pas réactiver le bouton si le contenu est trop long
                    if (contentTextarea && contentTextarea.value.length <= 10000) {
                        document.querySelector('button[type="submit"]').disabled = false;
                    }
                }
            }
        });
        
        // Déclencher l'événement pour initialiser le compteur
        titleInput.dispatchEvent(new Event('input'));
    }
    
    // Validation du contenu
    if (contentTextarea) {
        const charCounter = document.getElementById('char-counter');
        
        contentTextarea.addEventListener('input', function() {
            const maxLength = 10000;
            const currentLength = this.value.length;
            
            if (charCounter) {
                charCounter.textContent = `${currentLength}/${maxLength} caractères`;
                
                if (currentLength > maxLength) {
                    charCounter.style.color = '#dc3545';
                    document.querySelector('button[type="submit"]').disabled = true;
                } else {
                    charCounter.style.color = '#6c757d';
                    // Ne pas réactiver le bouton si le titre est trop long
                    if (titleInput && titleInput.value.length <= 100) {
                        document.querySelector('button[type="submit"]').disabled = false;
                    }
                }
            }
        });
        
        // Déclencher l'événement pour initialiser le compteur
        contentTextarea.dispatchEvent(new Event('input'));
    }
    
    // Validation du formulaire avant soumission
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('[required]');
            let valid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    e.preventDefault();
                    valid = false;
                    field.classList.add('is-invalid');
                    
                    // Créer un message d'erreur si nécessaire
                    let errorMsg = field.nextElementSibling;
                    if (!errorMsg || !errorMsg.classList.contains('error-message')) {
                        errorMsg = document.createElement('div');
                        errorMsg.className = 'error-message';
                        errorMsg.style.color = '#dc3545';
                        errorMsg.style.fontSize = '12px';
                        errorMsg.style.marginTop = '5px';
                        errorMsg.textContent = 'Ce champ est requis';
                        field.parentNode.insertBefore(errorMsg, field.nextSibling);
                    }
                } else {
                    field.classList.remove('is-invalid');
                    const errorMsg = field.nextElementSibling;
                    if (errorMsg && errorMsg.classList.contains('error-message')) {
                        errorMsg.remove();
                    }
                }
            });
            
            return valid;
        });
    });
}

/**
 * Initialise la sélection des destinataires
 */
function initRecipientSelection() {
    // Mettre à jour les tags de destinataires
    updateSelectedRecipients();
    
    // Gérer la recherche de destinataires
    const searchInput = document.getElementById('search-recipients');
    if (searchInput) {
        searchInput.addEventListener('keyup', filterRecipients);
        searchInput.focus();
    }
    
    // Gérer les changements de case à cocher
    const checkboxes = document.querySelectorAll('input[name="destinataires[]"]');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateSelectedRecipients();
        });
    });
    
    // Gérer le changement de cible pour les formulaires d'annonce
    const cibleSelect = document.getElementById('cible');
    if (cibleSelect) {
        cibleSelect.addEventListener('change', toggleTargetOptions);
        // Initialiser l'état
        toggleTargetOptions();
    }
}

/**
 * Met à jour l'affichage des destinataires sélectionnés
 */
function updateSelectedRecipients() {
    const container = document.getElementById('selected-recipients-container');
    if (!container) return;
    
    container.innerHTML = '';
    
    const checkboxes = document.querySelectorAll('input[name="destinataires[]"]:checked');
    
    checkboxes.forEach(checkbox => {
        const label = checkbox.nextElementSibling;
        if (!label) return;
        
        const text = label.textContent;
        const value = checkbox.value;
        
        const tag = document.createElement('div');
        tag.className = 'recipient-tag';
        tag.innerHTML = `
            <span>${text}</span>
            <span class="remove-tag" onclick="removeRecipient('${value}')">×</span>
        `;
        
        container.appendChild(tag);
    });
    
    // Mettre à jour les boutons après chaque changement
    updateCategoryButtons();
}

/**
 * Filtre les destinataires selon le terme de recherche
 */
function filterRecipients() {
    const searchInput = document.getElementById('search-recipients');
    if (!searchInput) return;
    
    const searchTerm = searchInput.value.toLowerCase();
    const recipientItems = document.querySelectorAll('.recipient-item');
    let visibleCount = 0;
    
    recipientItems.forEach(item => {
        const label = item.querySelector('label');
        if (!label) return;
        
        const text = label.textContent.toLowerCase();
        if (text.includes(searchTerm)) {
            item.style.display = 'flex';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
    
    // Afficher/masquer les catégories en fonction des éléments visibles
    const categories = document.querySelectorAll('.recipient-category');
    categories.forEach(category => {
        const visibleItems = category.querySelectorAll('.recipient-item[style="display: flex;"]').length;
        category.style.display = visibleItems > 0 ? 'block' : 'none';
    });
    
    // Afficher un message si aucun résultat
    const noResultsMessage = document.getElementById('no-results-message');
    if (noResultsMessage) {
        noResultsMessage.style.display = visibleCount === 0 ? 'block' : 'none';
    }
}

/**
 * Met à jour l'affichage des boutons de catégorie
 */
function updateCategoryButtons() {
    document.querySelectorAll('.recipient-category').forEach(category => {
        const categoryId = category.id;
        const checkboxes = category.querySelectorAll('input[type="checkbox"]');
        const selectAllBtn = category.querySelector('.category-actions a:first-child');
        const deselectAllBtn = category.querySelector('.category-actions a:last-child');
        
        const totalCheckboxes = checkboxes.length;
        const checkedCheckboxes = category.querySelectorAll('input[type="checkbox"]:checked').length;
        
        if (selectAllBtn && deselectAllBtn) {
            // Cas 1: Personne n'est sélectionné
            if (checkedCheckboxes === 0) {
                selectAllBtn.style.display = 'inline';
                deselectAllBtn.style.display = 'none';
            }
            // Cas 2: Tout le monde est sélectionné
            else if (checkedCheckboxes === totalCheckboxes) {
                selectAllBtn.style.display = 'none';
                deselectAllBtn.style.display = 'inline';
            }
            // Cas 3: Sélection partielle
            else {
                selectAllBtn.style.display = 'inline';
                deselectAllBtn.style.display = 'inline';
            }
        }
    });
}

/**
 * Sélectionne tous les destinataires d'une catégorie
 * @param {string} categoryId ID de la catégorie
 */
function selectAllInCategory(categoryId) {
    const category = document.getElementById(categoryId);
    if (!category) return;
    
    const checkboxes = category.querySelectorAll('input[type="checkbox"]:not(:checked)');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
    
    updateSelectedRecipients();
}

/**
 * Désélectionne tous les destinataires d'une catégorie
 * @param {string} categoryId ID de la catégorie
 */
function deselectAllInCategory(categoryId) {
    const category = document.getElementById(categoryId);
    if (!category) return;
    
    const checkboxes = category.querySelectorAll('input[type="checkbox"]:checked');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    
    updateSelectedRecipients();
}

/**
 * Supprime un destinataire de la sélection
 * @param {string} value Valeur du destinataire à supprimer
 */
function removeRecipient(value) {
    const checkbox = document.querySelector(`input[value="${value}"]`);
    if (checkbox) {
        checkbox.checked = false;
        updateSelectedRecipients();
    }
}

/**
 * Bascule l'affichage des options de cible
 */
function toggleTargetOptions() {
    const cible = document.getElementById('cible');
    if (!cible) return;