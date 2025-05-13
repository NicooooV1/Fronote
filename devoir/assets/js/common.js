/**
 * assets/js/common.js
 * Fonctions JavaScript communes à toutes les pages
 */

document.addEventListener('DOMContentLoaded', function() {
    /**
     * Système de notification
     * @param {string} message - Message à afficher
     * @param {string} type - Type de notification (success, error)
     */
    window.showNotification = function(message, type = 'success') {
        const notification = document.getElementById('notification');
        if (!notification) {
            // Créer l'élément de notification s'il n'existe pas
            const newNotification = document.createElement('div');
            newNotification.id = 'notification';
            newNotification.className = `pronote-notification pronote-notification-${type}`;
            
            const icon = document.createElement('i');
            icon.className = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
            
            const span = document.createElement('span');
            span.textContent = message;
            
            newNotification.appendChild(icon);
            newNotification.appendChild(span);
            document.body.appendChild(newNotification);
            
            // Disparition automatique après 3 secondes
            setTimeout(() => {
                newNotification.remove();
            }, 3000);
        } else {
            // Utiliser l'élément existant
            notification.className = `pronote-notification pronote-notification-${type}`;
            notification.querySelector('span').textContent = message;
            notification.style.display = 'flex';
            
            // Disparition automatique après 3 secondes
            setTimeout(() => {
                notification.style.display = 'none';
            }, 3000);
        }
    };
    
    /**
     * Formatage des dates en français
     * @param {string|Date} dateStr - Date à formater
     * @param {boolean} includeTime - Inclure l'heure
     * @returns {string} - Date formatée
     */
    window.formatDate = function(dateStr, includeTime = false) {
        if (!dateStr) return '';
        
        const date = dateStr instanceof Date ? dateStr : new Date(dateStr);
        
        // Tableaux pour la traduction
        const jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
        const mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        
        const jour = jours[date.getDay()];
        const jourNum = date.getDate();
        const moisNom = mois[date.getMonth()];
        const annee = date.getFullYear();
        
        let formatted = `${jour} ${jourNum} ${moisNom} ${annee}`;
        
        if (includeTime) {
            const heures = String(date.getHours()).padStart(2, '0');
            const minutes = String(date.getMinutes()).padStart(2, '0');
            formatted += ` à ${heures}h${minutes}`;
        }
        
        return formatted;
    };
    
    /**
     * Fonction pour fermer les modales
     */
    document.addEventListener('click', function(e) {
        // Gestion des boutons de fermeture de modal
        if (e.target.classList.contains('pronote-modal-close') || 
            e.target.id === 'btn-annuler') {
            const modal = e.target.closest('.pronote-modal-backdrop');
            if (modal) modal.style.display = 'none';
        }
        
        // Fermer la modale si on clique en dehors
        if (e.target.classList.contains('pronote-modal-backdrop')) {
            e.target.style.display = 'none';
        }
    });
    
    /**
     * Fonction de confirmation personnalisée
     * @param {string} message - Message de confirmation
     * @param {Function} onConfirm - Fonction à exécuter en cas de confirmation
     */
    window.confirmAction = function(message, onConfirm) {
        if (confirm(message)) {
            onConfirm();
        }
    };
});