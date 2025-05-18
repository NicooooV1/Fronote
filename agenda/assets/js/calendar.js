// calendar.js - Fonctionnalités JavaScript pour le calendrier

document.addEventListener('DOMContentLoaded', function() {
  // Initialisation du calendrier
  initCalendar();
  
  // Ajout des écouteurs d'événements pour la navigation
  setupCalendarNavigation();
  
  // Initialiser les interactions avec les événements
  setupEventInteractions();
});

/**
 * Initialise le calendrier et ses fonctionnalités de base
 */
function initCalendar() {
  // Récupérer les références aux éléments du DOM
  const calendarDays = document.querySelectorAll('.calendar-day');
  const calendarEvents = document.querySelectorAll('.calendar-event');
  
  // Définir la hauteur des jours du calendrier pour une meilleure apparence
  adjustCalendarDayHeight();
  
  // Ajouter des écouteurs d'événements pour les événements du calendrier
  calendarEvents.forEach(event => {
    event.addEventListener('click', function(e) {
      e.stopPropagation(); // Empêcher la propagation au jour du calendrier
    });
  });
}

/**
 * Configure les interactions avec les événements du calendrier
 */
function setupEventInteractions() {
  // Ajouter des interactions pour les événements (clic, survol, etc.)
  const calendarEvents = document.querySelectorAll('.calendar-event');
  
  calendarEvents.forEach(event => {
    // Ajouter une classe au survol pour l'effet visuel
    event.addEventListener('mouseenter', function() {
      this.classList.add('event-hover');
    });
    
    event.addEventListener('mouseleave', function() {
      this.classList.remove('event-hover');
    });
  });
}

/**
 * Configure la navigation du calendrier (mois précédent, suivant)
 */
function setupCalendarNavigation() {
  // Gérer le changement de vue (jour, semaine, mois, liste)
  const viewOptions = document.querySelectorAll('.calendar-view-options a');
  
  viewOptions.forEach(option => {
    option.addEventListener('click', function(e) {
      // Retirer la classe active de tous les boutons
      viewOptions.forEach(opt => opt.classList.remove('active'));
      
      // Ajouter la classe active au bouton cliqué
      this.classList.add('active');
    });
  });
}

/**
 * Ajuste la hauteur des jours du calendrier pour une meilleure apparence
 */
function adjustCalendarDayHeight() {
  const calendarDays = document.querySelectorAll('.calendar-day:not(.empty)');
  
  // Déterminer la hauteur maximale nécessaire
  let maxEventsCount = 0;
  
  calendarDays.forEach(day => {
    const eventsCount = day.querySelectorAll('.calendar-event').length;
    maxEventsCount = Math.max(maxEventsCount, eventsCount);
  });
  
  // Définir une hauteur minimale basée sur le nombre maximum d'événements
  if (maxEventsCount > 0) {
    const minHeight = 100 + (maxEventsCount * 26); // 100px de base + 26px par événement
    
    calendarDays.forEach(day => {
      day.style.minHeight = minHeight + 'px';
    });
  }
}

/**
 * Fonction pour ouvrir la vue détaillée d'un jour spécifique
 * @param {string} date - La date au format YYYY-MM-DD
 */
function openDayView(date) {
  window.location.href = 'agenda_jour.php?date=' + date;
}

/**
 * Fonction pour ouvrir la vue détaillée d'un événement
 * @param {number} eventId - L'identifiant de l'événement
 * @param {Event} e - L'événement du DOM
 */
function openEventDetails(eventId, e) {
  if (e) {
    e.stopPropagation(); // Empêcher la propagation au jour du calendrier
  }
  window.location.href = 'details_evenement.php?id=' + eventId;
}

/**
 * Fonction pour synchroniser les événements en temps réel (à implémenter avec AJAX)
 */
function syncEvents() {
  // Cette fonction pourrait être appelée périodiquement pour mettre à jour le calendrier
  // sans rechargement complet de la page, en utilisant AJAX pour récupérer les nouveaux événements
  
  // Exemple de code pour une requête AJAX
  /*
  fetch('get_events.php?month=' + currentMonth + '&year=' + currentYear)
    .then(response => response.json())
    .then(data => {
      // Mettre à jour les événements dans le calendrier
      updateCalendarEvents(data);
    })
    .catch(error => {
      console.error('Erreur lors de la synchronisation des événements:', error);
    });
  */
}

/**
 * Fonction pour mettre à jour les événements dans le calendrier (appelée par syncEvents)
 * @param {Array} events - Liste des événements à afficher
 */
function updateCalendarEvents(events) {
  // Cette fonction mettrait à jour le DOM avec les nouveaux événements
  
  // Supprimer les événements existants
  document.querySelectorAll('.calendar-day-events').forEach(container => {
    container.innerHTML = '';
  });
  
  // Ajouter les nouveaux événements
  events.forEach(event => {
    const eventDate = new Date(event.date_debut);
    const day = eventDate.getDate();
    
    // Trouver le conteneur du jour correspondant
    const dayContainer = document.querySelector(`.calendar-day[data-day="${day}"] .calendar-day-events`);
    
    if (dayContainer) {
      // Créer l'élément d'événement
      const eventElement = document.createElement('div');
      eventElement.className = `calendar-event event-${event.type_evenement}`;
      eventElement.dataset.eventId = event.id;
      
      // Ajouter l'heure et le titre
      const eventTime = document.createElement('span');
      eventTime.className = 'event-time';
      eventTime.textContent = eventDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
      
      const eventTitle = document.createElement('span');
      eventTitle.className = 'event-title';
      eventTitle.textContent = event.titre;
      
      // Assembler l'élément
      eventElement.appendChild(eventTime);
      eventElement.appendChild(eventTitle);
      
      // Ajouter l'écouteur d'événement
      eventElement.addEventListener('click', function(e) {
        openEventDetails(event.id, e);
      });
      
      // Ajouter au conteneur
      dayContainer.appendChild(eventElement);
    }
  });
}

// Exporter les fonctions pour les utiliser ailleurs si nécessaire
window.calendarFunctions = {
  openDayView,
  openEventDetails,
  syncEvents
};