// pronote-cahier-texte.js - Gestion du cahier de texte
document.addEventListener('DOMContentLoaded', () => {
    // Éléments DOM
    const apiCahierTexte = '/api/cahier_texte';
    const apiDevoirs = '/api/devoirs';
    const listView = document.getElementById('list-view');
    const weekView = document.getElementById('week-view');
    const listViewBtn = document.getElementById('list-view-btn');
    const weekViewBtn = document.getElementById('week-view-btn');
    const tabDevoirs = document.getElementById('tab-devoirs');
    const tabContenu = document.getElementById('tab-contenu');
    const modalSeance = document.getElementById('modal-seance');
    const form = document.getElementById('form-seance');
    const btnEnregistrer = document.getElementById('btn-enregistrer');
    const btnAnnuler = document.getElementById('btn-annuler');
    const btnAjouterSeance = document.getElementById('btn-ajouter-seance');
    const modalClose = document.getElementById('modal-close');
    const notification = document.getElementById('notification');
    
    // Gestion des filtres
    const filtreMatiere = document.getElementById('filtre-matiere');
    const filtreClasse = document.getElementById('filtre-classe');
    const filtrePeriode = document.getElementById('filtre-periode');
    const filtreDate = document.getElementById('filtre-date');
    const dateFilterContainer = document.getElementById('date-filter-container');
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
            
            // Charger le cahier de texte après avoir obtenu le profil
            loadCahierTexte();
        } catch (error) {
            console.error('Erreur:', error);
            // En cas d'erreur, on charge quand même le cahier de texte
            loadCahierTexte();
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
    
    // Chargement du cahier de texte
    async function loadCahierTexte(params = '') {
        try {
            listView.innerHTML = '<div class="loading">Chargement...</div>';
            
            const response = await fetch(apiCahierTexte + params);
            if (!response.ok) throw new Error('Erreur lors du chargement du cahier de texte');
            
            const data = await response.json();
            
            if (data.length === 0) {
                listView.innerHTML = '<p>Aucune séance trouvée dans le cahier de texte.</p>';
                return;
            }
            
            // Réinitialiser le contenu avant d'ajouter les nouvelles séances
            listView.innerHTML = '';
            
            // Trier les séances par date (plus récentes en premier)
            data.sort((a, b) => new Date(b.date_cours) - new Date(a.date_cours));
            
            // Afficher chaque séance
            data.forEach(async seance => {
                // Création de la carte pour la séance
                const div = document.createElement('div');
                div.className = 'pronote-lesson';
                
                // Formater la date
                const dateCours = new Date(seance.date_cours);
                const options = { 
                    weekday: 'long', 
                    day: 'numeric', 
                    month: 'long', 
                    year: 'numeric'
                };
                const formattedDate = dateCours.toLocaleDateString('fr-FR', options);
                
                // Horaire (si disponible)
                let horaireText = '';
                if (seance.heure_debut && seance.heure_fin) {
                    horaireText = ` (${seance.heure_debut}-${seance.heure_fin})`;
                }
                
                // Boutons d'action pour les enseignants
                let actionButtons = '';
                if (isTeacher) {
                    actionButtons = `
                        <button class="pronote-btn pronote-btn-small pronote-btn-secondary btn-edit" data-id="${seance.id}">
                            <i class="fas fa-edit"></i>
                            <span>Modifier</span>
                        </button>
                        <button class="pronote-btn pronote-btn-small btn-delete" style="background-color: #f44336; color: white;" data-id="${seance.id}">
                            <i class="fas fa-trash"></i>
                            <span>Supprimer</span>
                        </button>
                    `;
                }
                
                // Documents joints
                let documentsHTML = '';
                if (seance.documents) {
                    documentsHTML = '<div class="pronote-lesson-files"><h4>Documents joints :</h4>';
                    
                    const docs = seance.documents.split(',').map(doc => doc.trim());
                    docs.forEach(doc => {
                        // Déterminer le type d'icône en fonction de l'extension
                        let iconClass = 'fa-file';
                        const ext = doc.split('.').pop().toLowerCase();
                        
                        if (['pdf'].includes(ext)) iconClass = 'fa-file-pdf';
                        else if (['doc', 'docx'].includes(ext)) iconClass = 'fa-file-word';
                        else if (['ppt', 'pptx'].includes(ext)) iconClass = 'fa-file-powerpoint';
                        else if (['xls', 'xlsx'].includes(ext)) iconClass = 'fa-file-excel';
                        else if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) iconClass = 'fa-file-image';
                        
                        documentsHTML += `
                            <a href="${doc}" class="pronote-file-link" target="_blank">
                                <i class="fas ${iconClass}"></i>
                                <span>${doc.split('/').pop()}</span>
                            </a>
                        `;
                    });
                    
                    documentsHTML += '</div>';
                }
                
                // Devoirs associés
                let devoirsHTML = '';
                
                // Récupérer les devoirs associés à cette séance
                if (seance.id_cahier_texte) {
                    try {
                        const devoirsResponse = await fetch(`${apiDevoirs}?id_cahier_texte=${seance.id}`);
                        if (devoirsResponse.ok) {
                            const devoirs = await devoirsResponse.json();
                            
                            if (devoirs.length > 0) {
                                devoirsHTML = '<div class="pronote-homework-section"><h4 class="pronote-homework-header">Travail à faire pour la prochaine séance :</h4>';
                                
                                devoirs.forEach(devoir => {
                                    const dateRemise = new Date(devoir.date_remise);
                                    const dateOptions = { day: 'numeric', month: 'long', year: 'numeric' };
                                    const formattedRemiseDate = dateRemise.toLocaleDateString('fr-FR', dateOptions);
                                    
                                    devoirsHTML += `
                                        <div class="pronote-homework-item">
                                            <input type="checkbox" class="pronote-checkbox" id="hw-${devoir.id}">
                                            <span>${devoir.titre} (à rendre le ${formattedRemiseDate})</span>
                                        </div>
                                    `;
                                });
                                
                                devoirsHTML += '</div>';
                            }
                        }
                    } catch (error) {
                        console.error('Erreur récupération devoirs:', error);
                    }
                }
                
                // Assembler la carte
                div.innerHTML = `
                    <div class="pronote-lesson-header">
                        <div>
                            <div class="pronote-lesson-subject">${seance.matiere}</div>
                            <div class="pronote-lesson-meta">
                                <span>${seance.classe}</span>
                                <span>${formattedDate}${horaireText}</span>
                            </div>
                        </div>
                        <div>
                            ${actionButtons}
                        </div>
                    </div>
                    <div class="pronote-lesson-content">
                        ${seance.contenu}
                    </div>
                    ${documentsHTML}
                    ${devoirsHTML}
                `;
                
                listView.appendChild(div);
            });
            
            // Mettre à jour la vue semaine
            updateWeekView(data);
            
        } catch (error) {
            console.error('Erreur:', error);
            listView.innerHTML = '<p>Erreur lors du chargement du cahier de texte.</p>';
            showNotification('Erreur lors du chargement du cahier de texte', 'error');
        }
    }
    
    // Mise à jour de la vue semaine
    function updateWeekView(seances) {
        // Cette fonction serait développée pour mettre à jour dynamiquement la vue semaine
        // Elle remplirait les créneaux de la semaine avec les séances
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
    
    // Fonction pour ouvrir le modal d'ajout de séance
    function openAddModal() {
        form.reset();
        document.querySelector('.pronote-modal-title').textContent = 'Ajouter une séance';
        modalSeance.style.display = 'flex';
        // Charger les devoirs disponibles en fonction de la classe et de la matière
        loadDevoirsForForm();
    }
    
    // Fonction pour ouvrir le modal de modification
    async function openEditModal(seanceId) {
        try {
            const response = await fetch(`${apiCahierTexte}/${seanceId}`);
            if (!response.ok) throw new Error('Erreur lors de la récupération de la séance');
            
            const seance = await response.json();
            
            // Remplir le formulaire avec les données de la séance
            form.querySelector('[name="id"]').value = seance.id;
            form.querySelector('[name="matiere"]').value = seance.matiere;
            form.querySelector('[name="classe"]').value = seance.classe;
            
            // Formater la date pour le champ date
            const dateCours = new Date(seance.date_cours);
            const dateStr = dateCours.toISOString().split('T')[0]; // Format "YYYY-MM-DD"
            form.querySelector('[name="date_cours"]').value = dateStr;
            
            // Remplir les horaires si disponibles
            if (seance.heure_debut) form.querySelector('[name="heure_debut"]').value = seance.heure_debut;
            if (seance.heure_fin) form.querySelector('[name="heure_fin"]').value = seance.heure_fin;
            
            form.querySelector('[name="titre"]').value = seance.titre || '';
            form.querySelector('[name="contenu"]').value = seance.contenu;
            
            if (seance.documents) {
                form.querySelector('[name="documents"]').value = seance.documents;
            }
            
            document.querySelector('.pronote-modal-title').textContent = 'Modifier une séance';
            modalSeance.style.display = 'flex';
            
            // Charger les devoirs disponibles et cocher ceux qui sont associés
            await loadDevoirsForForm(seance.id);
            
        } catch (error) {
            console.error('Erreur:', error);
            showNotification('Erreur lors de la récupération de la séance', 'error');
        }
    }
    
    // Fonction pour fermer le modal
    function closeModal() {
        modalSeance.style.display = 'none';
    }
    
    // Charger les devoirs pour le formulaire
    async function loadDevoirsForForm(seanceId = null) {
        try {
            const classe = form.querySelector('[name="classe"]').value;
            const matiere = form.querySelector('[name="matiere"]').value;
            
            // Si la classe ou la matière n'est pas sélectionnée, on ne charge pas les devoirs
            if (!classe || !matiere) {
                document.getElementById('devoirs-container').innerHTML = '<p>Sélectionnez une classe et une matière pour voir les devoirs disponibles.</p>';
                return;
            }
            
            // Récupérer tous les devoirs pour cette classe et cette matière
            const params = `?classe=${encodeURIComponent(classe)}&matiere=${encodeURIComponent(matiere)}`;
            const response = await fetch(apiDevoirs + params);
            
            if (!response.ok) throw new Error('Erreur lors du chargement des devoirs');
            
            const devoirs = await response.json();
            const devoirsContainer = document.getElementById('devoirs-container');
            
            if (devoirs.length === 0) {
                devoirsContainer.innerHTML = '<p>Aucun devoir disponible pour cette classe et cette matière.</p>';
                return;
            }
            
            // Afficher les devoirs sous forme de cases à cocher
            devoirsContainer.innerHTML = '';
            
            devoirs.forEach(devoir => {
                const dateRemise = new Date(devoir.date_remise);
                const options = { day: 'numeric', month: 'short', year: 'numeric' };
                const formattedDate = dateRemise.toLocaleDateString('fr-FR', options);
                
                const div = document.createElement('div');
                div.style.display = 'flex';
                div.style.alignItems = 'center';
                div.style.marginBottom = '10px';
                
                div.innerHTML = `
                    <input type="checkbox" class="pronote-checkbox" id="devoir-${devoir.id}" value="${devoir.id}" name="devoirs[]">
                    <label for="devoir-${devoir.id}" style="margin-left: 10px;">${devoir.titre} (à rendre le ${formattedDate})</label>
                `;
                
                devoirsContainer.appendChild(div);
            });
            
            // Si on modifie une séance, cocher les devoirs associés
            if (seanceId) {
                // Récupérer les devoirs associés à cette séance
                const devoirsAssocResponse = await fetch(`${apiDevoirs}?id_cahier_texte=${seanceId}`);
                if (devoirsAssocResponse.ok) {
                    const devoirsAssoc = await devoirsAssocResponse.json();
                    
                    // Cocher les devoirs associés
                    devoirsAssoc.forEach(devoir => {
                        const checkbox = document.getElementById(`devoir-${devoir.id}`);
                        if (checkbox) checkbox.checked = true;
                    });
                }
            }
            
        } catch (error) {
            console.error('Erreur:', error);
            document.getElementById('devoirs-container').innerHTML = '<p>Erreur lors du chargement des devoirs.</p>';
        }
    }
    
    // Fonction pour enregistrer une séance
    async function saveSeance() {
        try {
            // Récupérer les données du formulaire
            const id = form.querySelector('[name="id"]').value;
            const matiere = form.querySelector('[name="matiere"]').value;
            const classe = form.querySelector('[name="classe"]').value;
            const dateCours = form.querySelector('[name="date_cours"]').value;
            const heureDebut = form.querySelector('[name="heure_debut"]').value;
            const heureFin = form.querySelector('[name="heure_fin"]').value;
            const titre = form.querySelector('[name="titre"]').value;
            const contenu = form.querySelector('[name="contenu"]').value;
            const documents = form.querySelector('[name="documents"]').value;
            
            // Récupérer les devoirs sélectionnés
            const devoirs = [];
            form.querySelectorAll('[name="devoirs[]"]:checked').forEach(checkbox => {
                devoirs.push(checkbox.value);
            });
            
            // Valider les champs requis
            if (!matiere || !classe || !dateCours || !contenu) {
                throw new Error('Veuillez remplir tous les champs obligatoires');
            }
            
            // Préparer les données
            const data = {
                matiere,
                classe,
                date_cours: dateCours,
                heure_debut: heureDebut,
                heure_fin: heureFin,
                titre,
                contenu,
                documents,
                devoirs
            };
            
            // Déterminer la méthode et l'URL
            const method = id ? 'PUT' : 'POST';
            const url = id ? `${apiCahierTexte}/${id}` : apiCahierTexte;
            
            const response = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });
            
            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.error || 'Erreur lors de l\'enregistrement de la séance');
            }
            
            // Recharger le cahier de texte
            closeModal();
            loadCahierTexte();
            showNotification(id ? 'Séance modifiée avec succès' : 'Séance ajoutée avec succès');
            
        } catch (error) {
            console.error('Erreur:', error);
            showNotification(error.message, 'error');
        }
    }
    
    // Fonction pour supprimer une séance
    async function deleteSeance(seanceId) {
        if (!confirm('Êtes-vous sûr de vouloir supprimer cette séance ?')) return;
        
        try {
            const response = await fetch(`${apiCahierTexte}/${seanceId}`, {
                method: 'DELETE'
            });
            
            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.error || 'Erreur lors de la suppression de la séance');
            }
            
            // Recharger le cahier de texte
            loadCahierTexte();
            showNotification('Séance supprimée avec succès');
            
        } catch (error) {
            console.error('Erreur:', error);
            showNotification('Erreur lors de la suppression de la séance', 'error');
        }
    }
    
    // Fonction pour filtrer le cahier de texte
    function filterCahierTexte() {
        const matiere = filtreMatiere.value;
        const classe = filtreClasse.value;
        const periode = filtrePeriode.value;
        const date = filtreDate.value;
        
        let params = '?';
        if (matiere) params += `matiere=${encodeURIComponent(matiere)}&`;
        if (classe) params += `classe=${encodeURIComponent(classe)}&`;
        
        // Gestion de la période
        if (periode === 'custom' && date) {
            params += `date_cours=${encodeURIComponent(date)}&`;
        } else {
            // Calculer la date de début en fonction de la période
            const today = new Date();
            let startDate;
            
            switch (periode) {
                case 'semaine':
                    // Début de la semaine (lundi)
                    startDate = new Date(today);
                    startDate.setDate(today.getDate() - today.getDay() + 1); // Lundi = 1
                    params += `date_debut=${startDate.toISOString().split('T')[0]}&`;
                    break;
                    
                case 'mois':
                    // Début du mois
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                    params += `date_debut=${startDate.toISOString().split('T')[0]}&`;
                    break;
                    
                case 'trimestre':
                    // Début du trimestre scolaire (approximatif)
                    const month = today.getMonth();
                    if (month < 3) startDate = new Date(today.getFullYear() - 1, 8, 1); // Septembre de l'année précédente
                    else if (month < 6) startDate = new Date(today.getFullYear(), 0, 1); // Janvier
                    else if (month < 8) startDate = new Date(today.getFullYear(), 3, 1); // Avril
                    else startDate = new Date(today.getFullYear(), 8, 1); // Septembre
                    
                    params += `date_debut=${startDate.toISOString().split('T')[0]}&`;
                    break;
            }
        }
        
        // Supprimer le dernier '&' ou '?' si présent
        params = params.replace(/[&?]$/, '');
        
        // Si params contient uniquement '?', on le met à vide
        if (params === '?') params = '';
        
        loadCahierTexte(params);
    }
    
    // Fonction pour réinitialiser les filtres
    function resetFilters() {
        filtreMatiere.value = '';
        filtreClasse.value = '';
        filtrePeriode.value = 'semaine';
        filtreDate.value = '';
        dateFilterContainer.style.display = 'none';
        loadCahierTexte();
    }
    
    // Changer entre les vues liste et semaine
    function switchToListView() {
        listViewBtn.classList.add('active');
        weekViewBtn.classList.remove('active');
        listView.style.display = 'block';
        weekView.style.display = 'none';
        currentView = 'list';
    }
    
    function switchToWeekView() {
        listViewBtn.classList.remove('active');
        weekViewBtn.classList.add('active');
        listView.style.display = 'none';
        weekView.style.display = 'block';
        currentView = 'week';
    }
    
    // Naviguer vers la page des devoirs
    function navigateToDevoirs() {
        window.location.href = '/devoirs.html';
    }
    
    // Afficher/masquer le filtre de date personnalisée
    function toggleDateFilter() {
        if (filtrePeriode.value === 'custom') {
            dateFilterContainer.style.display = 'block';
        } else {
            dateFilterContainer.style.display = 'none';
        }
    }
    
    // Initialisation
    fetchUserProfile();
    loadMatieres();
    loadClasses();
    
    // Écouteurs d'événements
    
    // Changer de vue
    listViewBtn.addEventListener('click', switchToListView);
    weekViewBtn.addEventListener('click', switchToWeekView);
    
    // Filtrer
    filtrePeriode.addEventListener('change', toggleDateFilter);
    btnFiltrer.addEventListener('click', filterCahierTexte);
    btnReinitialiser.addEventListener('click', resetFilters);
    
    // Navigation entre onglets
    tabDevoirs.addEventListener('click', navigateToDevoirs);
    
    // Modals
    btnAjouterSeance.addEventListener('click', openAddModal);
    btnAnnuler.addEventListener('click', closeModal);
    modalClose.addEventListener('click', closeModal);
    btnEnregistrer.addEventListener('click', saveSeance);
    
    // Chargement dynamique des devoirs quand on change la classe ou la matière
    form.querySelector('[name="classe"]').addEventListener('change', () => loadDevoirsForForm());
    form.querySelector('[name="matiere"]').addEventListener('change', () => loadDevoirsForForm());
    
    // Empêcher la soumission directe du formulaire
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        saveSeance();
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
            deleteSeance(button.dataset.id);
        }
    });
});