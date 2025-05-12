/**
 * Gestion des téléchargements de fichiers
 */
document.addEventListener('DOMContentLoaded', function() {
    // Initialiser les zones de téléchargement (dropzones)
    initDropzones();
    
    // Configurer l'aperçu des fichiers
    initFilePreview();
    
    // Gestion de la suppression des fichiers
    initDeleteButtons();
    
    // Fonctions d'utilitaires pour le téléchargement de fichiers
    
    // Initialiser les zones de dépôt de fichiers
    function initDropzones() {
        const dropzones = document.querySelectorAll('.dropzone');
        
        dropzones.forEach(function(dropzone) {
            const input = dropzone.querySelector('input[type="file"]');
            if (!input) return;
            
            // Cliquer sur la zone ouvre le sélecteur de fichiers
            dropzone.addEventListener('click', function(e) {
                if (e.target.tagName !== 'BUTTON' && e.target.parentElement.tagName !== 'BUTTON') {
                    input.click();
                }
            });
            
            // Gérer le drag & drop
            dropzone.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.add('dragover');
            });
            
            dropzone.addEventListener('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('dragover');
            });
            
            dropzone.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('dragover');
                
                // Transférer les fichiers à l'input
                if (e.dataTransfer.files.length > 0) {
                    input.files = e.dataTransfer.files;
                    
                    // Déclencher l'événement change pour mettre à jour l'aperçu
                    const event = new Event('change', { bubbles: true });
                    input.dispatchEvent(event);
                }
            });
            
            // Mettre à jour la liste des fichiers lors de la sélection
            input.addEventListener('change', function() {
                const fileList = this.parentElement.querySelector('.file-list');
                if (!fileList) return;
                
                // Vider la liste actuelle si on ne permet pas les sélections multiples
                if (!this.hasAttribute('multiple')) {
                    fileList.innerHTML = '';
                }
                
                // Ajouter les nouveaux fichiers à la liste
                for (let i = 0; i < this.files.length; i++) {
                    const file = this.files[i];
                    const fileItem = createFileItem(file);
                    fileList.appendChild(fileItem);
                }
                
                // Montrer/cacher les éléments selon s'il y a des fichiers
                updateDropzoneState(this.parentElement, this.files.length > 0);
            });
        });
    }
    
    // Créer un élément de liste pour un fichier
    function createFileItem(file) {
        const fileItem = document.createElement('div');
        fileItem.className = 'file-item';
        
        // Déterminer l'icône selon le type de fichier
        let icon = 'insert_drive_file'; // Par défaut
        
        if (file.type.includes('pdf')) {
            icon = 'picture_as_pdf';
        } else if (file.type.includes('image')) {
            icon = 'image';
        } else if (file.type.includes('word') || file.type.includes('document')) {
            icon = 'description';
        } else if (file.type.includes('excel') || file.type.includes('sheet')) {
            icon = 'table_chart';
        } else if (file.type.includes('presentation') || file.type.includes('powerpoint')) {
            icon = 'slideshow';
        } else if (file.type.includes('zip') || file.type.includes('rar')) {
            icon = 'archive';
        }
        
        fileItem.innerHTML = `
            <div class="file-icon">
                <i class="material-icons">${icon}</i>
            </div>
            <div class="file-info">
                <div class="file-name">${file.name}</div>
                <div class="file-size">${formatFileSize(file.size)}</div>
            </div>
            <div class="file-actions">
                <button type="button" class="btn-remove-file" title="Supprimer">
                    <i class="material-icons">close</i>
                </button>
            </div>
        `;
        
        // Ajouter un gestionnaire pour le bouton de suppression
        fileItem.querySelector('.btn-remove-file').addEventListener('click', function(e) {
            e.stopPropagation(); // Éviter de déclencher le clic sur dropzone
            fileItem.remove();
            
            // Mettre à jour l'état de la dropzone
            const fileList = this.closest('.file-list');
            const dropzone = fileList.closest('.dropzone');
            updateDropzoneState(dropzone, fileList.children.length > 0);
        });
        
        return fileItem;
    }
    
    // Mettre à jour l'état visuel de la dropzone
    function updateDropzoneState(dropzone, hasFiles) {
        const placeholder = dropzone.querySelector('.dropzone-placeholder');
        const fileList = dropzone.querySelector('.file-list');
        
        if (placeholder && fileList) {
            placeholder.style.display = hasFiles ? 'none' : 'block';
            fileList.style.display = hasFiles ? 'block' : 'none';
        }
    }
    
    // Initialiser l'aperçu des fichiers existants
    function initFilePreview() {
        const fileItems = document.querySelectorAll('.file-item');
        
        fileItems.forEach(function(item) {
            // Gérer le clic sur l'aperçu d'un fichier
            item.addEventListener('click', function(e) {
                // Si c'est un bouton, ne rien faire (géré ailleurs)
                if (e.target.tagName === 'BUTTON' || e.target.closest('button')) {
                    return;
                }
                
                // Récupérer l'URL du fichier (si disponible)
                const fileUrl = this.dataset.url;
                if (fileUrl) {
                    window.open(fileUrl, '_blank');
                }
            });
        });
    }
    
    // Initialiser les boutons de suppression de fichiers
    function initDeleteButtons() {
        const deleteButtons = document.querySelectorAll('.delete-file-btn');
        
        deleteButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                
                if (confirm('Êtes-vous sûr de vouloir supprimer ce fichier ?')) {
                    const fileId = this.dataset.fileId;
                    const itemToRemove = this.closest('.file-item');
                    
                    // Envoyer une requête AJAX pour supprimer le fichier
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', BASE_URL + '/api/files.php', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                if (response.success) {
                                    itemToRemove.remove();
                                    showNotification('Fichier supprimé avec succès', 'success');
                                } else {
                                    showNotification('Erreur: ' + response.message, 'error');
                                }
                            } catch (e) {
                                showNotification('Erreur de traitement de la réponse', 'error');
                            }
                        } else {
                            showNotification('Erreur de connexion au serveur', 'error');
                        }
                    };
                    xhr.send('action=delete_file&file_id=' + encodeURIComponent(fileId));
                }
            });
        });
    }
    
    // Formater la taille d'un fichier en KB, MB, etc.
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    // Afficher une notification
    function showNotification(message, type) {
        // Si la fonction est définie globalement
        if (typeof displayNotification === 'function') {
            displayNotification(message, type);
        } else {
            // Fallback: une alerte simple
            alert(message);
        }
    }
});