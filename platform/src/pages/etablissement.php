<?php
declare(strict_types=1);
require __DIR__ . '/_common.php';
$db = pf_db();
$load = function () use ($db, $ID) {
    $s = $db->prepare('SELECT * FROM establishments WHERE id = ?');
    $s->execute([$ID]);
    return $s->fetch() ?: null;
};
$etab = $load();
if (!$etab) { http_response_code(404); pf_header('etablissements', 'Établissement introuvable'); echo '<div class="pf-empty">Établissement introuvable.</div>'; pf_footer(); exit; }
$oneShot = null; // secret à afficher une seule fois (nouvelle clé de licence ou clé d'API)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pf_check_csrf();
    $act = (string) ($_POST['action'] ?? '');
    $name = $etab['name'];
    switch ($act) {
        case 'infos':
            $email = trim((string) ($_POST['contact_email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { pf_flash('Adresse e-mail invalide.', 'crit'); break; }
            $db->prepare('UPDATE establishments SET contact_name = ?, contact_email = ?, city = ?, notes = ? WHERE id = ?')
               ->execute([trim((string) $_POST['contact_name']) ?: null, $email ?: null, trim((string) $_POST['city']) ?: null, trim((string) $_POST['notes']) ?: null, $ID]);
            pf_audit($op['user'], 'etablissement.modifier', $name);
            pf_flash('Fiche mise à jour.');
            break;
        case 'ip':
            $ip = trim((string) ($_POST['ip'] ?? ''));
            if (!filter_var($ip, FILTER_VALIDATE_IP)) { pf_flash('Adresse IP invalide.', 'crit'); break; }
            [$c, $r] = Dist::call('POST', '/admin/allow', ['ip' => $ip, 'note' => $name], 8, true);
            if ($c === 200) { pf_audit($op['user'], 'distribution.ip_autoriser', $ip, ['etablissement' => $ID]); pf_flash('IP ' . e($ip) . ' autorisée à télécharger.'); }
            else { pf_flash(e(Dist::explain($c, $r)), 'crit'); }
            break;
        case 'revoquer':
            if (!$etab['license_prefix']) { pf_flash('Aucune licence à révoquer.', 'warn'); break; }
            [$c, $r] = Dist::call('POST', '/admin/revoke', ['prefix' => $etab['license_prefix']]);
            if ($c !== 200) { pf_flash(e(Dist::explain($c, $r)), 'crit'); break; }
            $db->prepare("UPDATE establishments SET status = 'suspendu' WHERE id = ?")->execute([$ID]);
            pf_audit($op['user'], 'etablissement.suspendre', $name, ['licence' => $etab['license_prefix']]);
            pf_flash('Licence révoquée : l\'instance continue de fonctionner mais ne reçoit plus de mises à jour ni de support.', 'warn');
            break;
        case 'nouvelle_cle':
            [$c, $r] = Dist::call('POST', '/admin/issue', [
                'tier' => $etab['tier'], 'modules' => json_decode((string) $etab['modules_json'], true) ?: [],
                'max_activations' => (int) $etab['max_activations'], 'expires_at' => $etab['expires_at'],
                'note' => mb_substr($name . ($etab['uai'] ? ' (' . $etab['uai'] . ')' : ''), 0, 200),
            ]);
            if ($c !== 200 || empty($r['key'])) { pf_flash(e(Dist::explain($c, $r)), 'crit'); break; }
            if ($etab['license_prefix'] && empty($_POST['garder'])) { Dist::call('POST', '/admin/revoke', ['prefix' => $etab['license_prefix']]); }
            $db->prepare("UPDATE establishments SET license_id = ?, license_prefix = ?, status = 'autorise' WHERE id = ?")->execute([(int) $r['id'], (string) $r['prefix'], $ID]);
            pf_audit($op['user'], 'etablissement.nouvelle_licence', $name, ['ancienne' => $etab['license_prefix'], 'nouvelle' => $r['prefix']]);
            $oneShot = ['Nouvelle clé de licence', (string) $r['key'], 'L\'ancienne licence est révoquée. Réinstallation : sudo ./bootstrap-client.sh --key=<clé>'];
            break;
        case 'cle_api':
            // Instance hors distribution (ex. instance de référence) : clé d'API pour l'escalade du support.
            $key = 'fpk_' . bin2hex(random_bytes(24));
            $db->prepare('INSERT INTO instance_links (establishment_id, api_key_hash, label, verified_at) VALUES (?, ?, ?, NOW())')
               ->execute([$ID, hash('sha256', $key), trim((string) ($_POST['label'] ?? '')) ?: $name]);
            pf_audit($op['user'], 'instance.cle_api', $name);
            $oneShot = ['Clé d\'API de l\'instance', $key, 'À placer dans le .env de l\'instance : PLATFORM_API_KEY=<clé> (et PLATFORM_URL=' . pf_config()['public_url'] . ').'];
            break;
        case 'revoquer_lien':
            $db->prepare('UPDATE instance_links SET revoked = 1 WHERE id = ? AND establishment_id = ?')->execute([(int) $_POST['link'], $ID]);
            pf_audit($op['user'], 'instance.lien_revoquer', $name, ['link' => (int) $_POST['link']]);
            pf_flash('Accès de l\'instance à la plateforme révoqué.', 'warn');
            break;
        case 'archiver':
            $db->prepare("UPDATE establishments SET status = 'archive' WHERE id = ?")->execute([$ID]);
            pf_audit($op['user'], 'etablissement.archiver', $name);
            pf_flash('Établissement archivé.');
            break;
    }
    Dist::forget();
    if ($oneShot === null) { pf_redirect('/etablissements/' . $ID); }
    $etab = $load();
}

$lic = null;
foreach ((Dist::get('/admin/licenses', 'licenses')['data']['licenses'] ?? []) as $l) {
    if ((int) $l['id'] === (int) $etab['license_id']) { $lic = $l; }
}
$parkAll = Dist::get('/admin/park', 'park');
$insts = pf_park_by_license()[(int) $etab['license_id']] ?? [];
$links = $db->prepare('SELECT * FROM instance_links WHERE establishment_id = ? ORDER BY id DESC'); $links->execute([$ID]); $links = $links->fetchAll();
$tickets = $db->prepare('SELECT * FROM tickets WHERE establishment_id = ? ORDER BY updated_at DESC LIMIT 20'); $tickets->execute([$ID]); $tickets = $tickets->fetchAll();
// Une instance a fait son premier rapport : l'établissement passe « installé ».
if ($insts && $etab['status'] === 'autorise') {
    $db->prepare("UPDATE establishments SET status = 'installe' WHERE id = ?")->execute([$ID]);
    $etab['status'] = 'installe';
}
[$sl, $st] = PF_STATUS[$etab['status']];

pf_header('etablissements', $etab['name'], 'Établissement', pf_badges());
if ($oneShot) {
    echo '<section class="pf-card pf-keycard"><div class="pf-card__body"><div class="pf-notice pf-notice--warn"><strong>Copiez-la maintenant :</strong> elle ne sera plus affichée.</div>'
       . '<div class="pf-field"><span class="pf-field__label">' . e($oneShot[0]) . '</span><code class="pf-key">' . e($oneShot[1]) . '</code></div><p class="pf-muted">' . e($oneShot[2]) . '</p></div></section>';
}
echo '<div class="pf-row pf-head-meta">' . pf_pill($sl, $st) . ($etab['uai'] ? ' <span class="pf-mono">' . e($etab['uai']) . '</span>' : '') . ($etab['city'] ? ' <span class="pf-muted">' . e($etab['city']) . '</span>' : '') . '<span class="pf-topbar__spacer"></span><a class="pf-btn pf-btn--ghost pf-btn--sm" href="/etablissements"><i class="fas fa-arrow-left"></i> Établissements</a></div>';

echo '<div class="pf-grid pf-grid--2">';
// Licence
echo '<section class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-key"></i> Licence</h2></div><div class="pf-card__body">';
if ($lic) {
    $mods = json_decode((string) $lic['modules_json'], true) ?: [];
    echo '<dl class="pf-dl"><dt>Préfixe</dt><dd class="pf-mono">' . e($lic['key_prefix']) . '…</dd>'
       . '<dt>État</dt><dd>' . ($lic['revoked'] ? pf_pill('Révoquée', 'crit') : pf_pill('Valide', 'ok')) . '</dd>'
       . '<dt>Installations</dt><dd>' . (int) $lic['used_count'] . ' / ' . (int) $lic['max_activations'] . '</dd>'
       . '<dt>Modules premium</dt><dd>' . ($mods ? e(implode(', ', $mods)) : '<span class="pf-muted">aucun</span>') . '</dd>'
       . '<dt>Fin du support</dt><dd>' . e($lic['expires_at'] ?: 'sans limite') . '</dd>'
       . '<dt>Émise le</dt><dd>' . e($lic['created_at']) . '</dd></dl>';
} else {
    echo '<p class="pf-muted">' . ($etab['license_prefix'] ? 'Licence ' . e($etab['license_prefix']) . '… introuvable sur le serveur de distribution.' : 'Aucune licence.') . '</p>';
}
echo '<div class="pf-row pf-actions">';
echo '<form method="post" class="pf-inline">' . pf_csrf_field() . '<input type="hidden" name="action" value="nouvelle_cle"><button class="pf-btn pf-btn--ghost pf-btn--sm" data-confirm="Émettre une nouvelle clé ? L\'ancienne sera révoquée."><i class="fas fa-rotate"></i> Nouvelle clé</button></form>';
if ($lic && !$lic['revoked']) {
    echo '<form method="post" class="pf-inline">' . pf_csrf_field() . '<input type="hidden" name="action" value="revoquer"><button class="pf-btn pf-btn--danger pf-btn--sm" data-confirm="Révoquer la licence ? L\'instance continue de fonctionner mais n\'a plus ni mises à jour ni support."><i class="fas fa-ban"></i> Révoquer</button></form>';
}
echo '</div></div></section>';

// Instance(s)
echo '<section class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-server"></i> Instance</h2></div><div class="pf-card__body">';
if (!$parkAll['ok']) { echo '<div class="pf-notice pf-notice--warn">' . e(Dist::explain($parkAll['code'], $parkAll['data'])) . '</div>'; }
elseif (!$insts) { echo '<p class="pf-muted">Pas encore installée (aucun rapport reçu). Une fois installée, l\'instance envoie son état chaque jour.</p>'; }
foreach ($insts as $i) {
    [$tl, $tt] = pf_instance_tone($i);
    echo '<dl class="pf-dl"><dt>Serveur</dt><dd>' . e($i['hostname'] ?? '?') . ' ' . pf_pill($tl, $tt) . '</dd>'
       . '<dt>Adresse</dt><dd>' . ($i['app_url'] ? '<a href="' . e($i['app_url']) . '" rel="noopener" target="_blank">' . e($i['app_url']) . '</a>' : '—') . '</dd>'
       . '<dt>Version</dt><dd>v' . e($i['app_version'] ?? '?') . ' · PHP ' . e($i['php_version'] ?? '?') . '</dd>'
       . '<dt>Comptes</dt><dd>' . (int) ($i['accounts_count'] ?? 0) . '</dd>'
       . '<dt>Disque</dt><dd>' . e($i['disk_pct'] ?? '?') . ' %</dd>'
       . '<dt>Dernière sauvegarde</dt><dd>' . (isset($i['backup_age_seconds']) ? 'il y a ' . intdiv((int) $i['backup_age_seconds'], 3600) . ' h' : '<span class="pf-muted">inconnue</span>') . '</dd>'
       . '<dt>Dernier rapport</dt><dd>' . e(pf_ago($i['last_seen'] ?? null)) . ' depuis ' . e($i['last_ip'] ?? '?') . '</dd></dl>';
}
echo '<form method="post" class="pf-row pf-actions">' . pf_csrf_field() . '<input type="hidden" name="action" value="ip"><input class="pf-input pf-input--sm" name="ip" placeholder="IP publique du serveur" required><button class="pf-btn pf-btn--ghost pf-btn--sm"><i class="fas fa-unlock"></i> Autoriser l\'IP</button></form>';
echo '</div></section>';

// Demandes escaladées
echo '<section class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-life-ring"></i> Demandes escaladées</h2></div><div class="pf-card__body pf-card__body--flush">';
if (!$tickets) { echo '<div class="pf-empty">Aucune demande.</div>'; }
else {
    echo '<table class="pf-table pf-table--compact"><tbody>';
    foreach ($tickets as $t) { [$tsl, $tst] = PF_TICKET_STATUS[$t['status']]; echo '<tr><td><a href="/support/' . (int) $t['id'] . '">' . e($t['subject']) . '</a></td><td>' . pf_pill($tsl, $tst) . '</td><td class="pf-muted">' . e(pf_ago($t['updated_at'])) . '</td></tr>'; }
    echo '</tbody></table>';
}
echo '</div></section>';

// Accès de l'instance à la plateforme
echo '<section class="pf-card"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-plug"></i> Liaison avec la plateforme</h2></div><div class="pf-card__body">';
echo '<p class="pf-muted">Une instance installée par la distribution s\'authentifie toute seule (jeton d\'installation). Pour une instance installée autrement, générez une clé d\'API.</p>';
if ($links) {
    echo '<table class="pf-table pf-table--compact"><tbody>';
    foreach ($links as $l) {
        echo '<tr><td>' . e($l['label'] ?: ($l['hostname'] ?: 'Instance')) . '<div class="pf-muted">' . ($l['api_key_hash'] ? 'clé d\'API' : 'jeton d\'installation') . ' · vue ' . e(pf_ago($l['last_seen'])) . '</div></td><td>'
           . ($l['revoked'] ? pf_pill('Révoquée', 'crit') : '<form method="post" class="pf-inline">' . pf_csrf_field() . '<input type="hidden" name="action" value="revoquer_lien"><input type="hidden" name="link" value="' . (int) $l['id'] . '"><button class="pf-btn pf-btn--ghost pf-btn--sm" data-confirm="Couper l\'accès de cette instance à la plateforme ?">Révoquer</button></form>') . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '<form method="post" class="pf-row pf-actions">' . pf_csrf_field() . '<input type="hidden" name="action" value="cle_api"><input class="pf-input pf-input--sm" name="label" placeholder="Nom de l\'instance (facultatif)"><button class="pf-btn pf-btn--ghost pf-btn--sm"><i class="fas fa-plus"></i> Générer une clé d\'API</button></form>';
echo '</div></section>';

// Informations
echo '<section class="pf-card pf-card--wide"><div class="pf-card__head"><h2 class="pf-card__title"><i class="fas fa-address-card"></i> Informations</h2></div><form method="post" class="pf-card__body pf-form">' . pf_csrf_field() . '<input type="hidden" name="action" value="infos"><div class="pf-form__grid">';
foreach (['contact_name' => 'Nom du contact', 'contact_email' => 'E-mail du contact', 'city' => 'Ville'] as $k => $l) {
    echo '<label class="pf-field"><span class="pf-field__label">' . e($l) . '</span><input class="pf-input" name="' . $k . '" value="' . e($etab[$k] ?? '') . '"></label>';
}
echo '</div><label class="pf-field"><span class="pf-field__label">Notes internes</span><textarea class="pf-input" name="notes" rows="3">' . e($etab['notes'] ?? '') . '</textarea></label>';
echo '<div class="pf-row pf-form__actions"><button class="pf-btn pf-btn--primary">Enregistrer</button><span class="pf-topbar__spacer"></span>';
echo '<span class="pf-muted">Autorisé par ' . e($etab['created_by']) . ' le ' . e(date('d/m/Y', strtotime($etab['created_at']))) . '</span></div></form>';
if ($etab['status'] !== 'archive') {
    echo '<form method="post" class="pf-card__body pf-row">' . pf_csrf_field() . '<input type="hidden" name="action" value="archiver"><button class="pf-btn pf-btn--ghost pf-btn--sm" data-confirm="Archiver cet établissement ? (la licence n\'est pas révoquée)"><i class="fas fa-box-archive"></i> Archiver</button></form>';
}
echo '</section></div>';

pf_footer();
