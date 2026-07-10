<?php
declare(strict_types=1);
/**
 * Vie scolaire — Statistiques par classe
 */
require_once __DIR__ . '/includes/VieScolaireService.php';
$pageTitle = 'Statistiques par classe';

require_once __DIR__ . '/../../API/core.php';
requireAuth();
if (!isAdmin() && !isVieScolaire()) {
    header('Location: ../../accueil/accueil.php');
    exit;
}

require_once __DIR__ . '/includes/header.php';

$pdo = getPDO();
$service = new VieScolaireService($pdo);
$statsClasses = $service->getStatsParClasse();
?>

<div class="page-header">
    <h1><i class="fas fa-chart-bar"></i> Statistiques par classe</h1>
</div>

<div class="data-table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th><?= __('vie_scolaire.class') ?></th>
                <th class="text-center">Élèves</th>
                <th class="text-center"><?= __('vie_scolaire.absences') ?></th>
                <th class="text-center">Retards</th>
                <th class="text-center">Abs./élève</th>
                <th>Visualisation</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($statsClasses as $sc):
                $absParEleve = $sc['nb_eleves'] > 0 ? round((float) ($sc['nb_absences'] / $sc['nb_eleves']), 1) : 0;
                $barWidth = min(100, $absParEleve * 10);
            ?>
            <tr>
                <td class="fw-500"><?= htmlspecialchars($sc['classe']) ?></td>
                <td class="text-center"><?= $sc['nb_eleves'] ?></td>
                <td class="text-center"><span class="badge badge-danger"><?= $sc['nb_absences'] ?></span></td>
                <td class="text-center"><span class="badge badge-warning"><?= $sc['nb_retards'] ?></span></td>
                <td class="text-center"><?= $absParEleve ?></td>
                <td>
                    <div class="mini-bar">
                        <div class="mini-bar-fill <?= $absParEleve > 5 ? 'bar-danger' : ($absParEleve > 3 ? 'bar-warning' : 'bar-success') ?>" style="width:<?= $barWidth ?>%"></div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
