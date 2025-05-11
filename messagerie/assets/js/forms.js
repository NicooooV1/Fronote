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
    
    // Suppression des compteurs existants pour éviter les doublons
    const existingTitleCounter = document.getElementById('title-counter');
    const existingCharCounter = document.getElementById('char-counter');
    
    if (existingTitleCounter) {
        existingTitleCounter.parentNode.removeChild(existingTitleCounter);
    }
    
    if (existingCharCounter) {
        existingCharCounter.parentNode.removeChild(existingCharCounter);
    }
    
    // Validation du titre
    if (titleInput) {
        const titleCounter = document.createElement('div');
        titleCounter.id = 'title-counter';
        titleCounter.className = 'text-muted small';
        titleCounter.style.fontSize = '12px';
        titleCounter.style.color = '#6c757d';
        titleCounter.style.marginTop = '5px';
        
        titleInput.parentNode.insertBefore(titleCounter, titleInput.nextSibling);
        
        titleInput.addEventListener('input', function() {
            const maxLength = 100;
            const currentLength = this.value.length;
            
            titleCounter.textContent = `${currentLength}/${maxLength} caractères`;
            
            if (currentLength > maxLength) {
                titleCounter.style.color = '#dc3545';
                const submitButton = document.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                }
            } else {
                titleCounter.style.color = '#6c757d';
                // Ne pas réactiver le bouton si le contenu est trop long
                if (contentTextarea && contentTextarea.value.length <= 10000) {
                    const submitButton = document.querySelector('button[type="submit"]');
                    if (submitButton) {
                        submitButton.disabled = false;
                    }
                }
            }
        });
        
        // Déclencher l'événement pour initialiser le compteur
        titleInput.dispatchEvent(new Event('input'));
    }
    
    // Validation du contenu
    if (contentTextarea) {
        const charCounter = document.createElement('div');
        charCounter.id = 'char-counter';
        charCounter.className = 'text-muted small';
        charCounter.style.fontSize = '12px';
        charCounter.style.color = '#6c757d';
        charCounter.style.marginTop = '5px';
        
        contentTextarea.parentNode.insertBefore(charCounter, contentTextarea.nextSibling);
        
        contentTextarea.addEventListener('input', function() {
            const maxLength = 10000;
            const currentLength = this.value.length;
            
            charCounter.textContent = `${currentLength}/${maxLength} caractères`;
            
            if (currentLength > maxLength) {
                charCounter.style.color = '#dc3545';
                const submitButton = document.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                }
            } else {
                charCounter.style.color = '#6c757d';
                // Ne pas réactiver le bouton si le titre est trop long
                if (titleInput && titleInput.value.length <= 100) {
                    const submitButton = document.querySelector('button[type="submit"]');
                    if (submitButton) {
                        submitButton.disabled = false;
                    }
                } else if (!titleInput) {
                    // Si pas de titre, activer le bouton
                    const submitButton = document.querySelector('button[type="submit"]');
                    if (submitButton) {
                        submitButton.disabled = false;
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
            // Vérifier les limites de caractères
            let valid = true;
            
            // Vérifier le titre
            if (titleInput && titleInput.value.length > 100) {
                e.preventDefault();
                valid = false;
                if (!document.getElementById('title-error')) {
                    const errorMsg = document.createElement('div');
                    errorMsg.id = 'title-error';
                    errorMsg.style.color = '#dc3545';
                    errorMsg.style.fontSize = '12px';
                    errorMsg.style.marginTop = '5px';
                    errorMsg.textContent = 'Le titre ne peut pas dépasser 100 caractères';
                    titleInput.parentNode.insertBefore(errorMsg, titleInput.nextSibling.nextSibling);
                }
                // Afficher une notification d'erreur
                if (typeof afficherNotificationErreur === 'function') {
                    afficherNotificationErreur('Le titre ne peut pas dépasser 100 caractères');
                }
            }
            
            // Vérifier le contenu
            if (contentTextarea && contentTextarea.value.length > 10000) {
                e.preventDefault();
                valid = false;
                if (!document.getElementById('content-error')) {
                    const errorMsg = document.createElement('div');
                    errorMsg.id = 'content-error';
                    errorMsg.style.color = '#dc3545';
                    errorMsg.style.fontSize = '12px';
                    errorMsg.style.marginTop = '5px';
                    errorMsg.textContent = 'Le message ne peut pas dépasser 10000 caractères';
                    contentTextarea.parentNode.insertBefore(errorMsg, contentTextarea.nextSibling.nextSibling);
                }
                // Afficher une notification d'erreur
                if (typeof afficherNotificationErreur === 'function') {
                    afficherNotificationErreur('Le message ne peut pas dépasser 10000 caractères');
                }
            }
            
            // Vérifier aussi les champs requis
            const requiredFields = form.querySelectorAll('[required]');
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
                    // Afficher une notification d'erreur
                    if (typeof afficherNotificationErreur === 'function') {
                        afficherNotificationErreur(`Le champ "${field.getAttribute('placeholder') || field.name}" est requis`);
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
    
    // Validation des pièces jointes
    const fileInput = document.getElementById('attachments');
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            const maxSize = 10 * 1024 * 1024; // 10 Mo
            const allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png', 'gif'];
            
            let valid = true;
            let errorMessage = '';
            
            // Vérifier chaque fichier
            for (let i = 0; i < this.files.length; i++) {
                const file = this.files[i];
                
                // Vérifier la taille
                if (file.size > maxSize) {
                    valid = false;
                    errorMessage = `Le fichier ${file.name} est trop volumineux (max: 10 Mo)`;
                    break;
                }
                
                // Vérifier l'extension
                const extension = file.name.split('.').pop().toLowerCase();
                if (!allowedExtensions.includes(extension)) {
                    valid = false;
                    errorMessage = `Le type de fichier .${extension} n'est pas autorisé`;
                    break;
                }
            }
            
            // Afficher une erreur si nécessaire
            if (!valid) {
                // Réinitialiser l'input
                this.value = '';
                // Vider la liste des fichiers
                const fileList = document.getElementById('file-list');
                if (fileList) {
                    fileList.innerHTML = '';
                }
                // Afficher une notification d'erreur
                if (typeof afficherNotificationErreur === 'function') {
                    afficherNotificationErreur(errorMessage);
                } else {
                    alert(errorMessage);
                }
            }
        });
    }
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
    
    // Vérifier si on a déjà des destinataires sélectionnés
    const selectedDestinatairesCookies = getCookie('selected_destinataires');
    if (selectedDestinatairesCookies) {
        try {
            const selectedDestinataires = JSON.parse(selectedDestinatairesCookies);
            // Cocher les destinataires
            selectedDestinataires.forEach(dest => {
                const checkbox = document.querySelector(`input[name="destinataires[]"][value="${dest}"]`);
                if (checkbox) {
                    checkbox.checked = true;
                }
            });
            // Mettre à jour l'affichage
            updateSelectedRecipients();
        } catch (e) {
            console.error('Erreur lors de la lecture des destinataires enregistrés:', e);
        }
    }
}

/**
 * Lit un cookie par son nom
 * @param {string} name Nom du cookie
 * @returns {string|null} Valeur du cookie ou null
 */
function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    if (match) return match[2];
    return null;
}

/**
 * Définit un cookie
 * @param {string} name Nom du cookie
 * @param {string} value Valeur du cookie
 * @param {number} days Durée en jours
 */
function setCookie(name, value, days) {
    const date = new Date();
    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
    const expires = "expires=" + date.toUTCString();
    document.cookie = name + "=" + value + ";" + expires + ";path=/";
}

/**
 * Met à jour l'affichage des destinataires sélectionnés
 */
function updateSelectedRecipients() {
    const container = document.getElementById('selected-recipients-container');
    if (!container) return;
    
    container.innerHTML = '';
    
    const checkboxes = document.querySelectorAll('input[name="destinataires[]"]:checked');
    const selectedDestinataires = [];
    
    checkboxes.forEach(checkbox => {
        const label = checkbox.nextElementSibling;
        if (!label) return;
        
        const text = label.textContent;
        const value = checkbox.value;
        
        // Enregistrer pour les cookies
        selectedDestinataires.push(value);
        
        const tag = document.createElement('div');
        tag.className = 'recipient-tag';
        tag.innerHTML = `
            <span>${text}</span>
            <span class="remove-tag" onclick="removeRecipient('${value}')">×</span>
        `;
        
        container.appendChild(tag);
    });
    
    // Enregistrer les destinataires dans un cookie (pour la persistance)
    setCookie('selected_destinataires', JSON.stringify(selectedDestinataires), 1);
    
    // Mettre à jour les boutons après chaque changement
    updateCategoryButtons();
    
    // Activer/désactiver le bouton d'envoi en fonction du nombre de destinataires
    const submitBtn = document.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.disabled = selectedDestinataires.length === 0;
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
    
    const currentUserId = document.body.getAttribute('data-user-id');
    const currentUserType = document.body.getAttribute('data-user-type');
    const currentUserSelector = `input[value="${currentUserType}_${currentUserId}"]`;
    
    const checkboxes = category.querySelectorAll('input[type="checkbox"]:not(:checked):not(' + currentUserSelector + ')');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
    
    updateSelectedRecipients();
    
    // Notification de succès
    if (typeof afficherNotificationSucces === 'function') {
        const categoryName = category.querySelector('.category-title')?.textContent || 'catégorie';
        afficherNotificationSucces(`Tous les destinataires de la ${categoryName} ont été sélectionnés`, 2000);
    }
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
    
    // Notification de succès
    if (typeof afficherNotificationSucces === 'function') {
        const categoryName = category.querySelector('.category-title')?.textContent || 'catégorie';
        afficherNotificationSucces(`Tous les destinataires de la ${categoryName} ont été désélectionnés`, 2000);
    }
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
 * Initialise la gestion des pièces jointes
 */
function initFileUpload() {
    const fileInput = document.getElementById('attachments');
    
    if (fileInput) {
        fileInput.addEventListener('change', function() {
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
                
                // Notification de succès
                if (typeof afficherNotificationSucces === 'function') {
                    afficherNotificationSucces(`${this.files.length} fichier(s) sélectionné(s)`, 2000);
                }
            }
        });
    }
}

/**
 * Formate la taille d'un fichier
 * @param {number} bytes Taille en octets
 * @returns {string} Taille formatée
 */
function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    else if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
    else return Math.round(bytes / 1048576 * 10) / 10 + ' MB';
}