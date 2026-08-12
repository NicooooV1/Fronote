<?php
declare(strict_types=1);
/**
 * Gestion des classes — CRUD, effectifs, prof principal, affectation rapide
 */
require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/../includes/admin_functions.php';

requireAuth();
tenantGate('tenant.classes.manage');

$pdo = getPDO();
$admin = getCurrentUser();
$message = '';
$error = '';

// Cloisonnement multi-tenant : toutes les listes affichées (profs, classes, élèves)
// sont bornées à l'établissement COURANT (contexte de session).
$etabId = \API\Core\EstablishmentContext::id();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$stmtProfs = $pdo->prepare("SELECT id, nom, prenom FROM professeurs WHERE etablissement_id = ? ORDER BY nom, prenom");
$stmtProfs->execute([$etabId]);
$professeurs = $stmtProfs->fetchAll(PDO::FETCH_ASSOC);

// POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['csrf_token'] ?? '') === $csrf_token) {
    $action = $_POST['action'] ?? '';

    // Cloisonnement multi-tenant : toutes les opérations sur les classes sont bornées à
    // l'établissement COURANT (contexte de session ; un super_admin ayant basculé opère
    // sur l'établissement sélectionné). Sans ce scope, un admin éditait/supprimait les
    // classes d'un AUTRE établissement par class_id falsifié (IDOR cross-tenant).
    $etabId = \API\Core\EstablishmentContext::id();

    if ($action === 'create_class') {
        $nom = trim($_POST['nom'] ?? '');
        $niveau = trim($_POST['niveau'] ?? '');
        $annee = trim($_POST['annee_scolaire'] ?? date('Y') . '-' . (date('Y') + 1));
        $ppId = intval($_POST['professeur_principal_id'] ?? 0) ?: null;
        if (!empty($nom) && !empty($niveau)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO classes (nom, niveau, annee_scolaire, professeur_principal_id, etablissement_id) VALUES (?,?,?,?,?)");
                $stmt->execute([$nom, $niveau, $annee, $ppId, $etabId]);
                logAudit('class_created', 'classes', $pdo->lastInsertId());
                $message = "Classe « $nom » créée.";
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate')) {
                    $error = "Cette classe existe déjà pour cette année.";
                } else {
                    error_log("create_class failed: " . $e->getMessage());
                    $error = "Erreur lors de la création de la classe.";
                }
            }
        }
    }

    if ($action === 'edit_class') {
        $cid = intval($_POST['class_id'] ?? 0);
        $nom = trim($_POST['nom'] ?? '');
        $niveau = trim($_POST['niveau'] ?? '');
        $ppId = intval($_POST['professeur_principal_id'] ?? 0) ?: null;
        $actif = isset($_POST['actif']) ? 1 : 0;
        if ($cid > 0 && !empty($nom)) {
            $st = $pdo->prepare("UPDATE classes SET nom = ?, niveau = ?, professeur_principal_id = ?, actif = ? WHERE id = ? AND etablissement_id = ?");
            $st->execute([$nom, $niveau, $ppId, $actif, $cid, $etabId]);
            if ($st->rowCount() > 0) {
                logAudit('class_edited', 'classes', $cid);
                $message = "Classe modifiée.";
            } else {
                $error = "Classe introuvable.";
            }
        }
    }

    if ($action === 'delete_class') {
        $cid = intval($_POST['class_id'] ?? 0);
        if ($cid > 0) {
            // Vérifier les élèves (classe scopée à l'établissement courant)
            $count = $pdo->prepare("SELECT COUNT(*) FROM eleves WHERE etablissement_id = ? AND classe = (SELECT nom FROM classes WHERE id = ? AND etablissement_id = ?)");
            $count->execute([$etabId, $cid, $etabId]);
            if ($count->fetchColumn() > 0) {
                $error = "Impossible de supprimer : des élèves sont encore affectés à cette classe.";
            } else {
                $st = $pdo->prepare("DELETE FROM classes WHERE id = ? AND etablissement_id = ?");
                $st->execute([$cid, $etabId]);
                if ($st->rowCount() > 0) {
                    logAudit('class_deleted', 'classes', $cid);
                    $message = "Classe supprimée.";
                } else {
                    $error = "Classe introuvable.";
                }
            }
        }
    }

    if ($action === 'assign_students') {
        $cid = intval($_POST['class_id'] ?? 0);
        $className = trim($_POST['class_name'] ?? '');
        $studentIds = $_POST['student_ids'] ?? [];
        // La classe cible doit appartenir à l'établissement courant.
        $ownsClass = $pdo->prepare("SELECT 1 FROM classes WHERE id = ? AND etablissement_id = ? LIMIT 1");
        $ownsClass->execute([$cid, $etabId]);
        if ($cid > 0 && !empty($className) && !$ownsClass->fetchColumn()) {
            $error = "Classe introuvable.";
        } elseif ($cid > 0 && !empty($className)) {
            try {
                $pdo->beginTransaction();
                // Retirer tous les élèves de cette classe (bornés à l'établissement courant)
                $pdo->prepare("UPDATE eleves SET classe = '' WHERE classe = ? AND etablissement_id = ?")->execute([$className, $etabId]);
                // Affecter les sélectionnés — uniquement des élèves de CET établissement
                // (empêche d'aspirer un élève d'un autre établissement via un id falsifié).
                if (!empty($studentIds)) {
                    $ids = array_values(array_filter(array_map('intval', $studentIds), fn($v) => $v > 0));
                    if (!empty($ids)) {
                        $ph = implode(',', array_fill(0, count($ids), '?'));
                        $st = $pdo->prepare("UPDATE eleves SET classe = ? WHERE etablissement_id = ? AND id IN ($ph)");
                        $st->execute(array_merge([$className, $etabId], $ids));
                    }
                }
                $pdo->commit();
                logAudit('students_assigned', 'classes', $cid, [], ['count' => count($studentIds)]);
                $message = count($studentIds) . " élève(s) affecté(s) à " . htmlspecialchars($className) . ".";
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("assign_students failed: " . $e->getMessage());
                $error = "Échec de l'affectation des élèves.";
            }
        }
    }
}

// Charger classes avec effectifs (bornées à l'établissement courant)
$stmtClasses = $pdo->prepare("
    SELECT c.*,
        (SELECT COUNT(*) FROM eleves e WHERE e.classe = c.nom AND e.actif = 1 AND e.etablissement_id = c.etablissement_id) AS effectif,
        (SELECT CONCAT(p.prenom, ' ', p.nom) FROM professeurs p WHERE p.id = c.professeur_principal_id) AS pp_nom
    FROM classes c
    WHERE c.etablissement_id = ?
    ORDER BY c.actif DESC, c.niveau, c.nom
");
$stmtClasses->execute([$etabId]);
$classes = $stmtClasses->fetchAll(PDO::FETCH_ASSOC);

// Stats (scopées établissement courant)
$totalClasses = count($classes);
$stmtTotEleves = $pdo->prepare("SELECT COUNT(*) FROM eleves WHERE actif = 1 AND etablissement_id = ?");
$stmtTotEleves->execute([$etabId]);
$totalEleves = $stmtTotEleves->fetchColumn();
$avgEffectif = $totalClasses > 0 ? round((float) ($totalEleves / $totalClasses), 1) : 0;

// Liste des élèves pour le modal d'affectation (bornée à l'établissement courant)
$stmtStudents = $pdo->prepare("SELECT id, nom, prenom, classe FROM eleves WHERE actif = 1 AND etablissement_id = ? ORDER BY nom, prenom");
$stmtStudents->execute([$etabId]);
$allStudentsData = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Gestion des classes';
$currentPage = 'classes';
$extraCss = ['../../assets/css/admin.css'];

ob_start();
?>
<style>
    .classes-container { max-width: 1100px; margin: 0 auto; }
    .classes-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px; }
    .class-card { background: var(--bg-card); border-radius: 10px; padding: 18px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); border-left: 4px solid #0f4c81; transition: transform 0.2s; }
    .class-card:hover { transform: translateY(-2px); }
    .class-card.inactive { opacity: 0.6; border-left-color: #ccc; }
    .class-card h3 { margin: 0 0 8px; font-size: 18px; display: flex; justify-content: space-between; align-items: center; }
    .class-card .effectif { font-size: 24px; font-weight: 700; color: #0f4c81; }
    .class-meta { font-size: 13px; color: #666; margin-bottom: 8px; }
    .class-actions { display: flex; gap: 6px; margin-top: 10px; }
    .badge-niveau { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; background: #e2e8f0; color: #4a5568; }
    .badge-inactive { background: #fee2e2; color: #991b1b; }
    .student-list { max-height: 300px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px; }
    .student-list label { display: block; padding: 4px 6px; font-size: 13px; cursor: pointer; border-radius: 4px; }
    .student-list label:hover { background: #f0f4f8; }
</style>
<?php
$extraHeadHtml = ob_get_clean();
include __DIR__ . '/../includes/header.php';
?>

<div class="classes-container">
    <?php if (!empty($message)): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="stats-bar">
        <div class="stat-card"><div class="val"><?= $totalClasses ?></div><div class="lbl">Classes</div></div>
        <div class="stat-card"><div class="val"><?= $totalEleves ?></div><div class="lbl">Élèves total</div></div>
        <div class="stat-card"><div class="val"><?= $avgEffectif ?></div><div class="lbl">Moyenne/classe</div></div>
    </div>

    <div class="top-bar">
        <button class="btn btn-primary" data-fr-click="addClass" data-fr-args='["createModal","active"]'><i class="fas fa-plus"></i> Nouvelle classe</button>
    </div>

    <div class="classes-grid">
        <?php foreach ($classes as $c): ?>
        <div class="class-card <?= $c['actif'] ? '' : 'inactive' ?>">
            <h3>
                <?= htmlspecialchars($c['nom']) ?>
                <span class="effectif"><?= $c['effectif'] ?></span>
            </h3>
            <div class="class-meta">
                <span class="badge-niveau"><?= htmlspecialchars($c['niveau']) ?></span>
                <?php if (!$c['actif']): ?><span class="badge-niveau badge-inactive">Inactive</span><?php endif; ?>
                <div style="margin-top:4px"><?= htmlspecialchars($c['annee_scolaire']) ?></div>
                <?php if (!empty($c['pp_nom'])): ?><div><i class="fas fa-user-tie"></i> <?= htmlspecialchars($c['pp_nom']) ?></div><?php endif; ?>
            </div>
            <div class="class-actions">
                <button class="btn-xs primary" data-fr-click="openEdit" data-fr-args='[<?= json_encode($c, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>]' title="Modifier"><i class="fas fa-pen"></i></button>
                <button class="btn-xs success" data-fr-click="openStudents" data-fr-args='[<?= $c["id"] ?>, <?= json_encode($c["nom"], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>]' title="Gérer élèves"><i class="fas fa-users"></i></button>
                <form method="post" style="display:inline" data-fr-confirm="Supprimer cette classe ?"><input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="action" value="delete_class"><input type="hidden" name="class_id" value="<?= $c['id'] ?>"><button class="btn-xs danger" title="Supprimer"><i class="fas fa-trash"></i></button></form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal Créer -->
<div class="modal-overlay" id="createModal">
    <div class="modal-box">
        <h3><i class="fas fa-plus"></i> Nouvelle classe</h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" value="create_class">
            <div class="form-row">
                <div class="form-group"><label>Nom</label><input type="text" name="nom" placeholder="6èmeA" required></div>
                <div class="form-group"><label>Niveau</label><select name="niveau" required><option value="6ème">6ème</option><option value="5ème">5ème</option><option value="4ème">4ème</option><option value="3ème">3ème</option><option value="2nde">2nde</option><option value="1ère">1ère</option><option value="Terminale">Terminale</option></select></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Année scolaire</label><input type="text" name="annee_scolaire" value="<?= date('Y') . '-' . (date('Y') + 1) ?>"></div>
                <div class="form-group"><label>Prof. principal</label><select name="professeur_principal_id"><option value="">Aucun</option>
                    <?php foreach ($professeurs as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?></option><?php endforeach; ?>
                </select></div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end"><button type="button" class="btn btn-secondary" data-fr-click="removeClass" data-fr-args='["createModal","active"]'>Annuler</button><button type="submit" class="btn btn-primary">Créer</button></div>
        </form>
    </div>
</div>

<!-- Modal Modifier -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3><i class="fas fa-pen"></i> Modifier la classe</h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" value="edit_class">
            <input type="hidden" name="class_id" id="edit_cid">
            <div class="form-row">
                <div class="form-group"><label>Nom</label><input type="text" name="nom" id="edit_nom" required></div>
                <div class="form-group"><label>Niveau</label><select name="niveau" id="edit_niveau"><option value="6ème">6ème</option><option value="5ème">5ème</option><option value="4ème">4ème</option><option value="3ème">3ème</option><option value="2nde">2nde</option><option value="1ère">1ère</option><option value="Terminale">Terminale</option></select></div>
            </div>
            <div class="form-group"><label>Prof. principal</label><select name="professeur_principal_id" id="edit_pp"><option value="">Aucun</option>
                <?php foreach ($professeurs as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-group"><label><input type="checkbox" name="actif" id="edit_actif" checked> Active</label></div>
            <div style="display:flex;gap:8px;justify-content:flex-end"><button type="button" class="btn btn-secondary" data-fr-click="removeClass" data-fr-args='["editModal","active"]'>Annuler</button><button type="submit" class="btn btn-primary">Enregistrer</button></div>
        </form>
    </div>
</div>

<!-- Modal Élèves -->
<div class="modal-overlay" id="studentsModal">
    <div class="modal-box">
        <h3><i class="fas fa-users"></i> Élèves de <span id="sm_class_name"></span></h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" value="assign_students">
            <input type="hidden" name="class_id" id="sm_cid">
            <input type="hidden" name="class_name" id="sm_cname">
            <input type="text" id="sm_search" placeholder="Rechercher…" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;margin-bottom:10px;box-sizing:border-box;font-size:13px" data-fr-input="filterStudents">
            <div class="student-list" id="sm_list">Chargement…</div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px"><button type="button" class="btn btn-secondary" data-fr-click="removeClass" data-fr-args='["studentsModal","active"]'>Annuler</button><button type="submit" class="btn btn-primary">Enregistrer</button></div>
        </form>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
const allStudents = <?= json_encode($allStudentsData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

function openEdit(c) {
    document.getElementById('edit_cid').value = c.id;
    document.getElementById('edit_nom').value = c.nom;
    document.getElementById('edit_niveau').value = c.niveau;
    document.getElementById('edit_pp').value = c.professeur_principal_id || '';
    document.getElementById('edit_actif').checked = !!c.actif;
    document.getElementById('editModal').classList.add('active');
}

function openStudents(cid, className) {
    document.getElementById('sm_cid').value = cid;
    document.getElementById('sm_cname').value = className;
    document.getElementById('sm_class_name').textContent = className;
    // Échappe nom/prénom/classe avant innerHTML (anti XSS stocké via profil élève).
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let html = '';
    allStudents.forEach(s => {
        const checked = s.classe === className ? 'checked' : '';
        html += `<label><input type="checkbox" name="student_ids[]" value="${parseInt(s.id, 10)}" ${checked}> ${esc(s.prenom)} ${esc(s.nom)} <small style="color:#888">(${esc(s.classe) || 'Sans classe'})</small></label>`;
    });
    document.getElementById('sm_list').innerHTML = html;
    document.getElementById('studentsModal').classList.add('active');
}

function filterStudents() {
    const q = document.getElementById('sm_search').value.toLowerCase();
    document.querySelectorAll('#sm_list label').forEach(l => {
        l.style.display = l.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

document.querySelectorAll('.modal-overlay').forEach(m => m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); }));
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
