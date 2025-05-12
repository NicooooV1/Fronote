/**
 * Système d'événements centralisé pour la messagerie
 */
const EventManager = {
    events: {},
    
    /**
     * S'abonner à un événement
     * @param {string} eventName - Nom de l'événement
     * @param {function} callback - Fonction de callback
     */
    subscribe: function(eventName, callback) {
        if (!this.events[eventName]) {
            this.events[eventName] = [];
        }
        this.events[eventName].push(callback);
        
        // Retourner une fonction pour se désabonner
        return () => {
            this.events[eventName] = this.events[eventName].filter(cb => cb !== callback);
        };
    },
    
    /**
     * Publier un événement
     * @param {string} eventName - Nom de l'événement
     * @param {any} data - Données à transmettre
     */
    publish: function(eventName, data) {
        if (!this.events[eventName]) return;
        
        this.events[eventName].forEach(callback => {
            callback(data);
        });
    },
    
    /**
     * Supprimer tous les abonnements pour un événement
     * @param {string} eventName - Nom de l'événement
     */
    unsubscribeAll: function(eventName) {
        if (eventName) {
            delete this.events[eventName];
        } else {
            this.events = {};
        }
    }
};