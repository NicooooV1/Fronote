<?php
declare(strict_types=1);
/** Portail PLATEFORME — invitations Directeur (créer / lister / révoquer). */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

use API\Platform\DirectorInvitationService;

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.director_invites.create');

$pdo  = getPDO();
$accId = (int) ($_SESSION['platform']['account_id'] ?? 0);
$svc  = new DirectorInvitationService($pdo);

$msg = ''; $err = ''; $link = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) { $err = 'Session expirée.'; }
    else {
        try {
            if (($_POST['action'] ?? '') === 'create') {
                $type = (string) $_POST['invitation_type'];
                $opts = ['first_name' => $_POST['first_name'] ?? null, 'last_name' => $_POST['last_name'] ?? null, 'ttl_hours' => 72];
                if ($type !== 'create_establishment') {
                    $opts['allowed_establishment_ids'] = array_map('intval', (array) ($_POST['estabs'] ?? []));
                }
                $res = $svc->create($accId, trim((string) $_POST['email']), $type, $opts);
                $link = $base . '/director/accept.php?token=' . $res['token'];
                $msg = 'Invitation créée. Transmettez ce lien au Directeur (affiché une seule fois).';
            } elseif (($_POST['action'] ?? '') === 'revoke' && platformCan('platform.director_invites.revoke')) {
                $svc->revoke((int) $_POST['invite_id']);
                $msg = 'Invitation révoquée.';
            }
        } catch (\Throwable $e) { $err = $e->getMessage(); }
    }
}

$svc->expireStale();
$pending = $svc->listPending();
$etabs   = $pdo->query("SELECT id, nom FROM etablissements WHERE status NOT IN ('deleted','purged') ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$h = fn($s) => htmlspecialchars((string) $s);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plateforme — Invitations Directeur</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; }
        header { background: #1e293b; padding: 14px 24px; } header a { color: #93c5fd; text-decoration: none; }
        main { max-width: 820px; margin: 24px auto; padding: 0 16px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 16px; margin: 14px 0; }
        input, select { width: 100%; box-sizing: border-box; padding: 8px; margin: 4px 0; border: 1px solid #334155; border-radius: 6px; background: #0f172a; color: #e2e8f0; }
        button { padding: 8px 14px; border: 0; border-radius: 6px; background: #3b82f6; color: #fff; font-weight: 600; cursor: pointer; }
        button.danger { background: #dc2626; }
        table { width: 100%; border-collapse: collapse; } th, td { text-align: left; padding: 8px; border-bottom: 1px solid #334155; font-size: .9rem; }
        .alert { padding: 10px; border-radius: 8px; } .ok-a { background: #052e16; color: #86efac; } .err-a { background: #450a0a; color: #fca5a5; }
        .link { background: #0f172a; border: 1px dashed #3b82f6; padding: 10px; border-radius: 8px; word-break: break-all; }
    </style>
</head>
<body>
    <header><a href="<?= $base ?>/platform/dashboard.php">← Tableau de bord</a></header>
    <main>
        <h1>Invitations Directeur</h1>
        <?php if ($msg): ?><div class="alert ok-a"><?= $h($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert err-a"><?= $h($err) ?></div><?php endif; ?>
        <?php if ($link): ?><div class="card"><strong>Lien d'invitation :</strong><div class="link"><?= $h($link) ?></div></div><?php endif; ?>

        <div class="card">
            <h2>Nouvelle invitation</h2>
            <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="create">
                <input name="email" type="email" placeholder="Email du Directeur" required>
                <div style="display:flex;gap:8px"><input name="first_name" placeholder="Prénom"><input name="last_name" placeholder="Nom"></div>
                <select name="invitation_type">
                    <option value="create_establishment">Créer un établissement</option>
                    <option value="join_establishment">Rejoindre un établissement</option>
                    <option value="manage_multiple_establishments">Gérer plusieurs établissements</option>
                </select>
                <fieldset style="border:1px solid #334155;border-radius:8px">
                    <legend>Établissements (pour rejoindre / multi)</legend>
                    <?php foreach ($etabs as $e): ?>
                        <label style="display:block"><input type="checkbox" name="estabs[]" value="<?= (int) $e['id'] ?>" style="width:auto"> <?= $h($e['nom']) ?> (#<?= (int) $e['id'] ?>)</label>
                    <?php endforeach; ?>
                </fieldset>
                <div style="margin-top:8px"><button type="submit">Créer l'invitation</button></div>
            </form>
        </div>

        <div class="card">
            <h2>Invitations en attente</h2>
            <table>
                <thead><tr><th>Email</th><th>Type</th><th>Expire</th><th></th></tr></thead>
                <tbody>
                <?php if (!$pending): ?><tr><td colspan="4"><em>Aucune.</em></td></tr><?php endif; ?>
                <?php foreach ($pending as $i): ?>
                    <tr>
                        <td><?= $h($i['email']) ?></td><td><?= $h($i['invitation_type']) ?></td><td><?= $h($i['expires_at']) ?></td>
                        <td><?php if (platformCan('platform.director_invites.revoke')): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Révoquer ?')"><?= csrfField() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="invite_id" value="<?= (int) $i['id'] ?>"><button class="danger" type="submit">Révoquer</button></form>
                        <?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>
