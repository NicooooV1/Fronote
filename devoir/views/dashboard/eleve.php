<?php
/**
 * Vue pour le tableau de bord élève
 */

// Définir le titre de la page et les fichiers CSS/JS supplémentaires
$pageTitle = "Tableau de bord";
$extraCss = ["devoirs.css", "cahier.css"];
$extraJs = [];
$currentPage = "dashboard";

// Vérifier que l'utilisateur est un élève
if ($_SESSION['user_type'] !== TYPE_ELEVE) {
    // Rediriger vers la page d'accueil si l'utilisateur n'est pas un élève
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Inclure l'en-tête
require_once ROOT_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h1>Bienvenue, <?php echo htmlspecialchars($_SESSION['user_fullname']); ?></h1>
</div>

<!-- Résumé du compte -->
<div class="card mb-4">
    <div class="card-header">
        <h2>Résumé de mon compte</h2>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col">
                <h3>Mes classes</h3>
                <?php if (empty($classes)): ?>
                    <p class="text-muted">Vous n'êtes inscrit à aucune classe.</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($classes as $classe): ?>
                            <li><?php echo htmlspecialchars($classe['nom']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            
            <div class="col">
                <h3>Mes devoirs</h3>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $stats['total_devoirs']; ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                    
                    <div class="stat-card warning">
                        <div class="stat-value"><?php echo $stats['devoirs_a_faire']; ?></div>
                        <div class="stat-label">À faire</div>
                    </div>
                    
                    <div class="stat-card success">
                        <div class="stat-value"><?php echo $stats['devoirs_rendus']; ?></div>
                        <div class="stat-label">Rendus</div>
                    </div>
                    
                    <div class="stat-card danger">
                        <div class="stat-value"><?php echo $stats['devoirs_en_retard']; ?></div>
                        <div class="stat-label">En retard</div>
                    </div>
                </div>
            </div>
            
            <div class="col">
                <h3>Activité récente</h3>
                <div class="timeline">
                    <?php if (empty($activites)): ?>
                        <p class="text-muted">Aucune activité récente.</p>
                    <?php else: ?>
                        <?php foreach ($activites as $activite): ?>
                            <div class="timeline-item">
                                <div class="timeline-date"><?php echo formatDate($activite['date'], 'datetime'); ?></div>
                                <div class="timeline-content">
                                    <?php echo htmlspecialchars($activite['description']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Devoirs à venir -->
<div class="card mb-4">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h2>Mes devoirs à venir</h2>
            <a href="<?php echo BASE_URL; ?>/devoirs/index.php" class="btn btn-sm btn-primary">
                <i class="material-icons">list</i> Voir tous mes devoirs
            </a>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($devoirs)): ?>
            <div class="alert alert-info">
                <p>Vous n'avez aucun devoir à rendre prochainement.</p>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Titre</th>
                            <th>Matière</th>
                            <th>Date limite</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($devoirs as $devoir): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($devoir['titre']); ?></td>
                                <td><?php echo htmlspecialchars($devoir['matiere_nom']); ?></td>
                                <td><?php echo formatDate($devoir['date_limite'], 'datetime'); ?></td>
                                <td>
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
                                </td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/devoirs/details.php?id=<?php echo $devoir['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="material-icons">visibility</i> Voir
                                    </a>
                                    
                                    <?php if ((!isset($devoir['rendu_id']) || empty($devoir['rendu_id'])) && $devoir['statut'] !== STATUT_CORRIGE): ?>
                                        <a href="<?php echo BASE_URL; ?>/devoirs/rendre.php?id=<?php echo $devoir['id']; ?>" class="btn btn-sm btn-success">
                                            <i class="material-icons">assignment_turned_in</i> Rendre
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Prochaines séances -->
<div class="card mb-4">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h2>Mes prochaines séances</h2>
            <a href="<?php echo BASE_URL; ?>/cahier/calendrier.php" class="btn btn-sm btn-primary">
                <i class="material-icons">calendar_month</i> Voir mon calendrier
            </a>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($seances)): ?>
            <div class="alert alert-info">
                <p>Vous n'avez aucune séance prévue prochainement.</p>
            </div>
        <?php else: ?>
            <div class="seances-grid">
                <?php foreach ($seances as $seance): ?>
                    <?php
                        $seanceClass = 'seance-item';
                        if ($seance['statut'] === STATUT_REALISEE) $seanceClass .= ' status-realisee';
                        if ($seance['statut'] === STATUT_ANNULEE) $seanceClass .= ' status-annulee';
                    ?>
                    <div class="<?php echo $seanceClass; ?>" onclick="window.location.href='<?php echo BASE_URL; ?>/cahier/details.php?id=<?php echo $seance['id']; ?>'">
                        <div class="seance-date">
                            <?php echo formatDate($seance['date_debut'], 'date'); ?>
                        </div>
                        <div class="seance-time">
                            <?php echo formatDate($seance['date_debut'], 'time'); ?> - <?php echo formatDate($seance['date_fin'], 'time'); ?>
                        </div>
                        <div class="seance-title">
                            <?php echo htmlspecialchars($seance['titre']); ?>
                        </div>
                        <div class="seance-info">
                            <span class="seance-matiere"><?php echo htmlspecialchars($seance['matiere_nom']); ?></span>
                            <span class="seance-classe"><?php echo htmlspecialchars($seance['classe_nom']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Dernières notifications -->
<div class="card">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h2>Mes dernières notifications</h2>
            <a href="<?php echo BASE_URL; ?>/notifications/index.php" class="btn btn-sm btn-primary">
                <i class="material-icons">notifications</i> Voir toutes mes notifications
            </a>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($notifications)): ?>
            <div class="alert alert-info">
                <p>Vous n'avez aucune notification récente.</p>
            </div>
        <?php else: ?>
            <div class