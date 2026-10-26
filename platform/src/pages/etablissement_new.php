<?php
declare(strict_types=1);
/**
 * Autoriser un nouvel établissement : la plateforme émet sa licence sur le serveur de
 * distribution (CT122) et affiche la clé UNE SEULE FOIS, avec la commande d'installation.
 */
require __DIR__ . '/_common.php';
$errors = []; $done = null;
$v = fn($k, $d = '') => (string) ($_POST[$k] ?? $d);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pf_check_csrf();
    $name = trim($v('name'));
    $uai = strtoupper(trim($v('uai')));
    $email = trim($v('contact_email'));
    $exp = trim($v('expires_at'));
    $ip = trim($v('ip'));
    $mods = array_values(array_filter(array_map(fn($m) => preg_replace('/[^a-z0-9_]/', '', strtolower(trim($m))), explode(',', $v('modules')))));
    if (mb_strlen($name) < 3) { $errors[] = 'Le nom de l\'établissement est obligatoire.'; }
    if ($uai !== '' && !preg_match('/^\d{7}[A-Z]$/', $uai)) { $errors[] = 'Le code UAI doit faire 7 chiffres et une lettre (ex. 0123456X).'; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Adresse e-mail du contact invalide.'; }
    if ($exp !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) { $errors[] = 'Date de fin de support invalide.'; }
    if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) { $errors[] = 'Adresse IP invalide.'; }
    if ($uai !== '') {
        $dup = pf_db()->prepare("SELECT id FROM establishments WHERE uai = ? AND status <> 'archive'");
        $dup->execute([$uai]);
        if ($dup->fetchColumn()) { $errors[] = 'Un établissement actif porte déjà ce code UAI.'; }
    }
    if (!$errors) {
        $max = max(1, min(10, (int) $v('max_activations', '1')));
        [$code, $res] = Dist::call('POST', '/admin/issue', [
            'tier' => 'base', 'modules' => $mods, 'max_activations' => $max,
            'expires_at' => $exp ?: null, 'note' => mb_substr($name . ($uai ? ' (' . $uai . ')' : ''), 0, 200),
        ]);
        if ($code !== 200 || empty($res['key'])) {
            $errors[] = Dist::explain($code, $res);
        } else {
            pf_db()->prepare("INSERT INTO establishments (name, uai, city, kind, contact_name, contact_email, status, license_id, license_prefix, modules_json, max_activations, expires_at, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, 'autorise', ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$name, $uai ?: null, trim($v('city')) ?: null, $v('kind', 'college'), trim($v('contact_name')) ?: null, $email ?: null,
                    (int) $res['id'], (string) $res['prefix'], json_encode($mods), $max, $exp ?: null, trim($v('notes')) ?: null, $op['user']]);
            $eid = (int) pf_db()->lastInsertId();
            pf_audit($op['user'], 'etablissement.autoriser', $name, ['id' => $eid, 'licence' => $res['prefix'], 'modules' => $mods]);
            $ipMsg = '';
            if ($ip !== '') {
                [$c2, $r2] = Dist::call('POST', '/admin/allow', ['ip' => $ip, 'note' => $name], 8, true);
                $ipMsg = $c2 === 200 ? 'IP ' . $ip . ' autorisée à télécharger.' : 'IP non autorisée : ' . Dist::explain($c2, $r2);
                if ($c2 === 200) { pf_audit($op['user'], 'distribution.ip_autoriser', $ip, ['etablissement' => $eid]); }
            }
            Dist::forget();
            $done = ['id' => $eid, 'name' => $name, 'key' => (string) $res['key'], 'ip' => $ipMsg];
        }
    }
}

pf_header('etablissements', $done ? 'Établissement autorisé' : 'Autoriser un établissement', 'Établissements', pf_badges());
if ($done) {
    echo '<section class="pf-card pf-keycard"><div class="pf-card__body">';
    echo '<div class="pf-notice pf-notice--warn"><strong>Copiez cette clé maintenant :</strong> elle ne sera plus jamais affichée (seule son empreinte est conservée).</div>';
    echo '<p><strong>' . e($done['name']) . '</strong> est autorisé. Transmettez à l\'établissement :</p>';
    echo '<div class="pf-field"><span class="pf-field__label">Clé de licence</span><code class="pf-key">' . e($done['key']) . '</code></div>';
    echo '<div class="pf-field"><span class="pf-field__label">Installation (sur son serveur Debian, avec le fichier bootstrap-client.sh fourni)</span><code class="pf-key">sudo ./bootstrap-client.sh --key=' . e($done['key']) . '</code></div>';
    if ($done['ip'] !== '') { echo '<p class="pf-muted">' . e($done['ip']) . '</p>'; }
    else { echo '<p class="pf-muted">Pensez à autoriser l\'IP publique de son serveur (fiche de l\'établissement) : sans elle, le téléchargement est refusé.</p>'; }
    echo '<div class="pf-row"><a class="pf-btn pf-btn--primary" href="/etablissements/' . (int) $done['id'] . '">Ouvrir la fiche</a><a class="pf-btn pf-btn--ghost" href="/etablissements">Retour à la liste</a></div>';
    echo '</div></section>';
    pf_footer();
    exit;
}
foreach ($errors as $er) { echo '<div class="pf-notice pf-notice--crit">' . e($er) . '</div>'; }
$f = fn($name, $label, $attrs = '', $hint = '') => '<label class="pf-field"><span class="pf-field__label">' . e($label) . '</span><input class="pf-input" name="' . $name . '" value="' . e($v($name)) . '" ' . $attrs . '>' . ($hint ? '<span class="pf-field__hint">' . e($hint) . '</span>' : '') . '</label>';
echo '<form method="post" class="pf-card pf-form"><div class="pf-card__body">' . pf_csrf_field();
echo '<h2 class="pf-form__title">Établissement</h2><div class="pf-form__grid">';
echo $f('name', 'Nom *', 'required maxlength="190" placeholder="Collège Victor-Hugo"');
echo $f('uai', 'Code UAI', 'maxlength="8" placeholder="0123456X"', 'Identifiant national de l\'établissement (facultatif).');
echo $f('city', 'Ville', 'maxlength="120"');
echo '<label class="pf-field"><span class="pf-field__label">Type</span><select class="pf-input" name="kind">';
foreach (['ecole' => 'École', 'college' => 'Collège', 'lycee' => 'Lycée', 'lycee_pro' => 'Lycée professionnel', 'autre' => 'Autre'] as $k => $l) { echo '<option value="' . $k . '"' . ($v('kind', 'college') === $k ? ' selected' : '') . '>' . $l . '</option>'; }
echo '</select></label></div>';
echo '<h2 class="pf-form__title">Contact</h2><div class="pf-form__grid">';
echo $f('contact_name', 'Nom du contact', 'maxlength="160"');
echo $f('contact_email', 'E-mail du contact', 'type="email" maxlength="190"');
echo '</div><h2 class="pf-form__title">Licence</h2><div class="pf-form__grid">';
echo $f('modules', 'Modules premium', 'placeholder="stages, internat"', 'Séparés par des virgules. Les modules de base sont toujours inclus.');
echo $f('max_activations', 'Installations permises', 'type="number" min="1" max="10" value="1"', 'En général 1 (un serveur par établissement).');
echo $f('expires_at', 'Fin du support', 'type="date"', 'Vide = sans limite.');
echo $f('ip', 'IP publique du serveur', 'placeholder="203.0.113.10"', 'Facultatif : l\'autorise tout de suite à télécharger.');
echo '</div><label class="pf-field"><span class="pf-field__label">Notes internes</span><textarea class="pf-input" name="notes" rows="3">' . e($v('notes')) . '</textarea></label>';
echo '<div class="pf-row pf-form__actions"><button class="pf-btn pf-btn--primary"><i class="fas fa-key"></i> Autoriser et émettre la licence</button><a class="pf-btn pf-btn--ghost" href="/etablissements">Annuler</a></div>';
echo '</div></form>';
pf_footer();
