<?php
declare(strict_types=1);
/**
 * Bootstrap partagé du portail ÉTABLISSEMENT (/e/{slug} → tenant/*.php?e={slug}).
 * Résout l'établissement par slug, expose $establishment/$slug/$base et le garde
 * tenantRequireAuth(). Session établissement isolée ($_SESSION['tenant']).
 */
require_once __DIR__ . '/../API/core.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

use API\Tenant\TenantContext;

$base = defined('BASE_URL') ? BASE_URL : '';
$slug = (string) ($_GET['e'] ?? ($_SESSION['tenant']['slug'] ?? ''));

$establishment = $slug !== '' ? TenantContext::establishmentBySlug(getPDO(), $slug) : null;
if ($establishment === null) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>Établissement introuvable</title>'
       . '<p style="font-family:system-ui;padding:40px">Établissement introuvable.</p>';
    exit;
}
$slug = $establishment['slug'] ?: $establishment['code'];

/** Garde établissement : exige une session tenant valide pour CET établissement. */
function tenantRequireAuth(array $establishment, string $base, string $slug): void
{
    $sess = $_SESSION['tenant'] ?? null;
    $loginUrl = "{$base}/tenant/login.php?e=" . urlencode($slug);
    if (empty($sess['membership_id']) || (int) ($sess['establishment_id'] ?? 0) !== (int) $establishment['id']) {
        header("Location: {$loginUrl}");
        exit;
    }
    // Revérifie que l'appartenance est toujours active et l'établissement non suspendu.
    $m = TenantContext::membershipFor(getPDO(), (int) $establishment['id'], (int) $sess['account_id']);
    if (!$m || in_array($establishment['status'] ?? 'active', ['suspended', 'deleted', 'purged'], true)) {
        unset($_SESSION['tenant']);
        header("Location: {$loginUrl}");
        exit;
    }
}
