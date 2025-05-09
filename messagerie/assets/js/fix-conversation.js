/**
 * /assets/js/fix-conversation.js - Correctifs pour l'affichage des conversations
 */

document.addEventListener('DOMContentLoaded', function() {
    // Faire défiler jusqu'en bas au chargement initial
    const messagesContainer = document.querySelector('.messages-container');
    if (messagesContainer) {
        // Utiliser un petit délai pour s'assurer que tout est chargé
        setTimeout(function() {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }, 100);
    }
    
    // S'assurer que les classes CSS sont correctement appliquées
    document.querySelectorAll('.message').forEach(message => {
        // Vérifier l'alignement des messages
        if (message.classList.contains('self')) {
            // Forcer l'alignement à droite si nécessaire
            if (window.getComputedStyle(message).alignSelf !== 'flex-end') {
                message.style.alignSelf = 'flex-end';
            }
        } else {
            // Forcer l'alignement à gauche si nécessaire
            if (window.getComputedStyle(message).alignSelf !== 'flex-start') {
                message.style.alignSelf = 'flex-start';
            }
        }
    });
    
    // Amélioration pour les nouveaux messages
    const originalAppendMessageToDOM = window.appendMessageToDOM;
    if (typeof originalAppendMessageToDOM === 'function') {
        window.appendMessageToDOM = function(message, container) {
            // Appeler la fonction originale
            originalAppendMessageToDOM(message, container);
            
            // Faire défiler vers le bas après ajout de message
            if (container) {
                container.scrollTop = container.scrollHeight;
            }
        };
    }
});

// S'assurer que ces fonctions sont disponibles globalement
window.scrollToBottom = function(element) {
    if (!element) return;
    element.scrollTop = element.scrollHeight;
};

window.isScrolledToBottom = function(element) {
    if (!element) return true;
    return Math.abs(element.scrollHeight - element.scrollTop - element.clientHeight) < 20;
};