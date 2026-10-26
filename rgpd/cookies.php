<?php
declare(strict_types=1);
/**
 * RGPD — Politique cookies et mesure d'audience (page publique).
 * Liste exhaustive des cookies déposés par Fronote et gestion du consentement.
 */
// Page AUTONOME et publique (liée depuis la page de connexion) : pas de gabarit applicatif,
// qui exige une session.
require_once __DIR__ . '/../API/bootstrap.php';

use API\Services\Privacy\ConsentService;

$inst = defined('INSTANCE_ID') ? INSTANCE_ID : '…';
$sess = getenv('SESSION_NAME') ?: ('fronote_' . $inst);
$c = ConsentService::current();
$cookies = [
    [$sess, 'Session de connexion (authentification, protection CSRF)', 'Fin de la session', 'Essentiel'],
    ['remember_' . $inst, 'Connexion mémorisée, uniquement si vous cochez « Se souvenir de moi »', '30 jours', 'Essentiel'],
    ['fronote_2fa_trust', 'Appareil de confiance après une double authentification réussie', '1 heure', 'Essentiel'],
    ['fn_' . $inst . '_*', 'Préférences d\'affichage signées (thème, état de l\'interface)', 'Jusqu\'à 1 an', 'Essentiel'],
    [ConsentService::COOKIE, 'Mémorisation de vos choix de cookies', ConsentService::MAX_AGE_DAYS . ' jours', 'Essentiel'],
];
$__base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Politique cookies — Fronote</title>
<link rel="stylesheet" href="<?= htmlspecialchars($__base) ?>/assets/lib/fontawesome/css/all.min.css">
<link rel="stylesheet" href="<?= htmlspecialchars($__base) ?>/assets/css/cookie-consent.css">
<style>
body{margin:0;font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;background:#f5f5f7;color:#1d1d1f}
.content-wrapper{max-width:860px;margin:0 auto;padding:32px 18px 60px}
.content-header h1{font-size:1.7rem;margin:0 0 18px;display:flex;gap:10px;align-items:center}
.content-header h1 i{color:#d97706}
.rgpd-info-banner{display:flex;gap:12px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:14px;padding:14px 16px;margin-bottom:18px}
.rgpd-info-banner p{margin:0}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;margin-bottom:16px;overflow:hidden}
.card-header{padding:14px 18px;border-bottom:1px solid #eef0f3}.card-header h2{margin:0;font-size:1.05rem}
.card-body{padding:14px 18px}.card-body p{margin:0 0 10px}
.table{width:100%;border-collapse:collapse;font-size:.9rem}.table th,.table td{text-align:left;padding:8px 6px;border-bottom:1px solid #eef0f3;vertical-align:top}
.btn{display:inline-block;border:0;background:#0f4c81;color:#fff;font:inherit;font-weight:600;padding:9px 16px;border-radius:10px;cursor:pointer}
a{color:#0f4c81}.back{display:inline-block;margin-bottom:14px}
</style></head><body>
<div class="content-wrapper">
    <a class="back" href="<?= htmlspecialchars($__base) ?>/">← Retour à Fronote</a>
    <div class="content-header">
        <h1><i class="fas fa-cookie-bite"></i> Politique cookies</h1>
    </div>

    <div class="rgpd-info-banner">
        <i class="fas fa-shield-alt"></i>
        <p>Fronote n'utilise <strong>aucun cookie publicitaire ni aucun service tiers</strong>. Les cookies essentiels
           sont indispensables au fonctionnement ; la mesure d'audience interne n'est activée qu'avec votre accord.</p>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>Votre choix actuel</h2></div>
        <div class="card-body">
            <p>
                <?php if ($c === null): ?>Vous n'avez pas encore fait de choix : seule l'utilisation essentielle est active.
                <?php else: ?>Mesure d'audience : <strong><?= $c['analytics'] ? 'acceptée' : 'refusée' ?></strong>, choix du <?= htmlspecialchars(date('d/m/Y', $c['at'])) ?>.
                <?php endif; ?>
            </p>
            <p><button type="button" class="btn" data-cc-open>Modifier mes choix</button></p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>Cookies essentiels</h2></div>
        <div class="card-body">
            <p>Exemptés de consentement (article 82 de la loi Informatique et Libertés) car strictement nécessaires au service que vous demandez.</p>
            <div style="overflow-x:auto">
            <table class="table">
                <thead><tr><th>Nom</th><th>Finalité</th><th>Durée</th></tr></thead>
                <tbody>
                <?php foreach ($cookies as [$n, $f, $d]): ?>
                    <tr><td><code><?= htmlspecialchars($n) ?></code></td><td><?= htmlspecialchars($f) ?></td><td><?= htmlspecialchars($d) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>Mesure d'audience interne (avec votre accord)</h2></div>
        <div class="card-body">
            <p>Si vous l'acceptez, Fronote enregistre, sur le serveur de l'établissement uniquement :</p>
            <ul>
                <li>la page consultée, sans paramètre ni identifiant (ex. « /modules/notes/notes.php ») ;</li>
                <li>votre profil (élève, parent, professeur…) — jamais votre nom ni votre identifiant ;</li>
                <li>le type d'appareil (ordinateur, tablette, téléphone), le jour, l'heure et la durée de la visite ;</li>
                <li>une empreinte technique qui <strong>change chaque jour</strong>, pour compter les visiteurs sans pouvoir suivre une personne dans le temps.</li>
            </ul>
            <p>Aucune adresse IP n'est conservée. Ces statistiques aident l'établissement à savoir quels services sont utiles et à quels moments.
               Elles sont conservées <strong>13 mois</strong> puis supprimées automatiquement. La preuve de votre choix (date, version de la politique,
               choix effectué) est conservée 25 mois.</p>
            <p>Vous pouvez retirer votre accord à tout moment : <a href="#" data-cc-open>gérer mes cookies</a>.</p>
        </div>
    </div>
</div>
<?php $_hdr_nonce = function_exists('csp_nonce') ? csp_nonce() : ''; include __DIR__ . '/../templates/cookie_consent.php'; ?>
</body></html>
