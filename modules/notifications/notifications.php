<?php
declare(strict_types=1);
/**
 * Notifications — historique complet (filtres par catégorie, non lues, actions groupées).
 * Interface pilotée par assets/js/notifications-page.js (API/endpoints/notifications.php).
 */
$pageTitle = 'Notifications';
require_once __DIR__ . '/includes/header.php';

use API\Services\Notifications\NotificationCenter;

$cats = NotificationCenter::CATEGORIES;
?>
<div class="ncp" id="ncp" data-api="<?= htmlspecialchars($rootPrefix) ?>API/endpoints/notifications.php" data-base="<?= htmlspecialchars($rootPrefix) ?>">
  <div class="ncp-toolbar">
    <div class="ncp-filters" role="group" aria-label="Catégories">
      <button type="button" class="ncp-chip" data-cat="" aria-pressed="true">Tout</button>
      <?php foreach ($cats as $k => [$label, , $icon]): ?>
      <button type="button" class="ncp-chip" data-cat="<?= htmlspecialchars($k) ?>" aria-pressed="false"><i class="fas <?= htmlspecialchars($icon) ?>" aria-hidden="true"></i> <?= htmlspecialchars($label) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="ncp-actions">
      <label class="ncp-switch"><input type="checkbox" id="ncp-unread"> <span>Non lues seulement</span></label>
      <button type="button" class="ncp-btn" data-act="readall"><i class="fas fa-check-double" aria-hidden="true"></i> Tout marquer comme lu</button>
      <button type="button" class="ncp-btn ncp-btn--muted" data-act="clean"><i class="far fa-trash-alt" aria-hidden="true"></i> Effacer les lues</button>
    </div>
  </div>
  <ul class="ncp-list" id="ncp-list" aria-live="polite"></ul>
  <div class="ncp-foot"><button type="button" class="ncp-btn" id="ncp-more" hidden>Afficher plus</button></div>
</div>
<?php
$extraJs = ['assets/js/notifications-page.js'];
require_once __DIR__ . '/includes/footer.php';
