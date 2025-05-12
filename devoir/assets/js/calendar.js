/**
 * Gestion du calendrier pour le cahier de texte
 */
document.addEventListener('DOMContentLoaded', function() {
    // Initialisation du calendrier
    initCalendar();
    
    // Fonctions d'utilitaires pour le calendrier
    function initCalendar() {
        const calendarEl = document.getElementById('calendar');
        if (!calendarEl) return;
        
        // Configuration du calendrier
        const calendar = new FullCalendar.Calendar(calendarEl, {
            locale: 'fr',
            initialView: 'timeGridWeek',
            headerToolbar: false, // Désactiver la barre d'outils par défaut (nous utilisons la nôtre)
            allDaySlot: false, // Désactiver la zone "toute la journée"
            height: 'auto',
            slotMinTime: '08:00',
            slotMaxTime: '18:00',
            slotDuration: '00:30:00', // Intervalle de 30 minutes
            nowIndicator: true, // Indicateur "maintenant"
            dayMaxEvents: true, // Permettre l'affichage "plus..." pour les jours chargés
            navLinks: true, // Permettre de cliquer sur les jours/semaines pour une vue plus détaillée
            editable: isTeacher(), // Glisser-déposer pour les professeurs uniquement
            selectable: isTeacher(), // Sélection de plages horaires pour les professeurs uniquement
            eventResizableFromStart: isTeacher(),
            eventDurationEditable: isTeacher(),
            businessHours: { // Heures de travail
                daysOfWeek: [1, 2, 3, 4, 5], // Lundi au vendredi
                startTime: '08:00',
                endTime: '18:00',
            },
            selectConstraint: {
                daysOfWeek: [1, 2, 3, 4, 5], // Sélection uniquement sur les jours de semaine
                startTime: '08:00',
                endTime: '18:00',
            },
            eventClassNames: function(arg) {
                // Ajouter des classes CSS selon le statut de la séance
                return ["status-" + arg.event.extendedProps.statut.toLowerCase()];
            },
            eventClick: function(info) {
                // Afficher les détails de l'événement
                showEventPopup(info.event);
            },
            select: function(info) {
                if (!isTeacher()) return;
                
                // Rediriger vers la page de création avec la date et l'heure pré-remplies
                const dateDebut = info.startStr.substr(0, 10);
                const heureDebut = info.startStr.substr(11, 5);
                
                // Récupérer la classe sélectionnée (si disponible)
                const classeFilter = document.getElementById('classe-filter');
                const classeId = classeFilter ? classeFilter.value : '';
                
                let url = BASE_URL + '/cahier/creer.php?date_debut=' + dateDebut + 
                          '&heure_debut=' + heureDebut;
                
                if (classeId) {
                    url += '&classe_id=' + classeId;
                }
                
                window.location.href = url;
            },
            eventDrop: handleEventChange,
            eventResize: handleEventChange
        });
        
        // Initialiser le calendrier
        calendar.render();
        
        // Référence globale pour y accéder depuis d'autres fonctions
        window.calendar = calendar;
        
        // Mettre à jour le titre avec la date actuelle
        updateCalendarTitle();
        
        // Gestion des boutons de navigation
        setupNavigationButtons();
        
        // Gestion des filtres
        setupFilters();
    }
    
    // Afficher les détails d'un événement dans une popup
    function showEventPopup(event) {
        // Créer la popup si elle n'existe pas
        if (!document.getElementById('event-popup')) {
            createEventPopup();
        }
        
        // Remplir la popup avec les détails de l'événement
        const title = document.getElementById('event-popup-title');
        const date = document.getElementById('event-date');
        const time = document.getElementById('event-time');
        const matiere = document.getElementById('event-matiere');
        const classe = document.getElementById('event-classe');
        const prof = document.getElementById('event-prof');
        const status = document.getElementById('event-status');
        const link = document.getElementById('event-details-link');
        
        title.textContent = event.title;
        date.textContent = formatDate(event.start);
        time.textContent = formatTime(event.start) + ' - ' + formatTime(event.end);
        matiere.textContent = event.extendedProps.matiere_nom || '';
        classe.textContent = event.extendedProps.classe_nom || '';
        prof.textContent = event.extendedProps.professeur_nom || '';
        
        // Mapper les statuts pour un affichage plus lisible
        const statusMap = {
            'PREV': 'Prévisionnelle',
            'REAL': 'Réalisée',
            'ANNUL': 'Annulée'
        };
        status.textContent = statusMap[event.extendedProps.statut] || event.extendedProps.statut;
        
        // Mettre à jour le lien vers la page détaillée
        link.href = BASE_URL + '/cahier/details.php?id=' + event.id;
        
        // Mettre à jour les boutons d'action selon le rôle de l'utilisateur
        const editButton = document.getElementById('event-edit-button');
        if (editButton && isTeacher()) {
            editButton.style.display = 'inline-block';
            editButton.onclick = function() {
                window.location.href = BASE_URL + '/cahier/editer.php?id=' + event.id;
            };
        } else if (editButton) {
            editButton.style.display = 'none';
        }
        
        // Afficher la popup
        document.getElementById('event-popup').style.display = 'block';
        document.getElementById('event-popup-overlay').style.display = 'block';
    }
    
    // Créer la popup d'événement
    function createEventPopup() {
        const popup = document.createElement('div');
        popup.id = 'event-popup';
        popup.className = 'event-popup';
        popup.style.display = 'none';
        
        const overlay = document.createElement('div');
        overlay.id = 'event-popup-overlay';
        overlay.className = 'event-popup-overlay';
        overlay.style.display = 'none';
        
        popup.innerHTML = `
            <div class="event-popup-header">
                <h3 class="event-popup-title" id="event-popup-title">Détails de la séance</h3>
                <button type="button" class="event-popup-close">&times;</button>
            </div>
            <div class="event-popup-content">
                <div class="event-popup-details">
                    <div class="event-detail-label">Date:</div>
                    <div id="event-date"></div>
                    
                    <div class="event-detail-label">Horaire:</div>
                    <div id="event-time"></div>
                    
                    <div class="event-detail-label">Matière:</div>
                    <div id="event-matiere"></div>
                    
                    <div class="event-detail-label">Classe:</div>
                    <div id="event-classe"></div>
                    
                    <div class="event-detail-label">Professeur:</div>
                    <div id="event-prof"></div>
                    
                    <div class="event-detail-label">Statut:</div>
                    <div id="event-status"></div>
                </div>
            </div>
            <div class="event-popup-footer">
                <a href="#" id="event-details-link" class="btn btn-primary">Voir les détails complets</a>
                <button type="button" id="event-edit-button" class="btn btn-accent">
                    <i class="material-icons">edit</i> Modifier
                </button>
            </div>
        `;
        
        document.body.appendChild(overlay);
        document.body.appendChild(popup);
        
        // Gestion de la fermeture de la popup
        document.querySelector('.event-popup-close').addEventListener('click', closeEventPopup);
        overlay.addEventListener('click', closeEventPopup);
    }
    
    // Fermer la popup d'événement
    function closeEventPopup() {
        document.getElementById('event-popup').style.display = 'none';
        document.getElementById('event-popup-overlay').style.display = 'none';
    }
    
    // Gérer le déplacement ou redimensionnement d'un événement
    function handleEventChange(info) {
        if (!isTeacher()) return;
        
        if (confirm('Êtes-vous sûr de vouloir modifier cette séance ?')) {
            // Récupérer l'ID de la séance et les nouvelles dates
            const id = info.event.id;
            const dateDebut = info.event.start;
            const dateFin = info.event.end;
            
            // Envoyer une requête AJAX pour mettre à jour la séance
            const xhr = new XMLHttpRequest();
            xhr.open('POST', BASE_URL + '/api/seances.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            showNotification('Séance mise à jour avec succès', 'success');
                        } else {
                            showNotification('Erreur: ' + response.message, 'error');
                            info.revert(); // Annuler le changement
                        }
                    } catch (e) {
                        showNotification('Erreur de traitement de la réponse', 'error');
                        info.revert(); // Annuler le changement
                    }
                } else {
                    showNotification('Erreur de connexion au serveur', 'error');
                    info.revert(); // Annuler le changement
                }
            };
            xhr.send('action=update_dates&id=' + encodeURIComponent(id) + 
                     '&date_debut=' + encodeURIComponent(dateDebut.toISOString()) + 
                     '&date_fin=' + encodeURIComponent(dateFin.toISOString()));
        } else {
            info.revert(); // Annuler le changement
        }
    }
    
    // Configurer les boutons de navigation du calendrier
    function setupNavigationButtons() {
        // Boutons précédent, aujourd'hui, suivant
        document.getElementById('prev-button')?.addEventListener('click', function() {
            window.calendar.prev();
            updateCalendarTitle();
        });
        
        document.getElementById('today-button')?.addEventListener('click', function() {
            window.calendar.today();
            updateCalendarTitle();
        });
        
        document.getElementById('next-button')?.addEventListener('click', function() {
            window.calendar.next();
            updateCalendarTitle();
        });
        
        // Gestion des vues (mois, semaine, jour)
        document.querySelectorAll('.calendar-view').forEach(function(view) {
            view.addEventListener('click', function() {
                document.querySelectorAll('.calendar-view').forEach(v => v.classList.remove('active'));
                this.classList.add('active');
                window.calendar.changeView(this.dataset.view);
                updateCalendarTitle();
            });
        });
    }
    
    // Mettre à jour le titre du calendrier
    function updateCalendarTitle() {
        const titleElement = document.getElementById('calendar-title');
        if (!titleElement || !window.calendar) return;
        
        const view = window.calendar.view;
        let title = '';
        
        if (view.type === 'dayGridMonth') {
            // Format: "Janvier 2023"
            title = new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric' })
                .format(view.currentStart);
            title = title.charAt(0).toUpperCase() + title.slice(1);
        } else if (view.type === 'timeGridWeek') {
            // Format: "Semaine du 1 au 7 janvier 2023"
            const startDate = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long' })
                .format(view.currentStart);
            const endDay = new Date(view.currentEnd);
            endDay.setDate(endDay.getDate() - 1); // La fin est exclusive
            const endDate = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
                .format(endDay);
            title = 'Semaine du ' + startDate + ' au ' + endDate;
        } else if (view.type === 'timeGridDay') {
            // Format: "Lundi 1 janvier 2023"
            title = new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
                .format(view.currentStart);
            title = title.charAt(0).toUpperCase() + title.slice(1);
        }
        
        titleElement.textContent = title;
    }
    
    // Configurer les filtres du calendrier
    function setupFilters() {
        // Filtrage par classe
        document.getElementById('classe-filter')?.addEventListener('change', function() {
            filterEvents();
        });
        
        // Filtrage par matière
        document.getElementById('matiere-filter')?.addEventListener('change', function() {
            filterEvents();
        });
        
        // Filtrage par statut
        document.getElementById('statut-filter')?.addEventListener('change', function() {
            filterEvents();
        });
    }
    
    // Appliquer les filtres
    function filterEvents() {
        const classeId = document.getElementById('classe-filter')?.value || '';
        const matiereId = document.getElementById('matiere-filter')?.value || '';
        const statut = document.getElementById('statut-filter')?.value || '';
        
        // Construire l'URL avec les paramètres de filtre
        let url = window.location.pathname;
        let params = [];
        
        if (classeId) params.push('classe_id=' + classeId);
        if (matiereId) params.push('matiere_id=' + matiereId);
        if (statut) params.push('statut=' + statut);
        
        // Ajouter les paramètres à l'URL
        if (params.length > 0) {
            url += '?' + params.join('&');
        }
        
        // Rediriger pour recharger avec les nouveaux filtres
        window.location.href = url;
    }
    
    // Vérifier si l'utilisateur est un professeur
    function isTeacher() {
        // Cette fonction est définie en fonction de la variable $_SESSION côté serveur
        // Elle est injectée dans le script par PHP
        return typeof userIsTeacher !== 'undefined' && userIsTeacher === true;
    }
    
    // Formater une date (JJ/MM/AAAA)
    function formatDate(date) {
        if (!date) return '';
        return new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(date);
    }
    
    // Formater une heure (HH:MM)
    function formatTime(date) {
        if (!date) return '';
        return new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' }).format(date);
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