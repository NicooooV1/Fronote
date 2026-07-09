<?php
declare(strict_types=1);
/**
 * Purge d'un établissement — opération DESTRUCTIVE réservée au SUPER-ADMIN.
 *
 * Garde-fous (cf. spécification SuperAdmin) :
 *   - super_admin uniquement (doublé par AccessControl + vérif ici, fail-closed) ;
 *   - POST + CSRF obligatoires ;
 *   - confirmation forte : saisir le NOM exact de l'établissement ;
 *   - SAUVEGARDE automatique AVANT toute suppression ;
 *   - journalisation d'audit systématique ;
 *   - aucune suppression silencieuse : si le moteur de purge n'est pas disponible,
 *     on refuse proprement (pas de suppression partielle à l'aveugle).
 */
require_once __DIR__ . '/../../API/bootstrap.php';
require_once __DIR__ . '/../../API/Legacy/Bridge.php';

use API\Services\SuperAdminService;

if (!SuperAdminService::isSuperAdmin()) {
    http_response_code(403);
    $_SESSION['error_message'] = "Purge réservée au super-administrateur.";
    redirect('accueil/accueil.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/etablissement/multi.php');
}
csrf_verify();

$id      = (int) ($_POST['id'] ?? 0);
$confirm = trim((string) ($_POST['confirm_nom'] ?? ''));
$pdo     = getPDO();

$stmt = $pdo->prepare("SELECT id, nom FROM etablissements WHERE id = ?");
$stmt->execute([$id]);
$etab = $stmt->fetch(\PDO::FETCH_ASSOC);

if (!$etab) {
    $_SESSION['error_message'] = "Établissement introuvable.";
    redirect('admin/etablissement/multi.php');
}

// Confirmation forte : le nom saisi doit correspondre exactement.
if (!hash_equals((string) $etab['nom'], $confirm)) {
    $_SESSION['error_message'] = "Confirmation invalide : saisissez le nom exact de l'établissement.";
    redirect('admin/etablissement/multi.php');
}

// Journaliser l'INTENTION avant toute action (trace même en cas d'échec).
try {
    app('audit')->logAuth('etablissement.purge.request', (string) $id, true, ['nom' => $etab['nom']]);
} catch (\Throwable $e) { error_log('[purge] audit request failed: ' . $e->getMessage()); }

// Sauvegarde OBLIGATOIRE avant purge.
$backupRef = null;
try {
    $backup    = app('backup');
    $result    = method_exists($backup, 'create') ? $backup->create() : null;
    $backupRef = is_array($result) ? ($result['file'] ?? $result['path'] ?? 'ok') : (is_string($result) ? $result : 'ok');
} catch (\Throwable $e) {
    error_log('[purge] backup failed: ' . $e->getMessage());
    $_SESSION['error_message'] = "Purge annulée : la sauvegarde préalable a échoué (" . $e->getMessage() . ").";
    redirect('admin/etablissement/multi.php');
}

// Exécuter la purge via le service dédié — refus propre si non disponible (pas de
// suppression partielle improvisée). À implémenter/tester dans SuperAdminService.
$service = new SuperAdminService($pdo);
if (!method_exists($service, 'purgeEstablishment')) {
    try {
        app('audit')->logAuth('etablissement.purge.unavailable', (string) $id, false, ['nom' => $etab['nom'], 'backup' => $backupRef]);
    } catch (\Throwable $e) {}
    $_SESSION['error_message'] = "Sauvegarde effectuée, mais le moteur de purge (SuperAdminService::purgeEstablishment) n'est pas encore activé. Aucune donnée supprimée.";
    redirect('admin/etablissement/multi.php');
}

try {
    $service->purgeEstablishment($id);
    app('audit')->logAuth('etablissement.purge.done', (string) $id, true, ['nom' => $etab['nom'], 'backup' => $backupRef]);
    $_SESSION['success_message'] = "Établissement « " . $etab['nom'] . " » purgé. Sauvegarde préalable : " . $backupRef . ".";
} catch (\Throwable $e) {
    error_log('[purge] failed: ' . $e->getMessage());
    try { app('audit')->logAuth('etablissement.purge.failed', (string) $id, false, ['nom' => $etab['nom'], 'error' => $e->getMessage()]); } catch (\Throwable $e2) {}
    $_SESSION['error_message'] = "Échec de la purge : " . $e->getMessage() . " (sauvegarde préalable : " . $backupRef . ").";
}
redirect('admin/etablissement/multi.php');
