<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Nous n'avons plus besoin de démarrer la session, car c'est fait dans Auth
include 'includes/header.php'; 
include 'includes/db.php';
include 'includes/auth.php';

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_role = $user['profil']; 

// Fonction pour calculer la moyenne
function calculerMoyenne($notes) {
  if (empty($notes)) return 0;
  
  $somme_ponderee = 0;
  $somme_coefficients = 0;
  
  foreach ($notes as $note) {
    $coef = isset($note['coefficient']) ? $note['coefficient'] : 1;
    $somme_ponderee += $note['note'] * $coef;
    $somme_coefficients += $coef;
  }
  
  return $somme_coefficients > 0 ? round($somme_ponderee / $somme_coefficients, 2) : 0;
}

// Fonction pour obtenir la note la plus haute
function getNoteMax($notes) {
  if (empty($notes)) return 0;
  $max = 0;
  foreach ($notes as $note) {
    if ($note['note'] > $max) $max = $note['note'];
  }
  return $max;
}

// Fonction pour obtenir la note la plus basse
function getNoteMin($notes) {
  if (empty($notes)) return 0;
  $min = 20;
  foreach ($notes as $note) {
    if ($note['note'] < $min) $min = $note['note'];
  }
  return $min;
}

// Déterminer le trimestre actuel (peut être personnalisé selon vos besoins)
function getTrimestre() {
  $mois = date('n');
  if ($mois >= 9 && $mois <= 12) return 1; // Septembre à Décembre
  if ($mois >= 1 && $mois <= 3) return 2;  // Janvier à Mars
  return 3; // Avril à Juin
}

$trimestre_actuel = isset($_GET['trimestre']) ? intval($_GET['trimestre']) : getTrimestre();
?>

<div class="container">
  <div class="user-info">
    <p>Connecté en tant que: <?= htmlspecialchars($user_fullname) ?> (<?= htmlspecialchars($user_role) ?>)</p>
  </div>

  <?php if (canManageNotes()): ?>
  <div class="actions">
    <a href="ajouter_note.php" class="button">Ajouter une note</a>
  </div>
  <?php endif; ?>
  
  <div class="filter-area">
    <div class="trimestre-selector">
      <h3>Détail des notes</h3>
      <select id="trimestre-select" onchange="window.location.href='?trimestre='+this.value">
        <option value="1" <?= $trimestre_actuel == 1 ? 'selected' : '' ?>>Trimestre 1</option>
        <option value="2" <?= $trimestre_actuel == 2 ? 'selected' : '' ?>>Trimestre 2</option>
        <option value="3" <?= $trimestre_actuel == 3 ? 'selected' : '' ?>>Trimestre 3</option>
      </select>
    </div>
    
    <div class="filter-buttons">
      <a href="?order=matiere&trimestre=<?= $trimestre_actuel ?>" class="button <?php echo (isset($_GET['order']) && $_GET['order'] == 'matiere') || !isset($_GET['order']) ? '' : 'button-secondary'; ?>">Par matière</a>
      <a href="?order=date&trimestre=<?= $trimestre_actuel ?>" class="button <?php echo (isset($_GET['order']) && $_GET['order'] == 'date') ? '' : 'button-secondary'; ?>">Par ordre chronologique</a>
      <?php if (!isStudent()): ?>
      <a href="?order=classe&trimestre=<?= $trimestre_actuel ?>" class="button <?php echo (isset($_GET['order']) && $_GET['order'] == 'classe') ? '' : 'button-secondary'; ?>">Par classe</a>
      <a href="?order=eleve&trimestre=<?= $trimestre_actuel ?>" class="button <?php echo (isset($_GET['order']) && $_GET['order'] == 'eleve') ? '' : 'button-secondary'; ?>">Par élève</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="notes">
    <?php
    $order = isset($_GET['order']) ? $_GET['order'] : 'matiere';
    
    // Déterminer les dates de début et fin du trimestre
    $annee = date('Y');
    switch ($trimestre_actuel) {
      case 1:
        $date_debut = $annee . '-09-01';
        $date_fin = $annee . '-12-31';
        break;
      case 2:
        $date_debut = $annee . '-01-01';
        $date_fin = $annee . '-03-31';
        break;
      case 3:
        $date_debut = $annee . '-04-01';
        $date_fin = $annee . '-07-31';
        break;
      default:
        $date_debut = $annee . '-09-01';
        $date_fin = $annee . '-12-31';
    }
    
    // Si c'est un élève, on ne montre que ses propres notes
    if (isStudent()) {
      // Pour les élèves, on utilise seulement le prénom stocké dans la session
      $student_firstname = $user['prenom'];
      
      switch ($order) {
        case 'date':
          $sql = 'SELECT * FROM notes WHERE nom_eleve = ? AND date_ajout BETWEEN ? AND ? ORDER BY date_ajout DESC';
          break;
        default:
          $sql = 'SELECT * FROM notes WHERE nom_eleve = ? AND date_ajout BETWEEN ? AND ? ORDER BY nom_matiere ASC, date_ajout DESC';
      }
      
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$student_firstname, $date_debut, $date_fin]);
    } 
    // Si c'est un professeur, on montre toutes les notes qu'il a ajoutées
    elseif (isTeacher()) {
      switch ($order) {
        case 'date':
          $sql = 'SELECT * FROM notes WHERE nom_professeur = ? AND date_ajout BETWEEN ? AND ? ORDER BY date_ajout DESC';
          break;
        case 'classe':
          $sql = 'SELECT * FROM notes WHERE nom_professeur = ? AND date_ajout BETWEEN ? AND ? ORDER BY classe ASC, nom_eleve ASC, date_ajout DESC';
          break;
        case 'eleve':
          $sql = 'SELECT * FROM notes WHERE nom_professeur = ? AND date_ajout BETWEEN ? AND ? ORDER BY nom_eleve ASC, nom_matiere ASC, date_ajout DESC';
          break;
        default:
          $sql = 'SELECT * FROM notes WHERE nom_professeur = ? AND date_ajout BETWEEN ? AND ? ORDER BY nom_matiere ASC, date_ajout DESC';
      }
      
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$user_fullname, $date_debut, $date_fin]);
    }
    // Sinon (administrateur, vie scolaire ou autre), on montre toutes les notes
    else {
      switch ($order) {
        case 'date':
          $sql = 'SELECT * FROM notes WHERE date_ajout BETWEEN ? AND ? ORDER BY date_ajout DESC';
          break;
        case 'classe':
          $sql = 'SELECT * FROM notes WHERE date_ajout BETWEEN ? AND ? ORDER BY classe ASC, nom_eleve ASC, date_ajout DESC';
          break;
        case 'eleve':
          $sql = 'SELECT * FROM notes WHERE date_ajout BETWEEN ? AND ? ORDER BY nom_eleve ASC, nom_matiere ASC, date_ajout DESC';
          break;
        default:
          $sql = 'SELECT * FROM notes WHERE date_ajout BETWEEN ? AND ? ORDER BY nom_matiere ASC, nom_eleve ASC, date_ajout DESC';
      }
      
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$date_debut, $date_fin]);
    }
    
    // Récupérer toutes les notes
    $toutes_notes = $stmt->fetchAll();
    
    // Si aucune note trouvée
    if (empty($toutes_notes)) {
      echo "<p>Aucune note n'a été ajoutée pour ce trimestre.</p>";
    } else {
      if ($order === 'matiere' || isStudent()) {
        // Regrouper les notes par matière
        $notes_par_matiere = [];
        foreach ($toutes_notes as $note) {
          $matiere = $note['nom_matiere'];
          if (!isset($notes_par_matiere[$matiere])) {
            $notes_par_matiere[$matiere] = [];
          }
          $notes_par_matiere[$matiere][] = $note;
        }
        
        // Calculer la moyenne générale (pour les élèves uniquement)
        $moyennes_matieres = [];
        $coefficients_matieres = [];
        foreach ($notes_par_matiere as $matiere => $notes) {
          $moyennes_matieres[$matiere] = calculerMoyenne($notes);
          
          // Calculer le coefficient total pour cette matière
          $coef_total = 0;
          foreach ($notes as $note) {
            $coef_total += isset($note['coefficient']) ? $note['coefficient'] : 1;
          }
          $coefficients_matieres[$matiere] = $coef_total;
        }
        
        // Calculer la moyenne générale
        $moyenne_generale = 0;
        $coef_general = 0;
        foreach ($moyennes_matieres as $matiere => $moyenne) {
          $coef = $coefficients_matieres[$matiere];
          $moyenne_generale += $moyenne * $coef;
          $coef_general += $coef;
        }
        $moyenne_generale = $coef_general > 0 ? round($moyenne_generale / $coef_general, 2) : 0;
        
        // Afficher la moyenne générale pour les élèves
        if (isStudent()) {
          echo "<div class='moyenne-generale'>
                  <h3>Moyenne générale: {$moyenne_generale}</h3>
                </div>";
        }
        
        // Afficher les notes par matière
        foreach ($notes_par_matiere as $matiere => $notes) {
          $moyenne_matiere = calculerMoyenne($notes);
          $note_max = getNoteMax($notes);
          $note_min = getNoteMin($notes);
          
          echo "<div class='matiere-bloc'>
                  <div class='matiere-header'>
                    <h3>{$matiere}</h3>
                    <div class='moyenne-matiere'>{$moyenne_matiere}</div>
                  </div>";
          
          if (isStudent()) {
            echo "<div class='matiere-stats'>
                    <div>Moyenne classe: " . (isset($notes[0]['moyenne_classe']) ? $notes[0]['moyenne_classe'] : '-') . "</div>
                    <div>Note la plus haute: {$note_max}</div>
                    <div>Note la plus basse: {$note_min}</div>
                  </div>";
          }
          
          echo "<div class='matiere-notes'>";
          
          // Trier les notes par date (plus récentes en premier)
          usort($notes, function($a, $b) {
            return strtotime($b['date_ajout']) - strtotime($a['date_ajout']);
          });
          
          foreach ($notes as $note) {
            // Formater la date pour l'affichage
            $date_obj = new DateTime($note['date_ajout']);
            $jour = $date_obj->format('d');
            $mois_abbr = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'][$date_obj->format('n') - 1];
            $date_format = $jour . ' ' . $mois_abbr;
            
            echo "<div class='note-item'>
                    <div class='note-date'>{$date_format}</div>
                    <div class='note-description'>" . (isset($note['description']) ? htmlspecialchars($note['description']) : 'Évaluation') . "</div>
                    <div class='note-moyenne-classe'>Moyenne classe: " . (isset($note['moyenne_classe']) ? $note['moyenne_classe'] : '-') . "</div>
                    <div class='note-score'>{$note['note']}</div>";
            
            // Afficher les boutons de modification et suppression pour les rôles autorisés
            if (canManageNotes()) {
              if (!isTeacher() || (isTeacher() && $note['nom_professeur'] == $user_fullname)) {
                echo "<div class='note-actions'>
                        <a href='modifier_note.php?id={$note['id']}' class='button button-small'>Modifier</a>
                        <a href='supprimer_note.php?id={$note['id']}' class='button button-small' onclick='return confirm(\"Êtes-vous sûr de vouloir supprimer cette note ?\");'>Supprimer</a>
                      </div>";
              }
            }
            
            echo "</div>";
          }
          
          echo "</div></div>";
        }
      } else {
        // Afficher les notes sans regroupement par matière
        $current_group = '';
        
        foreach ($toutes_notes as $note) {
          // Déterminer le groupe actuel en fonction du tri
          switch ($order) {
            case 'classe':
              $group = $note['classe'];
              break;
            case 'eleve':
              $group = $note['nom_eleve'];
              break;
            default:
              $group = date('d/m/Y', strtotime($note['date_ajout']));
          }
          
          // Afficher l'en-tête du groupe si on change de groupe
          if ($group != $current_group) {
            if ($current_group != '') {
              echo "</div>"; // Fermer le div du groupe précédent
            }
            echo "<div class='notes-group'>
                    <h3>{$group}</h3>";
            $current_group = $group;
          }
          
          // Formater la date pour l'affichage
          $date_obj = new DateTime($note['date_ajout']);
          $date_format = $date_obj->format('d/m/Y');
          
          echo "<div class='note'>
                  <div class='note-header'>
                    <div class='note-title'>{$note['nom_matiere']}" . (isset($note['description']) ? " - " . htmlspecialchars($note['description']) : "") . "</div>
                    <div class='note-date'>{$date_format}</div>
                  </div>
                  <div class='note-details'>
                    <div class='note-detail'>
                      <div>Classe:</div>
                      <div class='note-value'>{$note['classe']}</div>
                    </div>
                    <div class='note-detail'>
                      <div>Élève:</div>
                      <div class='note-value'>{$note['nom_eleve']}</div>
                    </div>
                    <div class='note-detail'>
                      <div>Professeur:</div>
                      <div class='note-value'>{$note['nom_professeur']}</div>
                    </div>
                    <div class='note-detail'>
                      <div>Coefficient:</div>
                      <div class='note-value'>" . (isset($note['coefficient']) ? $note['coefficient'] : '1') . "</div>
                    </div>
                    <div class='note-detail'>
                      <div>Note:</div>
                      <div class='note-score'>{$note['note']}/20</div>
                    </div>
                  </div>";
          
          // Afficher les boutons de modification et suppression pour les rôles autorisés
          if (canManageNotes()) {
            if (!isTeacher() || (isTeacher() && $note['nom_professeur'] == $user_fullname)) {
              echo "<div style='margin-top: 10px; display: flex; gap: 10px;'>
                      <a href='modifier_note.php?id={$note['id']}' class='button button-secondary'>Modifier</a>
                      <a href='supprimer_note.php?id={$note['id']}' class='button button-secondary' onclick='return confirm(\"Êtes-vous sûr de vouloir supprimer cette note ?\");'>Supprimer</a>
                    </div>";
            }
          }
          
          echo "</div>";
        }
        
        // Fermer le dernier groupe
        if ($current_group != '') {
          echo "</div>";
        }
      }
    }
    ?>
  </div>
</div>

</body>
</html>

<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>