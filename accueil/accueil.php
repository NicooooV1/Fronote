<?php
declare(strict_types=1);
/**
 * Accueil (4.3) — tableau de bord par rôle, cartes à taille fixe pensées pour leur contenu.
 * Données : API\Services\Home\HomeService. Personnalisation : masquer / réordonner les cartes
 * (accueil/api.php, stocké dans user_settings.accueil_config).
 */
$pageTitle  = 'Accueil';
$activePage = 'accueil';
require_once __DIR__ . '/../API/module_boot.php';
require_once __DIR__ . '/includes/cards.php';

use API\Services\Home\HomeService;

$home = new HomeService($pdo);
$uid = (int) ($user['id'] ?? getUserId());
$role = (string) $user_role;
$periode = $home->periode();
$R = $rootPrefix;

// ── messages (commun) ──
$conv = []; $unreadMsg = 0;
try {
    $ms = \API\Services\Messaging\MessagingService::forCurrentUser();
    $conv = $ms->conversations(['box' => 'inbox'], 4)['items'];
    $unreadMsg = $ms->unreadTotal();
} catch (\Throwable $e) {}

// ── élève suivi (élève lui-même, ou enfant choisi par le parent) ──
$enfants = []; $eleve = null;
if ($role === 'eleve') { $eleve = $home->eleve($uid); }
if ($role === 'parent') {
    $enfants = $home->enfants($uid);
    $sel = (int) ($_GET['enfant'] ?? ($_SESSION['accueil_enfant'] ?? 0));
    $ids = array_map(fn($e) => (int) $e['id'], $enfants);
    if (!in_array($sel, $ids, true)) { $sel = $ids[0] ?? 0; }
    $_SESSION['accueil_enfant'] = $sel;
    $eleve = $sel ? $home->eleve($sel) : null;
}

$cards = [];   // clé => [titre, icône, classe de taille, html, lien, libellé du lien]
$summary = []; // phrase de résumé sous le bonjour
$add = function (string $key, string $title, string $icon, string $size, string $html, ?string $link = null, string $linkLabel = 'Tout voir') use (&$cards) {
    $cards[$key] = [$title, $icon, $size, $html, $link, $linkLabel];
};

if ($eleve) {
    $who = $role === 'parent' ? $eleve['prenom'] : null;
    $day = $home->day(['classe_id' => (int) $eleve['classe_id']]);
    $notes = $home->dernieresNotes((int) $eleve['id'], (string) $eleve['classe']);
    $moy = $home->moyennes((int) $eleve['id'], (string) $eleve['classe'], $periode);
    $dv = $home->devoirs((string) $eleve['classe'], (int) $eleve['id']);
    $vs = $home->vieScolaire((int) $eleve['id'], $periode);
    $nbCours = $day['is_today'] ? count($day['items']) : 0;
    $demain = date('Y-m-d', strtotime('+1 day'));
    $dvDemain = count(array_filter($dv, fn($d) => $d['date_rendu'] === $demain && !$d['fait']));
    if ($nbCours) { $summary[] = $nbCours . ' cours aujourd\'hui'; }
    if ($dvDemain) { $summary[] = $dvDemain . ' devoir' . ($dvDemain > 1 ? 's' : '') . ' pour demain'; }
    $newNotes = count(array_filter($notes, fn($n) => $n['nouveau']));
    if ($newNotes) { $summary[] = $newNotes . ' nouvelle' . ($newNotes > 1 ? 's' : '') . ' note' . ($newNotes > 1 ? 's' : ''); }

    $add('journee', $day['is_today'] ? 'Aujourd\'hui' : 'Prochain jour de cours', 'fa-calendar-day', 'side', hm_card_journee($day, $R, 'eleve'), $R . 'modules/emploi_du_temps/emploi_du_temps.php', 'Emploi du temps');
    $add('notes', $who ? 'Dernières notes de ' . $who : 'Dernières notes', 'fa-star', 'main', hm_card_notes($notes), $R . 'modules/notes/notes.php', 'Toutes les notes');
    $add('devoirs', 'Travail à faire', 'fa-book-open', 'main', hm_card_devoirs($dv, $role === 'eleve'), $R . 'modules/cahierdetextes/cahierdetextes.php', 'Cahier de textes');
    $add('moyennes', 'Moyennes' . ($periode ? ' — ' . $periode['nom'] : ''), 'fa-chart-bar', 'side', hm_card_moyennes($moy), $R . 'modules/notes/notes.php', 'Détail');
    $add('vie', 'Absences et retards', 'fa-user-clock', 'side', hm_card_vie($vs), $R . 'modules/absences/absences.php', 'Détail');
    $add('messages', 'Messages', 'fa-envelope', 'main', hm_card_messages($conv, $R), $R . 'modules/messagerie/', 'Messagerie');
    $add('agenda', 'À venir', 'fa-calendar-alt', 'side', hm_card_agenda($home->evenements((string) $eleve['classe']), $home->menu()), $R . 'modules/agenda/agenda.php', 'Agenda');
} elseif ($role === 'professeur') {
    $day = $home->day(['professeur_id' => $uid]);
    $appels = $home->appels($uid, $day['date']);
    $todo = 0;
    foreach ($day['items'] as $c) {
        $a = $appels[(int) $c['classe_id'] . '|' . $c['debut']] ?? null;
        if ($day['is_today'] && $c['etat'] !== 'avenir' && (!$a || !in_array($a['statut'], ['valide', 'cloture'], true))) { $todo++; }
    }
    if ($day['is_today'] && $day['items']) { $summary[] = count($day['items']) . ' cours aujourd\'hui'; }
    if ($todo) { $summary[] = $todo . ' appel' . ($todo > 1 ? 's' : '') . ' à faire'; }
    $add('journee', $day['is_today'] ? 'Ma journée' : 'Prochain jour de cours', 'fa-calendar-day', 'side', hm_card_journee($day, $R, 'professeur', $appels, csrfField()), $R . 'modules/emploi_du_temps/emploi_du_temps.php', 'Emploi du temps');
    $add('evaluations', 'Dernières évaluations', 'fa-pen-to-square', 'main', hm_card_evaluations($home->evaluationsProf($uid)), $R . 'modules/notes/notes.php', 'Saisir des notes');
    $add('classes', 'Mes classes', 'fa-users', 'main', hm_card_classes($home->classesProf($uid), $R));
    $add('devoirs_donnes', 'Devoirs donnés à venir', 'fa-book-open', 'main', hm_card_devoirs_donnes($home->devoirsDonnes(trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')))), $R . 'modules/cahierdetextes/cahierdetextes.php', 'Cahier de textes');
    $add('messages', 'Messages', 'fa-envelope', 'side', hm_card_messages($conv, $R), $R . 'modules/messagerie/', 'Messagerie');
    $add('agenda', 'À venir', 'fa-calendar-alt', 'side', hm_card_agenda($home->evenements(null), null), $R . 'modules/agenda/agenda.php', 'Agenda');
} elseif ($role === 'vie_scolaire' || $role === 'administrateur') {
    $vs = $home->vieScolaireJour();
    $tiles = [
        ['icon' => 'fa-user-check', 'value' => $vs['presence'] !== null ? hm_num($vs['presence'], 1) . ' %' : '—', 'label' => 'Présence aujourd\'hui', 'sub' => $vs['effectif'] . ' élèves', 'href' => $R . 'modules/absences/absences.php'],
        ['icon' => 'fa-user-times', 'value' => $vs['nb_absents'], 'label' => 'Absents aujourd\'hui', 'href' => $R . 'modules/absences/absences.php', 'warn' => $vs['nb_absents'] > 0],
        ['icon' => 'fa-clock', 'value' => $vs['retards'], 'label' => 'Retards aujourd\'hui', 'href' => $R . 'modules/absences/absences.php'],
        ['icon' => 'fa-file-signature', 'value' => $vs['nb_a_traiter'], 'label' => 'Absences à justifier', 'sub' => '30 derniers jours', 'href' => $R . 'modules/absences/absences.php', 'warn' => $vs['nb_a_traiter'] > 0],
    ];
    if ($role === 'administrateur') {
        $dir = $home->direction($periode);
        $tiles = [
            $tiles[0],
            ['icon' => 'fa-graduation-cap', 'value' => hm_num($dir['moyenne'], 2), 'label' => 'Moyenne générale', 'sub' => $periode['nom'] ?? '', 'href' => $R . 'admin/systeme/stats.php'],
            ['icon' => 'fa-pen', 'value' => $dir['notes_semaine'], 'label' => 'Notes saisies (7 j)', 'href' => $R . 'modules/notes/notes.php', 'spark' => hm_spark(array_column($dir['serie_notes'], 'n'))],
            $tiles[3],
            ['icon' => 'fa-lock', 'value' => $dir['comptes_bloques'], 'label' => 'Comptes bloqués', 'href' => $R . 'admin/users/index.php', 'warn' => $dir['comptes_bloques'] > 0],
        ];
        $summary[] = $dir['eleves'] . ' élèves, ' . $dir['professeurs'] . ' professeurs';
    } else {
        $tiles[] = ['icon' => 'fa-gavel', 'value' => $vs['incidents'], 'label' => 'Incidents (7 j)', 'href' => $R . 'modules/discipline/discipline.php'];
    }
    if ($vs['nb_absents']) { $summary[] = $vs['nb_absents'] . ' absent' . ($vs['nb_absents'] > 1 ? 's' : '') . ' aujourd\'hui'; }
    if ($vs['nb_a_traiter']) { $summary[] = $vs['nb_a_traiter'] . ' absence' . ($vs['nb_a_traiter'] > 1 ? 's' : '') . ' à justifier'; }
    $add('kpis', 'Aujourd\'hui', 'fa-gauge-high', 'full', hm_card_kpis($tiles));
    if ($role === 'administrateur') {
        $add('classes_dir', 'Moyennes par classe' . ($periode ? ' — ' . $periode['nom'] : ''), 'fa-school', 'main', hm_card_classes_dir($dir['classes']), $R . 'admin/classes/index.php', 'Classes');
        $add('absents', 'Absents aujourd\'hui', 'fa-user-times', 'side', hm_card_absents($vs, $R), $R . 'modules/absences/absences.php', 'Absences');
        $add('annonces', 'Annonces', 'fa-bullhorn', 'main', hm_card_annonces($home->annonces()), $R . 'modules/annonces/annonces.php', 'Annonces');
    } else {
        $add('absents', 'Absents aujourd\'hui', 'fa-user-times', 'main', hm_card_absents($vs, $R), $R . 'modules/absences/absences.php', 'Absences');
        $add('a_traiter', 'À justifier', 'fa-file-signature', 'side', hm_card_a_traiter($vs), $R . 'modules/absences/absences.php', 'Traiter');
    }
    $add('messages', 'Messages', 'fa-envelope', ($role === 'administrateur' ? 'side' : 'main'), hm_card_messages($conv, $R), $R . 'modules/messagerie/', 'Messagerie');
    $add('agenda', 'À venir', 'fa-calendar-alt', 'side', hm_card_agenda($home->evenements(null), $role === 'vie_scolaire' ? $home->menu() : null), $R . 'modules/agenda/agenda.php', 'Agenda');
}
if ($unreadMsg) { $summary[] = $unreadMsg . ' message' . ($unreadMsg > 1 ? 's' : '') . ' non lu' . ($unreadMsg > 1 ? 's' : ''); }

// ── personnalisation : ordre + cartes masquées ──
$prefs = $home->prefs($uid, $role);
$keys = array_keys($cards);
$order = array_values(array_unique(array_merge(array_values(array_intersect($prefs['order'], $keys)), $keys)));
$hidden = array_values(array_intersect($prefs['hidden'], $keys));

$h = (int) date('G');
$greet = $h < 5 ? 'Bonsoir' : ($h < 12 ? 'Bonjour' : ($h < 18 ? 'Bon après-midi' : 'Bonsoir'));
$first = $user['prenom'] ?? explode(' ', (string) $user_fullname)[0];
$dateLong = hm_dayLabel(date('Y-m-d'));
$dateLong = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][(int) date('w')] . ' ' . (int) date('j') . ' ' . ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][(int) date('n') - 1];

$pageTitle = 'Accueil';
$extraCss = ['assets/css/home.css'];
$extraJs = ['assets/js/home.js'];
include __DIR__ . '/../templates/shared_header.php';
include __DIR__ . '/../templates/shared_topbar.php';
?>
<div class="hm" id="hm" data-api="<?= hm_e($R . 'accueil/api.php') ?>">
  <?php if (!empty($_SESSION['error_message'])): $fm = $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
  <div class="hm-flash" role="alert"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> <?= hm_e($fm) ?></div>
  <?php endif; ?>

  <header class="hm-hero">
    <div>
      <p class="hm-today"><?= hm_e(ucfirst($dateLong)) ?><?= $periode ? ' · ' . hm_e($periode['nom']) : '' ?></p>
      <h1><?= hm_e($greet) ?>, <?= hm_e($first) ?></h1>
      <?php if ($summary): ?><p class="hm-summary"><?= hm_e(ucfirst(implode(' · ', $summary))) ?>.</p><?php endif; ?>
    </div>
    <button type="button" class="hm-btn hm-btn--ghost" data-hm="customize" aria-expanded="false"><i class="fas fa-sliders-h" aria-hidden="true"></i> Personnaliser</button>
  </header>

  <?php if ($role === 'parent' && count($enfants) > 1): ?>
  <nav class="hm-kids" aria-label="Choix de l'enfant">
    <?php foreach ($enfants as $k): ?>
    <a href="?enfant=<?= (int) $k['id'] ?>" class="hm-kid<?= $eleve && (int) $eleve['id'] === (int) $k['id'] ? ' is-on' : '' ?>"<?= $eleve && (int) $eleve['id'] === (int) $k['id'] ? ' aria-current="true"' : '' ?>>
      <span class="hm-av" data-role="eleve"><?= hm_e(mb_substr($k['prenom'], 0, 1) . mb_substr($k['nom'], 0, 1)) ?></span>
      <span><b><?= hm_e($k['prenom']) ?></b><small><?= hm_e($k['classe']) ?></small></span>
    </a>
    <?php endforeach; ?>
  </nav>
  <?php elseif ($role === 'parent' && !$enfants): ?>
  <div class="hm-flash">Aucun enfant n'est rattaché à votre compte. Contactez le secrétariat de l'établissement.</div>
  <?php endif; ?>

  <div class="hm-custom" id="hm-custom" hidden>
    <p><b>Personnaliser l'accueil</b> — affichez ou masquez les cartes et changez leur ordre.</p>
    <ul class="hm-custom-list" id="hm-custom-list">
      <?php foreach ($order as $k): ?>
      <li data-key="<?= hm_e($k) ?>"><label class="hm-switch"><input type="checkbox"<?= in_array($k, $hidden, true) ? '' : ' checked' ?>><span aria-hidden="true"></span></label>
        <span class="hm-custom-name"><i class="fas <?= hm_e($cards[$k][1]) ?>" aria-hidden="true"></i> <?= hm_e($cards[$k][0]) ?></span>
        <button type="button" data-move="-1" aria-label="Monter"><i class="fas fa-arrow-up"></i></button><button type="button" data-move="1" aria-label="Descendre"><i class="fas fa-arrow-down"></i></button></li>
      <?php endforeach; ?>
    </ul>
    <div class="hm-custom-act"><button type="button" class="hm-btn hm-btn--ghost" data-hm="reset">Rétablir l'ordre conseillé</button><button type="button" class="hm-btn" data-hm="save">Enregistrer</button></div>
  </div>

  <?php
  $pos = array_flip($order);
  $renderCard = function (string $k) use ($cards, $hidden, $pos): string {
      [$title, $icon, $col, $html, $link, $linkLabel] = $cards[$k];
      return '<section class="hm-card" style="order:' . (int) $pos[$k] . '" data-key="' . hm_e($k) . '"' . (in_array($k, $hidden, true) ? ' hidden' : '') . ' aria-labelledby="hm-t-' . hm_e($k) . '">'
          . '<header class="hm-card-head"><h2 id="hm-t-' . hm_e($k) . '"><i class="fas ' . hm_e($icon) . '" aria-hidden="true"></i> ' . hm_e($title) . '</h2>'
          . ($link ? '<a class="hm-card-link" href="' . hm_e($link) . '">' . hm_e($linkLabel) . ' <i class="fas fa-arrow-right" aria-hidden="true"></i></a>' : '')
          . '</header><div class="hm-card-body">' . $html . '</div></section>';
  };
  $byCol = ['full' => [], 'main' => [], 'side' => []];
  foreach ($order as $k) { $byCol[$cards[$k][2]][] = $k; }
  ?>
  <div class="hm-grid" id="hm-grid" data-default-order="<?= hm_e(implode(',', array_keys($cards))) ?>">
    <?php if ($byCol['full']): ?><div class="hm-col hm-col--full"><?php foreach ($byCol['full'] as $k) { echo $renderCard($k); } ?></div><?php endif; ?>
    <div class="hm-col hm-col--main"><?php foreach ($byCol['main'] as $k) { echo $renderCard($k); } ?></div>
    <div class="hm-col hm-col--side"><?php foreach ($byCol['side'] as $k) { echo $renderCard($k); } ?></div>
  </div>
</div>
<?php include __DIR__ . '/../templates/shared_footer.php';
