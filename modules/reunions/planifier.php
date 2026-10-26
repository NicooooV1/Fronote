<?php
declare(strict_types=1);
/**
 * Réunions — PLANIFIER (refonte 4.6).
 * 1. choisir le type ; 2. le formulaire s'adapte : classe ou élève concerné, salle, ordre du jour
 * proposé, participants proposés (modifiables), créneaux pour une soirée parents-professeurs ;
 * 3. création + envoi des convocations.
 */
require_once __DIR__ . '/../../API/bootstrap.php';
requireAuth();
enforceModuleAccess('reunions');
require_once __DIR__ . '/includes/ReunionHub.php';
if (!ReunionHub::canOrganise()) { http_response_code(403); exit('Accès réservé aux personnels.'); }
$pdo = getPDO();
$etab = (int) \API\Core\EstablishmentContext::id();

// Participants proposés (JSON) pour un type + classe/élève : alimente le formulaire sans recharger
if (isset($_GET['proposer'])) {
    header('Content-Type: application/json; charset=utf-8');
    $hub0 = new ReunionHub($pdo);
    $eleve = (int) ($_GET['eleve'] ?? 0);
    if ($eleve && !assertUserCanReadEleve($eleve)) { $eleve = 0; }
    $rows = $hub0->proposer((string) ($_GET['type'] ?? ''), ($_GET['classe'] ?? '') ?: null, $eleve ?: null);
    $out = [];
    foreach ($rows as $p) { $out[] = $p + ['nom' => $hub0->nameOf($p['user_type'], (int) $p['user_id']), 'role_lib' => ReunionHub::ROLES[$p['role']] ?? $p['role']]; }
    echo json_encode(['participants' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

$type = isset(ReunionHub::TYPES[$_GET['type'] ?? $_POST['type'] ?? '']) ? (string) ($_GET['type'] ?? $_POST['type']) : '';
$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $type !== '') {
    if (!validateCSRFToken((string) ($_POST['csrf_token'] ?? ''))) { $erreur = 'Session expirée : rechargez la page.'; }
    else {
        $hub0 = new ReunionHub($pdo);
        $tm = ReunionHub::type($type);
        $date = (string) ($_POST['date'] ?? '');
        $hd = (string) ($_POST['heure_debut'] ?? ''); $hf = (string) ($_POST['heure_fin'] ?? '');
        $titre = trim((string) ($_POST['titre'] ?? ''));
        $classeNom = trim((string) ($_POST['classe'] ?? ''));
        $eleveId = (int) ($_POST['eleve_id'] ?? 0);
        if ($eleveId && !assertUserCanReadEleve($eleveId)) { $eleveId = 0; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $hd) || !preg_match('/^\d{2}:\d{2}$/', $hf) || $hf <= $hd) {
            $erreur = 'Date ou horaires invalides (la fin doit être après le début).';
        } elseif ($titre === '') {
            $erreur = 'Donnez un titre à la réunion.';
        } elseif (in_array($type, ['conseil_discipline', 'commission_educative', 'equipe_educative', 'entretien'], true) && !$eleveId) {
            $erreur = 'Choisissez l\'élève concerné.';
        } elseif ($type === 'conseil_classe' && $classeNom === '') {
            $erreur = 'Choisissez la classe.';
        } else {
            $classeId = null;
            if ($classeNom !== '') { $st = $pdo->prepare('SELECT id FROM classes WHERE nom = ? AND etablissement_id = ?'); $st->execute([$classeNom, $etab]); $classeId = (int) $st->fetchColumn() ?: null; }
            $salleId = (int) ($_POST['salle_id'] ?? 0) ?: null;
            $participants = [];
            foreach ((array) ($_POST['p'] ?? []) as $v) {
                if (preg_match('/^(administrateur|professeur|vie_scolaire|parent|eleve):(\d+):([a-z_]+)$/', (string) $v, $m)) { $participants[] = ['user_type' => $m[1], 'user_id' => (int) $m[2], 'role' => $m[3]]; }
            }
            $points = [];
            foreach ((array) ($_POST['pt_titre'] ?? []) as $i => $pt) { $points[] = [mb_substr((string) $pt, 0, 255), (int) ($_POST['pt_duree'][$i] ?? 10)]; }
            $creneaux = [];
            if ($type === 'parents_profs') {
                $duree = max(5, min(30, (int) ($_POST['duree_creneau'] ?? 10)));
                $q = $classeNom !== '' ? $pdo->prepare('SELECT DISTINCT id_professeur FROM professeur_classes WHERE nom_classe = ?') : $pdo->prepare('SELECT DISTINCT id_professeur FROM professeur_classes');
                $q->execute($classeNom !== '' ? [$classeNom] : []);
                $profIds = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
                // Salle : celle où le professeur fait le plus cours
                $salles = [];
                foreach ($pdo->query('SELECT e.professeur_id, s.nom, COUNT(*) n FROM emploi_du_temps e JOIN salles s ON s.id = e.salle_id GROUP BY e.professeur_id, s.nom ORDER BY n DESC')->fetchAll(PDO::FETCH_ASSOC) as $row) { $salles[(int) $row['professeur_id']] ??= $row['nom']; }
                $creneaux = ReunionHub::genererCreneaux($profIds, $hd, $hf, $duree, $salles);
                foreach ($profIds as $pid) { $participants[] = ['user_type' => 'professeur', 'user_id' => $pid, 'role' => 'professeur']; }
            }
            try {
                $id = $hub0->creer([
                    'type' => $type, 'titre' => mb_substr($titre, 0, 255), 'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
                    'date_debut' => "$date $hd:00", 'date_fin' => "$date $hf:00", 'lieu' => trim((string) ($_POST['lieu'] ?? '')) ?: null, 'salle_id' => $salleId,
                    'classe_id' => $classeId, 'eleve_id' => $eleveId ?: null, 'confidentiel' => !empty($_POST['confidentiel']),
                    'visio_url' => filter_var($_POST['visio_url'] ?? '', FILTER_VALIDATE_URL) ?: null, 'duree_creneau' => $type === 'parents_profs' ? $duree : null,
                ], $participants, $points, $creneaux);
                if (!empty($_POST['convoquer'])) { $hub0->convoquer($id); }
                header('Location: ' . ($type === 'parents_profs' ? 'soiree.php' : 'detail.php') . '?id=' . $id);
                exit;
            } catch (\Throwable $e) {
                error_log('[reunions/planifier] ' . $e->getMessage());
                $erreur = 'La réunion n\'a pas pu être créée.';
            }
        }
    }
}

$pageTitle = 'Planifier une réunion';
$pageSubtitle = $type ? ReunionHub::type($type)['label'] : 'Choisissez le type de réunion : le formulaire s\'adapte (participants, ordre du jour, créneaux).';
$pageBack = ['url' => 'modules/reunions/reunions.php', 'label' => 'Réunions'];
$headerExtraActions = '';
require_once __DIR__ . '/includes/header.php';
$h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<div class="rn rn-plan">
<?php if ($erreur): ?><div class="alert alert-danger"><?= $h($erreur) ?></div><?php endif; ?>

<?php if ($type === ''): ?>
  <div class="rn-typegrid">
    <?php foreach (ReunionHub::TYPES as $k => [$lib, $ic, $col, $dur, $conf, $desc]): ?>
    <a class="rn-typecard" href="planifier.php?type=<?= $h($k) ?>" style="--rn-c:<?= $h($col) ?>">
      <span class="rn-typecard__icon"><i class="fas <?= $h($ic) ?>"></i></span>
      <b><?= $h($lib) ?></b><span><?= $h($desc) ?></span>
      <small><i class="far fa-clock"></i> ~<?= $dur >= 60 ? intdiv($dur, 60) . ' h' . ($dur % 60 ? sprintf('%02d', $dur % 60) : '') : $dur . ' min' ?><?= $conf ? ' · <i class="fas fa-lock"></i> confidentiel' : '' ?></small>
    </a>
    <?php endforeach; ?>
  </div>
<?php else:
    $tm = ReunionHub::type($type);
    $classes = $pdo->prepare('SELECT nom FROM classes WHERE etablissement_id = ? AND actif = 1 ORDER BY nom'); $classes->execute([$etab]); $classes = $classes->fetchAll(PDO::FETCH_COLUMN);
    $sallesL = $pdo->prepare("SELECT id, nom FROM salles WHERE etablissement_id = ? AND actif = 1 ORDER BY FIELD(type, 'reunion', 'cdi', 'etude') DESC, nom"); $sallesL->execute([$etab]); $sallesL = $sallesL->fetchAll(PDO::FETCH_KEY_PAIR);
    $needEleve = in_array($type, ['conseil_discipline', 'commission_educative', 'equipe_educative', 'entretien'], true);
    $needClasse = in_array($type, ['conseil_classe', 'parents_profs', 'reunion_equipe'], true);
    $jour = (new DateTimeImmutable('+7 days'))->format('Y-m-d');
    $debut = $type === 'parents_profs' ? '16:30' : ($type === 'conseil_classe' ? '17:15' : '17:00');
    $fin = date('H:i', strtotime("2000-01-01 $debut") + $tm['duree'] * 60);
?>
  <form method="post" class="rn-form" data-rn-plan="<?= $h($type) ?>" style="--rn-c:<?= $h($tm['color']) ?>">
    <?= csrfField() ?><input type="hidden" name="type" value="<?= $h($type) ?>">
    <div class="rn-form__grid">
      <section class="rn-panel">
        <header class="rn-panel__head"><h2><i class="fas <?= $h($tm['icon']) ?>"></i> <?= $h($tm['label']) ?></h2><a class="rn-link" href="planifier.php">Changer de type</a></header>
        <label class="rn-field">Titre<input name="titre" class="form-control" required maxlength="255" value="<?= $h($_POST['titre'] ?? $tm['label']) ?>" data-rn-autotitle="<?= $h($tm['label']) ?>"></label>
        <?php if ($needClasse): ?>
        <label class="rn-field">Classe<?= $type === 'parents_profs' ? ' <small>(vide = tout l\'établissement)</small>' : '' ?>
          <select name="classe" class="form-select" data-rn-propose<?= $type === 'conseil_classe' ? ' required' : '' ?>><option value=""><?= $type === 'parents_profs' ? 'Toutes les classes' : '—' ?></option><?php foreach ($classes as $c): ?><option<?= ($_POST['classe'] ?? '') === $c ? ' selected' : '' ?>><?= $h($c) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <?php if ($needEleve): ?>
        <label class="rn-field">Élève concerné<input type="hidden" name="eleve_id" data-eleve-search data-required data-rn-propose value="<?= (int) ($_POST['eleve_id'] ?? 0) ?: '' ?>"></label>
        <?php endif; ?>
        <div class="rn-field-row">
          <label class="rn-field">Date<input type="date" name="date" class="form-control" required value="<?= $h($_POST['date'] ?? $jour) ?>"></label>
          <label class="rn-field">Début<input type="time" name="heure_debut" class="form-control" required value="<?= $h($_POST['heure_debut'] ?? $debut) ?>"></label>
          <label class="rn-field">Fin<input type="time" name="heure_fin" class="form-control" required value="<?= $h($_POST['heure_fin'] ?? $fin) ?>"></label>
        </div>
        <?php if ($type === 'parents_profs'): ?>
        <label class="rn-field">Durée d'un rendez-vous<select name="duree_creneau" class="form-select"><?php foreach ([5, 10, 15, 20] as $m): ?><option value="<?= $m ?>"<?= $m === 10 ? ' selected' : '' ?>><?= $m ?> minutes</option><?php endforeach; ?></select>
          <small class="rn-muted">Chaque professeur des classes concernées reçoit ses créneaux, dans la salle où il fait le plus cours.</small></label>
        <?php else: ?>
        <div class="rn-field-row">
          <label class="rn-field">Salle<select name="salle_id" class="form-select"><option value="">—</option><?php foreach ($sallesL as $sid => $sn): ?><option value="<?= (int) $sid ?>"><?= $h($sn) ?></option><?php endforeach; ?></select></label>
          <label class="rn-field">Ou lieu / visio<input name="lieu" class="form-control" placeholder="Ex. : bureau de la CPE"></label>
        </div>
        <?php endif; ?>
        <label class="rn-field">Description <small>(facultatif)</small><textarea name="description" class="form-control" rows="3" placeholder="Contexte, documents à préparer…"><?= $h($_POST['description'] ?? '') ?></textarea></label>
        <label class="rn-check"><input type="checkbox" name="confidentiel" value="1"<?= $tm['confidentiel'] ? ' checked' : '' ?>> Confidentielle (visible des seuls participants, de la direction et de la vie scolaire)</label>
      </section>

      <?php if ($type !== 'parents_profs'): ?>
      <section class="rn-panel">
        <header class="rn-panel__head"><h2><i class="fas fa-list-ol"></i> Ordre du jour</h2><span class="rn-muted" data-rn-total></span></header>
        <ol class="rn-ptlist" data-rn-points>
          <?php foreach ($tm['points'] ?: [['', 15]] as [$pt, $du]): ?>
          <li><input name="pt_titre[]" class="form-control" value="<?= $h($pt) ?>" placeholder="Intitulé du point"><input name="pt_duree[]" class="form-control" type="number" min="1" max="240" value="<?= (int) $du ?>" aria-label="Minutes"><button type="button" class="rn-icon-btn" data-rn-rm aria-label="Retirer ce point"><i class="fas fa-xmark"></i></button></li>
          <?php endforeach; ?>
        </ol>
        <button type="button" class="rn-link" data-rn-addpt><i class="fas fa-plus"></i> Ajouter un point</button>
      </section>
      <section class="rn-panel">
        <header class="rn-panel__head"><h2><i class="fas fa-users"></i> Participants</h2><span class="rn-muted" data-rn-pcount></span></header>
        <p class="rn-muted" data-rn-phint><?= $needEleve ? 'Choisissez l\'élève : les participants habituels seront proposés.' : ($needClasse ? 'Choisissez la classe : l\'équipe sera proposée.' : 'Participants proposés :') ?></p>
        <ul class="rn-pick" data-rn-people></ul>
      </section>
      <?php endif; ?>
    </div>
    <div class="rn-form__foot">
      <label class="rn-check"><input type="checkbox" name="convoquer" value="1" checked> Envoyer les convocations dès la création</label>
      <a class="btn btn-secondary" href="reunions.php">Annuler</a>
      <button class="btn btn-primary" type="submit"><i class="fas fa-check"></i> Créer la réunion</button>
    </div>
  </form>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
