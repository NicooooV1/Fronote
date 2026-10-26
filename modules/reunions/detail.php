<?php
declare(strict_types=1);
/**
 * Réunions — la SALLE de réunion (refonte 4.6).
 *   Avant  : convocations, ordre du jour, réponses des participants.
 *   Pendant (?seance=1) : mode séance plein écran — point en cours, minuteurs, présences, notes.
 *   Après  : compte rendu (brouillon généré depuis la séance, publication aux participants).
 */
require_once __DIR__ . '/includes/ReunionHub.php';
require_once __DIR__ . '/../../API/bootstrap.php';
requireAuth();
$__hub = new ReunionHub(getPDO());
$__r = $__hub->get((int) ($_GET['id'] ?? 0));
if ($__r && $__r['type'] === 'parents_profs') { header('Location: soiree.php?id=' . (int) $__r['id']); exit; }
if (!$__r || !$__hub->peutVoir($__r)) {
    $pageTitle = 'Réunion introuvable';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="empty-state"><i class="fas fa-door-closed"></i><p>Cette réunion n\'existe pas ou ne vous est pas ouverte.</p><a class="btn btn-secondary" href="reunions.php">Retour aux réunions</a></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
$r = $__r;
$seance = !empty($_GET['seance']) && $__hub->peutGerer($r) && $r['statut'] !== 'terminee';
$pageTitle = $r['titre'];
$pageSubtitle = $r['t']['label'] . ' · ' . ucfirst(ReunionHub::dateLongue(new DateTimeImmutable($r['date_debut'])));
$pageBack = ['url' => 'modules/reunions/reunions.php', 'label' => 'Réunions'];
$headerExtraActions = '';
$bodyClass = $seance ? 'rn-seance-mode' : '';
require_once __DIR__ . '/includes/header.php';

$h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$gestion = $hub->peutGerer($r);
$participants = $hub->participants((int) $r['id']);
$points = $hub->points((int) $r['id']);
$t = $r['t'];
$d = new DateTimeImmutable($r['date_debut']); $f = new DateTimeImmutable($r['date_fin']);
$now = new DateTimeImmutable();
$etat = $r['statut'] === 'annulee' ? 'annulee' : ($r['statut'] === 'terminee' ? 'terminee' : ($r['statut'] === 'en_cours' ? 'en_cours' : 'planifiee'));
$aEnvoyer = count(array_filter($participants, fn($p) => $p['convocation'] === 'a_envoyer' && !($p['user_type'] === $r['organisateur_type'] && (int) $p['user_id'] === (int) $r['organisateur_id'])));
$presents = count(array_filter($participants, fn($p) => in_array($p['presence'], ['present', 'retard'], true)));
$confirmes = count(array_filter($participants, fn($p) => $p['convocation'] === 'confirmee'));
$totalMin = array_sum(array_map(fn($p) => (int) $p['duree_minutes'], $points));
$csrf = generateCSRFToken();
$initiales = static function (string $n): string { $p = preg_split('/\s+/u', trim($n)); return mb_strtoupper(mb_substr($p[0] ?? '', 0, 1) . mb_substr(end($p) ?: '', 0, 1)); };
$roleLib = static fn(string $k) => ReunionHub::ROLES[$k] ?? ucfirst($k);
$convIcon = ['a_envoyer' => ['fa-hourglass-start', 'Convocation à envoyer'], 'envoyee' => ['fa-paper-plane', 'Convoqué·e, sans réponse'], 'confirmee' => ['fa-circle-check', 'Présence confirmée'], 'excusee' => ['fa-circle-xmark', 'Excusé·e']];

// Liens utiles selon le type
$liens = [];
if ($r['type'] === 'conseil_classe' && $r['classe_nom']) { $liens[] = ['../conseil_classe/conseil_classe.php?classe=' . urlencode((string) $r['classe_nom']), 'fa-users-rectangle', 'Ouvrir le conseil de classe ' . $r['classe_nom']]; $liens[] = ['../bulletins/bulletins.php', 'fa-file-lines', 'Bulletins du trimestre']; }
if ($r['eleve_id'] && (ReunionHub::isDirection() || ReunionHub::isVieScolaire() || getUserRole() === 'professeur')) {
    $liens[] = ['../vie_scolaire/suivi_eleve.php?id=' . (int) $r['eleve_id'], 'fa-folder-open', 'Dossier de suivi de ' . $r['eleve_nom']];
    if (in_array($r['type'], ['conseil_discipline', 'commission_educative'], true)) { $liens[] = ['../discipline/fiche_eleve.php?id=' . (int) $r['eleve_id'], 'fa-gavel', 'Incidents et sanctions']; }
}
if ($r['visio_url']) { $liens[] = [$r['visio_url'], 'fa-video', 'Rejoindre en visio']; }
$heureDe = static function (int $offsetMin) use ($r): string { return date('G\hi', strtotime($r['started_at'] ?: $r['date_debut']) + $offsetMin * 60); };
?>
<div class="rn rn-room<?= $seance ? ' is-seance' : '' ?>" style="--rn-c:<?= $h($t['color']) ?>" data-rn-id="<?= (int) $r['id'] ?>" data-csrf="<?= $h($csrf) ?>">

  <section class="rn-stage rn-stage--<?= $etat ?>">
    <div class="rn-stage__icon"><i class="fas <?= $h($t['icon']) ?>"></i></div>
    <div class="rn-stage__main">
      <p class="rn-stage__kicker"><?= $h($t['label']) ?><?php if ($r['confidentiel']): ?> · <i class="fas fa-lock"></i> Confidentiel<?php endif; ?></p>
      <ul class="rn-stage__facts">
        <li><i class="far fa-calendar"></i> <?= $h(ucfirst(ReunionHub::dateLongue($d, false))) ?></li>
        <li><i class="far fa-clock"></i> <?= $d->format('G\hi') ?> – <?= $f->format('G\hi') ?><?= $totalMin ? ' · ordre du jour : ' . intdiv($totalMin, 60) . ' h ' . sprintf('%02d', $totalMin % 60) : '' ?></li>
        <?php if ($r['salle_nom'] || $r['lieu']): ?><li><i class="fas fa-door-open"></i> <?= $h($r['salle_nom'] ?: $r['lieu']) ?></li><?php endif; ?>
        <?php if ($r['classe_nom']): ?><li><i class="fas fa-users"></i> Classe de <?= $h($r['classe_nom']) ?></li><?php endif; ?>
        <?php if ($r['eleve_nom']): ?><li><i class="fas fa-user-graduate"></i> <?= $h($r['eleve_nom']) ?> (<?= $h($r['eleve_classe']) ?>)</li><?php endif; ?>
        <li><i class="fas fa-user-tie"></i> Organisée par <?= $h($r['organisateur_nom']) ?></li>
      </ul>
    </div>
    <div class="rn-stage__side">
      <?php if ($etat === 'en_cours'): ?>
        <span class="rn-live"><i></i> Séance en cours</span>
        <span class="rn-stage__clock" data-rn-since="<?= $h(date(DATE_ATOM, strtotime((string) ($r['started_at'] ?: $r['date_debut'])))) ?>">—</span>
      <?php elseif ($etat === 'planifiee'): ?>
        <span class="rn-stage__when" data-rn-countdown="<?= $d->format(DATE_ATOM) ?>" data-rn-end="<?= $f->format(DATE_ATOM) ?>"><?= $h(ReunionHub::relatif($d, $f)) ?></span>
      <?php elseif ($etat === 'terminee'): ?>
        <span class="rn-chip rn-chip--ok"><i class="fas fa-flag-checkered"></i> Terminée</span>
      <?php else: ?>
        <span class="rn-chip rn-chip--muted">Annulée</span>
      <?php endif; ?>
      <div class="rn-stage__actions">
        <?php if ($gestion && $etat === 'planifiee'): ?>
          <?php if ($aEnvoyer): ?><button class="btn btn-secondary" data-rn-action="convoquer"><i class="fas fa-paper-plane"></i> Convoquer (<?= $aEnvoyer ?>)</button><?php endif; ?>
          <button class="btn btn-primary" data-rn-action="demarrer"><i class="fas fa-circle-play"></i> Démarrer la séance</button>
        <?php elseif ($gestion && $etat === 'en_cours' && !$seance): ?>
          <a class="btn btn-primary" href="detail.php?id=<?= (int) $r['id'] ?>&amp;seance=1"><i class="fas fa-expand"></i> Mode séance</a>
        <?php elseif ($seance): ?>
          <a class="btn btn-secondary" href="detail.php?id=<?= (int) $r['id'] ?>"><i class="fas fa-compress"></i> Quitter le mode séance</a>
        <?php endif; ?>
        <?php if (($r['ma_convocation'] ?? '') === 'envoyee' && $etat === 'planifiee'): ?>
          <button class="btn btn-primary" data-rn-action="repondre" data-reponse="confirmee"><i class="fas fa-check"></i> Je serai présent·e</button>
          <button class="btn btn-secondary" data-rn-action="repondre" data-reponse="excusee">Je serai excusé·e</button>
        <?php elseif (in_array($r['ma_convocation'] ?? '', ['confirmee', 'excusee'], true) && $etat === 'planifiee'): ?>
          <span class="rn-chip<?= $r['ma_convocation'] === 'confirmee' ? ' rn-chip--ok' : '' ?>"><?= $r['ma_convocation'] === 'confirmee' ? '<i class="fas fa-check"></i> Vous avez confirmé' : 'Vous êtes excusé·e' ?></span>
        <?php endif; ?>
      </div>
    </div>
  </section>

<?php if ($seance):
    $courant = null; $idx = 0; $ecoule = 0;
    foreach ($points as $i => $p) { if ($p['statut'] === 'en_cours') { $courant = $p; $idx = $i; } }
    $faits = count(array_filter($points, fn($p) => $p['statut'] === 'traite'));
?>
  <section class="rn-focus" aria-live="polite">
    <div class="rn-focus__progress"><?php foreach ($points as $p): ?><span class="is-<?= $h($p['statut']) ?>" style="flex:<?= max(1, (int) $p['duree_minutes']) ?>" title="<?= $h($p['titre']) ?>"></span><?php endforeach; ?></div>
    <?php if ($courant): ?>
    <p class="rn-focus__kicker">Point <?= $idx + 1 ?> sur <?= count($points) ?> · prévu <?= (int) $courant['duree_minutes'] ?> min</p>
    <h2 class="rn-focus__title"><?= $h($courant['titre']) ?></h2>
    <div class="rn-focus__timers">
      <div><span class="rn-focus__timer" data-rn-since="<?= $h(date(DATE_ATOM, strtotime((string) ($courant['debut_at'] ?: 'now')))) ?>" data-rn-budget="<?= (int) $courant['duree_minutes'] ?>">—</span><small>sur ce point</small></div>
      <div><span class="rn-focus__timer rn-focus__timer--total" data-rn-since="<?= $h(date(DATE_ATOM, strtotime((string) ($r['started_at'] ?: 'now')))) ?>" data-rn-budget="<?= (int) $totalMin ?>">—</span><small>depuis le début</small></div>
    </div>
    <div class="rn-focus__notes">
      <label>Notes du point<textarea data-rn-point="<?= (int) $courant['id'] ?>" data-rn-champ="notes" rows="4" placeholder="Ce qui est dit, en quelques mots…"><?= $h($courant['notes']) ?></textarea></label>
      <label>Décision<textarea data-rn-point="<?= (int) $courant['id'] ?>" data-rn-champ="decision" rows="2" placeholder="Décision prise (facultatif)"><?= $h($courant['decision']) ?></textarea></label>
      <span class="rn-saved" aria-live="polite"></span>
    </div>
    <div class="rn-focus__actions">
      <button class="btn btn-secondary" data-rn-action="maj_point" data-point="<?= (int) $courant['id'] ?>" data-champ="statut" data-valeur="reporte"><i class="fas fa-share"></i> Reporter ce point</button>
      <button class="btn btn-primary btn-lg" data-rn-action="suivant"><?= $idx + 1 < count($points) ? 'Point suivant <i class="fas fa-arrow-right"></i>' : 'Terminer l\'ordre du jour <i class="fas fa-flag-checkered"></i>' ?></button>
    </div>
    <?php else: ?>
    <p class="rn-focus__kicker"><?= $faits ?> point<?= $faits > 1 ? 's' : '' ?> traité<?= $faits > 1 ? 's' : '' ?> sur <?= count($points) ?></p>
    <h2 class="rn-focus__title">Ordre du jour épuisé</h2>
    <p class="rn-focus__hint">Vérifiez les présences, puis clôturez : un compte rendu est préparé à partir de vos notes.</p>
    <div class="rn-focus__actions"><button class="btn btn-primary btn-lg" data-rn-action="ouvrir_cloture"><i class="fas fa-flag-checkered"></i> Clôturer la réunion</button></div>
    <?php endif; ?>
  </section>
<?php endif; ?>

  <div class="rn-room__grid">
    <div class="rn-room__main">
      <?php if ($r['description']): ?><section class="rn-panel"><p class="rn-desc"><?= nl2br($h($r['description'])) ?></p></section><?php endif; ?>

      <section class="rn-panel">
        <header class="rn-panel__head"><h2><i class="fas fa-list-ol"></i> Ordre du jour</h2><span class="rn-muted"><?= count($points) ?> point<?= count($points) > 1 ? 's' : '' ?><?= $totalMin ? ' · ' . $totalMin . ' min' : '' ?></span></header>
        <?php if (!$points): ?><p class="rn-muted">Pas d'ordre du jour.</p><?php endif; ?>
        <ol class="rn-agenda">
          <?php $cumul = 0; foreach ($points as $i => $p): ?>
          <li class="rn-agenda__item is-<?= $h($p['statut']) ?>">
            <span class="rn-agenda__time"><?= $heureDe($cumul) ?></span>
            <span class="rn-agenda__dot"></span>
            <div class="rn-agenda__body">
              <div class="rn-agenda__title"><?= $h($p['titre']) ?> <small><?= (int) $p['duree_minutes'] ?> min</small>
                <?php if ($p['statut'] === 'reporte'): ?><span class="rn-chip rn-chip--muted">Reporté</span><?php elseif ($p['statut'] === 'en_cours'): ?><span class="rn-live"><i></i> En cours</span><?php endif; ?>
                <?php if ($gestion && $etat === 'en_cours' && $p['statut'] !== 'en_cours'): ?><button class="rn-link" data-rn-action="activer_point" data-point="<?= (int) $p['id'] ?>">Traiter maintenant</button><?php endif; ?>
              </div>
              <?php if (trim((string) $p['notes']) !== '' && ($gestion || $r['cr_publie'])): ?><p class="rn-agenda__notes"><?= nl2br($h($p['notes'])) ?></p><?php endif; ?>
              <?php if (trim((string) $p['decision']) !== '' && ($gestion || $r['cr_publie'])): ?><p class="rn-agenda__decision"><i class="fas fa-gavel"></i> <?= nl2br($h($p['decision'])) ?></p><?php endif; ?>
            </div>
          </li>
          <?php $cumul += (int) $p['duree_minutes']; endforeach; ?>
        </ol>
        <?php if ($gestion && in_array($etat, ['planifiee', 'en_cours'], true)): ?>
        <form class="rn-addpoint" data-rn-form="ajouter_point"><input name="titre" class="form-control" placeholder="Ajouter un point à l'ordre du jour…" required maxlength="255"><input name="duree" class="form-control" type="number" min="1" max="240" value="10" aria-label="Durée en minutes"><button class="btn btn-secondary" type="submit"><i class="fas fa-plus"></i> Ajouter</button></form>
        <?php endif; ?>
      </section>

      <?php if ($etat === 'terminee' && ($gestion || $r['cr_publie'])): ?>
      <section class="rn-panel rn-cr">
        <header class="rn-panel__head"><h2><i class="fas fa-file-lines"></i> Compte rendu</h2><?= $r['cr_publie'] ? '<span class="rn-chip rn-chip--ok">Publié aux participants</span>' : '<span class="rn-chip rn-chip--warn">Brouillon</span>' ?></header>
        <?php if ($gestion): ?>
        <form data-rn-form="enregistrer_cr">
          <textarea name="cr" class="form-control rn-cr__text" rows="14"><?= $h($r['pv_contenu'] ?: $hub->brouillonCompteRendu((int) $r['id'])) ?></textarea>
          <div class="rn-cr__actions"><label class="rn-check"><input type="checkbox" name="publier" value="1"<?= $r['cr_publie'] ? ' checked' : '' ?>> Publier aux participants</label><button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Enregistrer</button></div>
        </form>
        <?php else: ?><div class="rn-cr__read"><?= nl2br($h($r['pv_contenu'])) ?></div><?php endif; ?>
      </section>
      <?php endif; ?>
    </div>

    <aside class="rn-room__side">
      <section class="rn-panel">
        <header class="rn-panel__head"><h2><i class="fas fa-users"></i> Participants</h2>
          <span class="rn-muted"><?= $etat === 'planifiee' ? $confirmes . ' / ' . count($participants) . ' confirmés' : $presents . ' / ' . count($participants) . ' présents' ?></span></header>
        <ul class="rn-people">
          <?php foreach ($participants as $p): [$ic, $lib] = $convIcon[$p['convocation']] ?? ['fa-circle', '']; ?>
          <li class="rn-person">
            <span class="rn-avatar rn-avatar--<?= $h($p['user_type']) ?>"><?= $h($initiales($p['nom'])) ?></span>
            <span class="rn-person__txt"><b><?= $h($p['nom']) ?></b><small><?= $h($roleLib($p['role'])) ?></small></span>
            <?php if ($gestion && in_array($etat, ['en_cours', 'terminee'], true)): ?>
            <span class="rn-presence" role="group" aria-label="Présence de <?= $h($p['nom']) ?>">
              <?php foreach (['present' => ['fa-check', 'Présent'], 'retard' => ['fa-clock', 'En retard'], 'excuse' => ['fa-envelope', 'Excusé'], 'absent' => ['fa-xmark', 'Absent']] as $k => [$i2, $l2]): ?>
              <button type="button" class="<?= $p['presence'] === $k ? 'is-on is-' . $k : '' ?>" data-rn-action="presence" data-participant="<?= (int) $p['id'] ?>" data-presence="<?= $k ?>" title="<?= $l2 ?>" aria-pressed="<?= $p['presence'] === $k ? 'true' : 'false' ?>"><i class="fas <?= $i2 ?>"></i></button>
              <?php endforeach; ?>
            </span>
            <?php else: ?><i class="fas <?= $h($ic) ?> rn-conv rn-conv--<?= $h($p['convocation']) ?>" title="<?= $h($lib) ?>" aria-label="<?= $h($lib) ?>"></i><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php if ($liens): ?>
      <section class="rn-panel"><header class="rn-panel__head"><h2><i class="fas fa-link"></i> Pour préparer</h2></header>
        <ul class="rn-links"><?php foreach ($liens as [$u, $ic, $lib]): ?><li><a href="<?= $h($u) ?>"><i class="fas <?= $h($ic) ?>"></i> <?= $h($lib) ?></a></li><?php endforeach; ?></ul></section>
      <?php endif; ?>
      <?php if ($gestion && $etat === 'planifiee'): ?>
      <section class="rn-panel rn-panel--quiet"><button class="rn-link rn-link--danger" data-rn-action="annuler" data-confirm="Annuler cette réunion ? Les participants convoqués seront prévenus."><i class="fas fa-ban"></i> Annuler la réunion</button></section>
      <?php endif; ?>
    </aside>
  </div>

  <?php if ($gestion && $etat === 'en_cours'): ?>
  <dialog class="rn-dialog" id="rn-cloture">
    <form data-rn-form="clore">
      <h2>Clôturer la réunion</h2>
      <p class="rn-muted">Le compte rendu ci-dessous est préparé à partir des présences, de l'ordre du jour et de vos notes. Relisez-le avant de le publier.</p>
      <textarea name="cr" class="form-control rn-cr__text" rows="14"><?= $h($hub->brouillonCompteRendu((int) $r['id'])) ?></textarea>
      <div class="rn-cr__actions"><label class="rn-check"><input type="checkbox" name="publier" value="1" checked> Publier aux participants</label>
        <button class="btn btn-secondary" type="button" data-rn-close>Continuer la séance</button><button class="btn btn-primary" type="submit"><i class="fas fa-flag-checkered"></i> Clôturer</button></div>
    </form>
  </dialog>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
