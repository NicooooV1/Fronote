<?php
declare(strict_types=1);
/**
 * Gestion des mots de passe — Fusion demandes + réinitialisation manuelle
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../includes/admin_functions.php';

requireAuth();
tenantGate('tenant.users.manage');

$pdo = getPDO();
$admin = getCurrentUser();
$userObj = app('user');

$message = '';
$error = '';
$generatedPwd = '';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // Approuver une demande
    if ($action === 'approve') {
        $rid = intval($_POST['request_id'] ?? 0);
        $uid = intval($_POST['user_id'] ?? 0);
        $utype = $_POST['user_type'] ?? '';
        // Cloisonnement multi-tenant : ne réinitialiser QUE le mdp d'un utilisateur de son
        // établissement (super_admin exempté) — sinon IDOR de prise de contrôle inter-tenant.
        if ($rid > 0 && $uid > 0 && !empty($utype) && !adminCanManageUser($uid, $utype)) {
            $error = "Action non autorisée sur cet utilisateur.";
        } elseif ($rid > 0 && $uid > 0 && !empty($utype)) {
            $generatedPwd = generateSecurePassword(12);
            if ($userObj->changePassword($utype, $uid, $generatedPwd)) {
                $stmt = $pdo->prepare("UPDATE demandes_reinitialisation SET status = 'approved', date_traitement = NOW(), admin_id = ? WHERE id = ?");
                $stmt->execute([$admin['id'], $rid]);
                logAudit('password_reset_approved', $userObj->getTableName($utype), $uid);
                $message = "Demande approuvée. Nouveau mot de passe : <code>" . htmlspecialchars($generatedPwd) . "</code>";
            } else { $error = "Erreur : " . $userObj->getErrorMessage(); }
        }
    }

    // Rejeter
    if ($action === 'reject') {
        $rid = intval($_POST['request_id'] ?? 0);
        if ($rid > 0) {
            // Cloisonnement multi-tenant (cf. approve/manual_reset) : la demande doit cibler
            // un compte que cet admin peut gérer, sinon un admin d'un établissement pouvait
            // rejeter les demandes de réinitialisation d'un autre tenant.
            $dstmt = $pdo->prepare("SELECT user_id, user_type FROM demandes_reinitialisation WHERE id = ?");
            $dstmt->execute([$rid]);
            $dem = $dstmt->fetch(PDO::FETCH_ASSOC);
            if ($dem && adminCanManageUser((int) $dem['user_id'], (string) $dem['user_type'])) {
                $stmt = $pdo->prepare("UPDATE demandes_reinitialisation SET status = 'rejected', date_traitement = NOW(), admin_id = ? WHERE id = ?");
                $stmt->execute([$admin['id'], $rid]);
                logAudit('password_reset_rejected', 'demandes_reinitialisation', $rid);
                $message = "Demande rejetée.";
            } else {
                $error = "Demande introuvable ou hors de votre périmètre.";
            }
        }
    }

    // Réinit manuelle
    if ($action === 'manual_reset') {
        $uid = intval($_POST['user_id'] ?? 0);
        $utype = $_POST['user_type'] ?? '';
        // Cloisonnement multi-tenant (cf. approve) : refus si la cible n'est pas de
        // l'établissement courant (super_admin exempté).
        if ($uid > 0 && !empty($utype) && !adminCanManageUser($uid, $utype)) {
            $error = "Action non autorisée sur cet utilisateur.";
        } elseif ($uid > 0 && !empty($utype)) {
            $generatedPwd = generateSecurePassword(12);
            if ($userObj->changePassword($utype, $uid, $generatedPwd)) {
                logAudit('password_manual_reset', $userObj->getTableName($utype), $uid);
                $message = "Mot de passe réinitialisé. Nouveau : <code>" . htmlspecialchars($generatedPwd) . "</code>";
            } else { $error = "Erreur : " . $userObj->getErrorMessage(); }
        }
    }
}

// Récupérer demandes en attente
$requests = [];
try {
    $stmt = $pdo->query("
        SELECT r.*,
            CASE
                WHEN r.user_type = 'eleve' THEN (SELECT CONCAT(prenom,' ',nom) FROM eleves WHERE id = r.user_id)
                WHEN r.user_type = 'professeur' THEN (SELECT CONCAT(prenom,' ',nom) FROM professeurs WHERE id = r.user_id)
                WHEN r.user_type = 'parent' THEN (SELECT CONCAT(prenom,' ',nom) FROM parents WHERE id = r.user_id)
                WHEN r.user_type = 'vie_scolaire' THEN (SELECT CONCAT(prenom,' ',nom) FROM vie_scolaire WHERE id = r.user_id)
                ELSE 'Inconnu'
            END AS nom_complet,
            CASE
                WHEN r.user_type = 'eleve' THEN (SELECT identifiant FROM eleves WHERE id = r.user_id)
                WHEN r.user_type = 'professeur' THEN (SELECT identifiant FROM professeurs WHERE id = r.user_id)
                WHEN r.user_type = 'parent' THEN (SELECT identifiant FROM parents WHERE id = r.user_id)
                WHEN r.user_type = 'vie_scolaire' THEN (SELECT identifiant FROM vie_scolaire WHERE id = r.user_id)
                ELSE 'Inconnu'
            END AS identifiant
        FROM demandes_reinitialisation r
        WHERE r.status = 'pending'
        ORDER BY r.date_demande DESC
    ");
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Cloisonnement multi-tenant : ne montrer que les demandes des comptes gérables par cet
    // admin (super_admin exempté dans adminCanManageUser) — évite la fuite cross-tenant des
    // noms/identifiants des comptes en attente de réinitialisation.
    $requests = array_values(array_filter($requests, static function ($r) {
        return adminCanManageUser((int) $r['user_id'], (string) $r['user_type']);
    }));
} catch (Exception $e) {
    error_log('[' . basename(__FILE__) . '] ' . $e->getMessage());
}

// Recherche pour réinit manuelle
$searchResults = [];
$searchTerm = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_user'])) {
    $searchTerm = trim($_POST['search_term'] ?? '');
    if (!empty($searchTerm)) {
        try { $searchResults = $userObj->searchUsers($searchTerm); } catch (Exception $e) {
            error_log('[' . basename(__FILE__) . '] ' . $e->getMessage());
        }
    }
}

$pageTitle = 'Gestion des mots de passe';
$currentPage = 'passwords';
$extraCss = ['../../assets/css/admin.css'];

ob_start();
?>
<style>
    .pwd-container { max-width: 1000px; margin: 0 auto; }
    .tabs { display: flex; gap: 5px; margin-bottom: 20px; border-bottom: 2px solid #eee; }
    .tab-content { display: none; } .tab-content.active { display: block; }
    .req-table { width: 100%; border-collapse: collapse; background: var(--bg-card); border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .req-table th, .req-table td { padding: 10px 14px; text-align: left; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
    .req-table th { background: var(--bg-secondary); font-weight: 600; color: #4a5568; font-size: 13px; }
    .badge-count { background: #e74c3c; color: white; border-radius: 50%; padding: 1px 7px; font-size: 12px; font-weight: 600; margin-left: 6px; }
    .search-form { display: flex; gap: 10px; margin-bottom: 20px; }
    .search-form input { flex: 1; padding: 9px 12px; border: 1px solid #d2d6dc; border-radius: 6px; font-size: 14px; }
    .empty-state { text-align: center; padding: 40px; color: #999; }
    .empty-state i { font-size: 36px; margin-bottom: 10px; display: block; opacity: 0.3; }
</style>
<?php
$extraHeadHtml = ob_get_clean();
include __DIR__ . '/../includes/header.php';
?>

<div class="pwd-container">
    <?php if (!empty($message)): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="tabs">
        <button class="tab-btn active" data-fr-click="switchTab" data-fr-args='["requests"]'><i class="fas fa-clipboard-list"></i> Demandes en attente <?php if (count($requests) > 0): ?><span class="badge-count"><?= count($requests) ?></span><?php endif; ?></button>
        <button class="tab-btn" data-fr-click="switchTab" data-fr-args='["manual"]'><i class="fas fa-key"></i> Réinitialisation manuelle</button>
    </div>

    <!-- Onglet Demandes -->
    <div class="tab-content active" id="tab-requests">
        <?php if (empty($requests)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <p>Aucune demande de réinitialisation en attente.</p>
            </div>
        <?php else: ?>
            <table class="req-table">
                <thead><tr><th>Utilisateur</th><th>Identifiant</th><th>Type</th><th>Date demande</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($r['nom_complet']) ?></strong></td>
                        <td><code><?= htmlspecialchars($r['identifiant']) ?></code></td>
                        <td><span class="badge-profil <?= getProfilBadgeClass($r['user_type']) ?>"><?= getProfilLabel($r['user_type']) ?></span></td>
                        <td style="font-size:13px;"><?= date('d/m/Y H:i', strtotime($r['date_demande'])) ?></td>
                        <td>
                            <div class="actions-cell">
                                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="request_id" value="<?= $r['id'] ?>"><input type="hidden" name="user_id" value="<?= $r['user_id'] ?>"><input type="hidden" name="user_type" value="<?= $r['user_type'] ?>"><button class="btn-xs success" title="Approuver"><i class="fas fa-check"></i> Approuver</button></form>
                                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="request_id" value="<?= $r['id'] ?>"><button class="btn-xs danger" title="Rejeter"><i class="fas fa-times"></i> Rejeter</button></form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Onglet Réinit manuelle -->
    <div class="tab-content" id="tab-manual">
        <form method="post" class="search-form">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="text" name="search_term" placeholder="Rechercher un utilisateur…" value="<?= htmlspecialchars($searchTerm) ?>">
            <button type="submit" name="search_user" class="btn btn-primary"><i class="fas fa-search"></i> Rechercher</button>
        </form>

        <?php if (!empty($searchResults)): ?>
        <table class="req-table">
            <thead><tr><th>Nom</th><th>Identifiant</th><th>Profil</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($searchResults as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['prenom'] . ' ' . $u['nom']) ?></td>
                    <td><code><?= htmlspecialchars($u['identifiant']) ?></code></td>
                    <td><span class="badge-profil <?= getProfilBadgeClass($u['type']) ?>"><?= getProfilLabel($u['type']) ?></span></td>
                    <td>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="action" value="manual_reset"><input type="hidden" name="user_id" value="<?= $u['id'] ?>"><input type="hidden" name="user_type" value="<?= $u['type'] ?>"><button class="btn-xs success"><i class="fas fa-key"></i> Réinitialiser</button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
function switchTab(name, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    if (btn && btn.closest) btn.closest('.tab-btn').classList.add('active');
    document.getElementById('tab-' + name).classList.add('active');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
