// Configuration du module calendrier
const AgendaModule = {
    // Etat de l'application
    state: {
        currentView: 'hebdomadaire',
        startDate: null,
        endDate: null,
        events: [],
        showReplacements: true,
        userCanModify: false
    },
    
    // Initialisation
    init: function(canModify) {
        this.state.userCanModify = canModify;
        this.setupEventListeners();
        this.initDates();
        this.loadEvents();
    },
    
    // Configuration des écouteurs d'événements
    setupEventListeners: function() {
        // Boutons de navigation
        document.getElementById('previous-period').addEventListener('click', () => this.navigate('prev'));
        document.getElementById('next-period').addEventListener('click', () => this.navigate('next'));
        document.getElementById('today-button').addEventListener('click', () => this.goToday());
        
        // Boutons de vue
        document.getElementById('view-day').addEventListener('click', () => this.changeView('journalier'));
        document.getElementById('view-week').addEventListener('click', () => this.changeView('hebdomadaire'));
        document.getElementById('view-month').addEventListener('click', () => this.changeView('mensuel'));
        
        // Options
        document.getElementById('toggle-replacements').addEventListener('change', (e) => {
            this.state.showReplacements = e.target.checked;
            this.loadEvents();
        });
        
        // Export ICS
        document.getElementById('export-ics').addEventListener('click', () => this.exportICS());
        
        // Formulaire d'import ICS
        const importForm = document.getElementById('import-form');
        if (importForm) {
            importForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.importICS();
            });
        }
    },
    
    // Initialisation des dates selon la vue
    initDates: function() {
        const today = new Date();
        
        switch (this.state.currentView) {
            case 'journalier':
                this.state.startDate = this.formatDate(today);
                this.state.endDate = this.formatDate(today);
                break;
                
            case 'hebdomadaire':
                // Trouver le lundi de la semaine
                const monday = new Date(today);
                monday.setDate(today.getDate() - (today.getDay() + 6) % 7);
                this.state.startDate = this.formatDate(monday);
                
                const sunday = new Date(monday);
                sunday.setDate(monday.getDate() + 6);
                this.state.endDate = this.formatDate(sunday);
                break;
                
            case 'mensuel':
                // Premier jour du mois
                const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
                this.state.startDate = this.formatDate(firstDay);
                
                // Dernier jour du mois
                const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                this.state.endDate = this.formatDate(lastDay);
                break;
        }
        
        this.updateDateDisplay();
    },
    
    // Mise à jour de l'affichage des dates
    updateDateDisplay: function() {
        const dateDisplay = document.getElementById('current-period');
        
        switch (this.state.currentView) {
            case 'journalier':
                const dayDate = new Date(this.state.startDate);
                dateDisplay.textContent = dayDate.toLocaleDateString('fr-FR', {
                    weekday: 'long',
                    day: 'numeric',
                    month: 'long',
                    year: 'numeric'
                });
                break;
                
            case 'hebdomadaire':
                const startWeek = new Date(this.state.startDate);
                const endWeek = new Date(this.state.endDate);
                
                dateDisplay.textContent = `Semaine du ${startWeek.toLocaleDateString('fr-FR', {
                    day: 'numeric',
                    month: 'long'
                })} au ${endWeek.toLocaleDateString('fr-FR', {
                    day: 'numeric',
                    month: 'long',
                    year: 'numeric'
                })}`;
                break;
                
            case 'mensuel':
                const monthDate = new Date(this.state.startDate);
                dateDisplay.textContent = monthDate.toLocaleDateString('fr-FR', {
                    month: 'long',
                    year: 'numeric'
                });
                break;
        }
    },
    
    // Chargement des événements
    loadEvents: async function() {
        try {
            const url = `/api/events.php?start=${this.state.startDate}&end=${this.state.endDate}&view=${this.state.currentView}&remplacements=${this.state.showReplacements ? 1 : 0}`;
            const response = await fetch(url);
            
            if (response.ok) {
                this.state.events = await response.json();
                this.renderCalendar();
            } else {
                console.error('Erreur lors du chargement des événements');
            }
        } catch (error) {
            console.error('Erreur:', error);
        }
    },
    
    // Rendu du calendrier en fonction de la vue
    renderCalendar: function() {
        const calendar = document.getElementById('calendar');
        calendar.innerHTML = '';
        
        switch (this.state.currentView) {
            case 'journalier':
                this.renderDayView(calendar);
                break;
                
            case 'hebdomadaire':
                this.renderWeekView(calendar);
                break;
                
            case 'mensuel':
                this.renderMonthView(calendar);
                break;
        }
        
        // Ajouter les événements aux cases
        this.populateEvents();
        
        // Initialiser les interactions
        this.initializeEventHandlers();
    },
    
    // Rendu vue journalière
    renderDayView: function(calendar) {
        // Créer les en-têtes d'heures
        calendar.classList.add('day-view');
        
        // Ajouter les heures de 8h à 18h
        for (let h = 8; h <= 18; h++) {
            const hourRow = document.createElement('div');
            hourRow.classList.add('hour-row');
            
            const hourLabel = document.createElement('div');
            hourLabel.classList.add('hour-label');
            hourLabel.textContent = `${h}h00`;
            hourRow.appendChild(hourLabel);
            
            const hourSlot = document.createElement('div');
            hourSlot.classList.add('hour-slot');
            hourSlot.id = `day-${h}`;
            hourRow.appendChild(hourSlot);
            
            calendar.appendChild(hourRow);
        }
    },
    
    // Rendu vue hebdomadaire
    renderWeekView: function(calendar) {
        calendar.classList.add('week-view');
        
        // En-tête des jours
        const headerRow = document.createElement('div');
        headerRow.classList.add('header-row');
        
        // Case vide pour le coin supérieur gauche
        const emptyCorner = document.createElement('div');
        emptyCorner.classList.add('empty-corner');
        headerRow.appendChild(emptyCorner);
        
        // Jours de la semaine
        const days = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
        days.forEach((day, index) => {
            const dayHeader = document.createElement('div');
            dayHeader.classList.add('day-header');
            dayHeader.textContent = day;
            
            // Ajouter la date sous le jour
            const startDate = new Date(this.state.startDate);
            const dayDate = new Date(startDate);
            dayDate.setDate(startDate.getDate() + index);
            
            const dateSpan = document.createElement('span');
            dateSpan.classList.add('day-date');
            dateSpan.textContent = dayDate.toLocaleDateString('fr-FR', {
                day: 'numeric',
                month: 'numeric'
            });
            
            dayHeader.appendChild(document.createElement('br'));
            dayHeader.appendChild(dateSpan);
            
            headerRow.appendChild(dayHeader);
        });
        
        calendar.appendChild(headerRow);
        
        // Lignes d'heures
        for (let h = 8; h <= 18; h++) {
            const hourRow = document.createElement('div');
            hourRow.classList.add('hour-row');
            
            // Label d'heure
            const hourLabel = document.createElement('div');
            hourLabel.classList.add('hour-label');
            hourLabel.textContent = `${h}h00`;
            hourRow.appendChild(hourLabel);
            
            // Cellules pour chaque jour
            for (let d = 1; d <= 7; d++) {
                const dayCell = document.createElement('div');
                dayCell.classList.add('day-cell');
                dayCell.id = `cell-${d}-${h}`;
                hourRow.appendChild(dayCell);
            }
            
            calendar.appendChild(hourRow);
        }
    },
    
    // Rendu vue mensuelle
    renderMonthView: function(calendar) {
        calendar.classList.add('month-view');
        
        // En-tête des jours
        const headerRow = document.createElement('div');
        headerRow.classList.add('header-row');
        
        const days = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
        days.forEach(day => {
            const dayHeader = document.createElement('div');
            dayHeader.classList.add('day-header');
            dayHeader.textContent = day;
            headerRow.appendChild(dayHeader);
        });
        
        calendar.appendChild(headerRow);
        
        // Récupérer le premier jour du mois
        const startDate = new Date(this.state.startDate);
        const year = startDate.getFullYear();
        const month = startDate.getMonth();
        
        // Trouver le jour de la semaine du premier jour du mois (0 = dimanche)
        const firstDay = new Date(year, month, 1);
        let firstDayOffset = firstDay.getDay() - 1; // 0 = lundi
        if (firstDayOffset < 0) firstDayOffset = 6; // Dimanche = 6
        
        // Nombre de jours dans le mois
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        
        // Déterminer le nombre de semaines nécessaires
        const totalCells = firstDayOffset + daysInMonth;
        const totalWeeks = Math.ceil(totalCells / 7);
        
        let dayCounter = 1;
        
        // Création des semaines
        for (let w = 0; w < totalWeeks; w++) {
            const weekRow = document.createElement('div');
            weekRow.classList.add('week-row');
            
            // Création des jours dans la semaine
            for (let d = 0; d < 7; d++) {
                const dayCell = document.createElement('div');
                dayCell.classList.add('month-cell');
                
                // Ajouter le numéro de jour si on est dans le mois
                if ((w === 0 && d < firstDayOffset) || dayCounter > daysInMonth) {
                    dayCell.classList.add('outside-month');
                } else {
                    dayCell.textContent = dayCounter;
                    dayCell.id = `month-${year}-${month+1}-${dayCounter}`;
                    dayCell.dataset.date = `${year}-${String(month+1).padStart(2, '0')}-${String(dayCounter).padStart(2, '0')}`;
                    dayCounter++;
                }
                
                weekRow.appendChild(dayCell);
            }
            
            calendar.appendChild(weekRow);
        }
    },
    
    // Peupler le calendrier avec les événements
    populateEvents: function() {
        this.state.events.forEach(event => {
            const startDate = new Date(event.start);
            
            switch (this.state.currentView) {
                case 'journalier': {
                    const hour = startDate.getHours();
                    const slot = document.getElementById(`day-${hour}`);
                    
                    if (slot) {
                        this.createEventElement(slot, event);
                    }
                    break;
                }
                
                case 'hebdomadaire': {
                    const day = startDate.getDay() || 7; // 0=dimanche -> 7
                    const hour = startDate.getHours();
                    const cell = document.getElementById(`cell-${day}-${hour}`);
                    
                    if (cell) {
                        this.createEventElement(cell, event);
                    }
                    break;
                }
                
                case 'mensuel': {
                    const date = startDate.toISOString().split('T')[0];
                    const day = date.split('-')[2].replace(/^0/, ''); // Enlever le zéro initial
                    const month = date.split('-')[1].replace(/^0/, '');
                    const year = date.split('-')[0];
                    
                    const cell = document.getElementById(`month-${year}-${month}-${day}`);
                    
                    if (cell) {
                        this.createEventElement(cell, event, true);
                    }
                    break;
                }
            }
        });
    },
    
    // Créer un élément d'événement
    createEventElement: function(container, event, isMonthView = false) {
        const eventDiv = document.createElement('div');
        eventDiv.classList.add('event');
        eventDiv.dataset.id = event.id;
        eventDiv.style.backgroundColor = event.color;
        
        // Si c'est un remplacement, ajouter une classe spéciale
        if (event.type === 'remplacement') {
            eventDiv.classList.add('replacement');
        }
        
        // Dans la vue mois, simplifier l'affichage
        if (isMonthView) {
            eventDiv.classList.add('month-event');
            eventDiv.textContent = event.title.substring(0, 15);
            if (event.title.length > 15) {
                eventDiv.textContent += '...';
            }
        } else {
            const startTime = new Date(event.start).toLocaleTimeString('fr-FR', {
                hour: '2-digit',
                minute: '2-digit'
            });
            const endTime = new Date(event.end).toLocaleTimeString('fr-FR', {
                hour: '2-digit',
                minute: '2-digit'
            });
            
            eventDiv.innerHTML = `<div class="event-time">${startTime}-${endTime}</div>
                                 <div class="event-title">${event.title}</div>`;
        }
        
        // Ajouter les données pour le modal
        eventDiv.dataset.title = event.title;
        eventDiv.dataset.start = event.start;
        eventDiv.dataset.end = event.end;
        
        if (event.sala) eventDiv.dataset.sala = event.sala;
        if (event.prof) eventDiv.dataset.prof = event.prof;
        if (event.description) eventDiv.dataset.description = event.description;
        if (event.motif) eventDiv.dataset.motif = event.motif;
        if (event.type) eventDiv.dataset.type = event.type;
        if (event.original_id) eventDiv.dataset.originalId = event.original_id;
        
        // Ajouter l'événement
        container.appendChild(eventDiv);
    },
    
    // Initialiser les gestionnaires d'événements
    initializeEventHandlers: function() {
        // Clic sur un événement pour afficher le modal
        const events = document.querySelectorAll('.event');
        events.forEach(event => {
            event.addEventListener('click', (e) => {
                e.stopPropagation();
                this.showEventDetails(event);
            });
        });
        
        // Si l'utilisateur peut modifier, ajouter l'édition
        if (this.state.userCanModify) {
            // Double-clic sur un événement pour l'édition
            events.forEach(event => {
                event.addEventListener('dblclick', (e) => {
                    e.stopPropagation();
                    this.showEventEdit(event);
                });
            });
        }
    },
    
    // Afficher le modal de détails
    showEventDetails: function(eventEl) {
        const modal = document.getElementById('event-details-modal');
        const overlay = document.getElementById('modal-overlay');
        
        // Récupérer les données de l'événement
        const title = eventEl.dataset.title;
        const start = new Date(eventEl.dataset.start);
        const end = new Date(eventEl.dataset.end);
        const sala = eventEl.dataset.sala || '';
        const prof = eventEl.dataset.prof || '';
        const description = eventEl.dataset.description || '';
        const type = eventEl.dataset.type || 'cours';
        const motif = eventEl.dataset.motif || '';
        
        // Formater les dates
        const dateStr = start.toLocaleDateString('fr-FR', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        });
        
        const startTime = start.toLocaleTimeString('fr-FR', {
            hour: '2-digit',
            minute: '2-digit'
        });
        
        const endTime = end.toLocaleTimeString('fr-FR', {
            hour: '2-digit',
            minute: '2-digit'
        });
        
        // Remplir le contenu du modal
        document.getElementById('modal-title').textContent = title;
        document.getElementById('modal-date').textContent = dateStr;
        document.getElementById('modal-time').textContent = `${startTime} - ${endTime}`;
        
        const infoContainer = document.getElementById('modal-info');
        infoContainer.innerHTML = '';
        
        if (sala) {
            const salaEl = document.createElement('p');
            salaEl.innerHTML = `<strong>Salle:</strong> ${sala}`;
            infoContainer.appendChild(salaEl);
        }
        
        if (prof) {
            const profEl = document.createElement('p');
            profEl.innerHTML = `<strong>Professeur:</strong> ${prof}`;
            infoContainer.appendChild(profEl);
        }
        
        if (description) {
            const descEl = document.createElement('p');
            descEl.innerHTML = `<strong>Description:</strong> ${description}`;
            infoContainer.appendChild(descEl);
        }
        
        if (type === 'remplacement' && motif) {
            const motifEl = document.createElement('p');
            motifEl.innerHTML = `<strong>Motif du remplacement:</strong> ${motif}`;
            infoContainer.appendChild(motifEl);
        }
        
        // Afficher le modal
        overlay.style.display = 'block';
        modal.style.display = 'block';
        
        // Gestionnaire pour fermer le modal
        document.getElementById('close-modal').onclick = function() {
            overlay.style.display = 'none';
            modal.style.display = 'none';
        };
        
        overlay.onclick = function() {
            overlay.style.display = 'none';
            modal.style.display = 'none';
        };
    },
    
    // Navigation temporelle
    navigate: function(direction) {
        const startDate = new Date(this.state.startDate);
        
        switch (this.state.currentView) {
            case 'journalier':
                if (direction === 'prev') {
                    startDate.setDate(startDate.getDate() - 1);
                } else {
                    startDate.setDate(startDate.getDate() + 1);
                }
                this.state.startDate = this.formatDate(startDate);
                this.state.endDate = this.state.startDate;
                break;
                
            case 'hebdomadaire':
                if (direction === 'prev') {
                    startDate.setDate(startDate.getDate() - 7);
                } else {
                    startDate.setDate(startDate.getDate() + 7);
                }
                this.state.startDate = this.formatDate(startDate);
                
                const endDate = new Date(startDate);
                endDate.setDate(startDate.getDate() + 6);
                this.state.endDate = this.formatDate(endDate);
                break;
                
            case 'mensuel':
                if (direction === 'prev') {
                    startDate.setMonth(startDate.getMonth() - 1);
                } else {
                    startDate.setMonth(startDate.getMonth() + 1);
                }
                startDate.setDate(1);
                this.state.startDate = this.formatDate(startDate);
                
                const lastDay = new Date(startDate.getFullYear(), startDate.getMonth() + 1, 0);
                this.state.endDate = this.formatDate(lastDay);
                break;
        }
        
        this.updateDateDisplay();
        this.loadEvents();
    },
    
    // Retour à aujourd'hui
    goToday: function() {
        this.initDates();
        this.loadEvents();
    },
    
    // Changement de vue
    changeView: function(view) {
        if (view === this.state.currentView) return;
        
        // Mettre à jour la vue active
        document.querySelectorAll('.view-button').forEach(btn => {
            btn.classList.remove('active');
        });
        document.getElementById(`view-${view.substring(0, 3)}`).classList.add('active');
        
        // Changer la vue
        this.state.currentView = view;
        
        // Réinitialiser les dates
        this.initDates();
        this.loadEvents();
    },
    
    // Export ICS
    exportICS: function() {
        window.location.href = `/export_ics.php?start=${this.state.startDate}&end=${this.state.endDate}`;
    },
    
    // Import ICS (soumet le formulaire)
    importICS: function() {
        document.getElementById('import-form').submit();
    },
    
    // Utilitaire de formatage de date (YYYY-MM-DD)
    formatDate: function(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }
};

// Initialisation au chargement de la page
document.addEventListener('DOMContentLoaded', function() {
    // Récupérer les permissions de l'utilisateur depuis les attributs de données
    const canModify = document.body.dataset.canModify === 'true';
    
    // Initialiser le module
    AgendaModule.init(canModify);
});