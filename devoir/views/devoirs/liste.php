<?php
/**
 * Vue pour afficher la liste des devoirs
 */

// Définir le titre de la page et les fichiers CSS/JS supplémentaires
$pageTitle = "Liste des devoirs";
$extraCss = ["devoirs.css"];
$extraJs = ["devoirs.js"];
$currentPage = "devoirs";

// Inclure l'en-tête
require_once ROOT_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h1>Devoirs</h1>
    
    <?php if ($_SESSION['user_type'] === TYPE_PROFESSEUR || $_SESSION['is_admin']): ?>
        <div class="page-actions">
            <a href="<?php echo BASE_URL; ?>/devoirs/creer.php" class="btn btn-primary">
                <i class="material-icons">add</i> Créer un devoir
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="devoirs-filters">
    <div class="filters-toggle">
        <h3>Filtres</h3>
        <button type="button" id="toggle-filters" class="btn btn-sm">
            <i class="material-icons">filter_list</i>
        </button>
    </div>
    
    <div class="filters-content" id="filters-content">
        <form action="<?php echo BASE_URL; ?>/devoirs/index.php" method="GET" class="filters-form">
            <?php if ($_SESSION['user_type'] === TYPE_ELEVE || $_SESSION['user_type'] === TYPE_PROFESSEUR): ?>
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
            <?php elseif ($_SESSION['user_type'] === TYPE_PARENT): ?>
                <div class="form-group">
                    <label for="enfant_id" class="form-label">Enfant</label>
                    <select name="enfant_id" id="enfant_id" class="form-select">
                        <?php foreach ($enfants as $enfant): ?>
                            <option value="<?php echo $enfant['id']; ?>" <?php echo (isset($_GET['enfant_id']) && $_GET['enfant_id'] == $enfant['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($enfant['first_name'] . ' ' . $enfant['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            
            <div class="form-group">
                <label for="statut" class="form-label">Statut</label>
                <select name="statut" id="statut" class="form-select">
                    <option value="">Tous les statuts</option>
                    <option value="<?php echo STATUT_A_FAIRE; ?>" <?php echo (isset($_GET['statut']) && $_GET['statut'] === STATUT_A_FAIRE) ? 'selected' : ''; ?>>À faire</option>
                    <option value="<?php echo STATUT_EN_COURS; ?>" <?php echo (isset($_GET['statut']) && $_GET['statut'] === STATUT_EN_COURS) ? 'selected' : ''; ?>>En cours</option>
                    <option value="<?php echo STATUT_RENDU; ?>" <?php echo (isset($_GET['statut']) && $_GET['statut'] === STATUT_RENDU) ? 'selected' : ''; ?>>Rendu</option>
                    <option value="<?php echo STATUT_CORRIGE; ?>" <?php echo (isset($_GET['statut']) && $_GET['statut'] === STATUT_CORRIGE) ? 'selected' : ''; ?>>Corrigé</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="date_debut" class="form-label">Date début</label>
                <input type="date" name="date_debut" id="date_debut" class="form-control" value="<?php echo isset($_GET['date_debut']) ? $_GET['date_debut'] : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="date_fin" class="form-label">Date fin</label>
                <input type="date" name="date_fin" id="date_fin" class="form-control" value="<?php echo isset($_GET['date_fin']) ? $_GET['date_fin'] : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="search" class="form-label">Recherche</label>
                <input type="text" name="search" id="search" class="form-control" placeholder="Rechercher..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="order_by" class="form-label">Trier par</label>
                <select name="order_by" id="order_by" class="form-select">
                    <option value="date_limite ASC" <?php echo (isset($_GET['order_by']) && $_GET['order_by'] === 'date_limite ASC') ? 'selected' : ''; ?>>Date limite (croissant)</option>
                    <option value="date_limite DESC" <?php echo (isset($_GET['order_by']) && $_GET['order_by'] === 'date_limite DESC') ? 'selected' : ''; ?>>Date limite (décroissant)</option>
                    <option value="date_creation DESC" <?php echo (isset($_GET['order_by']) && $_GET['order_by'] === 'date_creation DESC') ? 'selected' : ''; ?>>Date de création (récent)</option>
                    <option value="titre ASC" <?php echo (isset($_GET['order_by']) && $_GET['order_by'] === 'titre ASC') ? 'selected' : ''; ?>>Titre (A-Z)</option>
                    <option value="titre DESC" <?php echo (isset($_GET['order_by']) && $_GET['order_by'] === 'titre DESC') ? 'selected' : ''; ?>>Titre (Z-A)</option>
                </select>
            </div>
            
            <div class="form-group">
                <button type="submit" class="btn btn-primary">
                    <i class="material-icons">search</i> Filtrer
                </button>
                <a href="<?php echo BASE_URL; ?>/devoirs/index.php" class="btn btn-accent">
                    <i class="material-icons">clear</i> Réinitialiser
                </a>
            </div>
        </form>
    </div>
</div>

<div class="devoirs-list">
    <?php if (empty($devoirs)): ?>
        <div class="alert alert-info">
            <p>Aucun devoir ne correspond à vos critères de recherche.</p>
        </div>
    <?php else: ?>
        <?php foreach ($devoirs as $devoir): 
            // Déterminer la classe CSS pour le statut du devoir
            $classeDevoir = '';
            $aujourdhui = date('Y-m-d');
            $dateLimite = date('Y-m-d', strtotime($devoir['date_limite']));
            
            if ($dateLimite < $aujourdhui && $devoir['statut'] === STATUT_A_FAIRE) {
                $classeDevoir = 'urgent';
            } elseif ($dateLimite === $aujourdhui) {
                $classeDevoir = 'aujourd-hui';
            } elseif ($devoir['statut'] === STATUT_RENDU || $devoir['statut'] === STATUT_CORRIGE) {
                $classeDevoir = 'rendu';
            }
            
            // Si élève, vérifier si un rendu existe
            $renduEleve = false;
            if ($_SESSION['user_type'] === TYPE_ELEVE && isset($devoir['rendu_id'])) {
                $renduEleve = true;
            }
        ?>
            <div class="card devoir-item <?php echo $classeDevoir; ?>">
                <div class="devoir-header">
                    <h3 class="devoir-titre"><?php echo htmlspecialchars($devoir['titre']); ?></h3>
                    <div class="devoir-meta">
                        <div class="devoir-date">
                            <i class="material-icons">event</i>
                            <?php echo date(DATETIME_FORMAT, strtotime($devoir['date_limite'])); ?>
                        </div>
                        <div class="statut-badge statut-<?php echo strtolower(str_replace('_', '-', $devoir['statut'])); ?>">
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
                        </div>
                    </div>
                </div>
                
                <div class="devoir-body">
                    <div class="devoir-description">
                        <?php echo htmlspecialchars(substr($devoir['description'], 0, 200)) . (strlen($devoir['description']) > 200 ? '...' : ''); ?>
                    </div>
                    
                    <div class="devoir-actions">
                        <a href="<?php echo BASE_URL; ?>/devoirs/details.php?id=<?php echo $devoir['id']; ?>" class="btn btn-primary btn-sm">
                            <i class="material-icons">visibility</i> Voir les détails
                        </a>
                        
                        <?php if ($_SESSION['user_type'] === TYPE_ELEVE): ?>
                            <?php if (!$renduEleve && $devoir['statut'] !== STATUT_CORRIGE): ?>
                                <a href="<?php echo BASE_URL; ?>/devoirs/rendre.php?id=<?php echo $devoir['id']; ?>" class="btn btn-success btn-sm">
                                    <i class="material-icons">assignment_turned_in</i> Rendre le devoir
                                </a>
                            <?php elseif ($renduEleve): ?>
                                <a href="<?php echo BASE_URL; ?>/devoirs/details.php?id=<?php echo $devoir['id']; ?>" class="btn btn-accent btn-sm">
                                    <i class="material-icons">assignment_turned_in</i> Voir mon rendu
                                </a>
                            <?php endif; ?>
                        <?php elseif ($_SESSION['user_type'] === TYPE_PROFESSEUR && $devoir['auteur_id'] == $_SESSION['user_id']): ?>
                            <a href="<?php echo BASE_URL; ?>/devoirs/editer.php?id=<?php echo $devoir['id']; ?>" class="btn btn-accent btn-sm">
                                <i class="material-icons">edit</i> Modifier
                            </a>
                            <?php if ($devoir['nb_rendus'] > 0): ?>
                                <a href="<?php echo BASE_URL; ?>/devoirs/rendus.php?devoir_id=<?php echo $devoir['id']; ?>" class="btn btn-success btn-sm">
                                    <i class="material-icons">grading</i> Voir les rendus (<?php echo $devoir['nb_rendus']; ?>)
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="devoir-footer">
                    <div class="devoir-info">
                        <span class="devoir-classe">
                            <i class="material-icons">school</i> <?php echo htmlspecialchars($devoir['classe_nom']); ?>
                        </span>
                        <?php if ($devoir['travail_groupe']): ?>
                            <span class="devoir-type">
                                <i class="material-icons">group</i> Travail de groupe
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="devoir-auteur">
                        <img src="<?php echo BASE_URL; ?>/assets/images/user-default.png" alt="Avatar">
                        <span><?php echo htmlspecialchars($devoir['auteur_prenom'] . ' ' . $devoir['auteur_nom']); ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <!-- Pagination -->
        <?php if (isset($totalPages) && $totalPages > 1): ?>
            <div class="pagination-container">
                <ul class="pagination">
                    <?php if ($page > 1): ?>
                        <li>
                            <a href="<?php echo BASE_URL; ?>/devoirs/index.php?page=<?php echo $page - 1; ?><?php echo $queryString; ?>">
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
                            <a href="<?php echo BASE_URL; ?>/devoirs/index.php?page=<?php echo $i; ?><?php echo $queryString; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <li>
                            <a href="<?php echo BASE_URL; ?>/devoirs/index.php?page=<?php echo $page + 1; ?><?php echo $queryString; ?>">
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

<?php
// Script JavaScript pour la page
$pageScript = "
    // Afficher/masquer les filtres
    document.getElementById('toggle-filters').addEventListener('click', function() {
        const filtersContent = document.getElementById('filters-content');
        filtersContent.classList.toggle('open');
    });
    
    // Si des filtres ont été appliqués, afficher le contenu des filtres
    if (window.location.search && window.location.search !== '?page=1') {
        document.getElementById('filters-content').classList.add('open');
    }
";

// Inclure le pied de page
require_once ROOT_PATH . '/includes/footer.php';
?>