<?php
/**
 * Template de pied de page pour PRONOTE
 */
?>
            <!-- Footer -->
            <div class="footer">
                <div class="footer-content">
                    <div class="footer-links">
                        <a href="#">Mentions Légales</a>
                        <a href="#">Aide</a>
                        <a href="#">Contact</a>
                    </div>
                    <div class="footer-copyright">
                        &copy; <?= date('Y') ?> PRONOTE - Tous droits réservés
                    </div>
                </div>
            </div>
        </div> <!-- Fin de main-content -->
    </div> <!-- Fin de app-container -->

    <script>
        // Navigation mobile
        document.addEventListener('DOMContentLoaded', function() {
            // Gestion du menu mobile
            const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
            const sidebar = document.getElementById('sidebar');
            const pageOverlay = document.getElementById('page-overlay');
            
            if (mobileMenuToggle && sidebar && pageOverlay) {
                mobileMenuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('mobile-visible');
                    pageOverlay.classList.toggle('visible');
                });
                
                pageOverlay.addEventListener('click', function() {
                    sidebar.classList.remove('mobile-visible');
                    pageOverlay.classList.remove('visible');
                });
            }
            
            // Messages d'alerte auto-disparaissants
            const alerts = document.querySelectorAll('.alert');
            if (alerts.length > 0) {
                alerts.forEach(alert => {
                    setTimeout(() => {
                        alert.style.opacity = '0';
                        setTimeout(() => {
                            alert.style.display = 'none';
                        }, 300);
                    }, 5000);
                });
            }
            
            <?php if (isset($customScripts)): echo $customScripts; endif; ?>
        });
    </script>
    
    <?php if (isset($footerScripts)): echo $footerScripts; endif; ?>
</body>
</html>
