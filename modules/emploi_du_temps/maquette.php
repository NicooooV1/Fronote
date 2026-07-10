<?php
declare(strict_types=1);
/**
 * maquette.php — Gestion de la maquette horaire (besoins de cours).
 * Alimente le moteur de génération automatique (ajax_generate.php, CDC §6).
 *
 * Accès : administrateur, vie_scolaire uniquement.
 */
ob_start();

require_once __DIR__ . '/../../API/core.php';
require_once __DIR__ . '/includes/EdtService.php';

$pdo = getPDO();
requireAuth();

if (!isAdmin() && !isVieScolaire()) {
    redirect('/accueil/accueil.php');
}

$user_fullname = getUserFullName();
$user_initials = getUserInitials();

$service = new EdtService($pdo);

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken()) {
        $errors[] = 'Token CSRF invalide.';
    } else {
        $postAction = $_POST['action'] ?? '';
        if ($postAction === 'delete') {
            if ($service->deleteMaquette((int)($_POST['id'] ?? 0))) {
                $success = 'Besoin supprimé.';
            } else {
                $errors[] = 'Suppression impossible.';
            }
        } elseif ($postAction === 'add') {
            $data = [
                'classe_id'     => (int)($_POST['classe_id'] ?? 0),
                'matiere_id'    => (int)($_POST['matiere_id'] ?? 0),
                'professeur_id' => (int)($_POST['professeur_id'] ?? 0),
                'nb_creneaux'   => (int)($_POST['nb_creneaux'] ?? 1),
                'type_cours'    => $_POST['type_cours'] ?? 'cours',
                'groupe'        => !empty($_POST['groupe']) ? trim($_POST['groupe']) : null,
                'salle_type'    => !empty($_POST['salle_type']) ? $_POST['salle_type'] : null,
            ];
            if ($data['classe_id'] <= 0)     $errors[] = 'Veuillez sélectionner une classe.';
            if ($data['matiere_id'] <= 0)    $errors[] = 'Veuillez sélectionner une matière.';
            if ($data['professeur_id'] <= 0) $errors[] = 'Veuillez sélectionner un professeur.';
            if ($data['nb_creneaux'] <= 0)   $errors[] = 'Le nombre de créneaux doit être positif.';

            if (empty($errors)) {
                $service->addMaquette($data);
                $success = 'Besoin ajouté à la maquette.';
            }
        }
    }
}

// Données
$classes     = $service->getClasses();
$matieres    = $service->getMatieres();
$professeurs = $service->getProfesseurs();
$maquette    = $service->getMaquette();

// Maps id → libellé pour l'affichage
$classeMap = $matiereMap = $profMap = [];
foreach ($classes as $c)     { $classeMap[$c['id']]  = $c['nom']; }
foreach ($matieres as $m)    { $matiereMap[$m['id']] = $m['nom']; }
foreach ($professeurs as $p) { $profMap[$p['id']]    = $p['prenom'] . ' ' . $p['nom']; }

$typesCours  = ['cours' => 'Cours', 'td' => 'TD', 'tp' => 'TP', 'examen' => 'Examen', 'autre' => 'Autre'];
$typesSalle  = ['' => 'Indifférent', 'standard' => 'Standard', 'info' => 'Informatique', 'labo' => 'Laboratoire', 'gymnase' => 'Gymnase', 'amphi' => 'Amphi'];

$pageTitle   = 'Maquette horaire';
$currentPage = 'maquette';
$pageSubtitle = 'Définir les besoins de cours pour la génération automatique';

include 'includes/header.php';
?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-list-check"></i> Besoins de cours (<?= count($maquette) ?>)</h3>
    </div>
    <div class="card-body">
        <?php if (empty($maquette)): ?>
        <p>Aucun besoin défini. Ajoutez-en pour pouvoir générer un emploi du temps.</p>
        <?php else: ?>
        <table class="edt-table" style="width:100%">
            <thead>
                <tr>
                    <th><?= __('emploi_du_temps.class') ?></th><th><?= __('emploi_du_temps.subject') ?></th><th><?= __('emploi_du_temps.teacher') ?></th>
                    <th>Créneaux</th><th><?= __('label.type') ?></th><th>Groupe</th><th><?= __('label.salle') ?></th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($maquette as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($classeMap[$row['classe_id']] ?? ('#' . $row['classe_id'])) ?></td>
                    <td><?= htmlspecialchars($matiereMap[$row['matiere_id']] ?? ('#' . $row['matiere_id'])) ?></td>
                    <td><?= htmlspecialchars($profMap[$row['professeur_id']] ?? ('#' . $row['professeur_id'])) ?></td>
                    <td><?= (int)$row['nb_creneaux'] ?></td>
                    <td><?= htmlspecialchars($typesCours[$row['type_cours']] ?? $row['type_cours']) ?></td>
                    <td><?= htmlspecialchars($row['groupe'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($row['salle_type'] ?? '—') ?></td>
                    <td>
                        <form method="POST" data-fr-confirm="Supprimer ce besoin ?" style="margin:0">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top:16px">
    <div class="card-header">
        <h3><i class="fas fa-plus"></i> Ajouter un besoin</h3>
    </div>
    <div class="card-body">
        <form method="POST" class="form-grid">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">

            <div class="form-row">
                <div class="form-group">
                    <label for="classe_id"><?= __('emploi_du_temps.class') ?> <span class="required">*</span></label>
                    <select name="classe_id" id="classe_id" class="form-control" required>
                        <option value="">-- Classe --</option>
                        <?php foreach ($classes as $cl): ?>
                            <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['nom']) ?> (<?= htmlspecialchars($cl['niveau']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="matiere_id"><?= __('emploi_du_temps.subject') ?> <span class="required">*</span></label>
                    <select name="matiere_id" id="matiere_id" class="form-control" required>
                        <option value="">-- Matière --</option>
                        <?php foreach ($matieres as $mat): ?>
                            <option value="<?= $mat['id'] ?>"><?= htmlspecialchars($mat['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="professeur_id"><?= __('emploi_du_temps.teacher') ?> <span class="required">*</span></label>
                    <select name="professeur_id" id="professeur_id" class="form-control" required>
                        <option value="">-- Professeur --</option>
                        <?php foreach ($professeurs as $prof): ?>
                            <option value="<?= $prof['id'] ?>"><?= htmlspecialchars($prof['prenom'] . ' ' . $prof['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="nb_creneaux">Nombre de créneaux / semaine <span class="required">*</span></label>
                    <input type="number" name="nb_creneaux" id="nb_creneaux" class="form-control" value="1" min="1" max="20" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="type_cours"><?= __('label.type') ?></label>
                    <select name="type_cours" id="type_cours" class="form-control">
                        <?php foreach ($typesCours as $val => $label): ?>
                            <option value="<?= $val ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="salle_type">Type de salle requis</label>
                    <select name="salle_type" id="salle_type" class="form-control">
                        <?php foreach ($typesSalle as $val => $label): ?>
                            <option value="<?= $val ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="groupe">Groupe (optionnel)</label>
                    <input type="text" name="groupe" id="groupe" class="form-control" placeholder="ex : Groupe A (vide = classe entière)">
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> <?= __('btn.add') ?></button>
                <a href="emploi_du_temps.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Retour à la grille</a>
            </div>
        </form>
    </div>
</div>

<?php
$extraScriptHtml = ob_get_clean();
include 'includes/footer.php';
?>
