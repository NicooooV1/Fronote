<?php
/** Fin manuelle d'une impersonation Support (depuis le bandeau permanent). Conserve la session plateforme. */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$ticketId = (int) ($_SESSION['impersonation']['ticket_id'] ?? 0);
\API\Support\SupportImpersonation::stop(getPDO(), false);

$base = defined('BASE_URL') ? BASE_URL : '';
$dest = $ticketId > 0 ? "/platform/support/ticket.php?id={$ticketId}" : "/platform/support/tickets.php";
header("Location: {$base}{$dest}");
exit;
