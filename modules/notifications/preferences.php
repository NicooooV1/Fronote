<?php
declare(strict_types=1);
/**
 * Notifications — préférences : pour chaque catégorie, les canaux souhaités
 * (dans l'application, récapitulatif e-mail quotidien, alerte sur l'appareil).
 */
$pageTitle = 'Préférences de notification';
require_once __DIR__ . '/includes/header.php';

use API\Services\Notifications\NotificationCenter;

$uid = (int) getUserId();
$utype = (string) getUserRole();
$nc = NotificationCenter::make();
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken()) {
    $in = (array) ($_POST['p'] ?? []);
    $clean = [];
    foreach (NotificationCenter::CATEGORIES as $c => $_) {
        $clean[$c] = ['web' => !empty($in[$c]['web']), 'email' => !empty($in[$c]['email']), 'push' => !empty($in[$c]['push'])];
    }
    $nc->savePreferences($uid, $utype, $clean);
    $saved = true;
}
$prefs = $nc->preferences($uid, $utype);
$channels = [
    'web'   => ['Dans Fronote', 'Cloche et alertes pendant que vous utilisez Fronote', 'fa-bell'],
    'email' => ['Récapitulatif e-mail', 'Un seul e-mail par jour, seulement s\'il y a du nouveau', 'fa-envelope'],
    'push'  => ['Sur cet appareil', 'Alerte du navigateur quand Fronote est en arrière-plan', 'fa-mobile-alt'],
];
?>
<div class="ncp ncp--prefs">
  <?php if ($saved): ?><div class="ncp-saved" role="status"><i class="fas fa-check-circle" aria-hidden="true"></i> Préférences enregistrées.</div><?php endif; ?>
  <p class="ncp-intro">Choisissez ce qui mérite votre attention. Les alertes <strong>urgentes</strong> de l'établissement vous parviennent toujours dans Fronote.</p>
  <form method="post" class="ncp-prefs">
    <?= csrfField() ?>
    <div class="ncp-table" role="table" aria-label="Préférences par catégorie">
      <div class="ncp-row ncp-row--head" role="row">
        <span role="columnheader">Catégorie</span>
        <?php foreach ($channels as [$label, $hint, $icon]): ?>
        <span role="columnheader" title="<?= htmlspecialchars($hint) ?>"><i class="fas <?= $icon ?>" aria-hidden="true"></i> <?= htmlspecialchars($label) ?></span>
        <?php endforeach; ?>
      </div>
      <?php foreach (NotificationCenter::CATEGORIES as $c => [$label, $desc, $icon]): ?>
      <div class="ncp-row" role="row">
        <span class="ncp-cat" role="cell"><span class="nc-ico" data-cat="<?= htmlspecialchars($c) ?>"><i class="fas <?= htmlspecialchars($icon) ?>" aria-hidden="true"></i></span>
          <span><b><?= htmlspecialchars($label) ?></b><small><?= htmlspecialchars($desc) ?></small></span></span>
        <?php foreach ($channels as $ch => [$chLabel]): ?>
        <span role="cell" class="ncp-cell"><label class="ncp-toggle"><input type="checkbox" name="p[<?= $c ?>][<?= $ch ?>]" value="1"<?= !empty($prefs[$c][$ch]) ? ' checked' : '' ?>><span aria-hidden="true"></span><em><?= htmlspecialchars($chLabel) ?></em></label></span>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="ncp-note"><i class="fas fa-info-circle" aria-hidden="true"></i> Les alertes « sur cet appareil » demandent votre accord au navigateur : activez-les depuis la cloche (bouton « Activer »).</p>
    <div class="ncp-submit"><button type="submit" class="ncp-btn ncp-btn--primary">Enregistrer</button></div>
  </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php';
