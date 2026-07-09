<?php
declare(strict_types=1);
/**
 * Acceptation d'une invitation Directeur (page publique, jeton requis).
 * Le Directeur crée son tenant_account (jamais platform) et, selon le type
 * d'invitation, crée son établissement ou rejoint le(s) établissement(s) autorisé(s).
 */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

use API\Platform\DirectorInvitationService;
use API\Tenant\TenantOnboardingService;

$base = defined('BASE_URL') ? BASE_URL : '';
$token = (string) ($_GET['token'] ?? ($_POST['token'] ?? ''));
$invSvc = new DirectorInvitationService(getPDO());
$invitation = $token !== '' ? $invSvc->validate($token) : null;

$err = '';
$done = null;
if ($invitation !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) {
        $err = 'Session expirée, veuillez réessayer.';
    } else {
        $pw = (string) ($_POST['password'] ?? '');
        if (strlen($pw) < 12) {
            $err = 'Mot de passe trop court (12 caractères minimum).';
        } elseif ($pw !== (string) ($_POST['password_confirm'] ?? '')) {
            $err = 'Les mots de passe ne correspondent pas.';
        } else {
            try {
                $done = (new TenantOnboardingService(getPDO()))->acceptInvitation(
                    $invitation,
                    [
                        'first_name' => trim((string) ($_POST['first_name'] ?? '')),
                        'last_name'  => trim((string) ($_POST['last_name'] ?? '')),
                        'username'   => trim((string) ($_POST['username'] ?? '')),
                        'password'   => $pw,
                    ],
                    [
                        'name' => trim((string) ($_POST['establishment_name'] ?? '')),
                        'type' => (string) ($_POST['establishment_type'] ?? 'college'),
                        'city' => trim((string) ($_POST['establishment_city'] ?? '')),
                    ]
                );
            } catch (\Throwable $e) {
                $err = $e->getMessage();
            }
        }
    }
}

if ($done !== null && $done['slug'] !== '') {
    header("Location: {$base}/e/" . urlencode($done['slug']) . "/login?accepted=1");
    exit;
}
$h = fn($s) => htmlspecialchars((string) $s);
$isCreate = $invitation && ($invitation['invitation_type'] ?? '') === 'create_establishment';
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invitation Directeur — Fronote</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f1f5f9; color: #1e293b; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; }
        .card { background: #fff; padding: 32px; border-radius: 12px; width: 380px; box-shadow: 0 8px 30px rgba(0,0,0,.12); }
        h1 { font-size: 1.15rem; margin: 0 0 12px; } .sub { color: #64748b; font-size: .85rem; margin-bottom: 16px; }
        input, select { width: 100%; box-sizing: border-box; padding: 10px; margin: 5px 0; border-radius: 8px; border: 1px solid #cbd5e1; }
        button { width: 100%; padding: 10px; margin-top: 12px; border: 0; border-radius: 8px; background: #2563eb; color: #fff; font-weight: 600; cursor: pointer; }
        .err { background: #fee2e2; color: #b91c1c; padding: 8px; border-radius: 8px; font-size: .85rem; }
        fieldset { border: 1px solid #e2e8f0; border-radius: 8px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($invitation === null): ?>
            <h1>Invitation invalide ou expirée</h1>
            <p class="sub">Ce lien d'invitation n'est plus valable. Contactez l'équipe Fronote.</p>
        <?php else: ?>
            <h1>Bienvenue, créez votre accès Directeur</h1>
            <div class="sub">Invitation pour <?= $h($invitation['email']) ?> ·
                <?= $isCreate ? "création d'un établissement" : 'rattachement aux établissements autorisés' ?></div>
            <?php if ($err): ?><div class="err"><?= $h($err) ?></div><?php endif; ?>
            <form method="post">
                <?= csrfField() ?><input type="hidden" name="token" value="<?= $h($token) ?>">
                <div style="display:flex;gap:8px">
                    <input name="first_name" placeholder="Prénom" value="<?= $h($invitation['first_name'] ?? '') ?>" required>
                    <input name="last_name" placeholder="Nom" value="<?= $h($invitation['last_name'] ?? '') ?>" required>
                </div>
                <input name="username" placeholder="Identifiant de connexion (défaut : email)">
                <input name="password" type="password" placeholder="Mot de passe (12+ caractères)" required>
                <input name="password_confirm" type="password" placeholder="Confirmer le mot de passe" required>
                <?php if ($isCreate): ?>
                <fieldset>
                    <legend>Votre établissement</legend>
                    <input name="establishment_name" placeholder="Nom de l'établissement" required>
                    <select name="establishment_type">
                        <option value="college">Collège</option><option value="lycee">Lycée</option>
                        <option value="primaire">Primaire</option><option value="superieur">Supérieur</option>
                        <option value="polyvalent">Polyvalent</option>
                    </select>
                    <input name="establishment_city" placeholder="Ville">
                </fieldset>
                <?php endif; ?>
                <button type="submit">Créer mon accès</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
