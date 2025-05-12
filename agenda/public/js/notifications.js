// Module de gestion des notifications
const NotificationsModule = {
    // État des notifications
    state: {
        preferences: [],
        webPushEnabled: false,
        coursesList: []
    },
    
    // Initialisation
    init: function() {
        this.setupEventListeners();
        this.loadPreferences();
        
        // Vérifier si le navigateur supporte les notifications
        if ('Notification' in window) {
            this.checkNotificationPermission();
        }
    },
    
    // Configuration des écouteurs d'événements
    setupEventListeners: function() {
        // Bouton de configuration des notifications
        const configButton = document.getElementById('notification-config');
        if (configButton) {
            configButton.addEventListener('click', () => this.openNotificationModal());
        }
        
        // Formulaire de configuration des notifications
        const notificationForm = document.getElementById('notification-form');
        if (notificationForm) {
            notificationForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.savePreference();
            });
            
            // Changement de type de notification
            const typeSelect = document.getElementById('notification-type');
            if (typeSelect) {
                typeSelect.addEventListener('change', () => this.toggleCourseSelector());
            }
        }
        
        // Fermeture du modal
        const closeButton = document.getElementById('close-notification-modal');
        if (closeButton) {
            closeButton.addEventListener('click', () => this.closeNotificationModal());
        }
        
        // Fermeture du modal par overlay
        const overlay = document.getElementById('modal-overlay');
        if (overlay) {
            overlay.addEventListener('click', () => this.closeNotificationModal());
        }
    },
    
    // Charger les préférences de notification
    loadPreferences: async function() {
        try {
            const response = await fetch('/api/notifications.php');
            
            if (response.ok) {
                const data = await response.json();
                this.state.preferences = data;
                
                // Si le calendrier est chargé, stocker la liste des cours
                if (window.AgendaModule && window.AgendaModule.state.events) {
                    this.state.coursesList = window.AgendaModule.state.events.filter(e => e.type === 'cours');
                }
            }
        } catch (error) {
            console.error('Erreur lors du chargement des préférences:', error);
        }
    },
    
    // Ouvrir le modal de configuration des notifications
    openNotificationModal: function() {
        const modal = document.getElementById('notification-modal');
        const overlay = document.getElementById('modal-overlay');
        
        if (modal && overlay) {
            // Remplir le sélecteur de cours si nécessaire
            this.populateCourseSelector();
            
            // Afficher le modal
            overlay.style.display = 'block';
            modal.style.display = 'block';
        }
    },
    
    // Fermer le modal de configuration des notifications
    closeNotificationModal: function() {
        const modal = document.getElementById('notification-modal');
        const overlay = document.getElementById('modal-overlay');
        
        if (modal && overlay) {
            overlay.style.display = 'none';
            modal.style.display = 'none';
        }
    },
    
    // Remplir le sélecteur de cours
    populateCourseSelector: function() {
        const courseSelector = document.getElementById('course-selector');
        
        if (!courseSelector || !this.state.coursesList.length) {
            return;
        }
        
        // Vider le sélecteur
        courseSelector.innerHTML = '';
        
        // Créer une Map pour regrouper les cours par matière
        const coursesBySubject = new Map();
        
        for (const course of this.state.coursesList) {
            if (!coursesBySubject.has(course.title)) {
                coursesBySubject.set(course.title, []);
            }
            
            coursesBySubject.get(course.title).push(course);
        }
        
        // Ajouter les options regroupées par matière
        for (const [subject, courses] of coursesBySubject) {
            const optgroup = document.createElement('optgroup');
            optgroup.label = subject;
            
            for (const course of courses) {
                const option = document.createElement('option');
                option.value = course.id;
                
                // Formater la date et l'heure
                const date = new Date(course.start);
                const day = date.toLocaleDateString('fr-FR', {
                    weekday: 'long',
                    day: 'numeric',
                    month: 'long'
                });
                
                const time = date.toLocaleTimeString('fr-FR', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                option.textContent = `${day} à ${time}`;
                
                optgroup.appendChild(option);
            }
            
            courseSelector.appendChild(optgroup);
        }
    },
    
    // Afficher/masquer le sélecteur de cours
    toggleCourseSelector: function() {
        const type = document.getElementById('notification-type').value;
        const courseSelectorGroup = document.getElementById('course-selector-group');
        
        if (type === 'specific_course') {
            courseSelectorGroup.style.display = 'block';
            this.populateCourseSelector();
        } else {
            courseSelectorGroup.style.display = 'none';
        }
    },
    
    // Sauvegarder une préférence de notification
    savePreference: async function() {
        const form = document.getElementById('notification-form');
        const formData = new FormData(form);
        
        try {
            const response = await fetch('/api/notifications.php?action=save', {
                method: 'POST',
                body: formData
            });
            
            if (response.ok) {
                const data = await response.json();
                
                if (data.success) {
                    this.state.webPushEnabled = data.web_push_enabled;
                    
                    // Si les notifications push ne sont pas activées, proposer de les activer
                    if (!this.state.webPushEnabled && Notification.permission === 'granted') {
                        this.registerServiceWorker();
                    }
                    
                    // Fermer le modal
                    this.closeNotificationModal();
                    
                    // Afficher un message de confirmation
                    alert('Préférences de notification enregistrées.');
                } else {
                    alert('Erreur lors de l\'enregistrement des préférences.');
                }
            }
        } catch (error) {
            console.error('Erreur lors de l\'enregistrement des préférences:', error);
            alert('Erreur lors de l\'enregistrement des préférences.');
        }
    },
    
    // Vérifier la permission pour les notifications
    checkNotificationPermission: function() {
        if (Notification.permission === 'granted') {
            // Si les permissions sont accordées, vérifier si le service worker est enregistré
            if ('serviceWorker' in navigator) {
                this.registerServiceWorker();
            }
        } else if (Notification.permission !== 'denied') {
            // Demander la permission
            Notification.requestPermission().then(permission => {
                if (permission === 'granted') {
                    this.registerServiceWorker();
                }
            });
        }
    },
    
    // Enregistrer le service worker pour les notifications
    registerServiceWorker: async function() {
        if ('serviceWorker' in navigator) {
            try {
                const registration = await navigator.serviceWorker.register('/public/service-worker.js');
                
                // Attendre que le service worker soit activé
                if (registration.active) {
                    this.subscribeToPushNotifications(registration);
                } else {
                    registration.addEventListener('activate', e => {
                        this.subscribeToPushNotifications(registration);
                    });
                }
            } catch (error) {
                console.error('Erreur lors de l\'enregistrement du service worker:', error);
            }
        }
    },
    
    // S'abonner aux notifications push
    subscribeToPushNotifications: async function(registration) {
        try {
            // Vérifier si l'utilisateur est déjà abonné
            let subscription = await registration.pushManager.getSubscription();
            
            if (!subscription) {
                // Créer un nouvel abonnement
                const response = await fetch('/api/notifications.php?action=get_vapid_key');
                const data = await response.json();
                
                if (data.publicKey) {
                    const convertedKey = this.urlBase64ToUint8Array(data.publicKey);
                    
                    subscription = await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: convertedKey
                    });
                }
            }
            
            // Enregistrer l'abonnement sur le serveur
            if (subscription) {
                await fetch('/api/notifications.php?action=register_push', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        subscription,
                        csrf_token: document.querySelector('input[name="csrf_token"]').value
                    })
                });
                
                this.state.webPushEnabled = true;
            }
        } catch (error) {
            console.error('Erreur lors de l\'abonnement aux notifications push:', error);
        }
    },
    
    // Convertir une clé base64 URL en Uint8Array
    urlBase64ToUint8Array: function(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');
        
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        
        return outputArray;
    }
};

// Initialisation du module de notifications
document.addEventListener('DOMContentLoaded', function() {
    // Attendre que le module Agenda soit chargé
    if (window.AgendaModule) {
        NotificationsModule.init();
    } else {
        // Attendre que le module Agenda se charge
        const checkAgendaModule = setInterval(() => {
            if (window.AgendaModule) {
                clearInterval(checkAgendaModule);
                NotificationsModule.init();
            }
        }, 100);
    }
});