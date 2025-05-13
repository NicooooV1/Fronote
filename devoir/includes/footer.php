<?php
// includes/footer.php - Pied de page commun à toutes les pages
if (!defined('INCLUDED')) {
    exit('Accès direct au fichier non autorisé');
}
?>

    <!-- Notification -->
    <div class="pronote-notification pronote-notification-success" id="notification" style="display: none;">
        <i class="fas fa-check-circle"></i>
        <span></span>
    </div>

    <script>
    // Fonctions JS communes
    document.addEventListener('DOMContentLoaded', function() {
        // Système de notification
        function showNotification(message, type = 'success') {
            const notification = document.getElementById('notification');
            notification.className = `pronote-notification pronote-notification-${type}`;
            notification.querySelector('span').textContent = message;
            notification.style.display = 'flex';
            
            // Disparition automatique après 3 secondes
            setTimeout(() => {
                notification.style.display = 'none';
            }, 3000);
        }
        
        // Notification provenant de PHP (redirection)
        <?php if ($notification): ?>
        showNotification('<?= addslashes($notification['message']) ?>', '<?= $notification['type'] ?>');
        <?php endif; ?>
        
        // Exposer la fonction showNotification au scope global
        window.showNotification = showNotification;
        
        // Délégation d'événements pour les actions communes
        document.addEventListener('click', function(e) {
            // Gestion des boutons de fermeture de modal
            if (e.target.classList.contains('pronote-modal-close') || 
                e.target.id === 'btn-annuler') {
                const modal = e.target.closest('.pronote-modal-backdrop');
                if (modal) modal.style.display = 'none';
            }
        });
    });
    </script>
</body>
</html>