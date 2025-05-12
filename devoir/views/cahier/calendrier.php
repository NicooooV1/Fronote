<?php
/**
 * Vue pour afficher le calendrier du cahier de texte
 */

// Définir le titre de la page et les fichiers CSS/JS supplémentaires
$pageTitle = "Calendrier - Cahier de Texte";
$extraCss = ["cahier.css"];
$extraJs = ["cahier.js"];
$currentPage = "cahier";
$includeCalendar = true;

// Inclure l'en-tête
require_once ROOT_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h1>Cahier de Texte</h1>
    
    <?php if ($_SESSION['user_type'] === TYPE_PROFESSEUR || $_SESSION['is_admin']): ?>
        <div class="page-actions">
            <a href="<?php echo BASE_URL; ?>/cahier/creer.php" class="btn btn-primary">
                <i class="material-icons">add</i> Créer une séance
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="view-navigation">
    <div class="nav-tabs">
        <a href="<?php echo BASE_URL; ?>/cahier/calendrier.php" class="active">
            <i class="material-icons">calendar_month</i> Calendrier
        </a>
        <a href="<?php echo BASE_URL; ?>/cahier/semaine.php">
            <i class="material-icons">view_week</i> Vue semaine
        </a>
        <a href="<?php echo BASE_URL; ?>/cahier/chapitres.php">
            <i class="material-icons">book</i> Chapitres
        </a>
    </div>
</div>

<div class="calendar-container">
    <div class="calendar-header">
        <h2 class="calendar-title" id="calendar-title">Calendrier</h2>
        
        <div class="calendar-nav">
            <button id="prev-button" title="Précédent">
                <i class="material-icons">chevron_left</i>
            </button>
            <button id="today-button" title="Aujourd'hui">
                <i class="material-icons">today</i>
            </button>
            <button id="next-button" title="Suivant">
                <i class="material-icons">chevron_right</i>
            </button>
            
            <div class="calendar-views">
                <div class="calendar-view" data-view="dayGridMonth">Mois</div>
                <div class="calendar-view active" data-view="timeGridWeek">Semaine</div>
                <div class="calendar-view" data-view="timeGridDay">Jour</div>
            </div>
        </div>
    </div>
    
    <div class="calendar-toolbar">
        <div class="calendar-filters">
            <?php if (!empty($classes)): ?>
                <div class="calendar-filter">
                    <label for="classe-filter">Classe:</label>
                    <select id="classe-filter" class="form-select">
                        <option value="">Toutes les classes</option>
                        <?php foreach ($classes as $classe): ?>
                            <option value="<?php echo $classe['id']; ?>" <?php echo (isset($_GET['classe_id']) && $_GET['classe_id'] == $classe['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($classe['nom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($matieres)): ?>
                <div class="calendar-filter">
                    <label for="matiere-filter">Matière:</label>
                    <select id="matiere-filter" class="form-select">
                        <option value="">Toutes les matières</option>
                        <?php foreach ($matieres as $matiere): ?>
                            <option value="<?php echo $matiere['id']; ?>" <?php echo (isset($_GET['matiere_id']) && $_GET['matiere_id'] == $matiere['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($matiere['nom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($_SESSION['user_type'] === TYPE_PROFESSEUR): ?>
            <div class="calendar-actions">
                <button id="duplicate-button" class="btn btn-sm btn-accent" disabled>
                    <i class="material-icons">content_copy</i> Dupliquer
                </button>
                <button id="edit-button" class="btn btn-sm btn-primary" disabled>
                    <i class="material-icons">edit</i> Modifier
                </button>
            </div>
        <?php endif; ?>
    </div>
    
    <div id="calendar" class="calendar-grid"></div>
</div>

<!-- Modal pour les détails de séance -->
<div class="modal" id="event-modal" style="display: none;">
    <div class="modal-overlay"></div>
    <div class="modal-container">
        <div class="modal-header">
            <h3 id="event-title">Détails de la séance</h3>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body" id="event-details">
            <div class="event-info">
                <p><strong>Date:</strong> <span id="event-date"></span></p>
                <p><strong>Heure:</strong> <span id="event-time"></span></p>
                <p><strong>Matière:</strong> <span id="event-matiere"></span></p>
                <p><strong>Classe:</strong> <span id="event-classe"></span></p>
                <p><strong>Professeur:</strong> <span id="event-prof"></span></p>
                <p><strong>Statut:</strong> <span id="event-status"></span></p>
            </div>
        </div>
        <div class="modal-footer">
            <a href="#" id="event-link" class="btn btn-primary">Voir les détails complets</a>
            <?php if ($_SESSION['user_type'] === TYPE_PROFESSEUR): ?>
                <button id="modal-edit-button" class="btn btn-accent">
                    <i class="material-icons">edit</i> Modifier
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// Script JavaScript pour initialiser le calendrier
$pageScript = "
    // Initialisation du calendrier
    document.addEventListener('DOMContentLoaded', function() {
        // Élément calendrier
        var calendarEl = document.getElementById('calendar');
        
        // Configuration du calendrier
        var calendar = new FullCalendar.Calendar(calendarEl, {
            locale: 'fr',
            initialView: 'timeGridWeek',
            headerToolbar: false, // Désactiver la barre d'outils par défaut
            allDaySlot: false, // Désactiver la zone 'toute la journée'
            slotMinTime: '".CALENDAR_START_HOUR.":00',
            slotMaxTime: '".CALENDAR_END_HOUR.":00',
            slotDuration: '00:30:00', // Intervalle de 30 minutes
            nowIndicator: true, // Indicateur 'maintenant'
            dayMaxEvents: true, // Permettre l'affichage 'plus...' pour les jours chargés
            navLinks: true, // Permettre de cliquer sur les jours/semaines pour une vue plus détaillée
            editable: " . (($_SESSION['user_type'] === TYPE_PROFESSEUR) ? 'true' : 'false') . ", // Glisser-déposer pour les professeurs
            eventResizableFromStart: " . (($_SESSION['user_type'] === TYPE_PROFESSEUR) ? 'true' : 'false') . ", // Redimensionner depuis le début pour les professeurs
            eventDurationEditable: " . (($_SESSION['user_type'] === TYPE_PROFESSEUR) ? 'true' : 'false') . ", // Redimensionner la durée pour les professeurs
            selectable: " . (($_SESSION['user_type'] === TYPE_PROFESSEUR) ? 'true' : 'false') . ", // Sélection de plages horaires pour les professeurs
            businessHours: { // Heures de travail
                daysOfWeek: [1, 2, 3, 4, 5], // Lundi au vendredi
                startTime: '".CALENDAR_START_HOUR.":00',
                endTime: '".CALENDAR_END_HOUR.":00',
            },
            eventSources: [
                {
                    events: " . $evenementsJson . ",
                    color: 'transparent' // La couleur est définie dans chaque événement
                }
            ],
            eventContent: function(arg) {
                // Personnaliser le contenu des événements
                var content = document.createElement('div');
                content.classList.add('calendar-event');
                content.classList.add('status-' + arg.event.extendedProps.statut.toLowerCase());
                content.style.backgroundColor = arg.event.backgroundColor || arg.event.extendedProps.color || '#1976D2';
                
                var title = document.createElement('div');
                title.classList.add('calendar-event-title');
                title.innerHTML = arg.event.title;
                
                var time = document.createElement('div');
                time.classList.add('calendar-event-time');
                time.innerHTML = arg.timeText;
                
                content.appendChild(title);
                content.appendChild(time);
                
                return { domNodes: [content] };
            },
            eventClick: function(info) {
                // Afficher les détails de l'événement dans la modal
                var event = info.event;
                
                document.getElementById('event-title').textContent = event.title;
                document.getElementById('event-date').textContent = new Date(event.start).toLocaleDateString();
                document.getElementById('event-time').textContent = new Date(event.start).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) + ' - ' + new Date(event.end).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                document.getElementById('event-matiere').textContent = event.extendedProps.matiere_nom || '';
                document.getElementById('event-classe').textContent = event.extendedProps.classe_nom || '';
                document.getElementById('event-prof').textContent = event.extendedProps.professeur_nom || '';
                
                var statusMap = {
                    'PREV': 'Prévisionnelle',
                    'REAL': 'Réalisée',
                    'ANNUL': 'Annulée'
                };
                document.getElementById('event-status').textContent = statusMap[event.extendedProps.statut] || event.extendedProps.statut;
                
                // Mettre à jour le lien vers la page détaillée
                document.getElementById('event-link').href = '".BASE_URL."/cahier/details.php?id=' + event.id;
                
                // Si l'utilisateur est un professeur, mettre à jour le bouton d'édition
                if (document.getElementById('modal-edit-button')) {
                    document.getElementById('modal-edit-button').onclick = function() {
                        window.location.href = '".BASE_URL."/cahier/editer.php?id=' + event.id;
                    };
                }
                
                // Afficher la modal
                document.getElementById('event-modal').style.display = 'block';
                
                // Mettre à jour les boutons d'action dans la barre d'outils
                if (document.getElementById('edit-button')) {
                    document.getElementById('edit-button').disabled = false;
                    document.getElementById('edit-button').onclick = function() {
                        window.location.href = '".BASE_URL."/cahier/editer.php?id=' + event.id;
                    };
                }
                
                if (document.getElementById('duplicate-button')) {
                    document.getElementById('duplicate-button').disabled = false;
                    document.getElementById('duplicate-button').onclick = function() {
                        window.location.href = '".BASE_URL."/cahier/dupliquer.php?id=' + event.id;
                    };
                }
            },
            select: function(info) {
                // Rediriger vers la page de création avec la date et l'heure pré-remplies
                if (document.getElementById('classe-filter') && document.getElementById('classe-filter').value) {
                    window.location.href = '".BASE_URL."/cahier/creer.php?date_debut=' + info.startStr.substring(0, 10) + '&heure_debut=' + info.startStr.substring(11, 16) + '&classe_id=' + document.getElementById('classe-filter').value;
                } else {
                    window.location.href = '".BASE_URL."/cahier/creer.php?date_debut=' + info.startStr.substring(0, 10) + '&heure_debut=' + info.startStr.substring(11, 16);
                }
            },
            eventDrop: function(info) {
                // Mettre à jour la date/heure d'une séance par drag & drop
                if (confirm('Êtes-vous sûr de vouloir déplacer cette séance ?')) {
                    // Récupérer l'ID de la séance et les nouvelles dates
                    var id = info.event.id;
                    var dateDebut = info.event.start;
                    var dateFin = info.event.end;
                    
                    // Envoyer une requête AJAX pour mettre à jour la séance
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '".BASE_URL."/cahier/api/update_dates.php', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                alert('Séance mise à jour avec succès');
                            } else {
                                alert('Erreur: ' + response.message);
                                info.revert(); // Annuler le déplacement
                            }
                        } else {
                            alert('Erreur de connexion au serveur');
                            info.revert(); // Annuler le déplacement
                        }
                    };
                    xhr.send('id=' + encodeURIComponent(id) + '&date_debut=' + encodeURIComponent(dateDebut.toISOString()) + '&date_fin=' + encodeURIComponent(dateFin.toISOString()));
                } else {
                    info.revert(); // Annuler le déplacement
                }
            },
            eventResize: function(info) {
                // Mettre à jour la durée d'une séance
                if (confirm('Êtes-vous sûr de vouloir modifier la durée de cette séance ?')) {
                    // Récupérer l'ID de la séance et les nouvelles dates
                    var id = info.event.id;
                    var dateDebut = info.event.start;
                    var dateFin = info.event.end;
                    
                    // Envoyer une requête AJAX pour mettre à jour la séance
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '".BASE_URL."/cahier/api/update_dates.php', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                alert('Séance mise à jour avec succès');
                            } else {
                                alert('Erreur: ' + response.message);
                                info.revert(); // Annuler le redimensionnement
                            }
                        } else {
                            alert('Erreur de connexion au serveur');
                            info.revert(); // Annuler le redimensionnement
                        }
                    };
                    xhr.send('id=' + encodeURIComponent(id) + '&date_debut=' + encodeURIComponent(dateDebut.toISOString()) + '&date_fin=' + encodeURIComponent(dateFin.toISOString()));
                } else {
                    info.revert(); // Annuler le redimensionnement
                }
            }
        });
        
        // Initialiser le calendrier
        calendar.render();
        
        // Mettre à jour le titre avec la date actuelle
        updateCalendarTitle();
        
        // Gestion des boutons de navigation
        document.getElementById('prev-button').addEventListener('click', function() {
            calendar.prev();
            updateCalendarTitle();
        });
        
        document.getElementById('today-button').addEventListener('click', function() {
            calendar.today();
            updateCalendarTitle();
        });
        
        document.getElementById('next-button').addEventListener('click', function() {
            calendar.next();
            updateCalendarTitle();
        });
        
        // Gestion des vues
        document.querySelectorAll('.calendar-view').forEach(function(view) {
            view.addEventListener('click', function() {
                document.querySelectorAll('.calendar-view').forEach(function(v) {
                    v.classList.remove('active');
                });
                this.classList.add('active');
                calendar.changeView(this.dataset.view);
                updateCalendarTitle();
            });
        });
        
        // Fermer la modal
        document.querySelector('.modal-close').addEventListener('click', function() {
            document.getElementById('event-modal').style.display = 'none';
        });
        
        document.querySelector('.modal-overlay').addEventListener('click', function() {
            document.getElementById('event-modal').style.display = 'none';
        });
        
        // Filtrage par classe et matière
        if (document.getElementById('classe-filter')) {
            document.getElementById('classe-filter').addEventListener('change', function() {
                filterEvents();
            });
        }
        
        if (document.getElementById('matiere-filter')) {
            document.getElementById('matiere-filter').addEventListener('change', function() {
                filterEvents();
            });
        }
        
        // Fonction pour filtrer les événements
        function filterEvents() {
            var classeId = document.getElementById('classe-filter') ? document.getElementById('classe-filter').value : '';
            var matiereId = document.getElementById('matiere-filter') ? document.getElementById('matiere-filter').value : '';
            
            // Recharger le calendrier avec les filtres
            window.location.href = '".BASE_URL."/cahier/calendrier.php' + 
                (classeId ? '?classe_id=' + classeId : '') + 
                (matiereId ? (classeId ? '&' : '?') + 'matiere_id=' + matiereId : '');
        }
        
        // Mettre à jour le titre du calendrier en fonction de la vue et de la date
        function updateCalendarTitle() {
            var view = calendar.view;
            var title = '';
            
            if (view.type === 'dayGridMonth') {
                title = new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric' }).format(view.currentStart);
                title = title.charAt(0).toUpperCase() + title.slice(1); // Mettre la première lettre en majuscule
            } else if (view.type === 'timeGridWeek') {
                var startDate = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long' }).format(view.currentStart);
                var endDate = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(view.currentEnd.getTime() - 1));
                title = 'Semaine du ' + startDate + ' au ' + endDate;
            } else if (view.type === 'timeGridDay') {
                title = new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(view.currentStart);
                title = title.charAt(0).toUpperCase() + title.slice(1); // Mettre la première lettre en majuscule
            }
            
            document.getElementById('calendar-title').textContent = title;
        }
    });
";

// Inclure le pied de page
require_once ROOT_PATH . '/includes/footer.php';
?>