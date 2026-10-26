<?php
declare(strict_types=1);
require __DIR__ . '/_common.php';
$cfg = pf_config();
$url = rtrim((string) ($cfg['demo_url'] ?? 'https://demo.nicov1.fr'), '/');
$resetToken = (string) ($cfg['demo_reset_token'] ?? '');

/** Appel de l'endpoint de remise à zéro de la démo (/demo/reset.php, jeton partagé). */
$resetCall = function (string $method) use ($url, $resetToken): array {
    $ch = curl_init($url . '/demo/reset.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['X-Demo-Reset-Token: ' . $resetToken, 'Accept: application/json'], CURLOPT_POSTFIELDS => $method === 'POST' ? '' : null]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, is_string($body) ? (json_decode($body, true) ?: []) : []];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset') {
    pf_check_csrf();
    if ($resetToken === '') {
        pf_flash('Bouton non configuré : renseigner demo_reset_token dans /etc/fronote-platform/config.php.', 'crit');
    } else {
        [$c, $r] = $resetCall('POST');
        if ($c === 202) {
            pf_audit($op['user'], 'demo.reinitialiser', 'demo');
            pf_flash('Réinitialisation demandée : la démo affiche « maintenance » ~15 secondes puis repart avec des données neuves.');
        } elseif ($c === 429) {
            pf_flash(e((string) ($r['message'] ?? 'Réinitialisation déjà en cours.')), 'warn');
        } else {
            pf_flash('La démo a refusé la demande (HTTP ' . $c . ($c === 403 ? ' : jeton différent de DEMO_RESET_TOKEN' : '') . ').', 'crit');
        }
    }
    pf_redirect('/demo');
}

$probe = function (string $u): array {
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($ch);
    $r = ['code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'ms' => (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000), 'size' => is_string($body) ? strlen($body) : 0];
    curl_close($ch);
    return $r;
};
$checks = ['Choix du rôle' => $url . '/demo/', 'Connexion' => $url . '/login/index.php', 'Entrée démo (professeur)' => $url . '/demo/?role=prof'];
$state = $resetToken !== '' ? $resetCall('GET') : [0, []];

pf_header('demo', 'Démo publique', 'demo.nicov1.fr (CT127)', pf_badges());

// ── Remise à zéro ──
$last = $state[1]['last_reset'] ?? null;
$ago = $last ? max(0, time() - (int) strtotime((string) $last)) : null;
$agoTxt = $ago === null ? 'inconnue' : ($ago < 90 ? 'il y a ' . $ago . ' s' : ($ago < 5400 ? 'il y a ' . round($ago / 60) . ' min' : 'il y a ' . round($ago / 3600, 1) . ' h'));
echo '<section class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-rotate"></i> Remise à zéro</h2></div><div class="pf-card__body">';
if ($resetToken === '') {
    echo '<p class="pf-muted">Le bouton n\'est pas configuré : ajouter <span class="pf-mono">demo_reset_token</span> dans la configuration de la plateforme (même valeur que <span class="pf-mono">DEMO_RESET_TOKEN</span> sur la démo).</p>';
} else {
    echo '<p>Dernière remise à zéro : <strong>' . e($agoTxt) . '</strong>'
       . ($last ? ' <span class="pf-muted">(' . e(date('d/m H:i', (int) strtotime((string) $last))) . ', ' . e((string) ($state[1]['trigger'] ?? '')) . ')</span>' : '')
       . (!empty($state[1]['queued']) ? ' · ' . pf_pill('demande en cours', 'warn') : '')
       . ($state[0] !== 200 ? ' · ' . pf_pill('état illisible (HTTP ' . $state[0] . ')', 'crit') : '') . '</p>';
    echo '<p class="pf-muted">Efface tout ce que les visiteurs ont fait et régénère le collège fictif (dates du jour). Automatique chaque heure ; utile après une démonstration en direct.</p>';
    echo '<form method="post" action="/demo">' . pf_csrf_field() . '<input type="hidden" name="action" value="reset">'
       . '<button class="pf-btn" type="submit" data-confirm="Réinitialiser la démo maintenant ? Les visiteurs connectés seront déconnectés.">'
       . '<i class="fas fa-rotate"></i> Réinitialiser la démo</button></form>';
}
echo '</div></section>';

echo '<section class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-heart-pulse"></i> Disponibilité</h2><div class="pf-card__actions"><a class="pf-btn pf-btn--ghost pf-btn--sm" href="' . e($url) . '/demo/" target="_blank" rel="noopener">Ouvrir la démo</a></div></div><div class="pf-card__body pf-card__body--flush"><table class="pf-table"><tbody>';
foreach ($checks as $label => $u) {
    $p = $probe($u);
    $ok = $p['code'] >= 200 && $p['code'] < 400;
    echo '<tr><td>' . e($label) . '<div class="pf-muted pf-mono">' . e($u) . '</div></td><td>' . pf_pill($ok ? 'OK (' . $p['code'] . ')' : ($p['code'] ? 'Erreur ' . $p['code'] : 'Injoignable'), $ok ? 'ok' : 'crit') . '</td><td>' . pf_pill($p['ms'] . ' ms', $p['ms'] < 800 ? 'ok' : ($p['ms'] < 2000 ? 'warn' : 'crit')) . '</td></tr>';
}
echo '</tbody></table></div></section>';
echo '<section class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-circle-info"></i> Fonctionnement</h2></div><div class="pf-card__body"><ul class="pf-list">'
   . '<li>Instance isolée (CT127) : entrée par Caddy seulement, aucune sortie réseau.</li>'
   . '<li>Données fictives, remises à zéro <strong>chaque heure</strong> et à la demande (<span class="pf-mono">fronote-demo-reset</span>).</li>'
   . '<li>Cinq rôles d\'essai : direction, professeur, élève, parent, vie scolaire (entrée <span class="pf-mono">/demo/?role=…</span>).</li>'
   . '<li>Mise à jour : <span class="pf-mono">fronote-demo-update.sh</span> après chaque publication (<span class="pf-mono">fronote-release.sh</span>).</li></ul></div></section>';
pf_footer();
