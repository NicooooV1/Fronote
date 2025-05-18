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
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete';
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'conv_id';
            idInput.value = id;
            
            form.appendChild(actionInput);
            form.appendChild(idInput);
            document.body.appendChild(form);
            form.submit();
        }
        event.stopPropagation();
    }
    
    function confirmDeletePermanently(id) {
        if (confirm('Êtes-vous sûr de vouloir supprimer définitivement cette conversation ? Cette action est irréversible.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_permanently';
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'conv_id';
            idInput.value = id;
            
            form.appendChild(actionInput);
            form.appendChild(idInput);
            document.body.appendChild(form);
            form.submit();
        }
        event.stopPropagation();
    }
    
    function archiveConversation(id) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'archive';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'conv_id';
        idInput.value = id;
        
        form.appendChild(actionInput);
        form.appendChild(idInput);
        document.body.appendChild(form);
        form.submit();
        
        event.stopPropagation();
    }
    
    function unarchiveConversation(id) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'unarchive';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'conv_id';
        idInput.value = id;
        
        form.appendChild(actionInput);
        form.appendChild(idInput);
        document.body.appendChild(form);
        form.submit();
        
        event.stopPropagation();
    }
    
    function restoreConversation(id) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'restore';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'conv_id';
        idInput.value = id;
        
        form.appendChild(actionInput);
        form.appendChild(idInput);
        document.body.appendChild(form);
        form.submit();
        
        event.stopPropagation();
    }
    </script>
</body>
</html>