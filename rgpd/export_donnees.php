<?php
declare(strict_types=1);
/**
 * M23 – RGPD — Export de mes données (Art. 15 Droit d'accès)
 * Permet à chaque utilisateur de télécharger toutes ses données
 */
require_once __DIR__ . '/../API/Legacy/Bridge.php';
requireAuth();

$userId   = getUserId();
$userType = getUserRole();

// Téléchargement JSON — traité AVANT tout rendu HTML : (1) sinon le header
// régénère des tokens CSRF à usage unique qui évincent (FIFO) le token soumis
// et la validation échouerait ; (2) les en-têtes de fichier ne peuvent être posés
// qu'avant toute sortie. POST + CSRF obligatoires (token hors URL, cf. formulaire).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['download'] ?? '') === '1' && validateCSRFToken()) {
    require_once __DIR__ . '/includes/AuditRgpdService.php';
    $rgpdService = new AuditRgpdService(getPDO());
    $data = $rgpdService->exporterDonneesUtilisateur($userId, $userType);

    // Traçabilité RGPD (Art.15) : journaliser l'export, comme annoncé à l'utilisateur.
    try {
        app('audit')->logAuth('rgpd_export', (string) $userId, true, ['user_type' => $userType]);
    } catch (\Throwable $e) { error_log('[rgpd] export audit failed: ' . $e->getMessage()); }

    $filename = 'mes_donnees_rgpd_' . date('Y-m-d_His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$pageTitle = 'Exporter mes données';
$activePage = 'mes_donnees';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <h1><i class="fas fa-download"></i> Exporter mes données</h1>
    </div>

    <div class="rgpd-info-banner">
        <i class="fas fa-shield-alt"></i>
        <p>
            Conformément à l'article 15 du RGPD (Droit d'accès), vous pouvez télécharger l'ensemble 
            de vos données personnelles stockées. Le fichier sera au format JSON et contiendra :
        </p>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2>Données incluses dans l'export</h2></div>
        <div class="card-body">
            <ul class="export-data-list">
                <li><i class="fas fa-user text-primary"></i> <strong>Profil utilisateur</strong> — Nom, prénom, email, téléphone, adresse</li>
                <li><i class="fas fa-check-circle text-success"></i> <strong>Consentements</strong> — Historique de vos choix RGPD</li>
                <li><i class="fas fa-file-contract text-info"></i> <strong>Demandes RGPD</strong> — Vos demandes et réponses</li>
                <?php if ($userType === 'eleve'): ?>
                <li><i class="fas fa-graduation-cap text-warning"></i> <strong>Notes & bulletins</strong> — Toutes vos évaluations</li>
                <li><i class="fas fa-calendar-times text-danger"></i> <strong>Absences & retards</strong> — Historique complet</li>
                <li><i class="fas fa-exclamation-triangle text-danger"></i> <strong>Incidents</strong> — Rapports disciplinaires</li>
                <li><i class="fas fa-clipboard-list text-info"></i> <strong>Inscriptions</strong> — Dossiers d'inscription</li>
                <?php elseif ($userType === 'parent'): ?>
                <li><i class="fas fa-file-invoice-dollar text-warning"></i> <strong>Factures</strong> — Historique de facturation</li>
                <?php endif; ?>
                <li><i class="fas fa-envelope text-secondary"></i> <strong>Messages envoyés</strong> — Contenu des messages</li>
                <li><i class="fas fa-desktop text-secondary"></i> <strong>Sessions</strong> — Historique de connexion</li>
                <li><i class="fas fa-history text-muted"></i> <strong>Journal d'activité</strong> — Actions enregistrées</li>
            </ul>
        </div>
    </div>

    <div class="card">
        <div class="card-body text-center" style="padding:2rem">
            <p class="text-muted mb-3">Le fichier sera généré instantanément au format JSON.</p>
            <form method="post" action="export_donnees.php" style="display:inline">
                <input type="hidden" name="download" value="1">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRFToken()) ?>">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-download"></i> Télécharger mes données
                </button>
            </form>
            <p class="text-muted mt-2" style="font-size:.85rem">
                Ce téléchargement est soumis à authentification et tracé dans le journal d'audit.
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
