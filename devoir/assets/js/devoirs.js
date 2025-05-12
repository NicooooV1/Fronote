document.addEventListener('DOMContentLoaded', function() {
    // Gestion des onglets dans le formulaire de devoir
    var formTabs = document.querySelectorAll('.form-tab');
    var tabContents = document.querySelectorAll('.tab-content');
    
    formTabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            // Supprimer la classe active de tous les onglets
            formTabs.forEach(function(t) {
                t.classList.remove('active');
            });
            
            // Ajouter la classe active à l'onglet cliqué
            this.classList.add('active');
            
            // Masquer tous les contenus
            tabContents.forEach(function(content) {
                content.classList.remove('active');
            });
            
            // Afficher le contenu correspondant à l'onglet
            var target = this.getAttribute('data-target');
            document.getElementById(target).classList.add('active');
        });
    });
    
    // Gestion du dropzone pour les pièces jointes
    var dropzone = document.querySelector('.dropzone');
    var dropzoneInput = document.querySelector('.dropzone-input');
    
    if (dropzone && dropzoneInput) {
        // Ouvrir le sélecteur de fichiers au clic sur le dropzone
        dropzone.addEventListener('click', function() {
            dropzoneInput.click();
        });
        
        // Gérer le drag & drop
        dropzone.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });
        
        dropzone.addEventListener('dragleave', function() {
            this.classList.remove('dragover');
        });
        
        dropzone.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            
            dropzoneInput.files = e.dataTransfer.files;
            updateFileList(dropzoneInput.files);
        });
        
        // Mettre à jour la liste des fichiers sélectionnés
        dropzoneInput.addEventListener('change', function() {
            updateFileList(this.files);
        });
        
        function updateFileList(files) {
            var fileList = document.querySelector('.pieces-jointes-list');
            fileList.innerHTML = '';
            
            if (files.length > 0) {
                for (var i = 0; i < files.length; i++) {
                    var file = files[i];
                    var fileItem = document.createElement('div');
                    fileItem.classList.add('piece-jointe-item');
                    
                    // Déterminer l'icône selon le type de fichier
                    var icon = '';
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
                    } else {
                        icon = 'insert_drive_file';
                    }
                    
                    fileItem.innerHTML = `
                        <div class="piece-jointe-icon">
                            <i class="material-icons">${icon}</i>
                        </div>
                        <div class="piece-jointe-info">
                            <div class="piece-jointe-nom">${file.name}</div>
                            <div class="piece-jointe-type">${formatBytes(file.size)}</div>
                        </div>
                    `;
                    
                    fileList.appendChild(fileItem);
                }
            } else {
                fileList.innerHTML = '<p>Aucun fichier sélectionné</p>';
            }
        }
        
        function formatBytes(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
            
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }
    }
    
    // Gestion des onglets des devoirs (élève)
    var devoirsTabs = document.querySelectorAll('.eleve-devoirs-tab');
    var devoirsLists = document.querySelectorAll('.eleve-devoirs-list');
    
    if (devoirsTabs.length > 0 && devoirsLists.length > 0) {
        devoirsTabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                // Supprimer la classe active de tous les onglets
                devoirsTabs.forEach(function(t) {
                    t.classList.remove('active');
                });
                
                // Ajouter la classe active à l'onglet cliqué
                this.classList.add('active');
                
                // Masquer toutes les listes
                devoirsLists.forEach(function(list) {
                    list.style.display = 'none';
                });
                
                // Afficher la liste correspondante
                var targetId = this.getAttribute('data-target');
                document.getElementById(targetId).style.display = 'block';
            });
        });
    }
});