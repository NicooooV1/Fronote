<?php
/**
 * Vue pour afficher et gérer les chapitres du cahier de texte
 */

// Définir le titre de la page et les fichiers CSS/JS supplémentaires
$pageTitle = "Chapitres - Cahier de Texte";
$extraCss = ["cahier.css"];
$extraJs = ["cahier.js"];
$currentPage = "chapitres";

// Inclure l'en-tête
require_once ROOT_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h1>Chapitres du cahier de texte</h1>
    
    <?php if ($_SESSION['user_type'] === TYPE_PROFESSEUR): ?>
        <div class="page-actions">
            <a href="<?php echo BASE_URL; ?>/cahier/creer_chapitre.php" class="btn btn-primary">
                <i class="material-icons">add</i> Créer un chapitre
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="view-navigation">
    <div class="nav-tabs">
        <a href="<?php echo BASE_URL; ?>/cahier/chapitres.php" class="active">
            <i class="material-icons">book</i> Chapitres
        </a>
    </div>
</div>

<!-- Filtres pour les chapitres -->
<div class="card mb-4">
    <div class="card-header">
        <h2>Filtres</h2>
    </div>
    <div class="card-body">
        <form action="<?php echo BASE_URL; ?>/cahier/chapitres.php" method="GET" class="filters-form">
            <div class="row">
                <?php if ($_SESSION['user_type'] === TYPE_PROFESSEUR): ?>
                    <div class="col">
                        <div class="form-group">
                            <label for="classe_id" class="form-label">Classe</label>
                            <select name="classe_id" id="classe_id" class="form-select">
                                <option value="">Toutes les classes</option>
                                <?php foreach ($classes as $classe): ?>
                                    <option value="<?php echo $classe['id']; ?>" <?php echo (isset($_GET['classe_id']) && $_GET['classe_id'] == $classe['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($classe['nom']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="col">
                        <div class="form-group">
                            <label for="matiere_id" class="form-label">Matière</label>
                            <select name="matiere_id" id="matiere_id" class="form-select">
                                <option value="">Toutes les matières</option>
                                <?php foreach ($matieres as $matiere): ?>
                                    <option value="<?php echo $matiere['id']; ?>" <?php echo (isset($_GET['matiere_id']) && $_GET['matiere_id'] == $matiere['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($matiere['nom']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="col">
                        <div class="form-group">
                            <label for="matiere_id" class="form-label">Matière</label>
                            <select name="matiere_id" id="matiere_id" class="form-select">
                                <option value="">Toutes les matières</option>
                                <?php foreach ($matieres as $matiere): ?>
                                    <option value="<?php echo $matiere['id']; ?>" <?php echo (isset($_GET['matiere_id']) && $_GET['matiere_id'] == $matiere['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($matiere['nom']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="col">
                    <div class="form-group">
                        <label for="search" class="form-label">Recherche</label>
                        <input type="text" name="search" id="search" class="form-control" placeholder="Titre, description..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                    </div>
                </div>
                
                <div class="col">
                    <div class="form-group">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="material-icons">search</i> Filtrer
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Liste des chapitres -->
<div class="chapitres-list">
    <?php if (empty($chapitres)): ?>
        <div class="alert alert-info">
            <p>Aucun chapitre trouvé. Veuillez modifier vos critères de recherche ou créer un nouveau chapitre.</p>
        </div>
    <?php else: ?>
        <?php foreach ($chapitres as $index => $chapitre): ?>
            <?php 
                // Calculer la progression du chapitre
                $progression = isset($chapitre['progression']) ? $chapitre['progression'] : ['pourcentage' => 0, 'seances_realisees' => 0, 'total_seances' => 0];
                $pourcentage = $progression['pourcentage'];
                
                // Déterminer la classe CSS en fonction de la progression
                $progressClass = 'bg-danger';
                if ($pourcentage >= 100) {
                    $progressClass = 'bg-success';
                } elseif ($pourcentage >= 50) {
                    $progressClass = 'bg-warning';
                } elseif ($pourcentage >= 25) {
                    $progressClass = 'bg-info';
                }
            ?>
            <div class="chapitre-item <?php echo isset($_GET['open']) && $_GET['open'] == $chapitre['id'] ? 'open' : ''; ?>">
                <div class="chapitre-header" data-chapitre-id="<?php echo $chapitre['id']; ?>">
                    <h3 class="chapitre-title">
                        <?php echo $index + 1; ?>. <?php echo htmlspecialchars($chapitre['titre']); ?>
                        <small class="text-muted">(<?php echo htmlspecialchars($chapitre['matiere_nom']); ?> - <?php echo htmlspecialchars($chapitre['classe_nom']); ?>)</small>
                    </h3>
                    
                    <div class="d-flex align-items-center">
                        <!-- Barre de progression -->
                        <div class="progress" style="width: 100px; height: 10px; margin-right: 10px;">
                            <div class="progress-bar <?php echo $progressClass; ?>" role="progressbar" style="width: <?php echo $pourcentage; ?>%" 
                                 aria-valuenow="<?php echo $pourcentage; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        
                        <span class="text-muted me-3">
                            <?php echo $progression['seances_realisees']; ?>/<?php echo $progression['total_seances']; ?> séances
                        </span>
                        
                        <i class="material-icons chapitre-toggle">expand_more</i>
                    </div>
                </div>
                
                <div class="chapitre-content">
                    <?php if (!empty($chapitre['description'])): ?>
                        <div class="chapitre-description mb-3">
                            <?php echo nl2br(htmlspecialchars($chapitre['description'])); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($chapitre['objectifs'])): ?>
                        <h4>Objectifs pédagogiques</h4>
                        <div class="chapitre-objectifs mb-3">
                            <?php echo nl2br(htmlspecialchars($chapitre['objectifs'])); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($chapitre['competences'])): ?>
                        <h4>Compétences travaillées</h4>
                        <div class="competences-list mb-3">
                            <?php foreach ($chapitre['competences'] as $competence): ?>
                                <div class="competence-item">
                                    <div class="competence-code"><?php echo htmlspecialchars($competence['code']); ?></div>
                                    <div class="competence-description"><?php echo htmlspecialchars($competence['description']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <h4>Séances</h4>
                    <?php if (empty($chapitre['seances'])): ?>
                        <p class="text-muted">Aucune séance associée à ce chapitre.</p>
                        
                        <?php if ($_SESSION['user_type'] === TYPE_PROFESSEUR && $chapitre['professeur_id'] == $_SESSION['user_id']): ?>
                            <a href="<?php echo BASE_URL; ?>/cahier/creer.php?chapitre_id=<?php echo $chapitre['id']; ?>" class="btn btn-sm btn-primary">
                                <i class="material-icons">add</i> Créer une séance
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Titre</th>
                                        <th>Statut</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($chapitre['seances'] as $seance): ?>
                                        <tr>
                                            <td><?php echo formatDate($seance['date_debut'], 'datetime'); ?></td>
                                            <td><?php echo htmlspecialchars($seance['titre']); ?></td>
                                            <td>
                                                <span class="statut-badge statut-<?php echo strtolower($seance['statut']); ?>">
                                                    <?php 
                                                        switch ($seance['statut']) {
                                                            case STATUT_PREVISIONNELLE:
                                                                echo 'Prévisionnelle';
                                                                break;
                                                            case STATUT_REALISEE:
                                                                echo 'Réalisée';
                                                                break;
                                                            case STATUT_ANNULEE:
                                                                echo 'Annulée';
                                                                break;
                                                        }
                                                    ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="<?php echo BASE_URL; ?>/cahier/details.php?id=<?php echo $seance['id']; ?>" class="btn btn-sm btn-primary">
                                                    <i class="material-icons">visibility</i> Voir
                                                </a>
                                                
                                                <?php if ($_SESSION['user_type'] === TYPE_PROFESSEUR && $chapitre['professeur_id'] == $_SESSION['user_id']): ?>
                                                    <a href="<?php echo BASE_URL; ?>/cahier/editer.php?id=<?php echo $seance['id']; ?>" class="btn btn-sm btn-accent">
                                                        <i class="material-icons">edit</i> Modifier
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if ($_SESSION['user_type'] === TYPE_PROFESSEUR && $chapitre['professeur_id'] == $_SESSION['user_id']): ?>
                            <a href="<?php echo BASE_URL; ?>/cahier/creer.php?chapitre_id=<?php echo $chapitre['id']; ?>" class="btn btn-sm btn-primary mt-2">
                                <i class="material-icons">add</i> Ajouter une séance
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if ($_SESSION['user_type'] === TYPE_PROFESSEUR && $chapitre['professeur_id'] == $_SESSION['user_id']): ?>
                        <div class="chapitre-actions mt-3">
                            <a href="<?php echo BASE_URL; ?>/cahier/editer_chapitre.php?id=<?php echo $chapitre['id']; ?>" class="btn btn-sm btn-accent">
                                <i class="material-icons">edit</i> Modifier le chapitre
                            </a>
                            
                            <form action="<?php echo BASE_URL; ?>/cahier/supprimer_chapitre.php" method="POST" class="d-inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce chapitre ?');">
                                <input type="hidden" name="id" value="<?php echo $chapitre['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger">
                                    <i class="material-icons">delete</i> Supprimer le chapitre
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php
// Script JavaScript pour la page
$pageScript = "
    // Gérer l'ouverture/fermeture des chapitres
    document.querySelectorAll('.chapitre-header').forEach(function(header) {
        header.addEventListener('click', function() {
            const chapitreItem = this.parentElement;
            chapitreItem.classList.toggle('open');
            
            // Mettre à jour l'URL pour garder l'état d'ouverture
            if (chapitreItem.classList.contains('open')) {
                const chapitreId = this.getAttribute('data-chapitre-id');
                // Ajouter le paramètre 'open' à l'URL sans recharger la page
                var url = new URL(window.location.href);
                url.searchParams.set('open', chapitreId);
                history.replaceState(null, '', url);
            } else {
                // Supprimer le paramètre 'open' de l'URL sans recharger la page
                var url = new URL(window.location.href);
                url.searchParams.delete('open');
                history.replaceState(null, '', url);
            }
        });
    });
";

// Inclure le pied de page
require_once ROOT_PATH . '/includes/footer.php';
?>