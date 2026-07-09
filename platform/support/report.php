<?php
declare(strict_types=1);
/** Portail PLATEFORME — rapport de fin d'intervention Support. */
require_once __DIR__ . '/../../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

use API\Support\SupportReportService;

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.support.ticket.view');

$accId = (int) ($_SESSION['platform']['account_id'] ?? 0);
$report = (new SupportReportService(getPDO()))->build((int) ($_GET['session'] ?? 0));
if ($report === null) { http_response_code(404); echo 'Rapport introuvable.'; exit; }

// Moindre privilège : seul l'agent ayant mené la session, l'assigné du ticket, ou un
// rôle de supervision (audit) peut lire le rapport — pas tout détenteur de ticket.view.
$involved  = (int) ($report['session']['platform_account_id'] ?? 0) === $accId;
$assigned  = (int) ($report['ticket']['assigned_platform_account_id'] ?? 0) === $accId;
$oversight = platformCan('platform.audit.view');
if (!($involved || $assigned || $oversight)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><p style="font-family:system-ui;padding:40px">Accès au rapport refusé.</p>';
    exit;
}
$h = fn($s) => htmlspecialchars((string) $s);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rapport d'intervention #<?= (int) $report['session']['id'] ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; }
        header { background: #1e293b; padding: 14px 24px; } header a { color: #93c5fd; text-decoration: none; }
        main { max-width: 900px; margin: 24px auto; padding: 0 16px; } h3 { margin-top: 22px; }
    </style>
</head>
<body>
    <header><a href="<?= $base ?>/platform/support/ticket.php?id=<?= (int) $report['session']['ticket_id'] ?>">← Ticket</a></header>
    <main>
        <h1>Rapport d'intervention #<?= (int) $report['session']['id'] ?></h1>
        <?php include __DIR__ . '/../../templates/support_report_body.php'; ?>
    </main>
</body>
</html>
