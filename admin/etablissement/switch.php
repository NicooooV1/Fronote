<?php
/**
 * Switch active establishment (super-admin or multi-etab admin).
 * POST /admin/etablissement/switch.php  (champs: id, CSRF token)
 */
require_once __DIR__ . '/../../API/bootstrap.php';
require_once __DIR__ . '/../../API/Legacy/Bridge.php';

use API\Middleware\EstablishmentScope;
use API\Services\SuperAdminService;

// Bascule d'établissement = opération cross-tenant → réservée au super-administrateur.
// Un administrateur classique est rattaché à UN seul établissement et ne doit jamais
// pouvoir scoper sa session sur un autre (escalade horizontale inter-établissements).
if (!SuperAdminService::isSuperAdmin()) {
    $_SESSION['error_message'] = "Accès réservé au super-administrateur.";
    redirect('accueil/accueil.php');
}

// Changement d'état (scope établissement) → POST + CSRF obligatoire (anti-CSRF).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/etablissement/multi.php');
}
csrf_verify();

$id = (int) ($_POST['id'] ?? 0);
if ($id < 1) {
    redirect('admin/etablissement/multi.php');
}

// Verify the establishment exists and is active
$pdo = getPDO();
$stmt = $pdo->prepare("SELECT id, nom FROM etablissements WHERE id = ? AND actif = 1");
$stmt->execute([$id]);
$etab = $stmt->fetch(\PDO::FETCH_ASSOC);

if (!$etab) {
    $_SESSION['error_message'] = __('admin.establishment_not_found');
    redirect('admin/etablissement/multi.php');
}

// Switch context
EstablishmentScope::switchTo($id);
$_SESSION['success_message'] = __('admin.switched_to', ['name' => $etab['nom']]);

redirect('admin/dashboard.php');
