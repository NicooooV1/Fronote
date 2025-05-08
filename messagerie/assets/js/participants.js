/**
 * /assets/js/participants.js - Gestion des participants
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialiser le sélecteur de destinataires
    initRecipientSelector();
    
    // Initialiser la recherche de destinataires
    initRecipientSearch();
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
        searchInput.addEventListener('keyup', filterRecipients);
        
        // Focus sur le champ de recherche au chargement
        searchInput.focus();
    }
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
    fetch(`api/get_participants.php?type=${type}&conv_id=${convId}`)
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