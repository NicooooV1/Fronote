<?php
declare(strict_types=1);
/**
 * Entrée de la DÉMONSTRATION publique : /demo/?role=prof → connecté en un clic.
 * Sans rôle : page de choix. 404 sur toute instance qui n'est pas en DEMO_MODE.
 */
require_once __DIR__ . '/../API/core.php';

use API\Core\DemoMode;

if (!DemoMode::active()) {
    http_response_code(404);
    exit('Not Found');
}

$role = (string) ($_GET['role'] ?? '');
if (isset(DemoMode::ROLES[$role])) {
    [$username] = DemoMode::ROLES[$role];
    $auth = app('auth');
    // Repartir d'une session propre (changement de rôle)
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $user = $auth->attemptAndGetUser(['login' => $username, 'password' => DemoMode::password()]);
    if (is_array($user) && isset($user[0]) && is_array($user[0])) { $user = $user[0]; }
    if (is_array($user) && !empty($user['id'])) {
        $auth->loginUser($user);
        try { app('audit')->logAuth('login', $user['type'] . ':' . $user['id'], true, ['demo' => $role]); } catch (\Throwable $e) {}
        redirect('accueil/accueil.php');
    }
    http_response_code(503);
    $error = "La démonstration est en cours de réinitialisation. Réessayez dans quelques secondes.";
}

$cards = '';
foreach (DemoMode::ROLES as $slug => [, $lib, $desc]) {
    $cards .= '<a class="c" href="?role=' . $slug . '"><b>' . htmlspecialchars($lib) . '</b><span>' . htmlspecialchars($desc) . '</span></a>';
}
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Démonstration Fronote — choisir un rôle</title>
<style>
body{margin:0;font:16px/1.55 system-ui,-apple-system,sans-serif;background:#f6f2e9;color:#16243f;padding:40px 18px}
main{max-width:860px;margin:0 auto}
.k{font:500 12px ui-monospace,monospace;letter-spacing:.15em;text-transform:uppercase;color:#b9772a}
h1{font:600 2rem/1.15 Georgia,serif;margin:.4rem 0 .6rem}
p{color:#586173;max-width:60ch}
.g{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin-top:26px}
.c{display:flex;flex-direction:column;gap:6px;background:#fffdf8;border:1px solid #d5ccb6;border-radius:13px;padding:18px 18px 20px;color:inherit;text-decoration:none;transition:transform .12s,box-shadow .12s}
.c:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(22,36,63,.1)}
.c b{font:600 1.15rem Georgia,serif}.c span{font-size:.92rem;color:#586173}
.e{background:#fbe9e4;border:1px solid #e8b9ab;color:#7a2e1d;padding:10px 14px;border-radius:9px;margin-top:16px}
a.back{color:#b9772a}
</style></head><body><main>
<span class="k">Démonstration publique</span>
<h1>Essayez Fronote dans la peau de…</h1>
<p>Un collège fictif complet — 8 classes, 190 élèves, emploi du temps, notes, absences, messagerie. Cliquez sur un rôle : vous êtes connecté immédiatement. Tout est réinitialisé toutes les <?= DemoMode::resetMinutes() ?> minutes.</p>
<?php if (!empty($error)): ?><div class="e"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="g"><?= $cards ?></div>
<p style="margin-top:28px"><a class="back" href="<?= htmlspecialchars(DemoMode::siteUrl()) ?>">← Retour au site Fronote</a></p>
</main></body></html>
