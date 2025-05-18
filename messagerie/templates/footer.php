<?php
/**
 * Pied de page HTML commun
 */
?>
    </div><!-- Fin content-container -->
        </div><!-- Fin main-content -->
    </div><!-- Fin app-container -->
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/js/main.js"></script>
    
    <?php if (basename($_SERVER['PHP_SELF']) === 'conversation.php'): ?>
    <script src="assets/js/conversation.js"></script>
    <?php endif; ?>
    
    <?php if (in_array(basename($_SERVER['PHP_SELF']), ['new_message.php', 'new_announcement.php', 'class_message.php'])): ?>
    <script src="assets/js/forms.js"></script>
    <?php endif; ?>
    
    <script>
    // Script pour les actions sur les conversations
    function confirmDelete(id) {
        if (confirm('Êtes-vous sûr de vouloir supprimer cette conversation ?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="conv_id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function confirmDeletePermanently(id) {
        if (confirm('Êtes-vous sûr de vouloir supprimer définitivement cette conversation ? Cette action est irréversible.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_permanently">
                <input type="hidden" name="conv_id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function archiveConversation(id) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        form.innerHTML = `
            <input type="hidden" name="action" value="archive">
            <input type="hidden" name="conv_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
    
    function unarchiveConversation(id) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        form.innerHTML = `
            <input type="hidden" name="action" value="unarchive">
            <input type="hidden" name="conv_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
    
    function restoreConversation(id) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        form.innerHTML = `
            <input type="hidden" name="action" value="restore">
            <input type="hidden" name="conv_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
    </script>
</body>
</html>