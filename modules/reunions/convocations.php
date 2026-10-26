<?php
declare(strict_types=1);
/**
 * Réunions — MES CONVOCATIONS : les réunions où je suis attendu·e, avec réponse en un clic.
 */
$pageTitle = 'Mes convocations';
$pageSubtitle = 'Les réunions où vous êtes attendu·e : confirmez votre présence ou excusez-vous.';
require_once __DIR__ . '/includes/header.php';
$h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$aVenir = array_values(array_filter($hub->lister(['quand' => 'a_venir', 'mes' => true]), fn($r) => $r['mon_role'] !== null && $r['mon_role'] !== 'organisateur'));
$passees = array_slice(array_values(array_filter($hub->lister(['quand' => 'passees', 'mes' => true, 'limit' => 40]), fn($r) => $r['mon_role'] !== null)), 0, 12);
$attente = count(array_filter($aVenir, fn($r) => $r['ma_convocation'] === 'envoyee'));
?>
<div class="rn" data-csrf="<?= $h(generateCSRFToken()) ?>">
  <?php if ($attente): ?><div class="rn-banner"><i class="fas fa-bell"></i> <?= $attente ?> convocation<?= $attente > 1 ? 's attendent' : ' attend' ?> votre réponse.</div><?php endif; ?>
  <?php if (!$aVenir): ?><div class="empty-state"><i class="fas fa-envelope-open"></i><p>Aucune convocation à venir.</p></div><?php endif; ?>
  <div class="rn-group__list">
  <?php foreach ($aVenir as $r): $t = $r['t']; $d = new DateTimeImmutable($r['date_debut']); ?>
    <article class="rn-conv-card" style="--rn-c:<?= $h($t['color']) ?>" data-rn-id="<?= (int) $r['id'] ?>">
      <span class="rn-card__date"><b><?= $d->format('j') ?></b><small><?= ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'][(int) $d->format('n')] ?></small><em><?= $d->format('G\hi') ?></em></span>
      <div class="rn-card__body"><span class="rn-card__type"><i class="fas <?= $h($t['icon']) ?>"></i> <?= $h($t['label']) ?> · <?= $h(ReunionHub::ROLES[$r['mon_role']] ?? $r['mon_role']) ?></span>
        <a class="rn-card__title" href="detail.php?id=<?= (int) $r['id'] ?>"><?= $h($r['titre']) ?></a>
        <span class="rn-card__meta"><span><i class="fas fa-door-open"></i> <?= $h($r['salle_nom'] ?: ($r['lieu'] ?: '—')) ?></span><span><?= $h(ReunionHub::relatif($d, new DateTimeImmutable($r['date_fin']))) ?></span></span></div>
      <div class="rn-card__side">
        <?php if ($r['ma_convocation'] === 'envoyee'): ?>
          <button class="btn btn-primary btn-sm" data-rn-action="repondre" data-reponse="confirmee"><i class="fas fa-check"></i> Présent·e</button>
          <button class="btn btn-secondary btn-sm" data-rn-action="repondre" data-reponse="excusee">Excusé·e</button>
        <?php elseif ($r['ma_convocation'] === 'confirmee'): ?><span class="rn-chip rn-chip--ok"><i class="fas fa-check"></i> Confirmé</span>
        <?php elseif ($r['ma_convocation'] === 'excusee'): ?><span class="rn-chip">Excusé·e</span>
        <?php else: ?><span class="rn-chip rn-chip--muted">Convocation à venir</span><?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
  <?php if ($passees): ?>
  <section class="rn-group"><h2 class="rn-group__title">Réunions passées</h2>
    <ul class="rn-past"><?php foreach ($passees as $r): ?>
      <li><a href="detail.php?id=<?= (int) $r['id'] ?>"><i class="fas <?= $h($r['t']['icon']) ?>" style="color:<?= $h($r['t']['color']) ?>"></i> <?= $h($r['titre']) ?></a>
        <span class="rn-muted"><?= $h(ReunionHub::dateLongue(new DateTimeImmutable($r['date_debut']), false)) ?></span><?= $r['cr_publie'] ? '<span class="rn-chip"><i class="fas fa-file-lines"></i> Compte rendu</span>' : '' ?></li>
    <?php endforeach; ?></ul></section>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
