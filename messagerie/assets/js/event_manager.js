/**
 * Système d'événements centralisé pour la messagerie
 * Permet la communication entre les différents modules via un modèle pub/sub
 */
const EventManager = {
    events: {},
    
    /**
     * S'abonner à un événement
     * @param {string} eventName - Nom de l'événement
     * @param {function} callback - Fonction de callback
     * @returns {function} Fonction pour se désabonner
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
     * @returns {boolean} True si des abonnés ont été notifiés
     */
    publish: function(eventName, data) {
        if (!this.events[eventName]) return false;
        
        this.events[eventName].forEach(callback => {
            try {
                callback(data);
            } catch (error) {
                console.error(`Erreur lors de l'exécution d'un callback pour l'événement "${eventName}":`, error);
            }
        });
        
        return true;
    },
    
    /**
     * Supprimer tous les abonnements pour un événement
     * @param {string} eventName - Nom de l'événement (optionnel, tous les événements si omis)
     */
    unsubscribeAll: function(eventName) {
        if (eventName) {
            delete this.events[eventName];
        } else {
            this.events = {};
        }
    },
    
    /**
     * Vérifie si un événement a des abonnés
     * @param {string} eventName - Nom de l'événement
     * @returns {boolean} True si l'événement a des abonnés
     */
    hasSubscribers: function(eventName) {
        return this.events[eventName] && this.events[eventName].length > 0;
    },
    
    /**
     * Obtient le nombre d'abonnés pour un événement
     * @param {string} eventName - Nom de l'événement
     * @returns {number} Nombre d'abonnés
     */
    getSubscribersCount: function(eventName) {
        return this.events[eventName] ? this.events[eventName].length : 0;
    },
    
    /**
     * Abonnement à un événement avec exécution unique
     * @param {string} eventName - Nom de l'événement
     * @param {function} callback - Fonction de callback
     * @returns {function} Fonction pour se désabonner
     */
    subscribeOnce: function(eventName, callback) {
        const unsubscribe = this.subscribe(eventName, function(data) {
            unsubscribe();
            callback(data);
        });
        
        return unsubscribe;
    }
};