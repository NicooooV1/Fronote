<?php
declare(strict_types=1);
require __DIR__ . '/_common.php';
$url = rtrim((string) (pf_config()['demo_url'] ?? 'https://demo.nicov1.fr'), '/');
$probe = function (string $u): array {
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => false, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($ch);
    $r = ['code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'ms' => (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000), 'size' => is_string($body) ? strlen($body) : 0];
    curl_close($ch);
    return $r;
};
$checks = ['Page d\'accueil' => $url . '/', 'Connexion' => $url . '/login/index.php', 'Entrée démo (professeur)' => $url . '/demo/?role=prof'];
pf_header('demo', 'Démo publique', 'demo.nicov1.fr (CT127)', pf_badges());
echo '<section class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-heart-pulse"></i> Disponibilité</h2><div class="pf-card__actions"><a class="pf-btn pf-btn--ghost pf-btn--sm" href="' . e($url) . '" target="_blank" rel="noopener">Ouvrir la démo</a></div></div><div class="pf-card__body pf-card__body--flush"><table class="pf-table"><tbody>';
foreach ($checks as $label => $u) {
    $p = $probe($u);
    $ok = $p['code'] >= 200 && $p['code'] < 400;
    echo '<tr><td>' . e($label) . '<div class="pf-muted pf-mono">' . e($u) . '</div></td><td>' . pf_pill($ok ? 'OK (' . $p['code'] . ')' : ($p['code'] ? 'Erreur ' . $p['code'] : 'Injoignable'), $ok ? 'ok' : 'crit') . '</td><td>' . pf_pill($p['ms'] . ' ms', $p['ms'] < 800 ? 'ok' : ($p['ms'] < 2000 ? 'warn' : 'crit')) . '</td></tr>';
}
echo '</tbody></table></div></section>';
echo '<section class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-circle-info"></i> Fonctionnement</h2></div><div class="pf-card__body"><ul class="pf-list">'
   . '<li>Instance isolée (CT127) : entrée par Caddy seulement, aucune sortie réseau.</li>'
   . '<li>Données fictives, remises à zéro <strong>chaque heure</strong> (<span class="pf-mono">fronote-demo-reset</span>).</li>'
   . '<li>Cinq rôles d\'essai : administrateur, professeur, élève, parent, vie scolaire (entrée <span class="pf-mono">/demo/?role=…</span>).</li>'
   . '<li>Mise à jour : automatique à chaque publication (<span class="pf-mono">fronote-release.sh</span>).</li></ul></div></section>';
pf_footer();
