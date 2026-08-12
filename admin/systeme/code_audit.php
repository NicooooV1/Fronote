<?php
declare(strict_types=1);
/**
 * Administration — Audit code (CDC §20)
 * Détecte coquilles vides, placeholders, endpoints sans CSRF et requêtes
 * métier sans scope etablissement_id.
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../includes/admin_functions.php';

requireAuth();
tenantGate('tenant.users.view');

$audit = new \API\Services\CodeAuditService(BASE_PATH);
$result = $audit->run();
$findings = $result['findings'];
$summary  = $result['summary'];

$sevBadge = static function (string $sev): string {
    $variant = match ($sev) {
        'critique', 'haute' => 'danger',
        'moyenne'           => 'warning',
        default             => 'default',
    };
    return ui_badge(ucfirst($sev), $variant);
};

$pageTitle = 'Audit code';
$currentPage = 'code_audit';

ob_start();
?>
<span class="fs-xs text-muted"><?= (int) $result['scanned'] ?> fichiers analysés</span>
<a href="code_audit.php" class="ui-btn ui-btn--ghost ui-btn--sm"><i class="fas fa-sync-alt"></i> Relancer</a>
<?php
$headerExtraActions = ob_get_clean();
include __DIR__ . '/../includes/header.php';
?>

<div class="content-body p-lg">

    <div class="d-flex gap-lg flex-wrap mb-lg">
        <?= ui_stat_card('Critiques', (string) $summary['critique'], ['icon' => 'fas fa-skull-crossbones', 'color' => $summary['critique'] ? 'danger' : 'success']) ?>
        <?= ui_stat_card('Hautes', (string) $summary['haute'], ['icon' => 'fas fa-exclamation-triangle', 'color' => $summary['haute'] ? 'danger' : 'success']) ?>
        <?= ui_stat_card('Moyennes', (string) $summary['moyenne'], ['icon' => 'fas fa-exclamation-circle', 'color' => $summary['moyenne'] ? 'warning' : 'success']) ?>
        <?= ui_stat_card('Faibles', (string) $summary['faible'], ['icon' => 'fas fa-info-circle', 'color' => 'primary']) ?>
    </div>

    <?php
    if (empty($findings)) {
        echo ui_card('Résultat', '<p class="text-success">Aucun problème détecté par les règles actuelles. 🎉</p>', ['icon' => 'fas fa-check-circle']);
    } else {
        $rows = [];
        foreach ($findings as $f) {
            $rows[] = [
                $sevBadge($f['severity']),
                '<span class="ui-badge ui-badge--default">' . e($f['category']) . '</span>',
                '<code>' . e($f['location']) . '</code>',
                e($f['message']),
                '<span class="text-muted">' . e($f['recommendation']) . '</span>',
            ];
        }
        echo ui_card('Problèmes détectés (' . count($findings) . ')', ui_table(
            [
                ['label' => 'Gravité', 'width' => '10%', 'align' => 'center'],
                ['label' => 'Catégorie', 'width' => '12%'],
                ['label' => 'Emplacement', 'width' => '24%'],
                ['label' => 'Problème'],
                ['label' => 'Recommandation', 'width' => '24%'],
            ],
            $rows
        ), ['icon' => 'fas fa-clipboard-list']);
    }
    ?>

    <p class="fs-xs text-muted mt-lg">
        Les catégories <strong>scope</strong> et <strong>csrf</strong> sont des heuristiques (analyse statique au niveau fichier) — vérifier manuellement avant correction.
    </p>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
