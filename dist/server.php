<?php
declare(strict_types=1);

/**
 * Serveur de distribution Fronote (point d'entrée HTTP unique).
 *
 * Endpoints (action via ?action= ou chemin) :
 *   POST /redeem            {key}                 → valide+consomme la clé, renvoie install_token + infos socle
 *   GET  /download          ?token=&artifact=     → stream l'archive (base | module-<x>) autorisée
 *   POST /module            {token, module}       → autorise un module premium (renvoie sa signature)
 *   GET  /health                                  → statut
 *   GET  /pubkey                                  → clé publique de signature (hex)
 *   POST /admin/allow       {ip, note?} (X-Admin-Token) → whiteliste une IP (bot Discord / CLI)
 *   POST /admin/disallow    {ip}        (X-Admin-Token) → retire une IP de la whitelist
 *   GET  /admin/list        ?ip=        (X-Admin-Token) → whitelist complète, ou statut d'une IP
 *   GET  /admin/refused     ?since_id=&limit= (X-Admin-Token) → tentatives refusées (delta)
 *
 * Sécurité : allowlist d'IP (une IP non listée est refusée ET journalisée pour le bot),
 * jeton d'admin en temps constant, jamais de secret en réponse, archives signées
 * Ed25519 (vérifiées côté client).
 */

require __DIR__ . '/lib/loader.php';

// Format de réponse : 'json' (défaut) ou 'sh' (KEY=VALUE, pour le bootstrapper bash).
$GLOBALS['RESPONSE_FORMAT'] = (($_GET['format'] ?? $_POST['format'] ?? '') === 'sh') ? 'sh' : 'json';

// ── utilitaires réponse ──────────────────────────────────────────────────────
/** Aplatit un tableau imbriqué en KEY_SUBKEY (listes → CSV) pour la sortie shell. */
function flatten_sh(array $p, string $prefix = ''): array
{
    $out = [];
    foreach ($p as $k => $v) {
        $key = strtoupper($prefix . preg_replace('/[^A-Za-z0-9]+/', '_', (string) $k));
        if (is_array($v)) {
            $isList = array_keys($v) === range(0, count($v) - 1);
            if ($isList) {
                $out[$key] = implode(',', array_map('strval', $v));
            } else {
                $out += flatten_sh($v, $key . '_');
            }
        } else {
            $out[$key] = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
        }
    }
    return $out;
}

function json_out(int $code, array $payload): never
{
    http_response_code($code);
    header('X-Content-Type-Options: nosniff');
    if (($GLOBALS['RESPONSE_FORMAT'] ?? 'json') === 'sh') {
        header('Content-Type: text/plain; charset=utf-8');
        foreach (flatten_sh($payload) as $k => $v) {
            // Valeurs sûres (hex/csv/url/enum) ; on neutralise tout retour à la ligne par prudence.
            echo $k . '=' . str_replace(["\n", "\r"], '', $v) . "\n";
        }
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function client_ip(array $cfg): string
{
    // IP réelle du client, utilisée comme SEUL contrôle d'accès (allowlist). Elle DOIT
    // donc être non-spoofable.
    //
    // ⚠️ FAILLE CORRIGÉE (revue adversariale 2026-08-12) : l'ancienne version renvoyait
    // le segment LE PLUS À GAUCHE de X-Forwarded-For dès que trust_forwarded=true. Or ce
    // segment est ENTIÈREMENT fourni par le client : Caddy AJOUTE l'IP du pair à droite
    // sans supprimer un XFF entrant. N'importe qui atteignant dist.nicov1.fr pouvait donc
    // envoyer « X-Forwarded-For: <une IP whitelistée> » et contourner toute l'allowlist.
    //
    // Défense : on n'honore XFF QUE si la connexion vient d'un proxy de confiance déclaré
    // (Caddy), et on prend alors le segment LE PLUS À DROITE (celui que CE proxy a ajouté,
    // hors de portée du client), validé comme IP. Sinon, l'IP TCP réelle. En complément,
    // le vhost Caddy réécrit XFF = IP du pair (header_up), rendant le contournement
    // impossible même en amont.
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $trusted = array_map('strval', (array) ($cfg['trusted_proxies'] ?? []));
    if (!empty($cfg['trust_forwarded'])
        && in_array($remote, $trusted, true)
        && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
        $cand = end($parts);   // segment ajouté par le proxy de confiance = non spoofable
        if ($cand !== false && filter_var($cand, FILTER_VALIDATE_IP)) {
            return $cand;
        }
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

function require_admin(array $cfg): void
{
    $sent = (string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ($_POST['admin_token'] ?? ''));
    if ($sent === '' || !hash_equals((string) $cfg['admin_token'], $sent)) {
        json_out(401, ['status' => 'unauthorized']);
    }
}

// ── bootstrap ────────────────────────────────────────────────────────────────
try {
    $cfg = dist_config();
    $store = dist_store($cfg);
} catch (\Throwable $e) {
    json_out(500, ['status' => 'server_error', 'message' => 'Configuration serveur invalide.']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');
if ($action === '') {
    // Dérive l'action du chemin (…/dist/server.php/redeem ou réécriture …/redeem).
    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    $action = (string) (array_slice(explode('/', $path), -1)[0] ?? '');
    if (str_contains($path, 'admin/')) {
        $action = 'admin_' . $action;
    }
}
$ip = client_ip($cfg);
$payloadDir = rtrim((string) $cfg['data_dir'], '/') . '/payloads';

// ── routes ───────────────────────────────────────────────────────────────────
switch ($action) {
    case 'health':
        json_out(200, ['status' => 'ok', 'service' => 'fronote-dist']);

    // no break (json_out exits)
    case 'pubkey':
        try {
            json_out(200, ['public_key' => dist_public_key_hex($cfg)]);
        } catch (\Throwable $e) {
            json_out(500, ['status' => 'server_error']);
        }

    case 'manifest': {
        // Métadonnées de la release courante (version, build, sha256 des artefacts). Public :
        // aucun secret, sert au canal de mise à jour client (update.sh compare sa version
        // locale à celle-ci). Le contenu reste authentifié par la signature Ed25519 au DL.
        $mf = $payloadDir . '/manifest.json';
        if (!is_file($mf)) { json_out(404, ['status' => 'no_manifest']); }
        $data = json_decode((string) @file_get_contents($mf), true) ?: [];
        json_out(200, [
            'status'          => 'ok',
            'version'         => (string) ($data['version'] ?? '0.0.0'),
            'build'           => (string) ($data['build'] ?? ''),
            'channel'         => (string) ($data['channel'] ?? 'stable'),
            'built_at'        => (string) ($data['built_at'] ?? ''),
            'base_sha256'     => (string) ($data['artifacts']['base']['sha256'] ?? ''),
            'base_sig'        => (string) ($data['artifacts']['base']['sig'] ?? ''),
            'simple_modules'  => $data['simple_modules'] ?? [],
            'premium_modules' => $data['premium_modules'] ?? [],
        ]);
    }

    case 'proposal': {
        // Formulaire PUBLIC du site vitrine (« faire une proposition »). Enregistre une
        // demande, relue ensuite par le bot Discord (admin_proposals) et postée dans un
        // salon. Endpoint public → validé, plafonné, anti-spam. AUCUN secret, aucun fichier.
        if ($method !== 'POST') { json_out(405, ['status' => 'method_not_allowed']); }

        // Honeypot : un champ caché que seuls les robots remplissent → on répond OK sans rien stocker.
        if (trim((string) ($_POST['website'] ?? '')) !== '') { json_out(200, ['status' => 'ok']); }

        // Nettoyage : retire les caractères de contrôle, borne les longueurs.
        $clean = static function (string $key, int $max): string {
            $v = (string) ($_POST[$key] ?? '');
            $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
            $v = trim($v);
            return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
        };
        $fields = [
            'etablissement' => $clean('etablissement', 150),
            'type'          => $clean('type', 40),
            'effectif'      => $clean('effectif', 40),
            'contact'       => $clean('contact', 120),
            'email'         => $clean('email', 160),
            'telephone'     => $clean('telephone', 40),
            'modules'       => $clean('modules', 400),
            'message'       => $clean('message', 4000),
        ];

        // Validation minimale : un moyen de recontact (email valide OU téléphone) + un contenu.
        $emailOk = $fields['email'] !== '' && filter_var($fields['email'], FILTER_VALIDATE_EMAIL);
        $telOk   = $fields['telephone'] !== '' && preg_match('/[0-9]{6,}/', preg_replace('/\D/', '', $fields['telephone']) ?? '');
        if (!$emailOk && !$telOk) { json_out(422, ['status' => 'need_contact', 'message' => 'Un email valide ou un téléphone est requis pour vous recontacter.']); }
        if ($fields['etablissement'] === '' && $fields['contact'] === '') { json_out(422, ['status' => 'need_who', 'message' => 'Indiquez au moins le nom de l\'établissement ou un contact.']); }
        if (strlen($fields['message']) < 5 && $fields['modules'] === '') { json_out(422, ['status' => 'need_message', 'message' => 'Dites-nous en quelques mots votre besoin.']); }
        if (!$emailOk) { $fields['email'] = ''; }   // ne stocke pas un email invalide

        // Anti-spam : au plus 4 propositions par IP et par heure.
        try {
            if ($store->countProposalsSince($ip, 3600) >= 4) {
                json_out(429, ['status' => 'rate_limited', 'message' => 'Trop de demandes récentes. Réessayez plus tard ou écrivez-nous directement.']);
            }
            $store->recordProposal($fields, $ip);
        } catch (\Throwable $e) {
            json_out(500, ['status' => 'server_error']);
        }
        json_out(200, ['status' => 'ok', 'message' => 'Merci ! Votre demande a bien été transmise. Nous revenons vers vous rapidement.']);
    }

    case 'redeem': {
        if ($method !== 'POST') { json_out(405, ['status' => 'method_not_allowed']); }
        $key = trim((string) ($_POST['key'] ?? ''));
        $res = $store->redeem($key, $ip);
        switch ($res['status']) {
            case 'ok':
                $baseSig = @file_get_contents($payloadDir . '/base.tar.gz.sig');
                $baseSha = is_file($payloadDir . '/base.tar.gz') ? hash_file('sha256', $payloadDir . '/base.tar.gz') : '';
                json_out(200, [
                    'status'         => 'ok',
                    'install_token'  => $res['install_token'],
                    'modules'        => $res['modules'],
                    'premium_modules' => $res['premium_modules'],
                    'base' => [
                        'artifact' => 'base',
                        'sig'      => $baseSig !== false ? trim((string) $baseSig) : '',
                        'sha256'   => $baseSha,
                        'url'      => rtrim((string) $cfg['dist_base_url'], '/') . '/download?artifact=base&token=' . $res['install_token'],
                    ],
                ]);
            case 'not_whitelisted':
                json_out(403, ['status' => 'not_whitelisted', 'message' => (string) ($cfg['not_whitelisted_message'] ?? 'Accès refusé : votre IP n\'est pas autorisée. Contactez le support.')]);
            case 'exhausted':
                json_out(409, ['status' => 'exhausted', 'message' => 'Cette clé a déjà été utilisée.']);
            case 'revoked':
                json_out(410, ['status' => 'revoked', 'message' => 'Licence révoquée.']);
            case 'expired':
                json_out(410, ['status' => 'expired', 'message' => 'Licence expirée.']);
            case 'invalid':
            default:
                json_out(401, ['status' => 'invalid', 'message' => 'Clé invalide.']);
        }
    }

    case 'module': {
        if ($method !== 'POST') { json_out(405, ['status' => 'method_not_allowed']); }
        $token = trim((string) ($_POST['token'] ?? ''));
        $module = trim((string) ($_POST['module'] ?? ''));
        $res = $store->authorizeModule($token, $module, $ip);
        if ($res['status'] !== 'ok') {
            // 'not_whitelisted' est le PREMIER test d'authorizeModule : il doit répondre
            // 403 (cohérent avec /redeem et /download), pas retomber sur le 400 générique.
            $codes = ['not_whitelisted' => 403, 'invalid_token' => 401, 'invalid_module' => 400,
                      'not_entitled' => 402, 'revoked' => 410, 'expired' => 410];
            json_out($codes[$res['status']] ?? 400, ['status' => $res['status']]);
        }
        $file = $payloadDir . '/module-' . $module . '.tar.gz';
        if (!is_file($file)) { json_out(404, ['status' => 'not_built', 'message' => 'Module non disponible au téléchargement.']); }
        json_out(200, [
            'status'  => 'ok',
            'module'  => $module,
            'sig'     => trim((string) @file_get_contents($file . '.sig')),
            'sha256'  => hash_file('sha256', $file),
            'url'     => rtrim((string) $cfg['dist_base_url'], '/') . '/download?artifact=module-' . $module . '&token=' . $token,
        ]);
    }

    case 'download': {
        if ($method !== 'GET') { json_out(405, ['status' => 'method_not_allowed']); }
        if (!$store->isIpAllowed($ip)) {
            $store->recordRefused($ip, 'download');
            json_out(403, ['status' => 'not_whitelisted']);
        }
        $token = trim((string) ($_GET['token'] ?? ''));
        $artifact = (string) ($_GET['artifact'] ?? '');
        if (!preg_match('/^(base|module-[a-z][a-z0-9_]*)$/', $artifact)) {
            json_out(400, ['status' => 'bad_artifact']);
        }
        // Autorisation : 'base' → jeton d'installation valide ; 'module-x' → droit sur x.
        if ($artifact === 'base') {
            // Un jeton valide suffit pour re-télécharger le socle. On propage le VRAI
            // statut (revoked/expired/…) au lieu de tout aplatir en « invalid_token » :
            // un client dont la licence vient d'être révoquée mérite un message exact.
            $ok = $store->authorizeModule($token, ($cfg['simple_modules'][0] ?? 'notes'), $ip);
            if (($ok['status'] ?? '') !== 'ok') {
                $codes = ['not_whitelisted' => 403, 'invalid_token' => 401, 'revoked' => 410, 'expired' => 410];
                json_out($codes[$ok['status'] ?? ''] ?? 401, ['status' => $ok['status'] ?? 'invalid_token']);
            }
        } else {
            $mod = substr($artifact, strlen('module-'));
            $ok = $store->authorizeModule($token, $mod, $ip);
            if (($ok['status'] ?? '') !== 'ok') { json_out(402, ['status' => $ok['status'] ?? 'denied']); }
        }
        $file = $payloadDir . '/' . $artifact . '.tar.gz';
        if (!is_file($file)) { json_out(404, ['status' => 'not_built']); }
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    case 'phone-home': {
        // Rapport périodique d'une instance installée. Authentifié par le JETON
        // D'INSTALLATION (pas par l'IP — elle change : NAT, IP résidentielles dynamiques ;
        // l'IP enregistrée à l'activation est même souvent celle de la box, pas de l'école).
        // Volontairement NON gaté par isIpAllowed() : au contraire, un phone-home valide
        // RAFRAÎCHIT l'allowlist de l'IP courante (rend le modèle allowlist vivable pour le
        // parc, sans rouvrir les installations neuves). Données AGRÉGÉES uniquement —
        // jamais un nom d'élève ni d'utilisateur (minimisation RGPD).
        if ($method !== 'POST') { json_out(405, ['status' => 'method_not_allowed']); }
        $token = trim((string) ($_POST['token'] ?? ''));
        $act = $store->activationByToken($token);
        if ($act === null) { json_out(401, ['status' => 'invalid_token']); }

        $intOrNull = static fn($v) => ($v === null || $v === '') ? null : (int) $v;
        $checks = (string) ($_POST['checks_json'] ?? '');
        if ($checks !== '' && (strlen($checks) > 4000 || json_decode($checks) === null)) { $checks = ''; }
        $fields = [
            'hostname'       => substr(trim((string) ($_POST['hostname'] ?? '')), 0, 120),
            'os'             => substr(trim((string) ($_POST['os'] ?? '')), 0, 120),
            'php_version'    => substr(trim((string) ($_POST['php_version'] ?? '')), 0, 20),
            'app_version'    => substr(trim((string) ($_POST['app_version'] ?? '')), 0, 40),
            'app_build'      => substr(trim((string) ($_POST['app_build'] ?? '')), 0, 80),
            'healthy'        => $intOrNull($_POST['healthy'] ?? null),
            'checks_json'    => $checks !== '' ? $checks : null,
            'tenants_count'  => $intOrNull($_POST['tenants_count'] ?? null),
            'accounts_count' => $intOrNull($_POST['accounts_count'] ?? null),
            'disk_pct'       => $intOrNull($_POST['disk_pct'] ?? null),
            'last_backup_at' => substr(trim((string) ($_POST['last_backup_at'] ?? '')), 0, 40) ?: null,
            'app_url'        => substr(trim((string) ($_POST['app_url'] ?? '')), 0, 200) ?: null,
        ];
        try {
            $store->upsertInstance((int) $act['activation_id'], (int) $act['license_id'], $fields, $ip);
            // Auto-rafraîchissement de l'allowlist de l'IP courante (best-effort).
            if (filter_var($ip, FILTER_VALIDATE_IP) && !$store->isIpAllowed($ip)) {
                $store->allowIp($ip, 'auto phone-home (instance #' . (int) $act['activation_id'] . ')');
            }
        } catch (\Throwable $e) {
            json_out(500, ['status' => 'server_error']);
        }

        // Enforcement DOUX : on renvoie le statut de licence + la version courante publiée.
        // Le client affiche une bannière si support expiré/révoqué et sait si une MAJ existe.
        $licStatus = 'ok';
        if ((int) $act['revoked'] === 1) { $licStatus = 'revoked'; }
        elseif (!empty($act['expires_at']) && strtotime((string) $act['expires_at']) < time()) { $licStatus = 'expired'; }
        $mf = $payloadDir . '/manifest.json';
        $latest = is_file($mf) ? (json_decode((string) @file_get_contents($mf), true) ?: []) : [];
        json_out(200, [
            'status'         => 'ok',
            'license_status' => $licStatus,
            'expires_at'     => $act['expires_at'] ?? null,
            'latest_version' => (string) ($latest['version'] ?? ''),
            'latest_build'   => (string) ($latest['build'] ?? ''),
        ]);
    }

    // ── API d'admin (appelée par le bot Discord du distributeur) ─────────────
    case 'admin_allow': {   // whitelister une IP
        if ($method !== 'POST') { json_out(405, ['status' => 'method_not_allowed']); }
        require_admin($cfg);
        $target = trim((string) ($_POST['ip'] ?? ''));
        if ($target === '' || !filter_var($target, FILTER_VALIDATE_IP)) { json_out(400, ['status' => 'bad_ip']); }
        $store->allowIp($target, (string) ($_POST['note'] ?? ''));
        json_out(200, ['status' => 'ok', 'allowed' => $target]);
    }

    case 'admin_disallow': {   // retirer une IP de la whitelist
        if ($method !== 'POST') { json_out(405, ['status' => 'method_not_allowed']); }
        require_admin($cfg);
        $target = trim((string) ($_POST['ip'] ?? ''));
        if ($target === '' || !filter_var($target, FILTER_VALIDATE_IP)) { json_out(400, ['status' => 'bad_ip']); }
        json_out(200, ['status' => 'ok', 'removed' => $store->disallowIp($target)]);
    }

    case 'admin_list': {   // lister la whitelist, ou le statut d'une IP
        require_admin($cfg);
        $target = trim((string) ($_GET['ip'] ?? ''));
        if ($target !== '') { json_out(200, ['status' => 'ok', 'ip' => $store->ipStatus($target)]); }
        json_out(200, ['status' => 'ok', 'allowlist' => $store->listAllowed()]);
    }

    case 'admin_refused': {   // tentatives refusées depuis un curseur (pour le bot Discord)
        require_admin($cfg);
        $since = (int) ($_GET['since_id'] ?? 0);
        $limit = (int) ($_GET['limit'] ?? 200);
        json_out(200, ['status' => 'ok', 'refused' => $store->listRefused($since, $limit)]);
    }

    case 'admin_licenses': {   // licences émises (pour /dist licences) — JAMAIS le key_hash
        require_admin($cfg);
        json_out(200, ['status' => 'ok', 'licenses' => $store->listLicenses((int) ($_GET['limit'] ?? 200))]);
    }

    case 'admin_park': {   // parc des instances installées (pour /dist parc + dashboard)
        require_admin($cfg);
        json_out(200, ['status' => 'ok', 'instances' => $store->listInstances((int) ($_GET['limit'] ?? 500))]);
    }

    case 'admin_proposals': {   // propositions du site vitrine (pour le bot Discord)
        require_admin($cfg);
        $since = (int) ($_GET['since_id'] ?? 0);
        $limit = (int) ($_GET['limit'] ?? 100);
        json_out(200, ['status' => 'ok', 'proposals' => $store->listProposals($since, $limit)]);
    }

    default:
        json_out(404, ['status' => 'unknown_action']);
}
