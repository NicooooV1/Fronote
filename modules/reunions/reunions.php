<?php
declare(strict_types=1);
/**
 * Réunions — le fil (refonte 4.6).
 * En haut, la prochaine réunion qui me concerne (compte à rebours, accès direct à la salle) ;
 * puis toutes les réunions visibles, rangées dans le temps, filtrables par type ; vue mensuelle.
 */
$pageTitle = 'Réunions';
require_once __DIR__ . '/includes/header.php';

$h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$type = isset(ReunionHub::TYPES[$_GET['type'] ?? '']) ? (string) $_GET['type'] : '';
$quand = in_array($_GET['quand'] ?? '', ['a_venir', 'passees'], true) ? (string) $_GET['quand'] : 'a_venir';
$mes = !empty($_GET['mes']);
$vue = ($_GET['vue'] ?? '') === 'mois' ? 'mois' : 'fil';
$me = ReunionHub::me();

$toutes = $hub->lister(['quand' => $vue === 'mois' ? 'toutes' : $quand, 'mes' => $mes, 'limit' => 300]);
$compte = [];
foreach ($toutes as $r) { $compte[$r['type']] = ($compte[$r['type']] ?? 0) + 1; }
$liste = $type !== '' ? array_values(array_filter($toutes, fn($r) => $r['type'] === $type)) : $toutes;

// Prochaine réunion qui me concerne (ou la prochaine tout court pour la direction)
$prochaine = null;
foreach ($hub->lister(['quand' => 'a_venir', 'mes' => true, 'limit' => 20]) as $r) { $prochaine = $r; break; }

$url = static function (array $over) use ($type, $quand, $mes, $vue): string {
    $q = array_filter(array_merge(['type' => $type, 'quand' => $quand, 'mes' => $mes ? 1 : '', 'vue' => $vue === 'mois' ? 'mois' : ''], $over), fn($v) => $v !== '' && $v !== null && $v !== 0);
    return 'reunions.php' . ($q ? '?' . http_build_query($q) : '');
};
$monStatut = static function (array $r): string {
    if ($r['type'] === 'parents_profs' && !$r['mon_role']) {
        return getUserRole() === 'parent' ? '<span class="rn-chip rn-chip--action">Réserver mes créneaux</span>' : '';
    }
    return match ($r['ma_convocation'] ?? '') {
        'envoyee' => '<span class="rn-chip rn-chip--warn">À confirmer</span>',
        'confirmee' => '<span class="rn-chip rn-chip--ok"><i class="fas fa-check"></i> Présence confirmée</span>',
        'excusee' => '<span class="rn-chip">Excusé·e</span>',
        'a_envoyer' => ($r['mon_role'] === 'organisateur' ? '<span class="rn-chip">Organisateur</span>' : ''),
        default => '',
    };
};
$carte = static function (array $r) use ($h, $monStatut): string {
    $t = $r['t']; $d = new DateTimeImmutable($r['date_debut']); $f = new DateTimeImmutable($r['date_fin']);
    $now = new DateTimeImmutable();
    $live = $r['statut'] === 'en_cours' || ($now >= $d && $now <= $f && $r['statut'] !== 'annulee');
    $meta = [];
    $meta[] = '<span><i class="far fa-clock"></i> ' . $d->format('G\hi') . ' – ' . $f->format('G\hi') . '</span>';
    if ($r['salle_nom'] || $r['lieu']) { $meta[] = '<span><i class="fas fa-door-open"></i> ' . $h($r['salle_nom'] ?: $r['lieu']) . '</span>'; }
    if ($r['classe_nom']) { $meta[] = '<span><i class="fas fa-users"></i> ' . $h($r['classe_nom']) . '</span>'; }
    if ($r['eleve_nom'] && ($r['mon_role'] || ReunionHub::isDirection() || ReunionHub::isVieScolaire())) { $meta[] = '<span><i class="fas fa-user-graduate"></i> ' . $h($r['eleve_nom']) . '</span>'; }
    if ($r['type'] === 'parents_profs') {
        $meta[] = '<span><i class="fas fa-calendar-check"></i> ' . (int) $r['nb_reserves'] . ' / ' . (int) $r['nb_creneaux'] . ' créneaux réservés</span>';
    } elseif ((int) $r['nb_participants'] > 0) {
        $meta[] = '<span><i class="fas fa-user-check"></i> ' . (int) $r['nb_confirmes'] . ' / ' . (int) $r['nb_participants'] . ' confirmés</span>';
    }
    $etat = $r['statut'] === 'annulee' ? '<span class="rn-chip rn-chip--muted">Annulée</span>'
        : ($live ? '<span class="rn-live"><i></i> En cours</span>'
        : ($r['statut'] === 'terminee' ? ($r['cr_publie'] ? '<span class="rn-chip"><i class="fas fa-file-lines"></i> Compte rendu</span>' : '<span class="rn-chip rn-chip--muted">Terminée</span>') : ''));
    return '<a class="rn-card' . ($live ? ' is-live' : '') . ($r['statut'] === 'annulee' ? ' is-cancelled' : '') . '" href="' . ($r['type'] === 'parents_profs' ? 'soiree.php' : 'detail.php') . '?id=' . (int) $r['id'] . '" style="--rn-c:' . $h($t['color']) . '">'
        . '<span class="rn-card__date"><b>' . $d->format('j') . '</b><small>' . ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'][(int) $d->format('n')] . '</small><em>' . ['', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'][(int) $d->format('N')] . '</em></span>'
        . '<span class="rn-card__body"><span class="rn-card__type"><i class="fas ' . $h($t['icon']) . '"></i> ' . $h($t['label']) . ($r['confidentiel'] ? ' <i class="fas fa-lock" title="Confidentiel"></i>' : '') . '</span>'
        . '<span class="rn-card__title">' . $h($r['titre']) . '</span>'
        . '<span class="rn-card__meta">' . implode('', $meta) . '</span></span>'
        . '<span class="rn-card__side">' . $etat . $monStatut($r) . '<span class="rn-card__rel">' . $h(ReunionHub::relatif($d, $f)) . '</span></span></a>';
};
?>
<div class="rn">

<?php if ($prochaine): $t = $prochaine['t']; $d = new DateTimeImmutable($prochaine['date_debut']); $f = new DateTimeImmutable($prochaine['date_fin']); ?>
  <section class="rn-hero" style="--rn-c:<?= $h($t['color']) ?>" aria-label="Prochaine réunion">
    <div class="rn-hero__icon"><i class="fas <?= $h($t['icon']) ?>"></i></div>
    <div class="rn-hero__main">
      <p class="rn-hero__kicker">Votre prochaine réunion · <?= $h($t['label']) ?></p>
      <h2 class="rn-hero__title"><?= $h($prochaine['titre']) ?></h2>
      <p class="rn-hero__meta">
        <span><i class="far fa-calendar"></i> <?= $h(ucfirst(ReunionHub::dateLongue($d))) ?></span>
        <?php if ($prochaine['salle_nom'] || $prochaine['lieu']): ?><span><i class="fas fa-door-open"></i> <?= $h($prochaine['salle_nom'] ?: $prochaine['lieu']) ?></span><?php endif; ?>
        <?php if ((int) $prochaine['nb_participants']): ?><span><i class="fas fa-users"></i> <?= (int) $prochaine['nb_participants'] ?> participants</span><?php endif; ?>
      </p>
    </div>
    <div class="rn-hero__count" data-rn-countdown="<?= $d->format(DATE_ATOM) ?>" data-rn-end="<?= $f->format(DATE_ATOM) ?>">
      <span class="rn-hero__count-val"><?= $h(ReunionHub::relatif($d, $f)) ?></span>
      <a class="btn btn-primary" href="<?= $prochaine['type'] === 'parents_profs' ? 'soiree.php' : 'detail.php' ?>?id=<?= (int) $prochaine['id'] ?>">
        <?= $prochaine['type'] === 'parents_profs' ? 'Ouvrir le planning' : 'Entrer dans la salle' ?> <i class="fas fa-arrow-right"></i></a>
    </div>
  </section>
<?php endif; ?>

  <div class="rn-toolbar">
    <nav class="rn-types" aria-label="Types de réunion">
      <a class="rn-type<?= $type === '' ? ' is-on' : '' ?>" href="<?= $h($url(['type' => ''])) ?>">Toutes <small><?= count($toutes) ?></small></a>
      <?php foreach (ReunionHub::TYPES as $k => [$lib, $ic, $col]): if (empty($compte[$k])) { continue; } ?>
      <a class="rn-type<?= $type === $k ? ' is-on' : '' ?>" style="--rn-c:<?= $h($col) ?>" href="<?= $h($url(['type' => $k])) ?>"><i class="fas <?= $h($ic) ?>"></i> <?= $h($lib) ?> <small><?= (int) $compte[$k] ?></small></a>
      <?php endforeach; ?>
    </nav>
    <div class="rn-switches">
      <?php if ($vue === 'fil'): ?>
      <div class="rn-seg" role="group" aria-label="Période">
        <a class="<?= $quand === 'a_venir' ? 'is-on' : '' ?>" href="<?= $h($url(['quand' => 'a_venir'])) ?>">À venir</a>
        <a class="<?= $quand === 'passees' ? 'is-on' : '' ?>" href="<?= $h($url(['quand' => 'passees'])) ?>">Passées</a>
      </div>
      <?php endif; ?>
      <div class="rn-seg" role="group" aria-label="Affichage">
        <a class="<?= $vue === 'fil' ? 'is-on' : '' ?>" href="<?= $h($url(['vue' => ''])) ?>"><i class="fas fa-stream"></i> Fil</a>
        <a class="<?= $vue === 'mois' ? 'is-on' : '' ?>" href="<?= $h($url(['vue' => 'mois'])) ?>"><i class="far fa-calendar"></i> Mois</a>
      </div>
      <a class="rn-toggle<?= $mes ? ' is-on' : '' ?>" href="<?= $h($url(['mes' => $mes ? '' : 1])) ?>" role="switch" aria-checked="<?= $mes ? 'true' : 'false' ?>"><span></span> Seulement les miennes</a>
    </div>
  </div>

<?php if ($vue === 'mois'):
    $mois = isset($_GET['m']) && preg_match('/^\d{4}-\d{2}$/', (string) $_GET['m']) ? new DateTimeImmutable($_GET['m'] . '-01') : new DateTimeImmutable('first day of this month');
    $debutGrille = $mois->modify('monday this week'); if ($debutGrille > $mois) { $debutGrille = $debutGrille->modify('-7 days'); }
    $parJour = [];
    foreach ($liste as $r) { $parJour[substr($r['date_debut'], 0, 10)][] = $r; }
    $nomMois = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'][(int) $mois->format('n')] . ' ' . $mois->format('Y');
?>
  <section class="rn-month">
    <header class="rn-month__head">
      <a class="btn btn-secondary btn-sm" href="<?= $h($url(['vue' => 'mois', 'm' => $mois->modify('-1 month')->format('Y-m')])) ?>" aria-label="Mois précédent"><i class="fas fa-chevron-left"></i></a>
      <h2><?= $h($nomMois) ?></h2>
      <a class="btn btn-secondary btn-sm" href="<?= $h($url(['vue' => 'mois', 'm' => $mois->modify('+1 month')->format('Y-m')])) ?>" aria-label="Mois suivant"><i class="fas fa-chevron-right"></i></a>
    </header>
    <div class="rn-month__grid">
      <?php foreach (['Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.', 'Dim.'] as $j): ?><div class="rn-month__dow"><?= $j ?></div><?php endforeach; ?>
      <?php for ($i = 0, $d = $debutGrille; $i < 42; $i++, $d = $d->modify('+1 day')): $k = $d->format('Y-m-d'); ?>
      <div class="rn-month__day<?= $d->format('m') !== $mois->format('m') ? ' is-out' : '' ?><?= $k === date('Y-m-d') ? ' is-today' : '' ?>">
        <span class="rn-month__num"><?= $d->format('j') ?></span>
        <?php foreach (array_slice($parJour[$k] ?? [], 0, 3) as $r): ?>
        <a class="rn-month__ev" style="--rn-c:<?= $h($r['t']['color']) ?>" href="<?= $r['type'] === 'parents_profs' ? 'soiree.php' : 'detail.php' ?>?id=<?= (int) $r['id'] ?>" title="<?= $h($r['titre']) ?>"><?= $h(substr($r['date_debut'], 11, 5)) ?> <?= $h($r['titre']) ?></a>
        <?php endforeach; ?>
        <?php if (count($parJour[$k] ?? []) > 3): ?><span class="rn-month__more">+<?= count($parJour[$k]) - 3 ?></span><?php endif; ?>
      </div>
      <?php endfor; ?>
    </div>
  </section>

<?php elseif (!$liste): ?>
  <div class="empty-state"><i class="fas fa-mug-hot"></i><p><?= $quand === 'a_venir' ? 'Aucune réunion à venir' . ($type ? ' de ce type' : '') . '.' : 'Aucune réunion passée' . ($type ? ' de ce type' : '') . '.' ?></p>
  <?php if ($estOrganisateur): ?><a class="btn btn-primary" href="planifier.php">Planifier une réunion</a><?php endif; ?></div>
<?php else:
    // Rangement temporel
    $groupes = [];
    $lundi = new DateTimeImmutable('monday this week');
    foreach ($liste as $r) {
        $d = new DateTimeImmutable(substr($r['date_debut'], 0, 10));
        if ($quand === 'passees') { $g = ucfirst(['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][(int) $d->format('n')] . ' ' . $d->format('Y')); }
        elseif ($d->format('Y-m-d') === date('Y-m-d')) { $g = 'Aujourd\'hui'; }
        elseif ($d < $lundi->modify('+7 days')) { $g = 'Cette semaine'; }
        elseif ($d < $lundi->modify('+14 days')) { $g = 'La semaine prochaine'; }
        else { $g = ucfirst(['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][(int) $d->format('n')] . ' ' . $d->format('Y')); }
        $groupes[$g][] = $r;
    }
?>
  <div class="rn-timeline">
    <?php foreach ($groupes as $g => $rs): ?>
    <section class="rn-group">
      <h2 class="rn-group__title"><?= $h($g) ?> <small><?= count($rs) ?></small></h2>
      <div class="rn-group__list"><?php foreach ($rs as $r) { echo $carte($r); } ?></div>
    </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
