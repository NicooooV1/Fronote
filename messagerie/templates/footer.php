<?php
/**
 * /templates/footer.php - Pied de page HTML commun
 */
?>
    </div><!-- Fin du .container -->
    
    <!-- Scripts communs -->
    <script src="<?= $baseUrl ?>assets/js/main.js"></script>
    <script src="<?= $baseUrl ?>assets/js/lightweight-realtime.js"></script>
    
    <!-- Scripts spécifiques à certaines pages -->
    <?php if ($currentPage === 'conversation'): ?>
    <script src="<?= $baseUrl ?>assets/js/conversation.js"></script>
    <script src="<?= $baseUrl ?>assets/js/message-actions.js"></script>
    <script src="<?= $baseUrl ?>assets/js/fix-conversation.js"></script>
    <?php endif; ?>
    
    <?php if (in_array($currentPage, ['new_message', 'new_announcement', 'class_message'])): ?>
    <script src="<?= $baseUrl ?>assets/js/participants.js"></script>
    <?php endif; ?>
</body>
</html>