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
            if (!response.ok) throw new Error(`HTTP error: ${response.status}`);
            
            // Vérifier si la réponse est JSON
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                return await response.json();
            }
            
            // Sinon retourner le texte
            return await response.text();
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
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({...data, csrf_token: csrfToken})
            });
            
            if (!response.ok) throw new Error(`HTTP error: ${response.status}`);
            
            // Vérifier si la réponse est JSON
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                return await response.json();
            }
            
            // Sinon retourner le texte
            return await response.text();
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
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            
            // Ajouter le token CSRF si pas déjà présent
            if (!formData.has('csrf_token')) {
                formData.append('csrf_token', csrfToken);
            }
            
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken
                },
                body: formData
            });
            
            if (!response.ok) throw new Error(`HTTP error: ${response.status}`);
            
            // Vérifier si la réponse est JSON
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                return await response.json();
            }
            
            // Sinon retourner le texte
            return await response.text();
        } catch (error) {
            this.handleError(error);
            throw error;
        }
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
        
        // Afficher une notification à l'utilisateur si la fonction existe
        if (typeof afficherNotificationErreur === 'function') {
            afficherNotificationErreur(`Erreur de communication avec le serveur: ${error.message}`);
        }
    }
    
    /**
     * Vérifie si une réponse contient une redirection de session expirée
     * @param {Object} data - Données de réponse
     * @returns {boolean} True si redirection nécessaire
     */
    static checkSessionExpired(data) {
        if (data && data.redirect && !data.success && data.error === 'Session expirée') {
            window.location.href = data.redirect;
            return true;
        }
        return false;
    }
}