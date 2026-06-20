<?php
/** Espace ÉTABLISSEMENT — vitrine du Design System (cahier §31). Utilise l'en-tête partagé
 *  (le design-system.css + interactions.js sont chargés app-wide via shared_header). */
require_once __DIR__ . '/../API/core.php';
require_once __DIR__ . '/includes/admin_functions.php';

requireAuth();
tenantGate('tenant.users.view', ['administrateur', 'super_admin']);

$pageTitle = 'Design System';
include __DIR__ . '/includes/header.php';
?>
<div class="admin-container" style="padding:var(--space-5, 20px)">
    <div class="page-header"><h1><i class="fas fa-palette"></i> Design System</h1></div>
    <p class="text-muted">Composants, thèmes (clair / sombre / liquide) et interactions de la refonte. Modifiez le thème, les animations et la transparence en direct.</p>
    <?php $dsScope = 'tenant'; include __DIR__ . '/../templates/design_system_showcase.php'; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
