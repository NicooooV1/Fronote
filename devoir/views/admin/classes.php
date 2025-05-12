<?php
/**
 * Vue pour la gestion des classes (Administration)
 */

// Définir le titre de la page et les fichiers CSS/JS supplémentaires
$pageTitle = "Gestion des classes";
$extraCss = [];
$extraJs = [];
$currentPage = "admin_classes";

// Vérifier que l'utilisateur est administrateur
if ($_SESSION['user_type'] !== TYPE_ADMIN) {
    // Rediriger vers la page d'accueil si l'utilisateur n'est pas administrateur
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Inclure l'en-tête
require_once ROOT_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h1>Gestion des classes</h1>
    
    <div class="page-actions">
        <a href="<?php echo BASE_URL; ?>/admin/ajouter_classe.php" class="btn btn-primary">
            <i class="material-icons">add</i> Ajouter une classe
        </a>
    </div>
</div>

<!-- Filtres pour les classes -->
<div class="card mb-4">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h2>Filtres</h2>
            <button id="toggle-filters" class="btn btn-sm btn-primary">
                <i class="material-icons">filter_list</i> Afficher/Masquer
            </button>
        </div>
    </div>
    <div class="card-body" id="filters-container" style="display: none;">
        <form action="<?php echo BASE_URL; ?>/admin/classes.php" method="GET" class="filters-form">
            <div class="row">
                <div class="col">
                    <div class="form-group">
                        <label for="niveau" class="form-label">Niveau</label>
                        <select name="niveau" id="niveau" class="form-select">
                            <option value="">Tous les niveaux</option>
                            <option value="6" <?php echo (isset($_GET['niveau']) && $_GET['niveau'] == '6') ? 'selected' : ''; ?>>6ème</option>
                            <option value="5" <?php echo (isset($_GET['niveau']) && $_GET['niveau'] == '5') ? 'selected' : ''; ?>>5ème</option>
                            <option value="4" <?php echo (isset($_GET['niveau']) && $_GET['niveau'] == '4') ? 'selected' : ''; ?>>4ème</option>
                            <option value="3" <?php echo (isset($_GET['niveau']) && $_GET['niveau'] == '3') ? 'selected' : ''; ?>>3ème</option>
                            <option value="2" <?php echo (isset($_GET['niveau']) && $_GET['niveau'] == '2') ? 'selected' : ''; ?>>2nde</option>
                            <option value="1" <?php echo (isset($_GET['niveau']) && $_GET['niveau'] == '1') ? 'selected' : ''; ?>>1ère</option>
                            <option value="0" <?php echo (isset($_GET['niveau']) && $_GET['niveau'] == '0') ? 'selected' : ''; ?>>Terminale</option>
                        </select>
                    </div>
                </div>
                
                <div class="col">
                    <div class="form-group">
                        <label for="annee_scolaire" class="form-label">Année scolaire</label>
                        <select name="annee_scolaire" id="annee_scolaire" class="form-select">
                            <option value="">Toutes les années</option>
                            <?php 
                                $currentYear = date('Y');
                                for ($i = 0; $i < 5; $i++) {
                                    $year = $currentYear - $i;
                                    $annee = $year . '-' . ($year + 1);
                                    $selected = (isset($_GET['annee_scolaire']) && $_GET['annee_scolaire'] == $annee) ? 'selected' : '';
                                    echo "<option value=\"$annee\" $selected>$annee</option>";
                                }
                            ?>
                        </select>
                    </div>
                </div>
                
                <div class="col">
                    <div class="form-group">
                        <label for="search" class="form-label">Recherche</label>
                        <input type="text" name="search" id="search" class="form-control" placeholder="Nom de la classe..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
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

<!-- Liste des classes -->
<div class="card">
    <div class="card-header">
        <h2>Liste des classes</h2>
    </div>
    <div class="card-body">
        <?php if (empty($classes)): ?>
            <div class="alert alert-info">
                <p>Aucune classe trouvée. Veuillez modifier vos critères de recherche ou ajouter une nouvelle classe.</p>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nom</th>
                            <th>Niveau</th>
                            <th>Année scolaire</th>
                            <th>Nombre d'élèves</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $classe): ?>
                            <tr>
                                <td><?php echo $classe['id']; ?></td>
                                <td><?php echo htmlspecialchars($classe['nom']); ?></td>
                                <td>
                                    <?php 
                                        switch ($classe['niveau']) {
                                            case '6': echo '6ème'; break;
                                            case '5': echo '5ème'; break;
                                            case '4': echo '4ème'; break;
                                            case '3': echo '3ème'; break;
                                            case '2': echo '2nde'; break;
                                            case '1': echo '1ère'; break;
                                            case '0': echo 'Terminale'; break;
                                            default: echo $classe['niveau'];
                                        }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($classe['annee_scolaire']); ?></td>
                                <td><?php echo $classe['nb_eleves']; ?></td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/admin/classe_details.php?id=<?php echo $classe['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="material-icons">visibility</i> Détails
                                    </a>
                                    
                                    <a href="<?php echo BASE_URL; ?>/admin/editer_classe.php?id=<?php echo $classe['id']; ?>" class="btn btn-sm btn-accent">
                                        <i class="material-icons">edit</i> Modifier
                                    </a>
                                    
                                    <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $classe['id']; ?>">
                                        <i class="material-icons">delete</i> Supprimer
                                    </button>
                                    
                                    <!-- Modal de confirmation pour la suppression -->
                                    <div class="modal fade" id="deleteModal<?php echo $classe['id']; ?>" tabindex="-1" aria-labelledby="deleteModalLabel<?php echo $classe['id']; ?>" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title" id="deleteModalLabel<?php echo $classe['id']; ?>">Confirmer la suppression</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <p>Êtes-vous sûr de vouloir supprimer la classe <?php echo htmlspecialchars($classe['nom']); ?> ?</p>
                                                    <p class="text-danger">Cette action est irréversible et supprimera toutes les données associées à cette classe (élèves, devoirs, séances, etc.).</p>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                                    <form action="<?php echo BASE_URL; ?>/admin/supprimer_classe.php" method="POST">
                                                        <input type="hidden" name="id" value="<?php echo $classe['id']; ?>">
                                                        <button type="submit" class="btn btn-danger">Supprimer</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if (isset($totalPages) && $totalPages > 1): ?>
                <div class="pagination-container mt-4">
                    <ul class="pagination">
                        <?php if ($page > 1): ?>
                            <li>
                                <a href="<?php echo BASE_URL; ?>/admin/classes.php?page=<?php echo $page - 1; ?><?php echo $queryString; ?>">
                                    <i class="material-icons">chevron_left</i> Précédent
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="disabled">
                                <a><i class="material-icons">chevron_left</i> Précédent</a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="<?php echo ($i === $page) ? 'active' : ''; ?>">
                                <a href="<?php echo BASE_URL; ?>/admin/classes.php?page=<?php echo $i; ?><?php echo $queryString; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <li>
                                <a href="<?php echo BASE_URL; ?>/admin/classes.php?page=<?php echo $page + 1; ?><?php echo $queryString; ?>">
                                    Suivant <i class="material-icons">chevron_right</i>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="disabled">
                                <a>Suivant <i class="material-icons">chevron_right</i></a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Statistiques des classes -->
<div class="stats-grid mt-4">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total_classes']; ?></div>
        <div class="stat-label">Classes</div>
    </div>
    
    <div class="stat-card primary">
        <div class="stat-value"><?php echo $stats['total_eleves']; ?></div>
        <div class="stat-label">Élèves</div>
    </div>
    
    <div class="stat-card accent">
        <div class="stat-value"><?php echo $stats['total_professeurs']; ?></div>
        <div class="stat-label">Professeurs</div>
    </div>
    
    <div class="stat-card success">
        <div class="stat-value"><?php echo $stats['moyenne_eleves']; ?></div>
        <div class="stat-label">Moyenne d'élèves par classe</div>
    </div>
    
    <div class="stat-card warning">
        <div class="stat-value"><?php echo $stats['nb_classes_annee_courante']; ?></div>
        <div class="stat-label">Classes de l'année en cours</div>
    </div>
</div>

<?php
// Script JavaScript pour la page
$pageScript = "
    // Afficher/masquer les filtres
    document.getElementById('toggle-filters').addEventListener('click', function() {
        const filtersContainer = document.getElementById('filters-container');
        if (filtersContainer.style.display === 'none') {
            filtersContainer.style.display = 'block';
        } else {
            filtersContainer.style.display = 'none';
        }
    });
    
    // Si des filtres ont été appliqués, afficher le conteneur des filtres
    if (window.location.search.includes('niveau=') || 
        window.location.search.includes('annee_scolaire=') || 
        window.location.search.includes('search=')) {
        document.getElementById('filters-container').style.display = 'block';
    }
";

// Inclure le pied de page
require_once ROOT_PATH . '/includes/footer.php';
?>