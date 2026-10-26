<?php
declare(strict_types=1);
/**
 * Accueil — rendu des cartes. Chaque fonction reçoit des données déjà calculées
 * (HomeService) et produit un fragment HTML échappé. Préfixe CSS : « hm- ».
 */

function hm_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function hm_num(?float $v, int $dec = 1): string { return $v === null ? '—' : number_format($v, $dec, ',', ' '); }
function hm_noteClass(?float $v): string { return $v === null ? '' : ($v >= 14 ? 'is-good' : ($v >= 10 ? 'is-mid' : 'is-low')); }
function hm_color(?string $c): string { return preg_match('/^#[0-9a-f]{6}$/i', (string) $c) ? (string) $c : '#64748b'; }
function hm_dayLabel(string $date, bool $withDay = true): string
{
    $d = new DateTimeImmutable($date); $t = new DateTimeImmutable('today');
    $diff = (int) $t->diff($d)->format('%r%a');
    if ($diff === 0) { return 'Aujourd\'hui'; }
    if ($diff === 1) { return 'Demain'; }
    if ($diff === -1) { return 'Hier'; }
    $j = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][(int) $d->format('w')];
    $m = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][(int) $d->format('n') - 1];
    return ($withDay ? $j . ' ' : '') . (int) $d->format('j') . ' ' . $m;
}
function hm_short(string $date): string
{
    $d = new DateTimeImmutable($date);
    return (int) $d->format('j') . ' ' . ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'][(int) $d->format('n') - 1];
}
function hm_empty(string $icon, string $text): string
{
    return '<div class="hm-empty"><i class="fas ' . hm_e($icon) . '" aria-hidden="true"></i><span>' . hm_e($text) . '</span></div>';
}

/** Frise de la journée. $appels (prof) : map classe|heure → appel ; $csrf : champ CSRF du formulaire d'appel. */
function hm_card_journee(array $day, string $root, string $role, array $appels = [], string $csrf = ''): string
{
    if (!$day['items']) { return hm_empty('fa-mug-hot', 'Aucun cours prévu cette semaine.'); }
    $h = '';
    if (!$day['is_today']) { $h .= '<p class="hm-note"><i class="far fa-calendar" aria-hidden="true"></i> Pas de cours aujourd\'hui — prochaine journée : <strong>' . hm_e(hm_dayLabel($day['date'])) . '</strong></p>'; }
    $h .= '<ol class="hm-tl">';
    $nextShown = false;
    foreach ($day['items'] as $c) {
        $etat = $c['etat'];
        $badge = '';
        if ($etat === 'encours') { $badge = '<span class="hm-badge hm-badge--live">En cours</span>'; }
        elseif ($etat === 'passe') { $badge = '<span class="hm-badge hm-badge--done">Terminé</span>'; }
        elseif ($day['is_today'] && !$nextShown) {
            $mins = (int) round((strtotime($day['date'] . ' ' . $c['heure_debut']) - time()) / 60);
            if ($mins >= 0) { $badge = '<span class="hm-badge hm-badge--next">' . ($mins < 60 ? 'Dans ' . $mins . ' min' : 'À ' . hm_e($c['debut'])) . '</span>'; }
            $nextShown = true;
        }
        $who = $role === 'professeur' ? 'Classe ' . ($c['classe'] ?? '') : ($c['professeur'] ?? '');
        $h .= '<li class="hm-tl-item is-' . hm_e($etat) . '" style="--c:' . hm_e(hm_color($c['couleur'])) . '">'
            . '<span class="hm-tl-time"><b>' . hm_e($c['debut']) . '</b><small>' . hm_e($c['fin']) . '</small></span>'
            . '<span class="hm-tl-body"><span class="hm-tl-title">' . hm_e($c['matiere'] ?? 'Cours') . ' ' . $badge . '</span>'
            . '<span class="hm-tl-meta"><i class="fas fa-door-open" aria-hidden="true"></i> ' . hm_e($c['salle'] ?? '—') . '<span class="hm-dot">·</span>' . hm_e($who) . '</span></span>';
        if ($role === 'professeur' && $day['is_today'] && !empty($c['classe_id'])) {
            $a = $appels[(int) $c['classe_id'] . '|' . $c['debut']] ?? null;
            if ($a) {
                $done = in_array($a['statut'], ['valide', 'cloture'], true);
                $h .= '<a class="hm-tl-act' . ($done ? ' is-done' : '') . '" href="' . hm_e($root . 'modules/appel/appel.php?id=' . (int) $a['id'] . '&date=' . $day['date']) . '">'
                    . ($done ? '<i class="fas fa-check" aria-hidden="true"></i> Appel fait' : '<i class="fas fa-clipboard-list" aria-hidden="true"></i> Reprendre') . '</a>';
            } else {
                $h .= '<form method="post" action="' . hm_e($root . 'modules/appel/appel.php?date=' . $day['date']) . '" class="hm-tl-form">' . $csrf
                    . '<input type="hidden" name="action" value="creer_appel"><input type="hidden" name="classe_id" value="' . (int) $c['classe_id'] . '">'
                    . '<input type="hidden" name="matiere_id" value="' . (int) $c['matiere_id'] . '"><input type="hidden" name="heure_debut" value="' . hm_e($c['heure_debut']) . '">'
                    . '<input type="hidden" name="heure_fin" value="' . hm_e($c['heure_fin']) . '">'
                    . '<button type="submit" class="hm-tl-act' . ($etat === 'passe' ? ' is-late' : '') . '"><i class="fas fa-clipboard-check" aria-hidden="true"></i> ' . ($etat === 'passe' ? 'Appel non fait' : 'Faire l\'appel') . '</button></form>';
            }
        }
        $h .= '</li>';
    }
    return $h . '</ol>';
}

function hm_card_notes(array $notes): string
{
    if (!$notes) { return hm_empty('fa-star', 'Aucune note pour le moment.'); }
    $h = '<ul class="hm-notes">';
    foreach ($notes as $n) {
        $v = (float) $n['sur20']; $mc = $n['moy_classe'] !== null ? (float) $n['moy_classe'] : null;
        $delta = $mc !== null ? $v - $mc : null;
        $sur = (float) $n['note_sur'];
        $h .= '<li class="hm-note-row" style="--c:' . hm_e(hm_color($n['couleur'])) . '">'
            . '<span class="hm-note-subj"><b>' . hm_e($n['matiere'] ?? '') . ($n['nouveau'] ? ' <span class="hm-new">Nouveau</span>' : '') . '</b>'
            . '<small>' . hm_e($n['type_evaluation'] ?: 'Évaluation') . ' · ' . hm_e(hm_short((string) $n['date_note'])) . ((float) $n['coefficient'] !== 1.0 ? ' · coef. ' . hm_e(hm_num((float) $n['coefficient'], 1)) : '') . '</small>'
            . ($n['commentaire'] ? '<em>« ' . hm_e($n['commentaire']) . ' »</em>' : '') . '</span>'
            . '<span class="hm-note-val ' . hm_noteClass($v) . '"><b>' . hm_e(hm_num((float) $n['note'], (float) $n['note'] == floor((float) $n['note']) ? 0 : 1)) . '</b><small>/' . hm_e(hm_num($sur, 0)) . '</small></span>'
            . '<span class="hm-note-cls">' . ($mc !== null ? '<small>Classe</small><b>' . hm_e(hm_num($mc, 1)) . '</b>'
                . '<i class="fas ' . ($delta >= 0.5 ? 'fa-arrow-up is-up' : ($delta <= -0.5 ? 'fa-arrow-down is-down' : 'fa-equals')) . '" title="' . hm_e(($delta >= 0 ? '+' : '') . hm_num($delta, 1)) . ' par rapport à la classe" aria-hidden="true"></i>' : '') . '</span>'
            . '</li>';
    }
    return $h . '</ul>';
}

function hm_card_moyennes(array $m): string
{
    if (!$m['matieres']) { return hm_empty('fa-chart-bar', 'Pas encore de moyenne sur cette période.'); }
    $g = $m['generale']; $gc = $m['generale_classe'];
    $h = '<div class="hm-avg-head"><div><small>Moyenne générale</small><b class="' . hm_noteClass($g) . '">' . hm_e(hm_num($g, 2)) . '</b><span>/20</span></div>'
        . '<div class="hm-avg-cls"><small>Classe</small><b>' . hm_e(hm_num($gc, 2)) . '</b></div></div><ul class="hm-bars">';
    foreach ($m['matieres'] as $r) {
        $v = $r['moy']; $c = $r['classe'];
        $h .= '<li style="--c:' . hm_e(hm_color($r['couleur'])) . '"><span class="hm-bar-lbl">' . hm_e($r['matiere']) . '</span>'
            . '<span class="hm-bar" role="img" aria-label="' . hm_e($r['matiere'] . ' : ' . hm_num($v, 2) . ', classe ' . hm_num($c, 2)) . '">'
            . '<span class="hm-bar-fill" style="width:' . ($v !== null ? round($v * 5, 1) : 0) . '%"></span>'
            . ($c !== null ? '<span class="hm-bar-cls" style="left:' . round($c * 5, 1) . '%" title="Moyenne de la classe : ' . hm_e(hm_num($c, 2)) . '"></span>' : '') . '</span>'
            . '<span class="hm-bar-val ' . hm_noteClass($v) . '">' . hm_e(hm_num($v, 2)) . '</span></li>';
    }
    return $h . '</ul><p class="hm-legend"><span class="hm-legend-fill"></span> Moyenne · <span class="hm-legend-cls"></span> Moyenne de la classe</p>';
}

function hm_card_devoirs(array $dv, bool $toggle): string
{
    if (!$dv) { return hm_empty('fa-check-circle', 'Aucun devoir à venir. Profitez-en !'); }
    $h = ''; $cur = null;
    foreach ($dv as $d) {
        if ($cur !== $d['date_rendu']) {
            if ($cur !== null) { $h .= '</ul>'; }
            $cur = $d['date_rendu'];
            $n = count(array_filter($dv, fn($x) => $x['date_rendu'] === $cur && !$x['fait']));
            $h .= '<div class="hm-dv-day"><span>Pour ' . hm_e(strtolower(hm_dayLabel($cur)) === 'demain' ? 'demain' : hm_dayLabel($cur)) . '</span>' . ($n ? '<small>' . $n . ' à faire</small>' : '<small class="is-ok">Tout est fait</small>') . '</div><ul class="hm-dv">';
        }
        $h .= '<li class="hm-dv-item' . ($d['fait'] ? ' is-done' : '') . '" style="--c:' . hm_e(hm_color($d['couleur'])) . '">'
            . ($toggle ? '<label class="hm-check"><input type="checkbox" data-devoir="' . (int) $d['id'] . '"' . ($d['fait'] ? ' checked' : '') . '><span aria-hidden="true"></span><em class="hm-sr">Marquer comme fait</em></label>' : '<span class="hm-dv-dot" aria-hidden="true"></span>')
            . '<span class="hm-dv-body"><b>' . hm_e($d['matiere']) . ($d['nouveau'] ? ' <span class="hm-new">Nouveau</span>' : '') . '</b><span>' . hm_e($d['titre']) . '</span></span></li>';
    }
    return $h . '</ul>';
}

function hm_card_vie(array $v): string
{
    $tile = fn($val, $lbl, $warn = false) => '<div class="hm-tile' . ($warn ? ' is-warn' : '') . '"><b>' . hm_e($val) . '</b><small>' . hm_e($lbl) . '</small></div>';
    $h = '<div class="hm-tiles">' . $tile($v['absences'], 'absence' . ($v['absences'] > 1 ? 's' : '')) . $tile($v['heures'] . ' h', 'manquées')
        . $tile($v['non_justifiees'], 'non justifiée' . ($v['non_justifiees'] > 1 ? 's' : ''), $v['non_justifiees'] > 0) . $tile($v['retards'], 'retard' . ($v['retards'] > 1 ? 's' : ''), $v['retards_nj'] > 0) . '</div>';
    if ($v['derniere']) {
        $a = $v['derniere'];
        $h .= '<p class="hm-note"><i class="fas fa-history" aria-hidden="true"></i> Dernière absence : ' . hm_e(hm_dayLabel(substr((string) $a['date_debut'], 0, 10))) . ', ' . hm_e(substr((string) $a['date_debut'], 11, 5)) . '–' . hm_e(substr((string) $a['date_fin'], 11, 5))
            . ' · ' . hm_e($a['motif'] ?: 'sans motif') . ' · ' . ((int) $a['justifie'] ? '<span class="hm-ok">justifiée</span>' : '<span class="hm-warn">à justifier</span>') . '</p>';
    } else { $h .= '<p class="hm-note"><i class="fas fa-medal" aria-hidden="true"></i> Aucune absence ce trimestre.</p>'; }
    return $h;
}

function hm_card_messages(array $conv, string $root): string
{
    if (!$conv) { return hm_empty('fa-envelope-open', 'Aucun message.'); }
    $h = '<ul class="hm-msgs">';
    foreach (array_slice($conv, 0, 4) as $c) {
        $who = $c['with'] ? implode(', ', $c['with']) : 'Moi';
        $av = $c['avatar'] ?? null;
        $h .= '<li><a class="hm-msg' . ($c['unread'] ? ' is-unread' : '') . '" href="' . hm_e($root . 'modules/messagerie/?c=' . (int) $c['id']) . '">'
            . '<span class="hm-av" data-role="' . hm_e($av['type'] ?? 'groupe') . '">' . hm_e($av['initials'] ?? '…') . '</span>'
            . '<span class="hm-msg-body"><span class="hm-msg-top"><b>' . hm_e($who) . '</b><time>' . hm_e(hm_dayLabel(substr((string) $c['updated_at'], 0, 10), false)) . '</time></span>'
            . '<span class="hm-msg-subj">' . hm_e($c['subject']) . '</span><span class="hm-msg-prev">' . hm_e(($c['last']['mine'] ? 'Vous : ' : '') . $c['last']['preview']) . '</span></span>'
            . ($c['unread'] ? '<span class="hm-count">' . (int) $c['unread'] . '</span>' : '') . '</a></li>';
    }
    return $h . '</ul>';
}

function hm_card_agenda(array $events, ?array $menu): string
{
    $h = '';
    if ($events) {
        $h .= '<ul class="hm-agenda">';
        foreach ($events as $e) {
            $d = new DateTimeImmutable((string) $e['date_debut']);
            $h .= '<li><span class="hm-date"><b>' . (int) $d->format('j') . '</b><small>' . hm_e(['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'][(int) $d->format('n') - 1]) . '</small></span>'
                . '<span class="hm-agenda-body"><b>' . hm_e($e['titre']) . '</b><small>' . hm_e(hm_dayLabel($d->format('Y-m-d'))) . ' · ' . hm_e($d->format('H:i')) . ($e['lieu'] ? ' · ' . hm_e($e['lieu']) : '') . '</small></span></li>';
        }
        $h .= '</ul>';
    } else { $h .= hm_empty('fa-calendar-check', 'Rien de prévu dans les trois prochaines semaines.'); }
    if ($menu) {
        $h .= '<div class="hm-menu"><div class="hm-menu-head"><i class="fas fa-utensils" aria-hidden="true"></i> Menu ' . hm_e(strtolower(hm_dayLabel((string) $menu['date_menu']))) . '</div>'
            . '<p><span>' . hm_e($menu['entree']) . '</span><b>' . hm_e($menu['plat_principal']) . '</b><span>' . hm_e($menu['accompagnement']) . '</span><span>' . hm_e($menu['dessert']) . '</span></p>'
            . ($menu['regime_special'] ? '<small>' . hm_e($menu['regime_special']) . '</small>' : '') . '</div>';
    }
    return $h;
}

function hm_card_annonces(array $a): string
{
    if (!$a) { return hm_empty('fa-bullhorn', 'Aucune annonce.'); }
    $h = '<ul class="hm-ann">';
    foreach ($a as $x) {
        $h .= '<li class="' . ($x['type'] === 'urgent' ? 'is-urgent' : '') . '">' . ((int) $x['epingle'] ? '<i class="fas fa-thumbtack" title="Épinglée" aria-hidden="true"></i> ' : '')
            . '<b>' . hm_e($x['titre']) . '</b><p>' . hm_e(mb_strimwidth(strip_tags((string) $x['contenu']), 0, 150, '…')) . '</p><small>' . hm_e(hm_dayLabel(substr((string) $x['date_publication'], 0, 10))) . '</small></li>';
    }
    return $h . '</ul>';
}

function hm_card_evaluations(array $ev): string
{
    if (!$ev) { return hm_empty('fa-pen', 'Aucune évaluation saisie.'); }
    $h = '<ul class="hm-evals">';
    foreach ($ev as $e) {
        $moy = $e['moyenne'] !== null ? (float) $e['moyenne'] : null;
        $h .= '<li style="--c:' . hm_e(hm_color($e['couleur'])) . '"><span class="hm-eval-cls">' . hm_e($e['classe']) . '</span>'
            . '<span class="hm-eval-body"><b>' . hm_e($e['type_evaluation'] ?: 'Évaluation') . ' · ' . hm_e($e['matiere']) . '</b><small>' . hm_e(hm_dayLabel((string) $e['date_note'])) . ' · ' . (int) $e['copies'] . ' copies</small>'
            . '<span class="hm-range" title="De ' . hm_e(hm_num((float) $e['mini'])) . ' à ' . hm_e(hm_num((float) $e['maxi'])) . '"><span style="left:' . round((float) $e['mini'] * 5, 1) . '%;width:' . round(((float) $e['maxi'] - (float) $e['mini']) * 5, 1) . '%"></span><i style="left:' . round(($moy ?? 0) * 5, 1) . '%"></i></span></span>'
            . '<span class="hm-eval-moy ' . hm_noteClass($moy) . '"><b>' . hm_e(hm_num($moy, 1)) . '</b><small>' . hm_e(hm_num((float) $e['mini'])) . '–' . hm_e(hm_num((float) $e['maxi'])) . '</small></span></li>';
    }
    return $h . '</ul>';
}

function hm_card_classes(array $cls, string $root): string
{
    if (!$cls) { return hm_empty('fa-users', 'Aucune classe.'); }
    $h = '<table class="hm-table"><thead><tr><th>Classe</th><th>Élèves</th><th>Moyenne</th><th>Absents</th></tr></thead><tbody>';
    foreach ($cls as $c) {
        $h .= '<tr><td><b>' . hm_e($c['nom']) . '</b>' . (!empty($c['pp']) ? ' <span class="hm-pp" title="Professeur principal">PP</span>' : '') . '</td><td>' . (int) $c['effectif'] . '</td>'
            . '<td class="' . hm_noteClass($c['moyenne']) . '">' . hm_e(hm_num($c['moyenne'], 1)) . '</td><td>' . ((int) $c['absents'] ? '<span class="hm-warn">' . (int) $c['absents'] . '</span>' : '0') . '</td></tr>';
    }
    return $h . '</tbody></table>';
}

function hm_card_devoirs_donnes(array $dv): string
{
    if (!$dv) { return hm_empty('fa-book-open', 'Aucun devoir à venir donné.'); }
    $h = '<ul class="hm-simple">';
    foreach ($dv as $d) { $h .= '<li><span class="hm-chip">' . hm_e($d['classe']) . '</span><span><b>' . hm_e($d['titre']) . '</b><small>Pour ' . hm_e(hm_dayLabel((string) $d['date_rendu'])) . '</small></span></li>'; }
    return $h . '</ul>';
}

function hm_card_kpis(array $tiles): string
{
    $h = '<div class="hm-kpis">';
    foreach ($tiles as $t) {
        $h .= '<a class="hm-kpi' . (!empty($t['warn']) ? ' is-warn' : '') . '" href="' . hm_e($t['href'] ?? '#') . '"><span class="hm-kpi-ico"><i class="fas ' . hm_e($t['icon']) . '" aria-hidden="true"></i></span>'
            . '<span><b>' . hm_e($t['value']) . '</b><small>' . hm_e($t['label']) . '</small>' . (!empty($t['sub']) ? '<em>' . hm_e($t['sub']) . '</em>' : '') . '</span>'
            . (!empty($t['spark']) ? $t['spark'] : '') . '</a>';
    }
    return $h . '</div>';
}

function hm_spark(array $vals): string
{
    if (!$vals) { return ''; }
    $max = max(1, max($vals)); $n = count($vals); $pts = [];
    foreach ($vals as $i => $v) { $pts[] = round($i * 100 / max(1, $n - 1), 1) . ',' . round(28 - $v / $max * 24, 1); }
    return '<svg class="hm-spark" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true"><polyline points="' . implode(' ', $pts) . '" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke"/></svg>';
}

function hm_card_absents(array $vs, string $root): string
{
    if (!$vs['absents']) { return hm_empty('fa-user-check', 'Aucun élève absent aujourd\'hui.'); }
    $h = '<ul class="hm-simple">';
    foreach (array_slice($vs['absents'], 0, 10) as $a) {
        $h .= '<li><span class="hm-chip">' . hm_e($a['classe']) . '</span><span><b>' . hm_e($a['prenom'] . ' ' . $a['nom']) . '</b><small>' . hm_e(substr((string) $a['date_debut'], 11, 5)) . '–' . hm_e(substr((string) $a['date_fin'], 11, 5))
            . ' · ' . hm_e($a['motif'] ?: 'sans motif') . '</small></span>' . ((int) $a['justifie'] ? '<span class="hm-ok">Justifiée</span>' : '<span class="hm-warn">À justifier</span>') . '</li>';
    }
    if (count($vs['absents']) > 10) { $h .= '<li class="hm-more">+ ' . (count($vs['absents']) - 10) . ' autres</li>'; }
    return $h . '</ul>';
}

function hm_card_a_traiter(array $vs): string
{
    if (!$vs['a_traiter']) { return hm_empty('fa-check-double', 'Toutes les absences récentes sont justifiées.'); }
    $h = '<ul class="hm-simple">';
    foreach ($vs['a_traiter'] as $a) {
        $h .= '<li><span class="hm-chip">' . hm_e($a['classe']) . '</span><span><b>' . hm_e($a['prenom'] . ' ' . $a['nom']) . '</b><small>' . hm_e(hm_dayLabel(substr((string) $a['date_debut'], 0, 10)))
            . ' · ' . hm_e(substr((string) $a['date_debut'], 11, 5)) . '–' . hm_e(substr((string) $a['date_fin'], 11, 5)) . '</small></span></li>';
    }
    return $h . '</ul>';
}

function hm_card_classes_dir(array $cls): string
{
    if (!$cls) { return hm_empty('fa-school', 'Aucune classe.'); }
    $h = '<ul class="hm-bars">';
    foreach ($cls as $c) {
        $v = $c['moyenne'] !== null ? (float) $c['moyenne'] : null;
        $h .= '<li><span class="hm-bar-lbl">' . hm_e($c['classe']) . ' <small>' . (int) $c['eleves'] . ' él.</small></span><span class="hm-bar"><span class="hm-bar-fill" style="width:' . ($v !== null ? round($v * 5, 1) : 0) . '%;background:var(--hm-primary)"></span></span>'
            . '<span class="hm-bar-val ' . hm_noteClass($v) . '">' . hm_e(hm_num($v, 2)) . '</span></li>';
    }
    return $h . '</ul>';
}
