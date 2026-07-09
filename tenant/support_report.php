<?php
declare(strict_types=1);
/** Portail ÉTABLISSEMENT — rapport de fin d'intervention Support (vue Direction, transparence). */
require __DIR__ . '/_bootstrap.php';
tenantRequireAuth($establishment, $base, $slug);

use API\Support\SupportReportService;

if (!tenantCan('tenant.support.ticket.view')) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><p style="font-family:system-ui;padding:40px">Accès refusé.</p>';
    exit;
}

$report = (new SupportReportService(getPDO()))->build((int) ($_GET['session'] ?? 0));
if ($report === null) { http_response_code(404); echo 'Rapport introuvable.'; exit; }

// SÉCURITÉ : la Direction ne consulte QUE les interventions de SON établissement.
if ((int) $report['session']['establishment_id'] !== (int) $_SESSION['tenant']['establishment_id']) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><p style="font-family:system-ui;padding:40px">Ce rapport ne concerne pas votre établissement.</p>';
    exit;
}
$h = fn($s) => htmlspecialchars((string) $s);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rapport d'intervention Support</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; }
        header { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 14px 24px; } header a { color: #2563eb; text-decoration: none; }
        main { max-width: 900px; margin: 24px auto; padding: 0 16px; } h3 { margin-top: 22px; }
    </style>
</head>
<body>
    <header><a href="<?= $base ?>/tenant/support.php?e=<?= urlencode($slug) ?>">← Support</a></header>
    <main>
        <h1>Rapport d'intervention #<?= (int) $report['session']['id'] ?></h1>
        <p style="color:#64748b">Ce que le Support Fronote a fait dans votre établissement, en toute transparence.</p>
        <?php include __DIR__ . '/../templates/support_report_body.php'; ?>
    </main>
</body>
</html>
