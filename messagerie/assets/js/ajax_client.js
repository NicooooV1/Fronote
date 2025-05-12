/**
 * Classe utilitaire pour gérer les requêtes AJAX
 */
class AjaxClient {
    /**
     * Effectue une requête GET
     * @param {string} url - URL de la requête
     * @param {Object} params - Paramètres de requête
     * @returns {Promise} Promise avec la réponse JSON
     */
    static async get(url, params = {}) {
        const queryParams = new URLSearchParams(params).toString();
        const fullUrl = queryParams ? `${url}?${queryParams}` : url;
        
        try {
            const response = await fetch(fullUrl);
            
            // Vérifier si la réponse est ok
            if (!response.ok) {
                throw new Error(`HTTP error: ${response.status}`);
            }
            
            // Vérifier si la réponse est JSON
            const contentType = response.headers.get('content-type');
            let data;
            
            if (contentType && contentType.includes('application/json')) {
                data = await response.json();
            } else {
                data = await response.text();
            }
            
            // Vérifier si la session a expiré
            if (this.checkSessionExpired(data)) {
                return data; // La redirection sera gérée par checkSessionExpired
            }
            
            return data;
        } catch (error) {
            this.handleError(error);
            throw error;
        }
    }
    
    /**
     * Effectue une requête POST avec données JSON
     * @param {string} url - URL de la requête
     * @param {Object} data - Données à envoyer
     * @returns {Promise} Promise avec la réponse JSON
     */
    static async post(url, data = {}) {
        try {
            // Récupérer le jeton CSRF
            const csrfToken = typeof Utils !== 'undefined' ? 
                              Utils.getCSRFToken() : 
                              document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            
            // Préparer les options de la requête
            const fetchOptions = {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({...data, csrf_token: csrfToken})
            };
            
            // Effectuer la requête
            const response = await fetch(url, fetchOptions);
            
            // Vérifier si la réponse est ok
            if (!response.ok) {
                throw new Error(`HTTP error: ${response.status}`);
            }
            
            // Vérifier si la réponse est JSON
            const contentType = response.headers.get('content-type');
            let responseData;
            
            if (contentType && contentType.includes('application/json')) {
                responseData = await response.json();
            } else {
                responseData = await response.text();
            }
            
            // Vérifier si la session a expiré
            if (this.checkSessionExpired(responseData)) {
                return responseData; // La redirection sera gérée par checkSessionExpired
            }
            
            return responseData;
        } catch (error) {
            this.handleError(error);
            throw error;
        }
    }
    
    /**
     * Envoie un formulaire via POST
     * @param {string} url - URL de la requête
     * @param {FormData} formData - Données du formulaire
     * @returns {Promise} Promise avec la réponse JSON
     */
    static async postForm(url, formData) {
        try {
            // Récupérer le jeton CSRF
            const csrfToken = typeof Utils !== 'undefined' ? 
                              Utils.getCSRFToken() : 
                              document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            
            // Ajouter le token CSRF si pas déjà présent
            if (!formData.has('csrf_token')) {
                formData.append('csrf_token', csrfToken);
            }
            
            // Effectuer la requête
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken
                },
                body: formData
            });
            
            // Vérifier si la réponse est ok
            if (!response.ok) {
                throw new Error(`HTTP error: ${response.status}`);
            }
            
            // Vérifier si la réponse est JSON
            const contentType = response.headers.get('content-type');
            let data;
            
            if (contentType && contentType.includes('application/json')) {
                data = await response.json();
            } else {
                data = await response.text();
            }
            
            // Vérifier si la session a expiré
            if (this.checkSessionExpired(data)) {
                return data; // La redirection sera gérée par checkSessionExpired
            }
            
            return data;
        } catch (error) {
            this.handleError(error);
            throw error;
        }
    }
    
    /**
     * Annule une requête en cours
     * @param {AbortController} controller - Contrôleur d'avortement
     */
    static abortRequest(controller) {
        if (controller && controller.abort) {
            controller.abort();
        }
    }
    
    /**
     * Crée un contrôleur d'avortement pour annuler une requête
     * @returns {AbortController} Contrôleur d'avortement
     */
    static createAbortController() {
        return new AbortController();
    }
    
    /**
     * Gère les erreurs de requête
     * @param {Error} error - Erreur survenue
     */
    static handleError(error) {
        console.error('AJAX error:', error);
        
        // Publier l'événement d'erreur
        if (typeof EventManager !== 'undefined') {
            EventManager.publish('ajax:error', {
                message: error.message,
                error: error
            });
        }
        
        // Afficher une notification à l'utilisateur
        if (typeof Notifications !== 'undefined') {
            Notifications.error(`Erreur de communication avec le serveur: ${error.message}`);
        } else if (typeof afficherNotificationErreur === 'function') {
            afficherNotificationErreur(`Erreur de communication avec le serveur: ${error.message}`);
        }
    }
    
    /**
     * Vérifie si une réponse contient une redirection de session expirée
     * @param {Object|string} data - Données de réponse
     * @returns {boolean} True si redirection nécessaire
     */
    static checkSessionExpired(data) {
        if (typeof data === 'object' && data && data.redirect && !data.success && data.error === 'Session expirée') {
            window.location.href = data.redirect;
            return true;
        }
        return false;
    }
}