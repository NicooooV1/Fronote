<?php
/** Portail PLATEFORME — démarre une impersonation Support (puis redirige vers l'app établissement). */
require_once __DIR__ . '/../../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

use API\Support\SupportImpersonation;

$base = defined('BASE_URL') ? BASE_URL : '';
platformAuthorize('platform.support.ticket.view');

$accId    = (int) ($_SESSION['platform']['account_id'] ?? 0);
$ticketId = (int) ($_POST['ticket_id'] ?? 0);
$backTicket = $ticketId > 0 ? "{$base}/platform/support/ticket.php?id={$ticketId}" : "{$base}/platform/support/tickets.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken()) {
    header("Location: {$backTicket}");
    exit;
}

$estabId = (int) ($_POST['establishment_id'] ?? 0);
// Le ticket_id du POST ne sert qu'à la redirection : vérifier qu'il appartient bien à
// l'établissement ciblé, sinon revenir à la liste (pas de fuite vers un autre ticket).
if ($ticketId > 0) {
    $chk = getPDO()->prepare("SELECT 1 FROM support_tickets WHERE id = ? AND establishment_id = ? LIMIT 1");
    $chk->execute([$ticketId, $estabId]);
    if (!$chk->fetchColumn()) { $ticketId = 0; $backTicket = "{$base}/platform/support/tickets.php"; }
}

$res = SupportImpersonation::start(getPDO(), $accId, $estabId, (int) ($_POST['tenant_account_id'] ?? 0));
if (!empty($res['ok'])) {
    header("Location: {$base}/accueil/accueil.php"); // l'agent entre dans l'app EN TANT QUE la cible
} else {
    header("Location: {$backTicket}&imp_error=" . urlencode((string) ($res['reason'] ?? 'Impersonation impossible.')));
}
exit;
