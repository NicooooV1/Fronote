<?php
declare(strict_types=1);
/** Fin manuelle d'une impersonation Support (depuis le bandeau). POST + CSRF requis. Conserve la session plateforme. */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$base = defined('BASE_URL') ? BASE_URL : '';

// Écriture d'état → exige POST + CSRF (empêche un arrêt forcé par CSRF/préfetch),
// et un marqueur d'impersonation effectivement présent dans CETTE session.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken() || empty($_SESSION['impersonation'])) {
    http_response_code(403);
    header("Location: {$base}/");
    exit;
}

$ticketId = (int) ($_SESSION['impersonation']['ticket_id'] ?? 0);
\API\Support\SupportImpersonation::stop(getPDO(), false);

$dest = $ticketId > 0 ? "/platform/support/ticket.php?id={$ticketId}" : "/platform/support/tickets.php";
header("Location: {$base}{$dest}");
exit;
