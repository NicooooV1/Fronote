<?php
/**
 * Pied de page HTML commun
 */
?>
    </div><!-- Fin du .container -->
    
    <!-- Scripts communs -->
    <script src="<?= $baseUrl ?>assets/js/main.js"></script>
    
    <?php if (in_array($currentPage, ['conversation'])): ?>
    <script src="<?= $baseUrl ?>assets/js/conversation.js"></script>
    <?php endif; ?>
    
    <?php if (in_array($currentPage, ['new_message', 'new_announcement', 'class_message'])): ?>
    <script src="<?= $baseUrl ?>assets/js/forms.js"></script>
    <?php endif; ?>

    <script src="<?= $baseUrl ?>assets/js/notifications.js"></script>
</body>
</html>