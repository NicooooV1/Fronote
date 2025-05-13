// pronote-devoirs.js - Gestion des devoirs
document.addEventListener('DOMContentLoaded', () => {
    // Éléments DOM
    const apiDevoirs = '/api/devoirs';
    const apiDevoirStatus = '/api/devoir_status';
    const listContainer = document.getElementById('devoirs-list');
    const calendarContainer = document.getElementById('calendar-view');
    const listViewBtn = document.getElementById('list-view-btn');
    const calendarViewBtn = document.getElementById('calendar-view-btn');
    const listView = document.getElementById('list-view');
    const calendarView = document.getElementById('calendar-view');
    const tabDevoirs = document.getElementById('tab-devoirs');
    const tabContenu = document.getElementById('tab-contenu');
    const modalDevoir = document.getElementById('modal-devoir');
    const form = document.getElementById('form-devoir');
    const btnEnregistrer = document.getElementById('btn-enregistrer');
    const btnAnnuler = document.getElementById('btn-annuler');
    const btnAjouterDevoir = document.getElementById('btn-ajouter-devoir');
    const modalClose = document.getElementById('modal-close');
    const notification = document.getElementById('notification');
    
    // Gestion des filtres
    const filtreMatiere = document.getElementById('filtre-matiere');
    const filtreClasse = document.getElementById('filtre-classe');
    const filtreEtat = document.getElementById('filtre-etat');
    const filtreDate = document.getElementById('filtre-date');
    const btnFiltrer = document.getElementById('btn-filtrer');
    const btnReinitialiser = document.getElementById('btn-reinitialiser');
    
    // Variables d'état
    let userProfile = '';
    let isTeacher = false;
    let currentView = 'list';
    
    // Récupérer le profil utilisateur
    async function fetchUserProfile() {
        try {
            const response = await fetch('/api/user/profile');
            if (!response.ok) throw new Error('Erreur lors de la récupération du profil');
            
            const data = await response.json();
            userProfile = data.profil;
            isTeacher = userProfile === 'professeur' || userProfile === 'administrateur';
            
            // Masquer les éléments réservés aux enseignants
            if (!isTeacher) {
                document.querySelectorAll('.teacher-only').forEach(el => el.style.display = 'none');
                document.getElementById('teacher-actions').style.display = 'none';
            }
            
            // Charger les devoirs après avoir obtenu le profil
            loadDevoirs();
        } catch (error) {
            console.error('Erreur:', error);
            // En cas d'erreur, on charge quand même les devoirs
            loadDevoirs();
        }
    }
    
    // Charger les matières pour les filtres et le formulaire
    async function loadMatieres() {
        try {
            const response = await fetch('/api/matieres');
            if (!response.ok) throw new Error('Erreur lors du chargement des matières');
            
            const data = await response.json();
            
            // Remplir le select du filtre
            filtreMatiere.innerHTML = '<option value="">Toutes matières</option>';
            
            // Remplir le select du formulaire
            const formMatiere = document.getElementById('matiere');
            formMatiere.innerHTML = '<option value="">Sélectionnez une matière</option>';
            
            data.forEach(m => {
                // Option pour le filtre
                const option1 = document.createElement('option');
                option1.value = m.nom;
                option1.textContent = m.nom;
                filtreMatiere.appendChild(option1);
                
                // Option pour le formulaire
                const option2 = document.createElement('option');
                option2.value = m.nom;
                option2.textContent = m.nom;
                formMatiere.appendChild(option2);
            });
        } catch (error) {
            console.error('Erreur:', error);
            showNotification('Erreur lors du chargement des matières', 'error');
        }
    }
    
    // Charger les classes pour les filtres et le formulaire
    async function loadClasses() {
        try {
            const response = await fetch('/api/classes');
            if (!response.ok) throw new Error('Erreur lors du chargement des classes');
            
            const data = await response.json();
            
            // Remplir le select du filtre
            filtreClasse.innerHTML = '<option value="">Toutes classes</option>';
            
            // Remplir le select du formulaire
            const formClasse = document.getElementById('classe');
            formClasse.innerHTML = '<option value="">Sélectionnez une classe</option>';
            
            // Parcourir la structure imbriquée des classes
            Object.keys(data).forEach(niveau => {
                Object.keys(data[niveau]).forEach(cycle => {
                    data[niveau][cycle].forEach(classe => {
                        // Option pour le filtre
                        const option1 = document.createElement('option');
                        option1.value = classe;
                        option1.textContent = classe;
                        filtreClasse.appendChild(option1);
                        
                        // Option pour le formulaire
                        const option2 = document.createElement('option');
                        option2.value = classe;
                        option2.textContent = classe;
                        formClasse.appendChild(option2);
                    });
                });
            });
        } catch (error) {
            console.error('Erreur:', error);
            showNotification('Erreur lors du chargement des classes', 'error');
        }
    }
    
    // Chargement des devoirs
    async function loadDevoirs(params = '') {
        try {
            listContainer.innerHTML = '<tr><td colspan="6">Chargement...</td></tr>';
            
            const response = await fetch(apiDevoirs + params);
            if (!response.ok) throw new Error('Erreur lors du chargement des devoirs');
            
            const data = await response.json();
            
            if (data.length === 0) {
                listContainer.innerHTML = '<tr><td colspan="6">Aucun devoir trouvé</td></tr>';
                return;
            }
            
            // Réinitialiser le contenu avant d'ajouter les nouveaux devoirs
            listContainer.innerHTML = '';
            
            // Trier les devoirs par date de remise (plus proches en premier)
            data.sort((a, b) => new Date(a.date_remise) - new Date(b.date_remise));
            
            // Afficher chaque devoir
            data.forEach(async devoir => {
                // Création de la ligne du tableau
                const tr = document.createElement('tr');
                
                // Formater la date
                const dateRemise = new Date(devoir.date_remise);
                const options = { 
                    day: 'numeric', 
                    month: 'long', 
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                };
                const formattedDate = dateRemise.toLocaleDateString('fr-FR', options);
                
                // Déterminer si la date est dépassée
                const isOverdue = new Date() > dateRemise;
                const dateClass = isOverdue ? 'overdue' : '';
                
                // Récupérer le statut du devoir si l'utilisateur est un élève
                let statusHTML = '';
                
                if (userProfile === 'eleve') {
                    try {
                        const statusResponse = await fetch(`${apiDevoirStatus}?id_devoir=${devoir.id}`);
                        const statusData = await statusResponse.json();
                        
                        if (statusData.length > 0) {
                            const status = statusData[0].status;
                            
                            switch (status) {
                                case 'non_fait':
                                    statusHTML = '<span class="pronote-status pronote-status-todo">À faire</span>';
                                    break;
                                case 'en_cours':
                                    statusHTML = '<span class="pronote-status pronote-status-in-progress">En cours</span>';
                                    break;
                                case 'termine':
                                    statusHTML = '<span class="pronote-status pronote-status-done">Terminé</span>';
                                    break;
                            }
                        } else {
                            // Par défaut, "À faire"
                            statusHTML = '<span class="pronote-status pronote-status-todo">À faire</span>';
                        }
                    } catch (error) {
                        console.error('Erreur de récupération du statut:', error);
                        statusHTML = '<span class="pronote-status pronote-status-todo">À faire</span>';
                    }
                } else {
                    // Pour les professeurs, afficher "Publié"
                    statusHTML = '<span class="pronote-status" style="background-color: #90caf9;">Publié</span>';
                }
                
                // Boutons d'action
                let actionsHTML = `
                    <div style="display: flex; gap: 5px;">
                        <a href="${devoir.url_sujet}" target="_blank" class="pronote-action-btn" title="Voir le sujet">
                            <i class="fas fa-file-alt" style="color: var(--pronote-blue);"></i>
                        </a>
                `;
                
                // Ajouter le lien vers le corrigé s'il existe
                if (devoir.url_corrige) {
                    actionsHTML += `
                        <a href="${devoir.url_corrige}" target="_blank" class="pronote-action-btn" title="Voir le corrigé">
                            <i class="fas fa-check" style="color: var(--pronote-dark-gray);"></i>
                        </a>
                    `;
                }
                
                // Boutons de statut pour les élèves
                if (userProfile === 'eleve') {
                    actionsHTML += `
                        <button class="pronote-action-btn btn-status-update" title="Marquer comme à faire" data-id="${devoir.id}" data-status="non_fait">
                            <i class="fas fa-times-circle" style="color: var(--pronote-todo);"></i>
                        </button>
                        <button class="pronote-action-btn btn-status-update" title="Marquer comme en cours" data-id="${devoir.id}" data-status="en_cours">
                            <i class="fas fa-clock" style="color: var(--pronote-inprogress);"></i>
                        </button>
                        <button class="pronote-action-btn btn-status-update" title="Marquer comme terminé" data-id="${devoir.id}" data-status="termine">
                            <i class="fas fa-check-circle" style="color: var(--pronote-done);"></i>
                        </button>
                    `;
                }
                
                // Boutons de modification pour les professeurs
                if (isTeacher) {
                    actionsHTML += `
                        <button class="pronote-action-btn btn-edit" title="Modifier" data-id="${devoir.id}">
                            <i class="fas fa-edit" style="color: var(--pronote-highlight);"></i>
                        </button>
                        <button class="pronote-action-btn btn-delete" title="Supprimer" data-id="${devoir.id}">
                            <i class="fas fa-trash" style="color: var(--pronote-todo);"></i>
                        </button>
                    `;
                }
                
                actionsHTML += `</div>`;
                
                // Assembler la ligne du tableau
                tr.innerHTML = `
                    <td>${devoir.matiere}</td>
                    <td>${devoir.titre}</td>
                    <td>${devoir.classe}</td>
                    <td class="${dateClass}">${formattedDate}</td>
                    <td>${statusHTML}</td>
                    <td>${actionsHTML}</td>
                `;
                
                listContainer.appendChild(tr);
            });
            
            // Mettre à jour le calendrier
            updateCalendarView(data);
            
        } catch (error) {
            console.error('Erreur:', error);
            listContainer.innerHTML = '<tr><td colspan="6">Erreur lors du chargement des devoirs</td></tr>';
            showNotification('Erreur lors du chargement des devoirs', 'error');
        }
    }
    
    // Mise à jour de la vue calendrier
    function updateCalendarView(devoirs) {
        // Logique pour remplir le calendrier avec les devoirs
        // Cette fonction serait développée davantage pour un calendrier dynamique
    }
    
    // Mettre à jour le statut d'un devoir
    async function updateDevoirStatus(devoirId, status) {
        try {
            const data = {
                id_devoir: devoirId,
                status: status
            };
            
            const response = await fetch(apiDevoirStatus, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });
            
            if (!response.ok) throw new Error('Erreur lors de la mise à jour du statut');
            
            // Recharger la liste des devoirs pour afficher le nouveau statut
            loadDevoirs();
            showNotification('Statut mis à jour');
            
        } catch (error) {
            console.error('Erreur:', error);
            showNotification('Erreur lors de la mise à jour du statut', 'error');
        }
    }
    
    // Fonction pour afficher une notification
    function showNotification(message, type = 'success') {
        notification.className = `pronote-notification pronote-notification-${type}`;
        notification.querySelector('span').textContent = message;
        notification.style.display = 'flex';
        
        // Disparition automatique après 3 secondes
        setTimeout(() => {
            notification.style.display = 'none';
        }, 3000);
    }
    
    // Fonction pour ouvrir le modal d'ajout de devoir
    function openAddModal() {
        form.reset();
        document.querySelector('.pronote-modal-title').textContent = 'Ajouter un devoir';
        modalDevoir.style.display = 'flex';
    }
    
    // Fonction pour ouvrir le modal de modification
    async function openEditModal(devoirId) {
        try {
            const response = await fetch(`${apiDevoirs}/${devoirId}`);
            if (!response.ok) throw new Error('Erreur lors de la récupération du devoir');
            
            const devoir = await response.json();
            
            // Remplir le formulaire avec les données du devoir
            form.querySelector('[name="id"]').value = devoir.id;
            form.querySelector('[name="titre"]').value = devoir.titre;
            form.querySelector('[name="matiere"]').value = devoir.matiere;
            form.querySelector('[name="classe"]').value = devoir.classe;
            
            // Formater la date et l'heure pour le champ datetime-local
            const dateRemise = new Date(devoir.date_remise);
            const dateStr = dateRemise.toISOString().slice(0, 16); // Format "YYYY-MM-DDTHH:MM"
            form.querySelector('[name="date_remise"]').value = dateStr;
            
            if (devoir.description) {
                form.querySelector('[name="description"]').value = devoir.description;
            }
            
            document.querySelector('.pronote-modal-title').textContent = 'Modifier un devoir';
            modalDevoir.style.display = 'flex';
            
        } catch (error) {
            console.error('Erreur:', error);
            showNotification('Erreur lors de la récupération du devoir', 'error');
        }
    }
    
    // Fonction pour fermer le modal
    function closeModal() {
        modalDevoir.style.display = 'none';
    }
    
    // Fonction pour enregistrer un devoir
    async function saveDevoir() {
        try {
            // Récupérer les données du formulaire
            const formData = new FormData(form);
            const id = formData.get('id');
            const method = id ? 'PUT' : 'POST';
            const url = id ? `${apiDevoirs}/${id}` : apiDevoirs;
            
            // Valider les champs requis
            const titre = formData.get('titre');
            const matiere = formData.get('matiere');
            const classe = formData.get('classe');
            const dateRemise = formData.get('date_remise');
            const description = formData.get('description');
            
            if (!titre || !matiere || !classe || !dateRemise || !description) {
                throw new Error('Veuillez remplir tous les champs obligatoires');
            }
            
            // Valider le fichier sujet si c'est un ajout
            if (!id && (!formData.get('fichier_sujet') || formData.get('fichier_sujet').size === 0)) {
                throw new Error('Le fichier sujet est obligatoire');
            }
            
            const response = await fetch(url, {
                method: method,
                body: formData
            });
            
            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.error || 'Erreur lors de l\'enregistrement du devoir');
            }
            
            // Recharger la liste des devoirs
            closeModal();
            loadDevoirs();
            showNotification(id ? 'Devoir modifié avec succès' : 'Devoir ajouté avec succès');
            
        } catch (error) {
            console.error('Erreur:', error);
            showNotification(error.message, 'error');
        }
    }
    
    // Fonction pour supprimer un devoir
    async function deleteDevoir(devoirId) {
        if (!confirm('Êtes-vous sûr de vouloir supprimer ce devoir ?')) return;
        
        try {
            const response = await fetch(`${apiDevoirs}/${devoirId}`, {
                method: 'DELETE'
            });
            
            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.error || 'Erreur lors de la suppression du devoir');
            }
            
            // Recharger la liste des devoirs
            loadDevoirs();
            showNotification('Devoir supprimé avec succès');
            
        } catch (error) {
            console.error('Erreur:', error);
            showNotification('Erreur lors de la suppression du devoir', 'error');
        }
    }
    
    // Fonction pour filtrer les devoirs
    function filterDevoirs() {
        const matiere = filtreMatiere.value;
        const classe = filtreClasse.value;
        const etat = filtreEtat.value;
        const date = filtreDate.value;
        
        let params = '?';
        if (matiere) params += `matiere=${encodeURIComponent(matiere)}&`;
        if (classe) params += `classe=${encodeURIComponent(classe)}&`;
        if (date) params += `date_remise=${encodeURIComponent(date)}&`;
        // Note: l'état sera filtré côté client car l'API ne supporte pas ce filtre
        
        // Supprimer le dernier '&' ou '?' si présent
        params = params.replace(/[&?]$/, '');
        
        // Si params contient uniquement '?', on le met à vide
        if (params === '?') params = '';
        
        loadDevoirs(params);
    }
    
    // Fonction pour réinitialiser les filtres
    function resetFilters() {
        filtreMatiere.value = '';
        filtreClasse.value = '';
        filtreEtat.value = '';
        filtreDate.value = '';
        loadDevoirs();
    }
    
    // Changer entre les vues liste et calendrier
    function switchToListView() {
        listViewBtn.classList.add('active');
        calendarViewBtn.classList.remove('active');
        listView.style.display = 'block';
        calendarView.style.display = 'none';
        currentView = 'list';
    }
    
    function switchToCalendarView() {
        listViewBtn.classList.remove('active');
        calendarViewBtn.classList.add('active');
        listView.style.display = 'none';
        calendarView.style.display = 'block';
        currentView = 'calendar';
    }
    
    // Naviguer vers la page cahier de texte
    function navigateToCahierTexte() {
        window.location.href = '/cahier_texte.html';
    }
    
    // Initialisation
    fetchUserProfile();
    loadMatieres();
    loadClasses();
    
    // Écouteurs d'événements
    
    // Changer de vue
    listViewBtn.addEventListener('click', switchToListView);
    calendarViewBtn.addEventListener('click', switchToCalendarView);
    
    // Filtrer
    btnFiltrer.addEventListener('click', filterDevoirs);
    btnReinitialiser.addEventListener('click', resetFilters);
    
    // Navigation entre onglets
    tabContenu.addEventListener('click', navigateToCahierTexte);
    
    // Modals
    btnAjouterDevoir.addEventListener('click', openAddModal);
    btnAnnuler.addEventListener('click', closeModal);
    modalClose.addEventListener('click', closeModal);
    btnEnregistrer.addEventListener('click', saveDevoir);
    
    // Empêcher la soumission directe du formulaire
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        saveDevoir();
    });
    
    // Délégation d'événements pour les boutons d'action
    document.addEventListener('click', (e) => {
        // Bouton de modification
        if (e.target.closest('.btn-edit')) {
            const button = e.target.closest('.btn-edit');
            openEditModal(button.dataset.id);
        }
        
        // Bouton de suppression
        if (e.target.closest('.btn-delete')) {
            const button = e.target.closest('.btn-delete');
            deleteDevoir(button.dataset.id);
        }
        
        // Boutons de statut
        if (e.target.closest('.btn-status-update')) {
            const button = e.target.closest('.btn-status-update');
            updateDevoirStatus(button.dataset.id, button.dataset.status);
        }
    });
});