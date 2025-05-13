<?php
// cahier_texte.php - Page principale du cahier de texte
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
$pageTitle = 'Cahier de texte';
$isTeacher = isTeacher();
$userProfile = getUserProfile();

// Constante pour les includes
define('INCLUDED', true);
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Contenu principal -->
<main class="pronote-main">
    <div class="pronote-page-title">
        <span>Cahier de texte - Contenu des séances</span>
        <div class="pronote-view-toggle">
            <button class="pronote-view-btn active" id="list-view-btn">
                <i class="fas fa-list"></i>
            </button>
            <button class="pronote-view-btn" id="week-view-btn">
                <i class="fas fa-calendar-week"></i>
            </button>
        </div>
    </div>

    <!-- Onglets -->
    <div class="pronote-tabs">
        <a href="/cahier_texte.php" class="pronote-tab active">Contenu des séances</a>
        <a href="/devoirs.php" class="pronote-tab">Travail à faire</a>
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
            <label class="pronote-filter-label">Période</label>
            <select class="pronote-filter-select" id="filtre-periode">
                <option value="semaine">Cette semaine</option>
                <option value="mois">Ce mois</option>
                <option value="trimestre">Ce trimestre</option>
                <option value="custom">Personnalisée</option>
            </select>
        </div>

        <div class="pronote-filter-group" id="date-filter-container" style="display: none;">
            <label class="pronote-filter-label">Date</label>
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
    <div id="list-view">
        <!-- Séances chargées dynamiquement -->
        <div class="loading">Chargement des séances...</div>
    </div>

    <!-- Vue semaine (cachée par défaut) -->
    <div id="week-view" style="display: none;">
        <div class="pronote-week-view">
            <!-- Colonne des horaires -->
            <div class="pronote-time-slots">
                <div style="height: 50px;"></div> <!-- Espace pour l'en-tête des jours -->
                <div class="pronote-time-slot">8h-9h</div>
                <div class="pronote-time-slot">9h-10h</div>
                <div class="pronote-time-slot">10h-11h</div>
                <div class="pronote-time-slot">11h-12h</div>
                <div class="pronote-time-slot">13h-14h</div>
                <div class="pronote-time-slot">14h-15h</div>
                <div class="pronote-time-slot">15h-16h</div>
                <div class="pronote-time-slot">16h-17h</div>
                <div class="pronote-time-slot">17h-18h</div>
            </div>

            <!-- Jours de la semaine (remplis dynamiquement) -->
            <div id="week-container">
                <!-- Colonnes des jours chargées dynamiquement -->
            </div>
        </div>
    </div>

    <?php if ($isTeacher): ?>
    <!-- Bouton d'ajout de séance (pour enseignants) -->
    <div style="position: fixed; bottom: 30px; right: 30px;">
        <button class="pronote-btn pronote-btn-primary" id="btn-ajouter-seance" style="border-radius: 50%; width: 56px; height: 56px; box-shadow: 0 3px 10px rgba(0,0,0,0.2);">
            <i class="fas fa-plus" style="font-size: 20px;"></i>
        </button>
    </div>
    <?php endif; ?>
</main>

<?php if ($isTeacher): ?>
<!-- Modal d'ajout/modification de séance -->
<div class="pronote-modal-backdrop" id="modal-seance" style="display: none;">
    <div class="pronote-modal">
        <div class="pronote-modal-header">
            <div class="pronote-modal-title" id="modal-title">Ajouter une séance</div>
            <button class="pronote-modal-close" id="modal-close">&times;</button>
        </div>
        <div class="pronote-modal-body">
            <form class="pronote-form" id="form-seance">
                <input type="hidden" name="id" id="seance-id">

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
                    <label class="pronote-form-label pronote-form-required" for="date_cours">Date du cours</label>
                    <input type="date" class="pronote-form-input" id="date_cours" name="date_cours" required>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label" for="horaire">Horaire</label>
                    <div style="display: flex; gap: 10px;">
                        <input type="time" class="pronote-form-input" id="heure_debut" name="heure_debut" placeholder="Début">
                        <input type="time" class="pronote-form-input" id="heure_fin" name="heure_fin" placeholder="Fin">
                    </div>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label pronote-form-required" for="titre">Titre de la séance</label>
                    <input type="text" class="pronote-form-input" id="titre" name="titre" required>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label pronote-form-required" for="contenu">Contenu de la séance</label>
                    <textarea class="pronote-form-textarea" id="contenu" name="contenu" required></textarea>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label" for="documents">Documents joints</label>
                    <input type="file" class="pronote-form-input" id="documents" name="documents[]" multiple>
                    <div class="pronote-form-hint">Formats acceptés: PDF, Word, PowerPoint, images. Taille max: 10 Mo</div>
                </div>

                <div class="pronote-form-group">
                    <label class="pronote-form-label">Devoirs associés</label>
                    <div id="devoirs-container">
                        <!-- Liste des devoirs chargée dynamiquement -->
                        <div class="loading">Sélectionnez une classe et une matière pour voir les devoirs disponibles</div>
                    </div>
                    <div class="pronote-form-hint">Sélectionnez les devoirs associés à cette séance.</div>
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
    const apiCahierTexte = '/api/cahier_texte';
    const apiDevoirs = '/api/devoirs';
    const listView = document.getElementById('list-view');
    const weekView = document.getElementById('week-view');
    const listViewBtn = document.getElementById('list-view-btn');
    const weekViewBtn = document.getElementById('week-view-btn');
    const weekContainer = document.getElementById('week-container');
    
    // Filtres
    const filtreMatiere = document.getElementById('filtre-matiere');
    const filtreClasse = document.getElementById('filtre-classe');
    const filtrePeriode = document.getElementById('filtre-periode');
    const filtreDate = document.getElementById('filtre-date');
    const dateFilterContainer = document.getElementById('date-filter-container');
    const btnFiltrer = document.getElementById('btn-filtrer');
    const btnReinitialiser = document.getElementById('btn-reinitialiser');
    
    // Variables d'état
    const userProfile = '<?= $userProfile ?>';
    const isTeacher = <?= $isTeacher ? 'true' : 'false' ?>;
    let currentView = 'list';
    
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
                    
                    const docs = Array.isArray(seance.documents) 
                        ? seance.documents 
                        : seance.documents.split(',').map(doc => doc.trim());
                    
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
                if (seance.id) {
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
                                            <label for="hw-${devoir.id}">${devoir.titre} (à rendre le ${formattedRemiseDate})</label>
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
        // Vider le conteneur
        weekContainer.innerHTML = '';
        
        // Déterminer la semaine actuelle (lundi à vendredi)
        const today = new Date();
        const currentDay = today.getDay(); // 0 = dimanche, 1 = lundi, etc.
        const diff = currentDay === 0 ? 6 : currentDay - 1; // Ajustement pour commencer le lundi
        
        const monday = new Date(today);
        monday.setDate(today.getDate() - diff);
        
        // Créer une colonne pour chaque jour de la semaine (lundi à vendredi)
        for (let i = 0; i < 5; i++) {
            const currentDate = new Date(monday);
            currentDate.setDate(monday.getDate() + i);
            
            const dayColumn = document.createElement('div');
            dayColumn.className = 'pronote-day-column';
            
            // Formater le jour pour l'en-tête
            const dayName = currentDate.toLocaleDateString('fr-FR', { weekday: 'long' });
            const dayNumber = currentDate.getDate();
            const monthName = currentDate.toLocaleDateString('fr-FR', { month: 'long' });
            
            dayColumn.innerHTML = `
                <div class="pronote-day-header">${dayName} ${dayNumber} ${monthName}</div>
            `;
            
            // Ajouter des espaces vides pour représenter les créneaux horaires
            // On commence par des espaces vides, puis on remplace par des séances si nécessaire
            const timeSlots = {
                '8h-9h': document.createElement('div'),
                '9h-10h': document.createElement('div'),
                '10h-11h': document.createElement('div'),
                '11h-12h': document.createElement('div'),
                '13h-14h': document.createElement('div'),
                '14h-15h': document.createElement('div'),
                '15h-16h': document.createElement('div'),
                '16h-17h': document.createElement('div'),
                '17h-18h': document.createElement('div')
            };
            
            // Initialiser les créneaux vides
            Object.values(timeSlots).forEach(slot => {
                slot.style.height = '60px';
                dayColumn.appendChild(slot);
            });
            
            // Chercher les séances pour ce jour
            seances.forEach(seance => {
                const seanceDate = new Date(seance.date_cours);
                
                // Vérifier si la séance est le même jour
                if (seanceDate.getDate() === currentDate.getDate() && 
                    seanceDate.getMonth() === currentDate.getMonth() && 
                    seanceDate.getFullYear() === currentDate.getFullYear()) {
                    
                    // Déterminer le créneau horaire
                    if (seance.heure_debut && seance.heure_fin) {
                        const startHour = parseInt(seance.heure_debut.split(':')[0]);
                        const endHour = parseInt(seance.heure_fin.split(':')[0]);
                        
                        // Calculer la hauteur du bloc en fonction de la durée
                        const duration = endHour - startHour;
                        const height = duration * 60;
                        
                        // Créer le bloc de séance
                        const classBlock = document.createElement('div');
                        classBlock.className = 'pronote-class-block';
                        classBlock.style.height = `${height}px`;
                        classBlock.innerHTML = `
                            <div class="pronote-class-name">${seance.matiere}</div>
                            <div class="pronote-class-time">${seance.heure_debut}-${seance.heure_fin}</div>
                            <div style="margin-top: 5px; font-size: 12px;">${seance.titre || ''}</div>
                        `;
                        
                        // Remplacer le créneau vide par ce bloc
                        const slotKey = `${startHour}h-${startHour+1}h`;
                        if (timeSlots[slotKey]) {
                            const parentNode = timeSlots[slotKey].parentNode;
                            const index = Array.from(parentNode.children).indexOf(timeSlots[slotKey]);
                            
                            if (index !== -1) {
                                parentNode.replaceChild(classBlock, timeSlots[slotKey]);
                                
                                // Supprimer les créneaux qui sont "couverts" par cette séance
                                for (let h = startHour + 1; h < endHour; h++) {
                                    const nextSlotKey = `${h}h-${h+1}h`;
                                    if (timeSlots[nextSlotKey] && timeSlots[nextSlotKey].parentNode) {
                                        timeSlots[nextSlotKey].parentNode.removeChild(timeSlots[nextSlotKey]);
                                    }
                                }
                            }
                        }
                    }
                }
            });
            
            weekContainer.appendChild(dayColumn);
        }
    }
    
    // Charger les devoirs associés pour le formulaire
    async function loadDevoirsForForm() {
        try {
            const classe = document.getElementById('classe').value;
            const matiere = document.getElementById('matiere').value;
            const devoirsContainer = document.getElementById('devoirs-container');
            
            // Si la classe ou la matière n'est pas sélectionnée, on ne charge pas les devoirs
            if (!classe || !matiere) {
                devoirsContainer.innerHTML = '<p>Sélectionnez une classe et une matière pour voir les devoirs disponibles.</p>';
                return;
            }
            
            devoirsContainer.innerHTML = '<div class="loading">Chargement des devoirs...</div>';
            
            // Récupérer tous les devoirs pour cette classe et cette matière
            const params = `?classe=${encodeURIComponent(classe)}&matiere=${encodeURIComponent(matiere)}`;
            const response = await fetch(apiDevoirs + params);
            
            if (!response.ok) throw new Error('Erreur lors du chargement des devoirs');
            
            const devoirs = await response.json();
            
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
                div.className = 'pronote-homework-item';
                
                div.innerHTML = `
                    <input type="checkbox" class="pronote-checkbox" id="devoir-${devoir.id}" value="${devoir.id}" name="devoirs[]">
                    <label for="devoir-${devoir.id}">${devoir.titre} (à rendre le ${formattedDate})</label>
                `;
                
                devoirsContainer.appendChild(div);
            });
            
            // Si on modifie une séance, cocher les devoirs associés
            const seanceId = document.getElementById('seance-id').value;
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
    
    // Ouvrir modal création
    if (isTeacher) {
        document.getElementById('btn-ajouter-seance').addEventListener('click', () => {
            const form = document.getElementById('form-seance');
            form.reset();
            document.getElementById('seance-id').value = '';
            document.getElementById('modal-title').textContent = 'Ajouter une séance';
            document.getElementById('modal-seance').style.display = 'flex';
            
            // Réinitialiser le conteneur des devoirs
            document.getElementById('devoirs-container').innerHTML = '<p>Sélectionnez une classe et une matière pour voir les devoirs disponibles.</p>';
        });
        
        // Enregistrer séance
        document.getElementById('btn-enregistrer').addEventListener('click', async () => {
            const form = document.getElementById('form-seance');
            const id = document.getElementById('seance-id').value;
            
            // Récupérer les données du formulaire
            const matiere = document.getElementById('matiere').value;
            const classe = document.getElementById('classe').value;
            const dateCours = document.getElementById('date_cours').value;
            const heureDebut = document.getElementById('heure_debut').value;
            const heureFin = document.getElementById('heure_fin').value;
            const titre = document.getElementById('titre').value;
            const contenu = document.getElementById('contenu').value;
            
            // Récupérer les documents sélectionnés (si l'API supporte l'upload de fichiers)
            const documentsInput = document.getElementById('documents');
            const documents = documentsInput.files;
            
            // Récupérer les devoirs sélectionnés
            const devoirs = [];
            document.querySelectorAll('[name="devoirs[]"]:checked').forEach(checkbox => {
                devoirs.push(checkbox.value);
            });
            
            // Valider les champs requis
            if (!matiere || !classe || !dateCours || !contenu || !titre) {
                showNotification('Veuillez remplir tous les champs obligatoires', 'error');
                return;
            }
            
            try {
                // Préparer les données - deux approches possibles selon l'API :
                
                // 1. Si l'API accepte les fichiers via multipart/form-data
                const formData = new FormData();
                formData.append('matiere', matiere);
                formData.append('classe', classe);
                formData.append('date_cours', dateCours);
                formData.append('heure_debut', heureDebut);
                formData.append('heure_fin', heureFin);
                formData.append('titre', titre);
                formData.append('contenu', contenu);
                
                // Ajouter les documents
                for (let i = 0; i < documents.length; i++) {
                    formData.append('documents[]', documents[i]);
                }
                
                // Ajouter les devoirs
                devoirs.forEach(devoirId => {
                    formData.append('devoirs[]', devoirId);
                });
                
                // 2. Ou si l'API accepte JSON
                const jsonData = {
                    matiere,
                    classe,
                    date_cours: dateCours,
                    heure_debut: heureDebut,
                    heure_fin: heureFin,
                    titre,
                    contenu,
                    devoirs
                };
                
                // Déterminer la méthode et l'URL
                const method = id ? 'PUT' : 'POST';
                const url = id ? `${apiCahierTexte}/${id}` : apiCahierTexte;
                
                // Utiliser l'approche adaptée selon votre API
                // Par défaut, utilisons l'approche JSON
                const response = await fetch(url, {
                    method,
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(jsonData)
                });
                
                if (!response.ok) {
                    const errorData = await response.json();
                    throw new Error(errorData.error || 'Erreur lors de l\'enregistrement de la séance');
                }
                
                // Fermer le modal et recharger les données
                document.getElementById('modal-seance').style.display = 'none';
                loadCahierTexte();
                showNotification(id ? 'Séance modifiée avec succès' : 'Séance ajoutée avec succès');
                
            } catch (error) {
                console.error('Erreur:', error);
                showNotification(error.message, 'error');
            }
        });
        
        // Chargement dynamique des devoirs quand on change la classe ou la matière
        document.getElementById('classe').addEventListener('change', loadDevoirsForForm);
        document.getElementById('matiere').addEventListener('change', loadDevoirsForForm);
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
    
    // Filtrer le cahier de texte
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
                    const diff = today.getDay() === 0 ? 6 : today.getDay() - 1;
                    startDate.setDate(today.getDate() - diff);
                    params += `date_debut=${formatDate(startDate)}&`;
                    break;
                    
                case 'mois':
                    // Début du mois
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                    params += `date_debut=${formatDate(startDate)}&`;
                    break;
                    
                case 'trimestre':
                    // Début du trimestre scolaire (approximatif)
                    const month = today.getMonth();
                    if (month < 3) startDate = new Date(today.getFullYear() - 1, 8, 1); // Septembre de l'année précédente
                    else if (month < 6) startDate = new Date(today.getFullYear(), 0, 1); // Janvier
                    else if (month < 8) startDate = new Date(today.getFullYear(), 3, 1); // Avril
                    else startDate = new Date(today.getFullYear(), 8, 1); // Septembre
                    
                    params += `date_debut=${formatDate(startDate)}&`;
                    break;
            }
        }
        
        // Formater la date en YYYY-MM-DD
        function formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
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
    
    // Afficher/masquer le filtre de date personnalisée
    function toggleDateFilter() {
        if (filtrePeriode.value === 'custom') {
            dateFilterContainer.style.display = 'block';
        } else {
            dateFilterContainer.style.display = 'none';
        }
    }
    
    // Initialisation
    loadCahierTexte();
    
    // Événements
    
    // Changer de vue
    listViewBtn.addEventListener('click', switchToListView);
    weekViewBtn.addEventListener('click', switchToWeekView);
    
    // Filtrer
    filtrePeriode.addEventListener('change', toggleDateFilter);
    btnFiltrer.addEventListener('click', filterCahierTexte);
    btnReinitialiser.addEventListener('click', resetFilters);
    
    // Délégation d'événements pour les boutons d'action
    document.addEventListener('click', async (e) => {
        // Bouton de modification
        if (e.target.closest('.btn-edit')) {
            const button = e.target.closest('.btn-edit');
            const id = button.dataset.id;
            
            try {
                const response = await fetch(`${apiCahierTexte}/${id}`);
                if (!response.ok) throw new Error('Erreur lors de la récupération de la séance');
                
                const seance = await response.json();
                
                // Remplir le formulaire avec les données de la séance
                const form = document.getElementById('form-seance');
                form.reset();
                
                document.getElementById('seance-id').value = seance.id;
                document.getElementById('matiere').value = seance.matiere;
                document.getElementById('classe').value = seance.classe;
                
                // Formater la date pour le champ date
                const dateCours = new Date(seance.date_cours);
                const dateStr = dateCours.toISOString().split('T')[0]; // Format "YYYY-MM-DD"
                document.getElementById('date_cours').value = dateStr;
                
                // Remplir les horaires si disponibles
                if (seance.heure_debut) document.getElementById('heure_debut').value = seance.heure_debut;
                if (seance.heure_fin) document.getElementById('heure_fin').value = seance.heure_fin;
                
                document.getElementById('titre').value = seance.titre || '';
                document.getElementById('contenu').value = seance.contenu;
                
                // Charger les devoirs associés
                loadDevoirsForForm();
                
                document.getElementById('modal-title').textContent = 'Modifier une séance';
                document.getElementById('modal-seance').style.display = 'flex';
                
            } catch (error) {
                console.error('Erreur:', error);
                showNotification('Erreur lors de la récupération de la séance', 'error');
            }
        }
        
        // Bouton de suppression
        if (e.target.closest('.btn-delete')) {
            const button = e.target.closest('.btn-delete');
            const id = button.dataset.id;
            
            if (confirm('Êtes-vous sûr de vouloir supprimer cette séance ?')) {
                try {
                    const response = await fetch(`${apiCahierTexte}/${id}`, {
                        method: 'DELETE'
                    });
                    
                    if (!response.ok) {
                        const errorData = await response.json();
                        throw new Error(errorData.error || 'Erreur lors de la suppression');
                    }
                    
                    loadCahierTexte();
                    showNotification('Séance supprimée avec succès');
                } catch (error) {
                    console.error('Erreur:', error);
                    showNotification('Erreur lors de la suppression de la séance', 'error');
                }
            }
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>