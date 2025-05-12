<?php
/**
 * Vue pour afficher les rendus d'un devoir
 */

// Définir le titre de la page et les fichiers CSS/JS supplémentaires
$pageTitle = "Rendus du devoir";
$extraCss = ["devoirs.css"];
$extraJs = ["devoirs.js"];
$currentPage = "devoirs";

// Vérifier que l'ID du devoir est fourni
if (!isset($_GET['devoir_id']) || empty($_GET['devoir_id'])) {
    // Rediriger vers la liste des devoirs si aucun ID n'est fourni
    header('Location: ' . BASE_URL . '/devoirs/index.php');
    exit;
}

// Vérifier les permissions (seuls les professeurs peuvent voir les rendus)
if ($_SESSION['user_type'] !== TYPE_PROFESSEUR && !$_SESSION['is_admin']) {
    // Rediriger vers la liste des devoirs si l'utilisateur n'est pas autorisé
    header('Location: ' . BASE_URL . '/devoirs/index.php');
    exit;
}

// Inclure l'en-tête
require_once ROOT_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h1>Rendus du devoir : <?php echo htmlspecialchars($devoir['titre']); ?></h1>
    <div class="page-actions">
        <a href="<?php echo BASE_URL; ?>/devoirs/details.php?id=<?php echo $devoir['id']; ?>" class="btn btn-primary">
            <i class="material-icons">arrow_back</i> Retour au devoir
        </a>
        
        <?php if ($stats['total_rendus'] > 0): ?>
            <a href="<?php echo BASE_URL; ?>/devoirs/export_rendus.php?devoir_id=<?php echo $devoir['id']; ?>" class="btn btn-accent">
                <i class="material-icons">download</i> Exporter les rendus
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Informations du devoir -->
<div class="card mb-4">
    <div class="card-header">
        <h2>Informations du devoir</h2>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col">
                <p><strong>Classe:</strong> <?php echo htmlspecialchars($devoir['classe_nom']); ?></p>
                <p><strong>Date de début:</strong> <?php echo formatDate($devoir['date_debut'], 'datetime'); ?></p>
                <p><strong>Date limite:</strong> <?php echo formatDate($devoir['date_limite'], 'datetime'); ?></p>
            </div>
            <div class="col">
                <p><strong>Statut:</strong> 
                    <span class="statut-badge statut-<?php echo strtolower(str_replace('_', '-', $devoir['statut'])); ?>">
                        <?php 
                            switch ($devoir['statut']) {
                                case STATUT_A_FAIRE:
                                    echo '<i class="material-icons">assignment</i> À faire';
                                    break;
                                case STATUT_EN_COURS:
                                    echo '<i class="material-icons">edit</i> En cours';
                                    break;
                                case STATUT_RENDU:
                                    echo '<i class="material-icons">check_circle</i> Rendu';
                                    break;
                                case STATUT_CORRIGE:
                                    echo '<i class="material-icons">grading</i> Corrigé';
                                    break;
                            }
                        ?>
                    </span>
                </p>
                <p><strong>Travail de groupe:</strong> <?php echo $devoir['travail_groupe'] ? 'Oui' : 'Non'; ?></p>
                <p><strong>Barème:</strong> <?php echo $devoir['bareme_id'] ? $devoir['bareme_id'] : 'Non défini'; ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Statistiques des rendus -->
<div class="stats-grid mb-4">
    <div class="stat-card primary">
        <div class="stat-value"><?php echo $stats['total_rendus']; ?> / <?php echo $stats['total_eleves']; ?></div>
        <div class="stat-label">Rendus (<?php echo $stats['pourcentage_rendus']; ?>%)</div>
    </div>
    
    <div class="stat-card success">
        <div class="stat-value"><?php echo $stats['total_corriges']; ?> / <?php echo $stats['total_rendus']; ?></div>
        <div class="stat-label">Corrigés (<?php echo $stats['pourcentage_corriges']; ?>%)</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['moyenne_notes'] ? number_format($stats['moyenne_notes'], 2) : 'N/A'; ?></div>
        <div class="stat-label">Moyenne</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['note_min'] ? $stats['note_min'] : 'N/A'; ?> / <?php echo $stats['note_max'] ? $stats['note_max'] : 'N/A'; ?></div>
        <div class="stat-label">Min / Max</div>
    </div>
</div>

<!-- Liste des rendus -->
<?php if (empty($rendus)): ?>
    <div class="alert alert-info">
        <p>Aucun élève n'a encore rendu ce devoir.</p>
    </div>
<?php else: ?>
    <!-- Filtres pour les rendus -->
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
            <form action="<?php echo BASE_URL; ?>/devoirs/rendus.php" method="GET" class="filters-form">
                <input type="hidden" name="devoir_id" value="<?php echo $devoir['id']; ?>">
                
                <div class="row">
                    <div class="col">
                        <div class="form-group">
                            <label for="statut" class="form-label">Statut</label>
                            <select name="statut" id="statut" class="form-select">
                                <option value="">Tous les statuts</option>
                                <option value="<?php echo STATUT_RENDU; ?>" <?php echo (isset($_GET['statut']) && $_GET['statut'] === STATUT_RENDU) ? 'selected' : ''; ?>>Rendu</option>
                                <option value="<?php echo STATUT_CORRIGE; ?>" <?php echo (isset($_GET['statut']) && $_GET['statut'] === STATUT_CORRIGE) ? 'selected' : ''; ?>>Corrigé</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="col">
                        <div class="form-group">
                            <label for="note_min" class="form-label">Note minimum</label>
                            <input type="number" name="note_min" id="note_min" class="form-control" min="0" step="0.5" value="<?php echo isset($_GET['note_min']) ? $_GET['note_min'] : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="col">
                        <div class="form-group">
                            <label for="note_max" class="form-label">Note maximum</label>
                            <input type="number" name="note_max" id="note_max" class="form-control" min="0" step="0.5" value="<?php echo isset($_GET['note_max']) ? $_GET['note_max'] : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="col">
                        <div class="form-group">
                            <label for="search" class="form-label">Recherche</label>
                            <input type="text" name="search" id="search" class="form-control" placeholder="Nom de l'élève..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-actions mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="material-icons">search</i> Filtrer
                    </button>
                    <a href="<?php echo BASE_URL; ?>/devoirs/rendus.php?devoir_id=<?php echo $devoir['id']; ?>" class="btn btn-accent">
                        <i class="material-icons">clear</i> Réinitialiser
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Tableau des rendus -->
    <div class="card">
        <div class="card-header">
            <h2>Liste des rendus</h2>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Élève</th>
                            <th>Date de rendu</th>
                            <th>Statut</th>
                            <th>Note</th>
                            <th>Fichiers</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rendus as $rendu): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($rendu['eleve_prenom'] . ' ' . $rendu['eleve_nom']); ?></td>
                                <td><?php echo formatDate($rendu['date_rendu'], 'datetime'); ?></td>
                                <td>
                                    <span class="statut-badge statut-<?php echo strtolower(str_replace('_', '-', $rendu['statut'])); ?>">
                                        <?php 
                                            switch ($rendu['statut']) {
                                                case STATUT_RENDU:
                                                    echo '<i class="material-icons">check_circle</i> Rendu';
                                                    break;
                                                case STATUT_CORRIGE:
                                                    echo '<i class="material-icons">grading</i> Corrigé';
                                                    break;
                                            }
                                        ?>
                                    </span>
                                </td>
                                <td><?php echo $rendu['note'] ? $rendu['note'] : 'Non noté'; ?></td>
                                <td><?php echo count($rendu['fichiers']); ?> fichier(s)</td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/devoirs/rendu_details.php?id=<?php echo $rendu['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="material-icons">visibility</i> Détails
                                    </a>
                                    
                                    <?php if ($rendu['statut'] === STATUT_RENDU): ?>
                                        <a href="<?php echo BASE_URL; ?>/devoirs/corriger.php?id=<?php echo $rendu['id']; ?>" class="btn btn-sm btn-accent">
                                            <i class="material-icons">grading</i> Corriger
                                        </a>
                                    <?php elseif ($rendu['statut'] === STATUT_CORRIGE): ?>
                                        <a href="<?php echo BASE_URL; ?>/devoirs/corriger.php?id=<?php echo $rendu['id']; ?>" class="btn btn-sm btn-accent">
                                            <i class="material-icons">edit</i> Modifier
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Pagination -->
    <?php if (isset($totalPages) && $totalPages > 1): ?>
        <div class="pagination-container mt-4">
            <ul class="pagination">
                <?php if ($page > 1): ?>
                    <li>
                        <a href="<?php echo BASE_URL; ?>/devoirs/rendus.php?devoir_id=<?php echo $devoir['id']; ?>&page=<?php echo $page - 1; ?><?php echo $queryString; ?>">
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
                        <a href="<?php echo BASE_URL; ?>/devoirs/rendus.php?devoir_id=<?php echo $devoir['id']; ?>&page=<?php echo $i; ?><?php echo $queryString; ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <li>
                        <a href="<?php echo BASE_URL; ?>/devoirs/rendus.php?devoir_id=<?php echo $devoir['id']; ?>&page=<?php echo $page + 1; ?><?php echo $queryString; ?>">
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
    if (window.location.search.includes('statut=') || 
        window.location.search.includes('note_min=') || 
        window.location.search.includes('note_max=') || 
        window.location.search.includes('search=')) {
        document.getElementById('filters-container').style.display = 'block';
    }
";

// Inclure le pied de page
require_once ROOT_PATH . '/includes/footer.php';
?>