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

// Fonction pour obtenir la couleur de la matière
function getMatiereColor($matiere) {
  $matiere = strtolower(trim($matiere));
  $colors = [
    'anglais' => 'anglais',
    'français' => 'francais',
    'mathématiques' => 'mathematiques',
    'histoire-géographie' => 'histoire-geographie',
    'histoire géographie' => 'histoire-geographie',
    'physique-chimie' => 'physique-chimie',
    'physique chimie' => 'physique-chimie',
    'svt' => 'svt',
    'sciences' => 'svt',
    'education physique et sportive' => 'eps',
    'eps' => 'eps',
    'latin' => 'latin',
    'arts plastiques' => 'arts',
    'musique' => 'arts',
    'technologie' => 'technologie',
    'espagnol' => 'lv2',
    'allemand' => 'lv2',
  ];
  
  foreach ($colors as $key => $value) {
    if (strpos($matiere, $key) !== false) {
      return $value;
    }
  }
  
  // Par défaut
  return 'anglais';
}

// Fonction pour obtenir le mois abrégé en français
function getMoisAbrege($mois) {
  $mois_abreges = [
    1 => 'janv.',
    2 => 'févr.',
    3 => 'mars',
    4 => 'avr.',
    5 => 'mai',
    6 => 'juin',
    7 => 'juil.',
    8 => 'août',
    9 => 'sept.',
    10 => 'oct.',
    11 => 'nov.',
    12 => 'déc.'
  ];
  
  return $mois_abreges[$mois] ?? '';
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
        default:
          $sql = 'SELECT * FROM notes WHERE date_ajout BETWEEN ? AND ? ORDER BY nom_matiere ASC, date_ajout DESC';
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
      if ($order === 'matiere') {
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
        if (isStudent()) {
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
          echo "<div class='moyenne-generale'>
                  <h3>Moyenne générale</h3>
                  <div class='moyenne-generale-value'>{$moyenne_generale}</div>
                </div>";
        }
        
        // Afficher les notes par matière
        foreach ($notes_par_matiere as $matiere => $notes) {
          $moyenne_matiere = calculerMoyenne($notes);
          $matiere_color = getMatiereColor($matiere);
          
          echo "<div class='matiere-bloc'>
                  <div class='matiere-header'>
                    <div class='matiere-color-indicator color-{$matiere_color}'></div>
                    <h3>{$matiere}</h3>
                    <div class='moyenne-matiere'>{$moyenne_matiere}</div>
                  </div>
                  <div class='matiere-notes'>";
          
          // Trier les notes par date (plus récentes en premier)
          usort($notes, function($a, $b) {
            return strtotime($b['date_ajout']) - strtotime($a['date_ajout']);
          });
          
          foreach ($notes as $note) {
            // Formater la date pour l'affichage
            $date_obj = new DateTime($note['date_ajout']);
            $jour = $date_obj->format('d');
            $mois = $date_obj->format('n');
            $mois_abrege = getMoisAbrege($mois);
            
            // Description de l'évaluation
            $description = isset($note['description']) && !empty($note['description']) 
                           ? htmlspecialchars($note['description']) 
                           : 'Évaluation';

            // Moyenne de la classe
            $moyenne_classe = isset($note['moyenne_classe']) && !empty($note['moyenne_classe']) 
                              ? $note['moyenne_classe'] 
                              : '-';
            
            echo "<div class='note-item'>
                    <div class='note-date-box'>
                      <div class='note-date-day'>{$jour}</div>
                      <div class='note-date-month'>{$mois_abrege}</div>
                    </div>
                    <div class='note-info'>
                      <div class='note-title'>{$description}</div>
                      <div class='note-moyenne-classe'>Moyenne classe: {$moyenne_classe}</div>
                    </div>
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
        // Affichage chronologique (style Pronote)
        foreach ($toutes_notes as $note) {
          // Formater la date pour l'affichage
          $date_obj = new DateTime($note['date_ajout']);
          $jour = $date_obj->format('d');
          $mois = $date_obj->format('n');
          $mois_abrege = getMoisAbrege($mois);
          $matiere_color = getMatiereColor($note['nom_matiere']);
          
          // Description de l'évaluation
          $description = isset($note['description']) && !empty($note['description']) 
                         ? htmlspecialchars($note['description']) 
                         : 'Évaluation';
          
          // Moyenne de la classe
          $moyenne_classe = isset($note['moyenne_classe']) && !empty($note['moyenne_classe']) 
                            ? $note['moyenne_classe'] 
                            : '-';
          
          echo "<div class='note'>
                  <div class='matiere-color color-{$matiere_color}'></div>
                  <div class='note-date-container'>
                    <div class='note-day'>{$jour}</div>
                    <div class='note-month'>{$mois_abrege}</div>
                  </div>
                  <div class='note-content'>
                    <div class='note-matiere'>{$note['nom_matiere']}</div>
                    <div class='note-eval'>{$description}</div>
                    <div class='note-moyenne'>Moyenne classe: {$moyenne_classe}</div>
                  </div>
                  <div class='note-value'>{$note['note']}</div>";
          
          // Afficher les boutons de modification et suppression pour les rôles autorisés
          if (canManageNotes()) {
            if (!isTeacher() || (isTeacher() && $note['nom_professeur'] == $user_fullname)) {
              echo "<div style='margin-left: 10px;'>
                      <a href='modifier_note.php?id={$note['id']}' class='button button-small'>Modifier</a>
                      <a href='supprimer_note.php?id={$note['id']}' class='button button-small' onclick='return confirm(\"Êtes-vous sûr de vouloir supprimer cette note ?\");'>Supprimer</a>
                    </div>";
            }
          }
          
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