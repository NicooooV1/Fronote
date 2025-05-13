<?php
// devoirs.php - Page principale de gestion des devoirs
require_once 'config.php';

// Vérification de l'authentification
if (!isAuthenticated()) {
    redirect('/login/index.php', 'Veuillez vous connecter pour accéder à cette page', 'error');
}

// Récupération des matières et classes pour les filtres et le formulaire
$etablissementData = getEtablissementData();
$matieres = $etablissementData['matieres'] ?? [];
$classes = $etablissementData['classes'] ?? [];

// Variables pour la page
$pageTitle = 'Travail à faire';
$isTeacher = isTeacher();
$userProfile = getUserProfile();

// Constantes pour le JavaScript
define('INCLUDED', true);
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Contenu principal -->
<main class="pronote-main">
    <div class="pronote-page-title">
        <span>Cahier de texte - Travail à faire</span>
        <div class="pronote-view-toggle">
            <button class="pronote-view-btn active" id="list-view-btn">
                <i class="fas fa-list"></i>
            </button>
            <button class="pronote-view-btn" id="calendar-view-btn">
                <i class="fas fa-calendar-alt"></i>
            </button>
        </div>
    </div>

    <!-- Onglets -->
    <div class="pronote-tabs">
        <a href="/cahier_texte.php" class="pronote-tab">Contenu des séances</a>
        <a href="/devoirs.php" class="pronote-tab active">Travail à faire</a>
    </div>

    <!-- Filtres -->
    <div class="pronote-filters">
        <div class="pronote-filter-group">
            <label class="pronote-filter-label">Matière</label>
            <select class="pronote-filter-select" id="filtre-matiere">
                <option value="">Toutes matières</option>
                <?php foreach ($matieres as $matiere): ?>
                <option value="<?= htmlspecialchars($matiere['nom']) ?>"><?= htmlspecialchars($matiere['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="pronote-filter-group">
            <label class="pronote-filter-label">Classe</label>
            <select class="pronote-filter-select" id="filtre-classe">
                <option value="">Toutes classes</option>
                <?php foreach ($classes as $niveau => $niveauData): ?>
                    <?php foreach ($niveauData as $cycle => $classesList): ?>
                        <?php foreach ($classesList as $classe): ?>
                        <option value="<?= htmlspecialchars($classe) ?>"><?= htmlspecialchars($classe) ?></option>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="pronote-filter-group">
            <label class="pronote-filter-label">État</label>
            <select class="pronote-filter-select" id="filtre-etat">
                <option value="">Tous</option>
                <option value="non_fait">À faire</option>
                <option value="en_cours">En cours</option>
                <option value="termine">Terminé</option>
            </select>
        </div>

        <div class="pronote-filter-group">
            <label class="pronote-filter-label">Date de remise</label>
            <input type="date" class="pronote-filter-input" id="filtre-date">
        </div>

        <div class="pronote-filter-actions">
            <button class="pronote-btn pronote-btn-primary" id="btn-filtrer">
                <i class="fas fa-filter"></i>
                <span>Filtrer</span>
            </button>
            <button class="pronote-btn pronote-btn-secondary" id="btn-reinitialiser">
                <i class="fas fa-undo"></i>
                <span>Réinitialiser</span>
            </button>
        </div>
    </div>

    <!-- Vue liste -->
    <div id="list-view" class="pronote-table-container">
        <table class="pronote-table">
            <thead>
                <tr>
                    <th>Matière</th>
                    <th>Titre</th>
                    <th>Classe</th>
                    <th>Date de remise</th>
                    <?php if ($userProfile === 'eleve'): ?>
                    <th>Statut</th>
                    <?php endif; ?>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="devoirs-list">
                <tr>
                    <td colspan="6">Chargement...</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Vue calendrier (cachée par défaut) -->
    <div id="calendar-view" style="display: none;">
        <div class="pronote-calendar-header">
            <div class="pronote-calendar-day">Lundi</div>
            <div class="pronote-calendar-day">Mardi</div>
            <div class="pronote-calendar-day">Mercredi</div>
            <div class="pronote-calendar-day">Jeudi</div>
            <div class="pronote-calendar-day">Vendredi</div>
            <div class="pronote-calendar-day">Samedi</div>
            <div class="pronote-calendar-day">Dimanche</div>
        </div>
        <div class="pronote-calendar" id="calendar-container">
            <!-- Rempli dynamiquement par JavaScript -->
        </div>
    </div>

    <?php if ($isTeacher): ?>
    <!-- Bouton d'ajout de devoir (pour enseignants) -->
    <div style="position: fixed; bottom: 30px; right: 30px;">
        <button class="pronote-btn pronote-btn-primary" id="btn-ajouter-devoir" style="border-radius: 50%; width: 56px; height: 56px; box-shadow: 0 3px 10px rgba(0,0,0,0.2);">
            <i class="fas fa-plus" style="font-size: 20px;"></i>
        </button>
    </div>
    <?php endif; ?>
</main>

<!-- Modal d'ajout/modification de devoir -->
<?php if ($isTeacher): ?>
<div class="pronote-modal-backdrop" id="modal-devoir" style="display: none;">
    <div class="pronote-modal">
        <div class="pronote-modal-header">
            <div class="pronote-modal-title" id="modal-title">Ajouter un devoir</div>
            <button class="pronote-modal-close" id="modal-close">&times;</button>
        </div>
        <div class="pronote-modal-body">
            <form class="pronote-form" id="form-devoir" enctype="multipart/form-data">
                <input type="hidden" name="id" id="devoir-id">

                <div class="pronote-form-group">
                    <label class="pronote-form-label pronote-form-required" for="titre">Titre</label>
                    <input type="text" class="pronote-form-input" id="titre" name="titre" required>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label pronote-form-required" for="matiere">Matière</label>
                    <select class="pronote-form-select" id="matiere" name="matiere" required>
                        <option value="">Sélectionnez une matière</option>
                        <?php foreach ($matieres as $matiere): ?>
                        <option value="<?= htmlspecialchars($matiere['nom']) ?>"><?= htmlspecialchars($matiere['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label pronote-form-required" for="classe">Classe</label>
                    <select class="pronote-form-select" id="classe" name="classe" required>
                        <option value="">Sélectionnez une classe</option>
                        <?php foreach ($classes as $niveau => $niveauData): ?>
                            <?php foreach ($niveauData as $cycle => $classesList): ?>
                                <?php foreach ($classesList as $classe): ?>
                                <option value="<?= htmlspecialchars($classe) ?>"><?= htmlspecialchars($classe) ?></option>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label pronote-form-required" for="description">Description</label>
                    <textarea class="pronote-form-textarea" id="description" name="description" required></textarea>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label pronote-form-required" for="date_remise">Date de remise</label>
                    <input type="datetime-local" class="pronote-form-input" id="date_remise" name="date_remise" required>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label pronote-form-required" for="fichier_sujet">Fichier sujet</label>
                    <input type="file" class="pronote-form-input" id="fichier_sujet" name="fichier_sujet" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx">
                    <div class="pronote-form-hint">Formats acceptés: PDF, images, documents Word. Taille max: 5 Mo</div>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label" for="fichier_corrige">Fichier corrigé</label>
                    <input type="file" class="pronote-form-input" id="fichier_corrige" name="fichier_corrige" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx">
                    <div class="pronote-form-hint">Facultatif. Publié seulement après la date de remise.</div>
                </div>
            </form>
        </div>
        <div class="pronote-modal-footer">
            <button class="pronote-btn pronote-btn-secondary" id="btn-annuler">Annuler</button>
            <button class="pronote-btn pronote-btn-primary" id="btn-enregistrer">Enregistrer</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Éléments DOM
    const apiDevoirs = '/api/devoirs';
    const apiDevoirStatus = '/api/devoir_status';
    const listContainer = document.getElementById('devoirs-list');
    const calendarContainer = document.getElementById('calendar-container');
    const listViewBtn = document.getElementById('list-view-btn');
    const calendarViewBtn = document.getElementById('calendar-view-btn');
    const listView = document.getElementById('list-view');
    const calendarView = document.getElementById('calendar-view');
    
    // Filtres
    const filtreMatiere = document.getElementById('filtre-matiere');
    const filtreClasse = document.getElementById('filtre-classe');
    const filtreEtat = document.getElementById('filtre-etat');
    const filtreDate = document.getElementById('filtre-date');
    const btnFiltrer = document.getElementById('btn-filtrer');
    const btnReinitialiser = document.getElementById('btn-reinitialiser');
    
    // Variables d'état
    const userProfile = '<?= $userProfile ?>';
    const isTeacher = <?= $isTeacher ? 'true' : 'false' ?>;
    let currentView = 'list';
    
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
                    weekday: 'long', 
                    year: 'numeric', 
                    month: 'long', 
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                };
                const formattedDate = dateRemise.toLocaleDateString('fr-FR', options);
                
                // Déterminer si la date est dépassée
                const isOverdue = new Date() > dateRemise;
                const dateClass = isOverdue ? 'overdue' : '';
                
                // Récupérer le statut du devoir si l'utilisateur est un élève
                let statusHTML = '';
                let statusCell = '';
                
                if (userProfile === 'eleve') {
                    try {
                        const statusResponse = await fetch(`${apiDevoirStatus}?id_devoir=${devoir.id}`);
                        const statusData = await statusResponse.json();
                        
                        let status = 'non_fait'; // Par défaut
                        
                        if (statusData.length > 0) {
                            status = statusData[0].status;
                        }
                        
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
                        
                        statusCell = `<td>${statusHTML}</td>`;
                    } catch (error) {
                        console.error('Erreur de récupération du statut:', error);
                        statusCell = '<td><span class="pronote-status pronote-status-todo">À faire</span></td>';
                    }
                } else {
                    // Pour les professeurs, pas de colonne statut
                    statusHTML = '';
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
                let html = `
                    <td>${devoir.matiere}</td>
                    <td>${devoir.titre}</td>
                    <td>${devoir.classe}</td>
                    <td class="${dateClass}">${formattedDate}</td>
                `;
                
                // Ajouter la colonne status pour les élèves
                if (userProfile === 'eleve') {
                    html += statusCell;
                }
                
                html += `<td>${actionsHTML}</td>`;
                
                tr.innerHTML = html;
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
        // Vider le calendrier
        calendarContainer.innerHTML = '';
        
        // Déterminer la semaine actuelle (lundi à dimanche)
        const today = new Date();
        const currentDay = today.getDay(); // 0 = dimanche, 1 = lundi, etc.
        const diff = currentDay === 0 ? 6 : currentDay - 1; // Ajustement pour commencer le lundi
        
        const monday = new Date(today);
        monday.setDate(today.getDate() - diff);
        
        // Générer les cases du calendrier pour un mois
        const daysInMonth = new Date(today.getFullYear(), today.getMonth() + 1, 0).getDate();
        const firstDayOfMonth = new Date(today.getFullYear(), today.getMonth(), 1).getDay();
        const adjustedFirstDay = firstDayOfMonth === 0 ? 7 : firstDayOfMonth; // Ajuster pour commencer le lundi
        
        // Ajouter les jours du mois précédent si nécessaire
        const prevMonthDays = adjustedFirstDay - 1;
        const prevMonth = new Date(today.getFullYear(), today.getMonth(), 0);
        const daysInPrevMonth = prevMonth.getDate();
        
        for (let i = 0; i < prevMonthDays; i++) {
            const dayNumber = daysInPrevMonth - prevMonthDays + i + 1;
            const dateDiv = createCalendarDate(dayNumber, true);
            calendarContainer.appendChild(dateDiv);
        }
        
        // Ajouter les jours du mois courant
        for (let i = 1; i <= daysInMonth; i++) {
            const date = new Date(today.getFullYear(), today.getMonth(), i);
            const isToday = i === today.getDate();
            const dateDiv = createCalendarDate(i, false, isToday);
            
            // Ajouter les devoirs pour cette date
            const devoirsForDay = devoirs.filter(d => {
                const devoirDate = new Date(d.date_remise);
                return devoirDate.getDate() === i && 
                       devoirDate.getMonth() === today.getMonth() && 
                       devoirDate.getFullYear() === today.getFullYear();
            });
            
            devoirsForDay.forEach(devoir => {
                const devoirDiv = document.createElement('div');
                devoirDiv.className = 'pronote-calendar-event';
                devoirDiv.dataset.id = devoir.id;
                
                const devoirDate = new Date(devoir.date_remise);
                const time = devoirDate.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
                
                devoirDiv.innerHTML = `
                    <div style="font-weight: 500;">${devoir.matiere}</div>
                    <div style="font-size: 12px;">${devoir.titre} (${time})</div>
                `;
                
                dateDiv.appendChild(devoirDiv);
            });
            
            calendarContainer.appendChild(dateDiv);
        }
        
        // Compléter avec les jours du mois suivant si nécessaire
        const totalDaysAdded = prevMonthDays + daysInMonth;
        const remainingDays = 42 - totalDaysAdded; // 6 semaines complètes (6x7=42)
        
        for (let i = 1; i <= remainingDays; i++) {
            const dateDiv = createCalendarDate(i, true);
            calendarContainer.appendChild(dateDiv);
        }
    }
    
    function createCalendarDate(day, isOtherMonth, isToday = false) {
        const dateDiv = document.createElement('div');
        dateDiv.className = 'pronote-calendar-date';
        
        if (isOtherMonth) {
            dateDiv.classList.add('other-month');
            dateDiv.style.opacity = '0.5';
        }
        
        if (isToday) {
            dateDiv.classList.add('today');
        }
        
        const dateHeader = document.createElement('div');
        dateHeader.className = 'pronote-calendar-date-header';
        dateHeader.textContent = day;
        
        dateDiv.appendChild(dateHeader);
        return dateDiv;
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
    
    // Ouvrir modal création
    if (isTeacher) {
        document.getElementById('btn-ajouter-devoir').addEventListener('click', () => {
            const form = document.getElementById('form-devoir');
            form.reset();
            document.getElementById('devoir-id').value = '';
            document.getElementById('modal-title').textContent = 'Ajouter un devoir';
            document.getElementById('fichier_sujet').required = true;
            document.getElementById('modal-devoir').style.display = 'flex';
        });
        
        // Enregistrer devoir
        document.getElementById('btn-enregistrer').addEventListener('click', async () => {
            const form = document.getElementById('form-devoir');
            const formData = new FormData(form);
            const id = formData.get('id');
            
            // Validation de base côté client
            const titre = formData.get('titre');
            const matiere = formData.get('matiere');
            const classe = formData.get('classe');
            const dateRemise = formData.get('date_remise');
            const description = formData.get('description');
            
            if (!titre || !matiere || !classe || !dateRemise || !description) {
                showNotification('Veuillez remplir tous les champs obligatoires', 'error');
                return;
            }
            
            // En mode création, vérifier que le fichier sujet est fourni
            if (!id && (!formData.get('fichier_sujet') || formData.get('fichier_sujet').size === 0)) {
                showNotification('Le fichier sujet est obligatoire', 'error');
                return;
            }
            
            try {
                const url = id ? `${apiDevoirs}/${id}` : apiDevoirs;
                const method = id ? 'PUT' : 'POST';
                
                const response = await fetch(url, {
                    method,
                    body: formData
                });
                
                if (!response.ok) {
                    const errorData = await response.json();
                    throw new Error(errorData.error || 'Erreur lors de l\'enregistrement du devoir');
                }
                
                document.getElementById('modal-devoir').style.display = 'none';
                loadDevoirs();
                showNotification(id ? 'Devoir modifié avec succès' : 'Devoir ajouté avec succès');
                
            } catch (error) {
                console.error('Erreur:', error);
                showNotification(error.message, 'error');
            }
        });
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
    
    // Filtrer les devoirs
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
    
    // Initialisation
    loadDevoirs();
    
    // Événements
    
    // Changer de vue
    listViewBtn.addEventListener('click', switchToListView);
    calendarViewBtn.addEventListener('click', switchToCalendarView);
    
    // Filtrer
    btnFiltrer.addEventListener('click', filterDevoirs);
    btnReinitialiser.addEventListener('click', resetFilters);
    
    // Délégation d'événements pour les boutons d'action
    document.addEventListener('click', async (e) => {
        // Bouton de modification
        if (e.target.closest('.btn-edit')) {
            const button = e.target.closest('.btn-edit');
            const id = button.dataset.id;
            
            try {
                const response = await fetch(`${apiDevoirs}/${id}`);
                if (!response.ok) throw new Error('Erreur lors de la récupération du devoir');
                
                const devoir = await response.json();
                
                // Remplir le formulaire avec les données du devoir
                const form = document.getElementById('form-devoir');
                form.reset();
                
                document.getElementById('devoir-id').value = devoir.id;
                document.getElementById('titre').value = devoir.titre;
                document.getElementById('matiere').value = devoir.matiere;
                document.getElementById('classe').value = devoir.classe;
                document.getElementById('description').value = devoir.description || '';
                
                // Formater la date et l'heure pour le champ datetime-local
                const dateRemise = new Date(devoir.date_remise);
                const dateStr = dateRemise.toISOString().slice(0, 16); // Format "YYYY-MM-DDTHH:MM"
                document.getElementById('date_remise').value = dateStr;
                
                // Le fichier sujet n'est pas obligatoire en mode édition
                document.getElementById('fichier_sujet').required = false;
                
                document.getElementById('modal-title').textContent = 'Modifier un devoir';
                document.getElementById('modal-devoir').style.display = 'flex';
                
            } catch (error) {
                console.error('Erreur:', error);
                showNotification('Erreur lors de la récupération du devoir', 'error');
            }
        }
        
        // Bouton de suppression
        if (e.target.closest('.btn-delete')) {
            const button = e.target.closest('.btn-delete');
            const id = button.dataset.id;
            
            if (confirm('Êtes-vous sûr de vouloir supprimer ce devoir ?')) {
                try {
                    const response = await fetch(`${apiDevoirs}/${id}`, {
                        method: 'DELETE'
                    });
                    
                    if (!response.ok) {
                        const errorData = await response.json();
                        throw new Error(errorData.error || 'Erreur lors de la suppression');
                    }
                    
                    loadDevoirs();
                    showNotification('Devoir supprimé avec succès');
                } catch (error) {
                    console.error('Erreur:', error);
                    showNotification('Erreur lors de la suppression du devoir', 'error');
                }
            }
        }
        
        // Boutons de statut
        if (e.target.closest('.btn-status-update')) {
            const button = e.target.closest('.btn-status-update');
            const id = button.dataset.id;
            const status = button.dataset.status;
            updateDevoirStatus(id, status);
        }
        
        // Événements pour la vue calendrier
        if (e.target.closest('.pronote-calendar-event')) {
            const event = e.target.closest('.pronote-calendar-event');
            const id = event.dataset.id;
            
            try {
                const response = await fetch(`${apiDevoirs}/${id}`);
                if (!response.ok) throw new Error('Erreur lors de la récupération du devoir');
                
                const devoir = await response.json();
                
                // Afficher les détails du devoir dans une alerte formatée
                const dateRemise = new Date(devoir.date_remise);
                const formattedDate = dateRemise.toLocaleDateString('fr-FR', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                alert(`Titre: ${devoir.titre}\nMatière: ${devoir.matiere}\nClasse: ${devoir.classe}\nDate de remise: ${formattedDate}\n\nDescription: ${devoir.description || 'Aucune description'}`);
                
            } catch (error) {
                console.error('Erreur:', error);
                showNotification('Erreur lors de la récupération du devoir', 'error');
            }
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>