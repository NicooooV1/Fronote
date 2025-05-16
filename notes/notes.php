<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Inclusions
include 'includes/header.php'; 
include 'includes/db.php';
include 'includes/auth.php';

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_role = $user['profil']; 
$classe_active = isset($_GET['classe']) ? $_GET['classe'] : '';
$eleve_actif = isset($_GET['eleve']) ? $_GET['eleve'] : '';

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

// Déterminer le trimestre actuel
function getTrimestre() {
  $mois = date('n');
  if ($mois >= 9 && $mois <= 12) return 1; // Septembre à Décembre
  if ($mois >= 1 && $mois <= 3) return 2;  // Janvier à Mars
  return 3; // Avril à Juin
}

$trimestre_actuel = isset($_GET['trimestre']) ? intval($_GET['trimestre']) : getTrimestre();

// Récupérer la matière enseignée par le professeur
$matiere_prof = '';
if (isTeacher()) {
  $stmt_prof = $pdo->prepare('SELECT matiere FROM professeurs WHERE nom = ? AND prenom = ?');
  $stmt_prof->execute([$user['nom'], $user['prenom']]);
  $prof_data = $stmt_prof->fetch();
  $matiere_prof = $prof_data ? $prof_data['matiere'] : '';
}

// Récupérer toutes les classes de l'établissement
$classes = [];
$stmt_all_classes = $pdo->query('SELECT DISTINCT classe FROM notes ORDER BY classe');
$all_classes = $stmt_all_classes->fetchAll(PDO::FETCH_COLUMN);

// Pour les professeurs, récupérer uniquement les classes qu'ils enseignent
$classe_enseignees = [];
if (isTeacher()) {
  $stmt_classes_prof = $pdo->prepare('SELECT DISTINCT classe FROM notes WHERE nom_professeur = ? ORDER BY classe');
  $stmt_classes_prof->execute([$user_fullname]);
  $classe_enseignees = $stmt_classes_prof->fetchAll(PDO::FETCH_COLUMN);
}

// Vérifier si le professeur enseigne dans la classe sélectionnée
$acces_autorise = true;
if (isTeacher() && !empty($classe_active)) {
  $acces_autorise = in_array($classe_active, $classe_enseignees);
}

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
?>

<div class="container">
  <div class="user-info">
    <p>Connecté en tant que: <?= htmlspecialchars($user_fullname) ?> (<?= htmlspecialchars($user_role) ?>)</p>
    <?php if (isTeacher() && !empty($matiere_prof)): ?>
    <p>Matière enseignée: <strong><?= htmlspecialchars($matiere_prof) ?></strong></p>
    <?php endif; ?>
  </div>

  <?php if (canManageNotes()): ?>
  <div class="actions">
    <a href="ajouter_note.php" class="button">Ajouter une note</a>
  </div>
  <?php endif; ?>
  
  <div class="filter-area">
    <div class="trimestre-selector">
      <h3>Détail des notes</h3>
      <select id="trimestre-select" onchange="window.location.href='?trimestre='+this.value<?= $classe_active ? '&classe='.$classe_active : '' ?><?= $eleve_actif ? '&eleve='.$eleve_actif : '' ?>">
        <option value="1" <?= $trimestre_actuel == 1 ? 'selected' : '' ?>>Trimestre 1</option>
        <option value="2" <?= $trimestre_actuel == 2 ? 'selected' : '' ?>>Trimestre 2</option>
        <option value="3" <?= $trimestre_actuel == 3 ? 'selected' : '' ?>>Trimestre 3</option>
      </select>
    </div>
  </div>

  <?php if (isStudent()): ?>
    <!-- INTERFACE ÉLÈVE -->
    <?php
    // Récupérer les notes de l'élève pour le trimestre en cours
    $student_firstname = $user['prenom'];
    $stmt = $pdo->prepare('SELECT * FROM notes WHERE nom_eleve = ? AND date_ajout BETWEEN ? AND ? ORDER BY nom_matiere ASC, date_ajout DESC');
    $stmt->execute([$student_firstname, $date_debut, $date_fin]);
    $toutes_notes = $stmt->fetchAll();
    
    // Si aucune note trouvée
    if (empty($toutes_notes)) {
      echo "<p>Aucune note n'a été ajoutée pour ce trimestre.</p>";
    } else {
      // Regrouper les notes par matière
      $notes_par_matiere = [];
      foreach ($toutes_notes as $note) {
        $matiere = $note['nom_matiere'];
        if (!isset($notes_par_matiere[$matiere])) {
          $notes_par_matiere[$matiere] = [];
        }
        $notes_par_matiere[$matiere][] = $note;
      }
      
      // Calculer la moyenne générale
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
      
      // Afficher la moyenne générale
      echo "<div class='moyenne-generale'>
              <h3>Moyenne générale</h3>
              <div class='moyenne-generale-value'>{$moyenne_generale}</div>
            </div>";
      
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
                  <div class='note-score'>{$note['note']}</div>
                </div>";
        }
        
        echo "</div></div>";
      }
    }
    ?>
  
  <?php elseif (isTeacher() || isAdmin() || isVieScolaire()): ?>
    <!-- INTERFACE PROFESSEUR / ADMIN / VIE SCOLAIRE -->
    
    <!-- Navigation des classes -->
    <div class="classes-navigation">
      <h3>Classes de l'établissement</h3>
      <div class="classes-list">
        <?php foreach ($all_classes as $classe): ?>
          <?php 
            $classe_css = '';
            // Pour les professeurs, marquer les classes qu'ils n'enseignent pas
            if (isTeacher() && !in_array($classe, $classe_enseignees)) {
              $classe_css = 'class="classe-non-enseignee"';
            }
            // Marquer la classe active
            if ($classe == $classe_active) {
              $classe_css = 'class="classe-active"';
            }
          ?>
          <a href="?classe=<?= $classe ?>&trimestre=<?= $trimestre_actuel ?>" <?= $classe_css ?>><?= $classe ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    
    <?php if (!empty($classe_active)): ?>
      <!-- Affichage pour une classe spécifique -->
      <?php if (isTeacher() && !$acces_autorise): ?>
        <!-- Message si le professeur n'enseigne pas dans cette classe -->
        <div class="message-erreur">
          <p>Vous n'êtes pas professeur dans la classe <?= htmlspecialchars($classe_active) ?>.</p>
        </div>
      <?php else: ?>
        <!-- Affichage des élèves de la classe si autorisé -->
        <div class="classe-details">
          <h3>Élèves de la classe <?= htmlspecialchars($classe_active) ?> - Trimestre <?= $trimestre_actuel ?></h3>
          
          <?php
          // Récupérer tous les élèves de cette classe
          $stmt_eleves = $pdo->prepare("SELECT DISTINCT nom_eleve FROM notes WHERE classe = ? ORDER BY nom_eleve");
          $stmt_eleves->execute([$classe_active]);
          $eleves = $stmt_eleves->fetchAll(PDO::FETCH_COLUMN);
          
          // Pour un professeur, on ne montre que les moyennes de sa matière
          if (isTeacher() && !empty($matiere_prof)) {
            // Récupérer les notes du trimestre pour sa matière uniquement
            $stmt_notes_matiere = $pdo->prepare("SELECT * FROM notes WHERE classe = ? AND nom_matiere = ? AND date_ajout BETWEEN ? AND ?");
            $stmt_notes_matiere->execute([$classe_active, $matiere_prof, $date_debut, $date_fin]);
            $notes_matiere = $stmt_notes_matiere->fetchAll();
            
            // Regrouper par élève
            $notes_par_eleve = [];
            foreach ($notes_matiere as $note) {
              $eleve = $note['nom_eleve'];
              if (!isset($notes_par_eleve[$eleve])) {
                $notes_par_eleve[$eleve] = [];
              }
              $notes_par_eleve[$eleve][] = $note;
            }
            
            // Calcul des moyennes par élève
            $moyennes_eleves = [];
            foreach ($notes_par_eleve as $eleve => $notes) {
              $moyennes_eleves[$eleve] = calculerMoyenne($notes);
            }
            
            // Afficher la liste des élèves et leur moyenne
            if (empty($eleve_actif)) {
              // Vue liste d'élèves avec moyennes
              echo "<div class='eleves-liste'>";
              if (empty($notes_matiere)) {
                echo "<p>Aucune note enregistrée pour cette classe dans votre matière durant ce trimestre.</p>";
              } else {
                echo "<table class='tableau-moyennes'>
                      <thead>
                        <tr>
                          <th>Élève</th>
                          <th>Moyenne en " . htmlspecialchars($matiere_prof) . "</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody>";
                
                foreach ($eleves as $eleve) {
                  $moyenne = isset($moyennes_eleves[$eleve]) ? $moyennes_eleves[$eleve] : '-';
                  $moyenne_class = '';
                  
                  // Coloration des moyennes
                  if ($moyenne != '-') {
                    if ($moyenne >= 15) {
                      $moyenne_class = 'class="moyenne-tres-bien"';
                    } elseif ($moyenne >= 12) {
                      $moyenne_class = 'class="moyenne-bien"';
                    } elseif ($moyenne >= 10) {
                      $moyenne_class = 'class="moyenne-assez-bien"';
                    } elseif ($moyenne < 10) {
                      $moyenne_class = 'class="moyenne-insuffisant"';
                    }
                  }
                  
                  echo "<tr>
                        <td>" . htmlspecialchars($eleve) . "</td>
                        <td {$moyenne_class}>" . $moyenne . "</td>
                        <td><a href='?classe=" . $classe_active . "&eleve=" . $eleve . "&trimestre=" . $trimestre_actuel . "' class='button button-small'>Voir détail</a></td>
                      </tr>";
                }
                
                echo "</tbody>
                    </table>";
              }
              echo "</div>";
            } else {
              // Vue détaillée pour un élève spécifique
              // Vérifier si l'élève existe dans cette classe
              if (!in_array($eleve_actif, $eleves)) {
                echo "<p>Cet élève n'existe pas dans cette classe.</p>";
              } else {
                // Récupérer les notes de l'élève pour la matière du professeur
                $notes_eleve = isset($notes_par_eleve[$eleve_actif]) ? $notes_par_eleve[$eleve_actif] : [];
                
                // Afficher les détails de l'élève
                echo "<div class='eleve-detail'>
                      <div class='eleve-header'>
                        <h4>" . htmlspecialchars($eleve_actif) . " - " . htmlspecialchars($matiere_prof) . "</h4>
                        <a href='?classe=" . $classe_active . "&trimestre=" . $trimestre_actuel . "' class='button button-secondary button-small'>Retour à la liste</a>
                      </div>";
                
                if (empty($notes_eleve)) {
                  echo "<p>Aucune note enregistrée pour cet élève dans votre matière durant ce trimestre.</p>";
                } else {
                  // Calcul de la moyenne
                  $moyenne_eleve = calculerMoyenne($notes_eleve);
                  echo "<div class='eleve-moyenne'>Moyenne: <span>" . $moyenne_eleve . "</span></div>";
                  
                  // Trier les notes par date (plus récentes en premier)
                  usort($notes_eleve, function($a, $b) {
                    return strtotime($b['date_ajout']) - strtotime($a['date_ajout']);
                  });
                  
                  // Afficher chaque note
                  echo "<div class='eleve-notes'>";
                  foreach ($notes_eleve as $note) {
                    // Formater la date pour l'affichage
                    $date_obj = new DateTime($note['date_ajout']);
                    $date_format = $date_obj->format('d/m/Y');
                    
                    // Description de l'évaluation
                    $description = isset($note['description']) && !empty($note['description']) 
                                  ? htmlspecialchars($note['description']) 
                                  : 'Évaluation';
                    
                    // Coefficient
                    $coefficient = isset($note['coefficient']) ? $note['coefficient'] : 1;
                    
                    echo "<div class='note-detail-item'>
                          <div class='note-detail-date'>" . $date_format . "</div>
                          <div class='note-detail-desc'>" . $description . "</div>
                          <div class='note-detail-coef'>Coef. " . $coefficient . "</div>
                          <div class='note-detail-value'>" . $note['note'] . "/20</div>";
                    
                    // Afficher les boutons de modification et suppression
                    if (canManageNotes()) {
                      echo "<div class='note-detail-actions'>
                            <a href='modifier_note.php?id=" . $note['id'] . "' class='button button-small'>Modifier</a>
                            <a href='supprimer_note.php?id=" . $note['id'] . "' class='button button-small' onclick='return confirm(\"Êtes-vous sûr de vouloir supprimer cette note ?\");'>Supprimer</a>
                          </div>";
                    }
                    
                    echo "</div>";
                  }
                  echo "</div>";
                }
                
                echo "</div>";
              }
            }
          } else {
            // Pour les administrateurs ou vie scolaire, afficher toutes les matières
            if (empty($eleves)) {
              echo "<p>Aucun élève trouvé dans cette classe.</p>";
            } else {
              echo "<div class='admin-classe-vue'>
                    <ul class='eleves-admin-liste'>";
              
              foreach ($eleves as $eleve) {
                echo "<li><a href='?classe=" . $classe_active . "&eleve=" . $eleve . "&trimestre=" . $trimestre_actuel . "'>" . htmlspecialchars($eleve) . "</a></li>";
              }
              
              echo "</ul>
                  </div>";
              
              // Si un élève est sélectionné, afficher ses notes
              if (!empty($eleve_actif) && in_array($eleve_actif, $eleves)) {
                // Récupérer toutes les notes de l'élève
                $stmt_notes_eleve = $pdo->prepare("SELECT * FROM notes WHERE classe = ? AND nom_eleve = ? AND date_ajout BETWEEN ? AND ? ORDER BY nom_matiere, date_ajout DESC");
                $stmt_notes_eleve->execute([$classe_active, $eleve_actif, $date_debut, $date_fin]);
                $notes_eleve_toutes = $stmt_notes_eleve->fetchAll();
                
                // Regrouper par matière
                $notes_par_matiere = [];
                foreach ($notes_eleve_toutes as $note) {
                  $matiere = $note['nom_matiere'];
                  if (!isset($notes_par_matiere[$matiere])) {
                    $notes_par_matiere[$matiere] = [];
                  }
                  $notes_par_matiere[$matiere][] = $note;
                }
                
                echo "<div class='eleve-detail admin-view'>
                      <h4>Notes de " . htmlspecialchars($eleve_actif) . " - Trimestre " . $trimestre_actuel . "</h4>";
                
                if (empty($notes_eleve_toutes)) {
                  echo "<p>Aucune note enregistrée pour cet élève durant ce trimestre.</p>";
                } else {
                  // Calculer la moyenne générale
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
                  
                  echo "<div class='admin-moyenne-generale'>Moyenne générale: <span>" . $moyenne_generale . "</span></div>";
                  
                  // Afficher les notes par matière
                  echo "<div class='admin-matieres-notes'>";
                  foreach ($notes_par_matiere as $matiere => $notes) {
                    $moyenne_matiere = calculerMoyenne($notes);
                    $matiere_color = getMatiereColor($matiere);
                    
                    echo "<div class='admin-matiere-bloc'>
                          <div class='admin-matiere-header'>
                            <div class='matiere-color-indicator color-{$matiere_color}'></div>
                            <h5>" . htmlspecialchars($matiere) . "</h5>
                            <div class='admin-moyenne-matiere'>" . $moyenne_matiere . "</div>
                          </div>
                          <div class='admin-matiere-notes'>";
                    
                    // Trier les notes par date
                    usort($notes, function($a, $b) {
                      return strtotime($b['date_ajout']) - strtotime($a['date_ajout']);
                    });
                    
                    foreach ($notes as $note) {
                      // Formater la date pour l'affichage
                      $date_obj = new DateTime($note['date_ajout']);
                      $date_format = $date_obj->format('d/m/Y');
                      
                      // Description et coefficient
                      $description = isset($note['description']) && !empty($note['description']) 
                                    ? htmlspecialchars($note['description']) 
                                    : 'Évaluation';
                      $coefficient = isset($note['coefficient']) ? $note['coefficient'] : 1;
                      
                      echo "<div class='admin-note-item'>
                            <div class='admin-note-date'>" . $date_format . "</div>
                            <div class='admin-note-desc'>" . $description . "</div>
                            <div class='admin-note-prof'>" . htmlspecialchars($note['nom_professeur']) . "</div>
                            <div class='admin-note-coef'>Coef. " . $coefficient . "</div>
                            <div class='admin-note-value'>" . $note['note'] . "/20</div>";
                      
                      // Afficher les boutons de modification et suppression
                      if (canManageNotes()) {
                        echo "<div class='admin-note-actions'>
                              <a href='modifier_note.php?id=" . $note['id'] . "' class='button button-small'>Modifier</a>
                              <a href='supprimer_note.php?id=" . $note['id'] . "' class='button button-small' onclick='return confirm(\"Êtes-vous sûr de vouloir supprimer cette note ?\");'>Supprimer</a>
                            </div>";
                      }
                      
                      echo "</div>";
                    }
                    
                    echo "</div>
                        </div>";
                  }
                  echo "</div>";
                }
                
                echo "</div>";
              }
            }
          }
          ?>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <!-- Message d'accueil quand aucune classe n'est sélectionnée -->
      <div class="message-accueil">
        <p>Veuillez sélectionner une classe dans la liste ci-dessus pour afficher les notes.</p>
        <?php if (isTeacher()): ?>
          <p>Vous enseignez dans les classes suivantes: <?= implode(', ', $classe_enseignees) ?></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  
  <?php endif; ?>

</div>

</body>
</html>

<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>