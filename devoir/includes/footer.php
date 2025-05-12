</main>
    
    <!-- Pied de page -->
    <footer class="footer">
        <div class="container footer-container">
            <div class="footer-copyright">
                &copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?> - Tous droits réservés
            </div>
            <div class="footer-links">
                <a href="<?php echo BASE_URL; ?>/mentions-legales.php">Mentions légales</a>
                <a href="<?php echo BASE_URL; ?>/confidentialite.php">Confidentialité</a>
                <a href="<?php echo BASE_URL; ?>/help.php">Aide</a>
                <a href="<?php echo BASE_URL; ?>/contact.php">Contact</a>
            </div>
        </div>
    </footer>
    
    <!-- Scripts JavaScript -->
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
    
    <?php if (isset($extraJs) && is_array($extraJs)): ?>
        <?php foreach ($extraJs as $js): ?>
            <script src="<?php echo BASE_URL; ?>/assets/js/<?php echo $js; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <?php if (isset($includeCalendar) && $includeCalendar): ?>
        <!-- FullCalendar -->
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales-all.min.js"></script>
    <?php endif; ?>
    
    <?php if (isset($includeTinyMCE) && $includeTinyMCE): ?>
        <!-- TinyMCE pour les éditeurs de texte enrichi -->
        <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/5/tinymce.min.js"></script>
        <script>
            tinymce.init({
                selector: '.tinymce',
                height: 300,
                menubar: false,
                plugins: [
                    'advlist autolink lists link image charmap print preview anchor',
                    'searchreplace visualblocks code fullscreen',
                    'insertdatetime media table paste code help wordcount'
                ],
                toolbar: 'undo redo | formatselect | bold italic backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat | help',
                content_style: 'body { font-family: Roboto, Arial, sans-serif; font-size: 14px }'
            });
        </script>
    <?php endif; ?>
    
    <?php if (isset($pageScript)): ?>
        <script>
            <?php echo $pageScript; ?>
        </script>
    <?php endif; ?>
</body>
</html>