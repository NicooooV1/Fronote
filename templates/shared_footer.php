<?php
declare(strict_types=1);
/**
 * Template partagé : Footer + fermeture HTML
 * 
 * Variable optionnelle :
 *   $extraJs — array : fichiers JS supplémentaires à charger
 *   $extraScriptHtml — string : bloc <script> supplémentaire inline
 */

$extraJs = $extraJs ?? [];
$extraScriptHtml = $extraScriptHtml ?? '';
?>

        <!-- Footer -->
        <footer class="footer">
            <div class="footer-content">
                <div class="footer-links">
                    <button type="button" id="openLegalModal" class="footer-legal-btn">Mentions Légales</button>
                </div>
                <div class="footer-copyright">
                    &copy; <?= date('Y') ?> FRONOTE - Tous droits réservés
                </div>
            </div>
        </footer>
    </div><!-- Fin main-content -->
</div><!-- Fin app-container -->

<?php
/* ─── Bottom-bar mobile par rôle (design system, §10.2) ───────────────────────
 * Affichée uniquement sur mobile (CSS .ds-bottom-bar @media <1024px) pour les
 * utilisateurs connectés ; visibilité gérée par assets/js/ui/interactions.js. */
$_bb_role = function_exists('getUserRole') ? (string) (getUserRole() ?? '') : '';
if ($_bb_role !== ''):
    $_bb_active = $activePage ?? '';
    $_bb_rp = $rootPrefix ?? '';
    // Items réutilisables [libellé, icône, href, clé $activePage] — définis une fois, composés par rôle.
    $bi = [
        'accueil'  => ['Accueil', 'fa-house', 'accueil/accueil.php', 'accueil'],
        'messages' => ['Messages', 'fa-envelope', 'modules/messagerie/index.php', 'messagerie'],
        'profil'   => ['Profil', 'fa-user', 'parametres/parametres.php', 'parametres'],
        'notes'    => ['Notes', 'fa-chart-column', 'modules/notes/notes.php', 'notes'],
        'absences' => ['Absences', 'fa-calendar-xmark', 'modules/absences/absences.php', 'absences'],
        'edt'      => ['EDT', 'fa-calendar-days', 'modules/emploi_du_temps/emploi_du_temps.php', 'emploi_du_temps'],
        'devoirs'  => ['Devoirs', 'fa-book', 'modules/cahierdetextes/cahierdetextes.php', 'cahierdetextes'],
        'cahier'   => ['Cahier', 'fa-book', 'modules/cahierdetextes/cahierdetextes.php', 'cahierdetextes'],
        'appel'    => ['Appel', 'fa-clipboard-check', 'modules/appel/appel.php', 'appel'],
        'admin'    => ['Admin', 'fa-gauge-high', 'admin/dashboard.php', 'admin'],
        'comptes'  => ['Comptes', 'fa-users', 'admin/users/index.php', 'admin_users'],
    ];
    $_bb_map = [
        'eleve'         => [$bi['accueil'], $bi['edt'], $bi['notes'], $bi['devoirs'], $bi['messages']],
        'parent'        => [$bi['accueil'], $bi['notes'], $bi['absences'], $bi['messages'], $bi['profil']],
        'professeur'    => [$bi['accueil'], $bi['appel'], $bi['notes'], $bi['cahier'], $bi['messages']],
        'vie_scolaire'  => [$bi['accueil'], $bi['absences'], $bi['appel'], $bi['messages'], $bi['profil']],
        'administrateur'=> [$bi['accueil'], $bi['admin'], $bi['comptes'], $bi['messages'], $bi['profil']],
    ];
    $_bb_map['super_admin'] = $_bb_map['administrateur'];
    $_bb_items = $_bb_map[$_bb_role] ?? [$bi['accueil'], $bi['messages'], $bi['profil']];
?>
<nav class="ds-bottom-bar" role="navigation" aria-label="Navigation mobile">
    <?php foreach ($_bb_items as [$_bbLbl, $_bbIcon, $_bbHref, $_bbKey]): $_bbOn = ($_bb_active === $_bbKey); ?>
    <a class="ds-bottom-bar-item<?= $_bbOn ? ' is-active' : '' ?>" href="<?= htmlspecialchars($_bb_rp . $_bbHref) ?>"<?= $_bbOn ? ' aria-current="page"' : '' ?>>
        <span class="ds-bottom-bar-item-icon"><i class="fas <?= $_bbIcon ?>"></i></span>
        <span class="ds-bottom-bar-item-label"><?= htmlspecialchars($_bbLbl) ?></span>
    </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<!-- Modal Mentions Légales -->
<div id="mentions-legales-modal" class="legal-modal-overlay">
    <div class="legal-modal">
        <button class="legal-modal-close" id="closeLegalModal" type="button">&times;</button>
        <h2>Mentions Légales</h2>
        <div class="legal-modal-body">
            <h3>Éditeur</h3>
            <p>FRONOTE — Application de gestion scolaire.<br>
               Ce logiciel est un projet à but éducatif.</p>

            <h3>Hébergement</h3>
            <p>Cette application est hébergée en réseau local par l'établissement.</p>

            <h3>Données personnelles</h3>
            <p>Les données personnelles recueillies sont exclusivement destinées à la gestion scolaire. Conformément au RGPD, vous disposez d'un droit d'accès, de rectification et de suppression de vos données. Pour toute demande, contactez l'administrateur de l'établissement.</p>

            <h3>Propriété intellectuelle</h3>
            <p>L'ensemble du contenu de cette application est protégé. Toute reproduction non autorisée est interdite.</p>
        </div>
    </div>
</div>

<?php foreach ($extraJs as $js): ?>
<script src="<?= htmlspecialchars(asset_bust($js)) ?>" nonce="<?= csp_nonce() ?>"></script>
<?php endforeach; ?>

<script nonce="<?= $_hdr_nonce ?? '' ?>">
document.addEventListener('DOMContentLoaded', function() {
    // ─── Legal Modal ────────────────────────────────────────────
    var legalModal = document.getElementById('mentions-legales-modal');
    var openBtn = document.getElementById('openLegalModal');
    var closeBtn = document.getElementById('closeLegalModal');

    if (openBtn && legalModal) {
        openBtn.addEventListener('click', function(e) {
            e.preventDefault();
            legalModal.classList.add('is-visible');
        });
    }
    if (closeBtn && legalModal) {
        closeBtn.addEventListener('click', function() {
            legalModal.classList.remove('is-visible');
        });
    }
    if (legalModal) {
        legalModal.addEventListener('click', function(e) {
            if (e.target === legalModal) legalModal.classList.remove('is-visible');
        });
    }

    // ─── Auto-fermeture des alertes ─────────────────────────────
    document.querySelectorAll('.alert-close').forEach(function(button) {
        button.addEventListener('click', function() {
            var alert = this.closest('.alert-banner, .alert');
            if (alert) {
                alert.style.opacity = '0';
                setTimeout(function() { alert.style.display = 'none'; }, 300);
            }
        });
    });

    document.querySelectorAll('.alert-success').forEach(function(alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            setTimeout(function() { alert.style.display = 'none'; }, 300);
        }, 5000);
    });
});
</script>

<?= $extraScriptHtml ?>

<?php // Dev toolbar (non-production only)
require_once __DIR__ . '/dev_toolbar.php';
?>

</body>
</html>
