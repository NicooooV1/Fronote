<?php
declare(strict_types=1);
/**
 * Widget : Annonces récentes
 * Variables disponibles : $data (tableau retourné par AnnonceWidgetProvider::getData)
 */
$annonces = $data['annonces'] ?? [];
$typeIcons = [
    'info'      => 'fas fa-info-circle',
    'urgent'    => 'fas fa-exclamation-triangle',
    'evenement' => 'fas fa-calendar',
    'sondage'   => 'fas fa-poll',
];
?>
<?php if (empty($annonces)): ?>
    <p class="widget-empty"><?= __('annonces.no_annonces') ?></p>
<?php else: ?>
    <ul class="widget-list">
        <?php foreach ($annonces as $a): ?>
        <li class="widget-list-item<?= !empty($a['epingle']) ? ' widget-list-item--pinned' : '' ?>">
            <span class="widget-list-icon">
                <i class="<?= htmlspecialchars($typeIcons[$a['type']] ?? 'fas fa-bullhorn') ?>"></i>
            </span>
            <div class="widget-list-content">
                <div class="widget-list-title"><?= htmlspecialchars($a['titre']) ?></div>
                <div class="widget-list-meta">
                    <?= htmlspecialchars(date('d/m/Y', strtotime($a['date_publication']))) ?>
                    <?php if (!empty($a['epingle'])): ?>
                        <span class="badge-pinned"><?= __('annonces.epinglee') ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <a href="annonces/annonces.php" class="widget-link">Voir toutes les annonces &rarr;</a>
<?php endif; ?>
