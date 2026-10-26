<?php
declare(strict_types=1);
/**
 * Réunions — soirée de RENCONTRES PARENTS-PROFESSEURS (refonte 4.6).
 *   Famille   : « mon parcours de la soirée » (rendez-vous dans l'ordre, salles) + tableau des
 *               professeurs de ses enfants avec leurs créneaux libres, réservation en un clic.
 *   Professeur: son planning de la soirée (familles attendues, créneaux libres).
 *   Direction / vie scolaire : vue d'ensemble (taux de remplissage par professeur).
 */
require_once __DIR__ . '/includes/ReunionHub.php';
require_once __DIR__ . '/../../API/bootstrap.php';
requireAuth();
$__hub = new ReunionHub(getPDO());
$__r = $__hub->get((int) ($_GET['id'] ?? 0));
if ($__r && $__r['type'] !== 'parents_profs') { header('Location: detail.php?id=' . (int) $__r['id']); exit; }
$pageTitle = $__r ? $__r['titre'] : 'Rencontres parents-professeurs';
$pageSubtitle = $__r ? ucfirst(ReunionHub::dateLongue(new DateTimeImmutable($__r['date_debut']), false)) . ' · ' . (new DateTimeImmutable($__r['date_debut']))->format('G\hi') . ' – ' . (new DateTimeImmutable($__r['date_fin']))->format('G\hi') : '';
$pageBack = ['url' => 'modules/reunions/reunions.php', 'label' => 'Réunions'];
$headerExtraActions = '';
require_once __DIR__ . '/includes/header.php';

$h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
if (!$__r || !$hub->peutVoir($__r)) {
    echo '<div class="empty-state"><i class="fas fa-door-closed"></i><p>Cette soirée n\'existe pas ou ne vous est pas ouverte.</p></div>';
    require_once __DIR__ . '/includes/footer.php'; exit;
}
$me = ReunionHub::me();
$data = $hub->tableau((int) $__r['id']);
$r = $data['reunion']; $profs = $data['profs']; $enfants = $data['enfants'];
$t = $r['t'];
$passee = new DateTimeImmutable($r['date_fin']) < new DateTimeImmutable();
$ouverte = !$passee && $r['statut'] !== 'annulee';
$csrf = generateCSRFToken();
$hm = static fn(string $t) => (int) substr($t, 0, 2) . 'h' . substr($t, 3, 2);
?>
<div class="rn rn-soiree" style="--rn-c:<?= $h($t['color']) ?>" data-rn-id="<?= (int) $r['id'] ?>" data-csrf="<?= $h($csrf) ?>">

<?php if ($me['type'] === 'parent'):
    $mesRdv = [];
    foreach ($profs as $p) { foreach ($p['creneaux'] as $c) { if ((int) ($c['parent_id'] ?? 0) === $me['id']) { $mesRdv[] = $c + ['prof' => $p]; } } }
    usort($mesRdv, fn($a, $b) => strcmp($a['heure_debut'], $b['heure_debut']));
    $aVoir = count(array_filter($profs, fn($p) => !array_filter($p['creneaux'], fn($c) => (int) ($c['parent_id'] ?? 0) === $me['id'])));
?>
  <section class="rn-route">
    <header class="rn-route__head">
      <div><p class="rn-stage__kicker">Mon parcours de la soirée</p>
        <h2><?= count($mesRdv) ? count($mesRdv) . ' rendez-vous' : 'Aucun rendez-vous pour l\'instant' ?></h2>
        <p class="rn-muted"><?= $ouverte ? ($aVoir ? $aVoir . ' professeur' . ($aVoir > 1 ? 's' : '') . ' de vos enfants à rencontrer encore : choisissez un créneau libre ci-dessous.' : 'Vous avez un rendez-vous avec chaque professeur.') : 'Soirée terminée.' ?></p></div>
      <div class="rn-route__kids"><?php foreach ($enfants as $e): ?><span class="rn-kid"><i class="fas fa-child"></i> <?= $h($e['prenom']) ?> <small><?= $h($e['classe']) ?></small></span><?php endforeach; ?></div>
    </header>
    <?php if ($mesRdv): ?>
    <ol class="rn-route__steps">
      <?php $prev = null; foreach ($mesRdv as $c):
          $gap = $prev ? (strtotime('2000-01-01 ' . $c['heure_debut']) - strtotime('2000-01-01 ' . $prev['heure_fin'])) / 60 : null; ?>
      <?php if ($gap !== null && $gap >= 10): ?><li class="rn-route__gap"><?= (int) $gap ?> min d'attente</li><?php elseif ($gap !== null && $prev['prof']['salle'] !== $c['prof']['salle']): ?><li class="rn-route__gap is-tight"><i class="fas fa-person-walking"></i> Changement de salle</li><?php endif; ?>
      <li class="rn-route__step">
        <span class="rn-route__time"><?= $hm($c['heure_debut']) ?></span>
        <span class="rn-route__card"><b><?= $h($c['prof']['nom']) ?></b> <span class="rn-muted"><?= $h($c['prof']['matiere']) ?></span>
          <span class="rn-route__meta"><i class="fas fa-door-open"></i> <?= $h($c['prof']['salle'] ?: 'Salle à préciser') ?> · pour <?= $h(explode(' ', (string) $c['eleve_nom'])[0]) ?></span></span>
        <?php if ($ouverte): ?><button class="rn-link rn-link--danger" data-rn-action="annuler_resa" data-resa="<?= (int) $c['resa_id'] ?>" data-confirm="Annuler ce rendez-vous ?">Annuler</button><?php endif; ?>
      </li>
      <?php $prev = $c; endforeach; ?>
    </ol>
    <?php endif; ?>
  </section>

  <section class="rn-board" aria-label="Professeurs et créneaux">
    <?php foreach ($profs as $p):
        $mien = array_values(array_filter($p['creneaux'], fn($c) => (int) ($c['parent_id'] ?? 0) === $me['id']));
        $libres = count(array_filter($p['creneaux'], fn($c) => !$c['resa_id']));
        $dejaVus = array_map(fn($c) => (int) $c['eleve_id'], $mien);
        $restants = array_values(array_filter($p['enfants'] ?? [], fn($e) => !in_array((int) $e['id'], $dejaVus, true))); ?>
    <article class="rn-teacher<?= $mien && !$restants ? ' is-done' : '' ?>">
      <header><span class="rn-avatar rn-avatar--professeur"><?= $h(mb_strtoupper(mb_substr($p['nom'], 0, 1) . mb_substr(strrchr(' ' . $p['nom'], ' '), 1, 1))) ?></span>
        <span><b><?= $h($p['nom']) ?></b><small><?= $h($p['matiere']) ?> · <?= $h($p['salle'] ?: '—') ?></small></span>
        <?= $mien ? '<span class="rn-chip rn-chip--ok"><i class="fas fa-check"></i> ' . $hm($mien[0]['heure_debut']) . '</span>' : '<span class="rn-chip">' . $libres . ' libre' . ($libres > 1 ? 's' : '') . '</span>' ?></header>
      <p class="rn-teacher__kids"><?php foreach ($p['enfants'] ?? [] as $e): ?><span><?= $h($e['prenom']) ?></span><?php endforeach; ?></p>
      <div class="rn-slots">
        <?php foreach ($p['creneaux'] as $c): $pris = (bool) $c['resa_id']; $moi = (int) ($c['parent_id'] ?? 0) === $me['id']; ?>
        <button type="button" class="rn-slot<?= $moi ? ' is-mine' : ($pris ? ' is-taken' : '') ?>"
          <?= ($pris || !$ouverte || !$restants) && !$moi ? 'disabled' : '' ?>
          <?php if (!$pris && $ouverte && $restants): ?>data-rn-book="<?= (int) $c['id'] ?>" data-eleves="<?= $h(json_encode(array_map(fn($e) => ['id' => (int) $e['id'], 'nom' => $e['prenom']], $restants))) ?>" data-label="<?= $h($p['nom'] . ' à ' . $hm($c['heure_debut'])) ?>"<?php endif; ?>
          title="<?= $moi ? 'Votre rendez-vous' : ($pris ? 'Déjà réservé' : 'Libre') ?>"><?= $hm($c['heure_debut']) ?></button>
        <?php endforeach; ?>
      </div>
    </article>
    <?php endforeach; ?>
    <?php if (!$profs): ?><div class="empty-state"><p>Aucun professeur de vos enfants ne participe à cette soirée.</p></div><?php endif; ?>
  </section>

  <dialog class="rn-dialog" id="rn-book">
    <form data-rn-form="reserver">
      <h2>Réserver un créneau</h2>
      <p class="rn-book__label"></p>
      <input type="hidden" name="creneau">
      <label class="rn-field">Pour<select name="eleve" class="form-select"></select></label>
      <label class="rn-field">Sujet que vous souhaitez aborder (facultatif)<textarea name="commentaire" class="form-control" rows="3" maxlength="300" placeholder="Par exemple : méthodes de travail, orientation…"></textarea></label>
      <div class="rn-cr__actions"><button class="btn btn-secondary" type="button" data-rn-close>Annuler</button><button class="btn btn-primary" type="submit"><i class="fas fa-check"></i> Réserver</button></div>
    </form>
  </dialog>

<?php elseif ($me['type'] === 'professeur' && isset($profs[0]) && $profs[0]['id'] === $me['id']):
    $p = $profs[0];
    $reserves = count(array_filter($p['creneaux'], fn($c) => $c['resa_id'])); ?>
  <section class="rn-route">
    <header class="rn-route__head"><div><p class="rn-stage__kicker">Mon planning · <?= $h($p['salle'] ?: 'salle à préciser') ?></p>
      <h2><?= $reserves ?> famille<?= $reserves > 1 ? 's' : '' ?> attendue<?= $reserves > 1 ? 's' : '' ?></h2>
      <p class="rn-muted"><?= count($p['creneaux']) - $reserves ?> créneaux encore libres sur <?= count($p['creneaux']) ?>.</p></div></header>
    <ol class="rn-route__steps rn-route__steps--dense">
      <?php foreach ($p['creneaux'] as $c): ?>
      <li class="rn-route__step<?= $c['resa_id'] ? '' : ' is-free' ?>">
        <span class="rn-route__time"><?= $hm($c['heure_debut']) ?></span>
        <?php if ($c['resa_id']): ?>
        <span class="rn-route__card"><b><?= $h($c['eleve_nom']) ?></b> <span class="rn-muted"><?= $h($c['eleve_classe']) ?> · <?= $h($hub->nameOf('parent', (int) $c['parent_id'])) ?></span>
          <?php if ($c['commentaire']): ?><span class="rn-route__meta"><i class="far fa-comment"></i> <?= $h($c['commentaire']) ?></span><?php endif; ?></span>
        <?php else: ?><span class="rn-route__card rn-muted">Libre</span><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ol>
  </section>
  <?php $profs = array_slice($profs, 1); ?>
<?php endif; ?>

<?php if ($me['type'] !== 'parent'):
    $tot = 0; $res = 0;
    foreach ($profs as $p) { $tot += count($p['creneaux']); $res += count(array_filter($p['creneaux'], fn($c) => $c['resa_id'])); } ?>
  <section class="rn-panel">
    <header class="rn-panel__head"><h2><i class="fas fa-table-cells"></i> <?= $me['type'] === 'professeur' ? 'Les autres professeurs' : 'Vue d\'ensemble' ?></h2>
      <span class="rn-muted"><?= $res ?> / <?= $tot ?> créneaux réservés<?= $tot ? ' · ' . round($res / $tot * 100) . ' %' : '' ?></span></header>
    <div class="rn-heat">
      <?php foreach ($profs as $p): $n = count($p['creneaux']); $k = count(array_filter($p['creneaux'], fn($c) => $c['resa_id'])); ?>
      <div class="rn-heat__row"><span class="rn-heat__who"><b><?= $h($p['nom']) ?></b><small><?= $h($p['matiere']) ?></small></span>
        <span class="rn-heat__cells"><?php foreach ($p['creneaux'] as $c): ?><i class="<?= $c['resa_id'] ? 'is-taken' : '' ?>" title="<?= $hm($c['heure_debut']) ?><?= $c['resa_id'] ? ' — ' . $h($c['eleve_nom']) : ' — libre' ?>"></i><?php endforeach; ?></span>
        <span class="rn-heat__pct"><?= $n ? round($k / $n * 100) : 0 ?> %</span></div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
