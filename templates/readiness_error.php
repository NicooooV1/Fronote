<?php
declare(strict_types=1);
/**
 * Page 503 affichée quand ProductionReadinessChecker refuse le démarrage en production.
 *
 * Volontairement autonome (aucune dépendance au container, qui n'est pas encore
 * initialisé) et GÉNÉRIQUE : ne révèle JAMAIS quel secret/réglage est en cause à un
 * visiteur anonyme. Le détail est dans les journaux serveur (motif « [readiness] »).
 */
if (!headers_sent()) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 3600');
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Service indisponible — configuration incomplète</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: #0f172a; color: #e2e8f0; }
        .card { max-width: 34rem; margin: 1.5rem; padding: 2rem 2.25rem; background: #1e293b;
                border: 1px solid #334155; border-radius: 14px; box-shadow: 0 10px 40px rgba(0,0,0,.4); }
        h1 { margin: 0 0 .5rem; font-size: 1.4rem; }
        .code { font-size: .8rem; letter-spacing: .08em; text-transform: uppercase; color: #94a3b8; }
        p { line-height: 1.6; margin: .75rem 0 0; }
        .hint { margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid #334155; font-size: .9rem; color: #94a3b8; }
        code { background: #0f172a; padding: .1rem .4rem; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="code">Erreur 503</div>
        <h1>Service momentanément indisponible</h1>
        <p>L'application ne peut pas démarrer car sa configuration de production est
           incomplète ou ne respecte pas les exigences de sécurité.</p>
        <p class="hint">Administrateur : le détail des problèmes est consigné dans les
           journaux du serveur (rechercher le motif <code>[readiness]</code>). Corrigez le
           fichier <code>.env</code> puis rechargez la page.</p>
    </div>
</body>
</html>
