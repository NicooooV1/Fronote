/**
 * /assets/js/participants.js - Gestion des participants
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialiser le sélecteur de destinataires
    initRecipientSelector();
    
    // Initialiser la recherche de destinataires
    initRecipientSearch();
    
    // Rendre les sections pliables
    initCollapsibleSections();
});

/**
 * Initialise le sélecteur de destinataires
 */
function initRecipientSelector() {
    // Mettre à jour les tags de destinataires au chargement
    updateSelectedRecipients();
    
    // Mettre à jour les tags à chaque changement de sélection
    const checkboxes = document.querySelectorAll('input[name="destinataires[]"]');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateSelectedRecipients();
        });
    });
}

/**
 * Initialise la recherche de destinataires
 */
function initRecipientSearch() {
    const searchInput = document.getElementById('search-recipients');
    if (searchInput) {
        // Recherche en temps réel avec un debounce de 300ms
        let timeoutId;
        searchInput.addEventListener('input', function() {
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => {
                filterRecipients();
            }, 300);
        });
        
        // Focus sur le champ de recherche au chargement
        searchInput.focus();
    }
}

/**
 * Initialise les sections pliables pour les catégories
 */
function initCollapsibleSections() {
    const categories = document.querySelectorAll('.recipient-category');
    
    categories.forEach(category => {
        const title = category.querySelector('.category-title');
        const itemsContainer = category.querySelector('.recipient-items');
        
        if (title && itemsContainer) {
            // Ajouter un indicateur si non présent
            if (!title.querySelector('i')) {
                title.innerHTML += ' <i class="fas fa-chevron-down"></i>';
            }
            title.style.cursor = 'pointer';
            
            title.addEventListener('click', function(e) {
                // Ne pas déclencher si on clique sur les liens de sélection multiple
                if (e.target.tagName === 'A' || e.target.closest('a') !== null) {
                    return;
                }
                
                const isExpanded = itemsContainer.style.display !== 'none';
                
                // Inverser l'état
                if (isExpanded) {
                    itemsContainer.style.display = 'none';
                    title.querySelector('i').className = 'fas fa-chevron-right';
                    title.classList.add('collapsed');
                } else {
                    itemsContainer.style.display = 'block';
                    title.querySelector('i').className = 'fas fa-chevron-down';
                    title.classList.remove('collapsed');
                }
            });
        }
    });
}

/**
 * Filtre les destinataires selon le terme de recherche
 */
function filterRecipients() {
    const searchInput = document.getElementById('search-recipients');
    if (!searchInput) return;
    
    const searchTerm = searchInput.value.toLowerCase();
    const recipientItems = document.querySelectorAll('.recipient-item');
    let hasVisibleItems = false;
    
    // Pour chaque élément, vérifier s'il correspond à la recherche
    recipientItems.forEach(item => {
        const label = item.querySelector('label');
        if (!label) return;
        
        const text = label.textContent.toLowerCase();
        const matchesSearch = text.includes(searchTerm);
        
        // Afficher/masquer l'élément
        item.style.display = matchesSearch ? 'flex' : 'none';
        
        if (matchesSearch) {
            hasVisibleItems = true;
        }
    });
    
    // Mettre à jour l'état de visibilité des catégories
    updateCategoriesVisibility();
    
    // Afficher un message si aucun résultat
    const noResults = document.getElementById('no-results-message');
    if (noResults) {
        noResults.style.display = hasVisibleItems ? 'none' : 'block';
    }
}

/**
 * Met à jour la visibilité des catégories en fonction des éléments visibles
 */
function updateCategoriesVisibility() {
    const categories = document.querySelectorAll('.recipient-category');
    
    categories.forEach(category => {
        const visibleItems = category.querySelectorAll('.recipient-item[style="display: flex;"]').length;
        category.style.display = visibleItems > 0 ? 'block' : 'none';
    });
}

/**
 * Met à jour l'affichage des destinataires sélectionnés
 */
function updateSelectedRecipients() {
    const container = document.getElementById('selected-recipients-container');
    if (!container) return;
    
    container.innerHTML = '';
    
    const checkboxes = document.querySelectorAll('input[name="destinataires[]"]:checked');
    
    if (checkboxes.length === 0) {
        container.innerHTML = '<div class="empty-state-message">Aucun destinataire sélectionné</div>';
        return;
    }
    
    checkboxes.forEach(checkbox => {
        const label = checkbox.nextElementSibling;
        if (!label) return;
        
        const text = label.textContent.trim();
        const value = checkbox.value;
        
        const tag = document.createElement('div');
        tag.className = 'recipient-tag';
        tag.innerHTML = `
            <span>${text}</span>
            <span class="remove-tag" onclick="removeRecipient('${value}')">×</span>
        `;
        
        container.appendChild(tag);
    });
}

/**
 * Supprime un destinataire de la sélection
 * @param {string} value - Valeur du destinataire à supprimer
 */
function removeRecipient(value) {
    const checkbox = document.querySelector(`input[value="${value}"]`);
    if (checkbox) {
        checkbox.checked = false;
        updateSelectedRecipients();
    }
}

/**
 * Sélectionne tous les destinataires dans une catégorie
 * @param {string} categoryId - ID de la catégorie
 */
function selectAllInCategory(categoryId) {
    const category = document.getElementById(categoryId);
    if (!category) return;
    
    const checkboxes = category.querySelectorAll('input[name="destinataires[]"]');
    checkboxes.forEach(checkbox => {
        // Ne sélectionner que les éléments visibles (pas ceux filtrés)
        if (checkbox.closest('.recipient-item').style.display !== 'none') {
            checkbox.checked = true;
        }
    });
    
    updateSelectedRecipients();
}

/**
 * Désélectionne tous les destinataires dans une catégorie
 * @param {string} categoryId - ID de la catégorie
 */
function deselectAllInCategory(categoryId) {
    const category = document.getElementById(categoryId);
    if (!category) return;
    
    const checkboxes = category.querySelectorAll('input[name="destinataires[]"]');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    
    updateSelectedRecipients();
}

/**
 * Bascule l'affichage des options de cible
 */
function toggleTargetOptions() {
    const cible = document.getElementById('cible');
    if (!cible) return;
    
    const targetClasses = document.getElementById('target-classes');
    if (!targetClasses) return;
    
    // Masquer toutes les options
    targetClasses.style.display = 'none';
    
    // Afficher les options correspondant à la cible
    if (cible.value === 'classes') {
        targetClasses.style.display = 'block';
    }
}