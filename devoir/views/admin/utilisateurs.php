<?php
/**
 * Vue pour la gestion des utilisateurs (Administration)
 */

// Définir le titre de la page et les fichiers CSS/JS supplémentaires
$pageTitle = "Gestion des utilisateurs";
$extraCss = [];
$extraJs = [];
$currentPage = "admin_users";

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
    <h1>Gestion des utilisateurs</h1>
    
    <div class="page-actions">
        <a href="<?php echo BASE_URL; ?>/admin/ajouter_utilisateur.php" class="btn btn-primary">
            <i class="material-icons">person_add</i> Ajouter un utilisateur
        </a>
    </div>
</div>

<!-- Filtres pour les utilisateurs -->
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
        <form action="<?php echo BASE_URL; ?>/admin/utilisateurs.php" method="GET" class="filters-form">
            <div class="row">
                <div class="col">
                    <div class="form-group">
                        <label for="user_type" class="form-label">Type d'utilisateur</label>
                        <select name="user_type" id="user_type" class="form-select">
                            <option value="">Tous les types</option>
                            <option value="<?php echo TYPE_ELEVE; ?>" <?php echo (isset($_GET['user_type']) && $_GET['user_type'] === TYPE_ELEVE) ? 'selected' : ''; ?>>Élèves</option>
                            <option value="<?php echo TYPE_PROFESSEUR; ?>" <?php echo (isset($_GET['user_type']) && $_GET['user_type'] === TYPE_PROFESSEUR) ? 'selected' : ''; ?>>Professeurs</option>
                            <option value="<?php echo TYPE_PARENT; ?>" <?php echo (isset($_GET['user_type']) && $_GET['user_type'] === TYPE_PARENT) ? 'selected' : ''; ?>>Parents</option>
                            <option value="<?php echo TYPE_VIE_SCOLAIRE; ?>" <?php echo (isset($_GET['user_type']) && $_GET['user_type'] === TYPE_VIE_SCOLAIRE) ? 'selected' : ''; ?>>Vie scolaire</option>
                            <option value="<?php echo TYPE_ADMIN; ?>" <?php echo (isset($_GET['user_type']) && $_GET['user_type'] === TYPE_ADMIN) ? 'selected' : ''; ?>>Administrateurs</option>
                        </select>
                    </div>
                </div>
                
                <div class="col">
                    <div class="form-group">
                        <label for="search" class="form-label">Recherche</label>
                        <input type="text" name="search" id="search" class="form-control" placeholder="Nom, prénom, email..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
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

<!-- Liste des utilisateurs -->
<div class="card">
    <div class="card-header">
        <h2>Liste des utilisateurs</h2>
    </div>
    <div class="card-body">
        <?php if (empty($users)): ?>
            <div class="alert alert-info">
                <p>Aucun utilisateur trouvé. Veuillez modifier vos critères de recherche ou ajouter un nouvel utilisateur.</p>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nom</th>
                            <th>Prénom</th>
                            <th>Email</th>
                            <th>Type</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['first_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>
                                    <?php 
                                        switch ($user['user_type']) {
                                            case TYPE_ELEVE:
                                                echo '<span class="badge badge-info">Élève</span>';
                                                break;
                                            case TYPE_PROFESSEUR:
                                                echo '<span class="badge badge-primary">Professeur</span>';
                                                break;
                                            case TYPE_PARENT:
                                                echo '<span class="badge badge-success">Parent</span>';
                                                break;
                                            case TYPE_VIE_SCOLAIRE:
                                                echo '<span class="badge badge-warning">Vie scolaire</span>';
                                                break;
                                            case TYPE_ADMIN:
                                                echo '<span class="badge badge-danger">Administrateur</span>';
                                                break;
                                            default:
                                                echo '<span class="badge badge-secondary">Autre</span>';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/admin/utilisateur_details.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="material-icons">visibility</i> Détails
                                    </a>
                                    
                                    <a href="<?php echo BASE_URL; ?>/admin/editer_utilisateur.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-accent">
                                        <i class="material-icons">edit</i> Modifier
                                    </a>
                                    
                                    <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $user['id']; ?>">
                                        <i class="material-icons">delete</i> Supprimer
                                    </button>
                                    
                                    <!-- Modal de confirmation pour la suppression -->
                                    <div class="modal fade" id="deleteModal<?php echo $user['id']; ?>" tabindex="-1" aria-labelledby="deleteModalLabel<?php echo $user['id']; ?>" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title" id="deleteModalLabel<?php echo $user['id']; ?>">Confirmer la suppression</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <p>Êtes-vous sûr de vouloir supprimer l'utilisateur <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?> ?</p>
                                                    <p class="text-danger">Cette action est irréversible et supprimera toutes les données associées à cet utilisateur.</p>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                                    <form action="<?php echo BASE_URL; ?>/admin/supprimer_utilisateur.php" method="POST">
                                                        <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
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
                                <a href="<?php echo BASE_URL; ?>/admin/utilisateurs.php?page=<?php echo $page - 1; ?><?php echo $queryString; ?>">
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
                                <a href="<?php echo BASE_URL; ?>/admin/utilisateurs.php?page=<?php echo $i; ?><?php echo $queryString; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <li>
                                <a href="<?php echo BASE_URL; ?>/admin/utilisateurs.php?page=<?php echo $page + 1; ?><?php echo $queryString; ?>">
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

<!-- Statistiques des utilisateurs -->
<div class="stats-grid mt-4">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total_users']; ?></div>
        <div class="stat-label">Utilisateurs</div>
    </div>
    
    <div class="stat-card primary">
        <div class="stat-value"><?php echo $stats['eleves']; ?></div>
        <div class="stat-label">Élèves</div>
    </div>
    
    <div class="stat-card accent">
        <div class="stat-value"><?php echo $stats['professeurs']; ?></div>
        <div class="stat-label">Professeurs</div>
    </div>
    
    <div class="stat-card success">
        <div class="stat-value"><?php echo $stats['parents']; ?></div>
        <div class="stat-label">Parents</div>
    </div>
    
    <div class="stat-card warning">
        <div class="stat-value"><?php echo $stats['vie_scolaire']; ?></div>
        <div class="stat-label">Vie scolaire</div>
    </div>
    
    <div class="stat-card danger">
        <div class="stat-value"><?php echo $stats['admins']; ?></div>
        <div class="stat-label">Administrateurs</div>
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
    if (window.location.search.includes('user_type=') || 
        window.location.search.includes('search=')) {
        document.getElementById('filters-container').style.display = 'block';
    }
";

// Inclure le pied de page
require_once ROOT_PATH . '/includes/footer.php';
?>