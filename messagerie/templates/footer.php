<?php
/**
 * /templates/footer.php - Pied de page HTML commun
 */
?>
    </div><!-- Fin du .container -->
    
    <!-- Scripts communs -->
    <script src="<?= $baseUrl ?>assets/js/main.js"></script>
    <script src="<?= $baseUrl ?>assets/js/realtime-messages.js"></script>
    
    <!-- Scripts spécifiques à certaines pages -->
    <?php if ($currentPage === 'index'): ?>
    <script>
        // Code spécifique à la page d'index si nécessaire
    </script>
    <?php endif; ?>
    
    <?php if ($currentPage === 'conversation'): ?>
    <script src="<?= $baseUrl ?>assets/js/conversation.js"></script>
    <script src="<?= $baseUrl ?>assets/js/message-actions.js"></script>
    <?php endif; ?>
    
    <?php if (in_array($currentPage, ['new_message', 'new_announcement', 'class_message'])): ?>
    <script src="<?= $baseUrl ?>assets/js/participants.js"></script>
    <?php endif; ?>
</body>
</html>