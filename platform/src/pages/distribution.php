<?php
declare(strict_types=1);
require __DIR__ . '/_common.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pf_check_csrf();
    $ip = trim((string) ($_POST['ip'] ?? ''));
    if (!filter_var($ip, FILTER_VALIDATE_IP)) { pf_flash('Adresse IP invalide.', 'crit'); pf_redirect('/distribution'); }
    if (($_POST['action'] ?? '') === 'allow') {
        [$c, $r] = Dist::call('POST', '/admin/allow', ['ip' => $ip, 'note' => mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 120)], 8, true);
        $c === 200 ? pf_audit($op['user'], 'distribution.ip_autoriser', $ip) : null;
        pf_flash($c === 200 ? 'IP ' . e($ip) . ' autorisée.' : e(Dist::explain($c, $r)), $c === 200 ? 'ok' : 'crit');
    } else {
        [$c, $r] = Dist::call('POST', '/admin/disallow', ['ip' => $ip], 8, true);
        $c === 200 ? pf_audit($op['user'], 'distribution.ip_retirer', $ip) : null;
        pf_flash($c === 200 ? 'IP ' . e($ip) . ' retirée.' : e(Dist::explain($c, $r)), $c === 200 ? 'ok' : 'crit');
    }
    Dist::forget();
    pf_redirect('/distribution');
}
$lic = Dist::get('/admin/licenses', 'licenses');
$park = Dist::get('/admin/park', 'park');
$allow = Dist::get('/admin/list', 'allowlist');
$refused = Dist::get('/admin/refused?since_id=0&limit=50', 'refused');
$etabByLic = pf_db()->query('SELECT license_id, id, name FROM establishments WHERE license_id IS NOT NULL')->fetchAll(PDO::FETCH_UNIQUE);

pf_header('distribution', 'Distribution', 'Serveur dist.nicov1.fr (CT122)', pf_badges());
$err = fn($r) => '<div class="pf-notice pf-notice--warn">' . e(Dist::explain($r['code'], $r['data'])) . '</div>';
$etabCell = fn($licId) => isset($etabByLic[$licId]) ? '<a href="/etablissements/' . (int) $etabByLic[$licId]['id'] . '">' . e($etabByLic[$licId]['name']) . '</a>' : '<span class="pf-muted">hors plateforme</span>';

echo '<section class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-server"></i> Parc installé</h2></div><div class="pf-card__body pf-card__body--flush">';
if (!$park['ok']) { echo $err($park); }
elseif (!($park['data']['instances'] ?? [])) { echo '<div class="pf-empty">Aucune instance n\'a encore envoyé de rapport.</div>'; }
else {
    echo '<table class="pf-table"><thead><tr><th>Instance</th><th>Établissement</th><th>Version</th><th>État</th><th>Dernier rapport</th></tr></thead><tbody>';
    foreach ($park['data']['instances'] as $i) {
        [$tl, $tt] = pf_instance_tone($i);
        echo '<tr><td>' . e($i['hostname'] ?? '?') . '<div class="pf-muted">' . e($i['app_url'] ?? '') . '</div></td><td>' . $etabCell((int) $i['license_id']) . '</td><td>v' . e($i['app_version'] ?? '?') . '</td><td>' . pf_pill($tl, $tt) . '</td><td class="pf-muted">' . e(pf_ago($i['last_seen'] ?? null)) . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div></section>';

echo '<section class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-key"></i> Licences</h2></div><div class="pf-card__body pf-card__body--flush">';
if (!$lic['ok']) { echo $err($lic); }
elseif (!($lic['data']['licenses'] ?? [])) { echo '<div class="pf-empty">Aucune licence émise.</div>'; }
else {
    echo '<table class="pf-table"><thead><tr><th>Préfixe</th><th>Établissement</th><th>Modules premium</th><th>Installations</th><th>Fin du support</th><th>État</th></tr></thead><tbody>';
    foreach ($lic['data']['licenses'] as $l) {
        $m = json_decode((string) $l['modules_json'], true) ?: [];
        echo '<tr><td class="pf-mono">' . e($l['key_prefix']) . '…</td><td>' . $etabCell((int) $l['id']) . ($l['note'] && !isset($etabByLic[(int) $l['id']]) ? '<div class="pf-muted">' . e($l['note']) . '</div>' : '') . '</td><td>' . ($m ? e(implode(', ', $m)) : '<span class="pf-muted">—</span>') . '</td><td>' . (int) $l['used_count'] . ' / ' . (int) $l['max_activations'] . '</td><td>' . e($l['expires_at'] ?: '—') . '</td><td>' . ($l['revoked'] ? pf_pill('Révoquée', 'crit') : pf_pill('Valide', 'ok')) . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div></section>';

echo '<div class="pf-grid pf-grid--2">';
echo '<section class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-shield-halved"></i> IP autorisées à télécharger</h2></div><div class="pf-card__body pf-card__body--flush">';
if (!$allow['ok']) { echo $err($allow); }
else {
    $list = $allow['data']['allowlist'] ?? [];
    if (!$list) { echo '<div class="pf-empty">Aucune IP autorisée.</div>'; }
    else {
        echo '<table class="pf-table pf-table--compact"><tbody>';
        foreach ($list as $a) {
            echo '<tr><td class="pf-mono">' . e($a['ip']) . '</td><td class="pf-muted">' . e($a['note'] ?? '') . '</td><td><form method="post" class="pf-inline">' . pf_csrf_field() . '<input type="hidden" name="action" value="disallow"><input type="hidden" name="ip" value="' . e($a['ip']) . '"><button class="pf-btn pf-btn--ghost pf-btn--sm" data-confirm="Retirer cette IP ?">Retirer</button></form></td></tr>';
        }
        echo '</tbody></table>';
    }
}
echo '<form method="post" class="pf-card__body pf-row">' . pf_csrf_field() . '<input type="hidden" name="action" value="allow"><input class="pf-input pf-input--sm" name="ip" placeholder="IP" required><input class="pf-input pf-input--sm" name="note" placeholder="Note"><button class="pf-btn pf-btn--ghost pf-btn--sm">Autoriser</button></form>';
echo '</div></section>';

echo '<section class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-hand"></i> Tentatives refusées</h2></div><div class="pf-card__body pf-card__body--flush">';
$rl = array_slice(array_reverse($refused['data']['refused'] ?? []), 0, 15);
if (!$refused['ok']) { echo $err($refused); }
elseif (!$rl) { echo '<div class="pf-empty">Aucune tentative refusée.</div>'; }
else {
    echo '<table class="pf-table pf-table--compact"><tbody>';
    foreach ($rl as $r) { echo '<tr><td class="pf-mono">' . e($r['ip'] ?? '') . '</td><td>' . e($r['kind'] ?? '') . ' <span class="pf-muted">' . e($r['reason'] ?? '') . '</span></td><td class="pf-muted">' . e($r['at'] ?? '') . '</td></tr>'; }
    echo '</tbody></table>';
}
echo '</div></section></div>';
pf_footer();
