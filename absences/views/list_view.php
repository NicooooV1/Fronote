<?php
// Fichier inclus depuis absences.php

// Vérifier si nous avons des absences à afficher
if (empty($absences)) {
    echo '<div class="no-data-message">
        <i class="fas fa-info-circle"></i>
        <p>Aucune absence ne correspond aux critères sélectionnés.</p>
    </div>';
    return;
}

// Tri des absences (par défaut par date)
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'date';
$order = isset($_GET['order']) ? $_GET['order'] : 'desc';

if ($sort === 'nom') {
    usort($absences, function($a, $b) use ($order) {
        $result = strcmp($a['nom'], $b['nom']);
        return $order === 'asc' ? $result : -$result;
    });
} elseif ($sort === 'classe') {
    usort($absences, function($a, $b) use ($order) {
        $result = strcmp($a['classe'], $b['classe']);
        return $order === 'asc' ? $result : -$result;
    });
} elseif ($sort === 'duree') {
    usort($absences, function($a, $b) use ($order) {
        $a_duree = strtotime($a['date_fin']) - strtotime($a['date_debut']);
        $b_duree = strtotime($b['date_fin']) - strtotime($b['date_debut']);
        $result = $a_duree - $b_duree;
        return $order === 'asc' ? $result : -$result;
    });
} else { // Par date
    usort($absences, function($a, $b) use ($order) {
        $result = strtotime($a['date_debut']) - strtotime($b['date_debut']);
        return $order === 'asc' ? $result : -$result;
    });
}

// Pagination
$absences_par_page = 20;
$nombre_absences = count($absences);
$nombre_pages = ceil($nombre_absences / $absences_par_page);
$page_courante = isset($_GET['page']) ? max(1, min($nombre_pages, intval($_GET['page']))) : 1;
$debut_absences = ($page_courante - 1) * $absences_par_page;
$absences_page = array_slice($absences, $debut_absences, $absences_par_page);
?>

<div class="absences-list">
  <div class="list-header">
    <div class="list-row header-row">
      <?php if (isAdmin() || isVieScolaire() || isTeacher()): ?>
        <div class="list-cell header-cell">Élève</div>
        <div class="list-cell header-cell">Classe</div>
      <?php endif; ?>
      <div class="list-cell header-cell">Date</div>
      <div class="list-cell header-cell">Durée</div>
      <div class="list-cell header-cell">Type</div>
      <div class="list-cell header-cell">Justifiée</div>
      <div class="list-cell header-cell">Actions</div>
    </div>
  </div>
  
  <div class="list-body">
    <?php foreach ($absences_page as $absence): ?>
      <?php
      // Calcul de la durée
      $debut = new DateTime($absence['date_debut']);
      $fin = new DateTime($absence['date_fin']);
      $duree = $debut->diff($fin);
      $duree_str = '';
      
      if ($duree->days > 0) {
          $duree_str .= $duree->days . 'j ';
      }
      if ($duree->h > 0) {
          $duree_str .= $duree->h . 'h ';
      }
      if ($duree->i > 0) {
          $duree_str .= $duree->i . 'min';
      }
      if (empty($duree_str)) {
          $duree_str = 'n/a';
      }
      ?>
      <div class="list-row absence-row <?= $absence['justifie'] ? 'justified' : 'not-justified' ?>">
        <?php if (isAdmin() || isVieScolaire() || isTeacher()): ?>
          <div class="list-cell">
            <?= htmlspecialchars($absence['nom'] . ' ' . $absence['prenom']) ?>
          </div>
          <div class="list-cell">
            <?= htmlspecialchars($absence['classe']) ?>
          </div>
        <?php endif; ?>
        <div class="list-cell">
          <?= $debut->format('d/m/Y H:i') ?>
          <br>
          <small>au <?= $fin->format('d/m/Y H:i') ?></small>
        </div>
        <div class="list-cell">
          <?= $duree_str ?>
        </div>
        <div class="list-cell">
          <span class="badge badge-<?= $absence['type_absence'] ?>">
            <?= ucfirst($absence['type_absence']) ?>
          </span>
        </div>
        <div class="list-cell">
          <?php if ($absence['justifie']): ?>
            <span class="badge badge-success">Oui</span>
          <?php else: ?>
            <span class="badge badge-danger">Non</span>
          <?php endif; ?>
        </div>
        <div class="list-cell">
          <div class="action-buttons">
            <a href="details_absence.php?id=<?= $absence['id'] ?>" class="btn-icon" title="Voir les détails">
              <i class="fas fa-eye"></i>
            </a>
            <?php if (canManageAbsences()): ?>
              <a href="modifier_absence.php?id=<?= $absence['id'] ?>" class="btn-icon" title="Modifier">
                <i class="fas fa-edit"></i>
              </a>
              <a href="supprimer_absence.php?id=<?= $absence['id'] ?>" class="btn-icon btn-danger" title="Supprimer" 
                 onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette absence ?');">
                <i class="fas fa-trash"></i>
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  
  <?php if ($nombre_pages > 1): ?>
    <div class="pagination">
      <?php if ($page_courante > 1): ?>
        <a href="?view=list&sort=<?= $sort ?>&order=<?= $order ?>&page=<?= $page_courante - 1 ?>&date_debut=<?= $date_debut ?>&date_fin=<?= $date_fin ?>&classe=<?= $classe ?>&justifie=<?= $justifie ?>" class="page-link">
          <i class="fas fa-chevron-left"></i> Précédent
        </a>
      <?php endif; ?>
      
      <div class="page-numbers">
        <?php for ($i = max(1, $page_courante - 2); $i <= min($nombre_pages, $page_courante + 2); $i++): ?>
          <a href="?view=list&sort=<?= $sort ?>&order=<?= $order ?>&page=<?= $i ?>&date_debut=<?= $date_debut ?>&date_fin=<?= $date_fin ?>&classe=<?= $classe ?>&justifie=<?= $justifie ?>" 
             class="page-number <?= $i === $page_courante ? 'active' : '' ?>">
            <?= $i ?>
          </a>
        <?php endfor; ?>
      </div>
      
      <?php if ($page_courante < $nombre_pages): ?>
        <a href="?view=list&sort=<?= $sort ?>&order=<?= $order ?>&page=<?= $page_courante + 1 ?>&date_debut=<?= $date_debut ?>&date_fin=<?= $date_fin ?>&classe=<?= $classe ?>&justifie=<?= $justifie ?>" class="page-link">
          Suivant <i class="fas fa-chevron-right"></i>
        </a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>