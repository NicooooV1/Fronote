<?php
/**
 * Pied de page commun pour le module Cahier de Textes
 * Utilise le système de design unifié de Pronote
 */
?>
      </div><!-- .content-container -->
    </div><!-- .main-content -->
  </div><!-- .app-container -->

  <script>
  document.addEventListener('DOMContentLoaded', function() {
    // Gestion des messages d'alerte
    document.querySelectorAll('.alert-close').forEach(button => {
      button.addEventListener('click', function() {
        const alert = this.parentElement;
        alert.style.opacity = '0';
        setTimeout(() => {
          alert.style.display = 'none';
        }, 300);
      });
    });
    
    // Auto-masquer les alertes après un délai
    document.querySelectorAll('.alert-banner:not(.alert-persistent)').forEach(alert => {
      setTimeout(() => {
        alert.style.opacity = '0';
        setTimeout(() => {
          alert.style.display = 'none';
        }, 300);
      }, 5000);
    });

    // Activation des liens de la sidebar
    const currentPath = window.location.pathname;
    const filename = currentPath.substring(currentPath.lastIndexOf('/') + 1);
    
    document.querySelectorAll('.sidebar-link').forEach(link => {
      const linkPath = link.getAttribute('href');
      if (linkPath && linkPath.includes(filename)) {
        link.classList.add('active');
      }
    });
  });
  </script>
</body>
</html>
