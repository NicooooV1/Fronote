<?php
/**
 * Vue pour afficher les détails d'une séance du cahier de texte
 */

// Définir le titre de la page et les fichiers CSS/JS supplémentaires
$pageTitle = "Détails de la séance";
$extraCss = ["cahier.css"];
$extraJs = ["cahier.js"];
$currentPage = "cahier";

// Vérifier que l'ID de la séance est fourni
if (!isset($_GET['id']) || empty($_GET['id'])) {
    // Rediriger vers le calendrier si aucun ID n'est fourni
    header('Location: ' . BASE_URL . '/cahier/calendrier.php');
    exit;
}

// Inclure l'en-tête
require_once ROOT_PATH . '/includes/header.php';
?>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-center">
        <h1><?php echo htmlspecialchars($seance['titre']); ?></h1>
        
        <div class="page-actions">
            <?php if ($_SESSION['user_type'] === TYPE_PROFESSEUR && $seance['professeur_id'] == $_SESSION['user_id']): ?>
                <a href="<?php echo BASE_URL; ?>/cahier/editer.php?id=<?php echo $seance['id']; ?>" class="btn btn-accent">
                    <i class="material-icons">edit</i> Modifier
                </a>
                
                <?php if ($seance['statut'] === STATUT_PREVISIONNELLE): ?>
                    <a href="<?php echo BASE_URL; ?>/cahier/api/change_status.php?id=<?php echo $seance['id']; ?>&statut=<?php echo STATUT_REALISEE; ?>" class="btn btn-success">
                        <i class="material-icons">check_circle</i> Marquer comme réalisée
                    </a>
                    
                    <a href="<?php echo BASE_URL; ?>/cahier/api/change_status.php?id=<?php echo $seance['id']; ?>&statut=<?php echo STATUT_ANNULEE; ?>" class="btn btn-danger">
                        <i class="material-icons">cancel</i> Marquer comme annulée
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            
            <a href="<?php echo BASE_URL; ?>/cahier/calendrier.php" class="btn btn-primary">
                <i class="material-icons">arrow_back</i> Retour au calendrier
            </a>
        </div>
    </div>
</div>

<div class="seance-detail">
    <!-- En-tête de la séance avec informations principales -->
    <div class="seance-detail-header" style="background-color: <?php echo $seance['matiere_couleur']; ?>">
        <h2 class="seance-detail-title"><?php echo htmlspecialchars($seance['titre']); ?></h2>
        
        <div class="seance-detail-meta">
            <div class="seance-detail-meta-item">
                <i class="material-icons">event</i>
                <?php echo formatDate($seance['date_debut'], 'date'); ?>
            </div>
            
            <div class="seance-detail-meta-item">
                <i class="material-icons">access_time</i>
                <?php echo formatDate($seance['date_debut'], 'time'); ?> - <?php echo formatDate($seance['date_fin'], 'time'); ?>
                (<?php echo $seance['duree_minutes']; ?> min)
            </div>
            
            <div class="seance-detail-meta-item">
                <i class="material-icons">subject</i>
                <?php echo htmlspecialchars($seance['matiere_nom']); ?>
            </div>
            
            <div class="seance-detail-meta-item">
                <i class="material-icons">group</i>
                <?php echo htmlspecialchars($seance['classe_nom']); ?>
            </div>
            
            <div class="seance-detail-meta-item">
                <i class="material-icons">person</i>
                <?php echo htmlspecialchars($seance['professeur_prenom'] . ' ' . $seance['professeur_nom']); ?>
            </div>
            
            <div class="seance-detail-meta-item">
                <i class="material-icons">info</i>
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
            </div>
        </div>
    </div>
    
    <!-- Corps de la séance avec le contenu principal -->
    <div class="seance-detail-content">
        <!-- Si un chapitre est associé -->
        <?php if (!empty($seance['chapitre_id'])): ?>
            <div class="seance-detail-section">
                <h3 class="seance-detail-section-title">Chapitre</h3>
                <p>
                    <a href="<?php echo BASE_URL; ?>/cahier/chapitre_details.php?id=<?php echo $seance['chapitre_id']; ?>">
                        <?php echo htmlspecialchars($seance['chapitre_titre']); ?>
                    </a>
                </p>
            </div>
        <?php endif; ?>
        
        <!-- Contenu de la séance -->
        <div class="seance-detail-section">
            <h3 class="seance-detail-section-title">Contenu</h3>
            <div class="seance-detail-contenu">
                <?php echo nl2br(htmlspecialchars($seance['contenu'])); ?>
            </div>
        </div>
        
        <!-- Objectifs pédagogiques -->
        <?php if (!empty($seance['objectifs'])): ?>
            <div class="seance-detail-objectifs">
                <h3>Objectifs pédagogiques</h3>
                <?php echo nl2br(htmlspecialchars($seance['objectifs'])); ?>
            </div>
        <?php endif; ?>
        
        <!-- Modalités de travail -->
        <?php if (!empty($seance['modalites'])): ?>
            <div class="seance-detail-modalites">
                <h3>Modalités de travail</h3>
                <?php echo nl2br(htmlspecialchars($seance['modalites'])); ?>
            </div>
        <?php endif; ?>
        
        <!-- Compétences travaillées -->
        <?php if (!empty($seance['competences'])): ?>
            <div class="seance-detail-section">
                <h3 class="seance-detail-section-title">Compétences travaillées</h3>
                <div class="competences-list">
                    <?php foreach ($seance['competences'] as $competence): ?>
                        <div class="competence-item">
                            <div class="competence-code"><?php echo htmlspecialchars($competence['code']); ?></div>
                            <div class="competence-description"><?php echo htmlspecialchars($competence['description']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Ressources associées -->
        <?php if (!empty($seance['ressources'])): ?>
            <div class="seance-detail-section">
                <h3 class="seance-detail-section-title">Ressources</h3>
                <div class="ressources-grid">
                    <?php foreach ($seance['ressources'] as $ressource): ?>
                        <div class="ressource-card">
                            <div class="ressource-card-header">
                                <h4 class="ressource-card-title"><?php echo htmlspecialchars($ressource['titre']); ?></h4>
                            </div>
                            
                            <div class="ressource-card-body">
                                <div class="ressource-preview">
                                    <?php if ($ressource['type'] === RESSOURCE_FILE): ?>
                                        <i class="material-icons ressource-preview-icon">description</i>
                                    <?php elseif ($ressource['type'] === RESSOURCE_LINK): ?>
                                        <i class="material-icons ressource-preview-icon">link</i>
                                    <?php elseif ($ressource['type'] === RESSOURCE_VIDEO): ?>
                                        <i class="material-icons ressource-preview-icon">videocam</i>
                                    <?php elseif ($ressource['type'] === RESSOURCE_TEXT): ?>
                                        <i class="material-icons ressource-preview-icon">text_fields</i>
                                    <?php elseif ($ressource['type'] === RESSOURCE_QCM): ?>
                                        <i class="material-icons ressource-preview-icon">quiz</i>
                                    <?php elseif ($ressource['type'] === RESSOURCE_GALLERY): ?>
                                        <i class="material-icons ressource-preview-icon">photo_library</i>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="ressource-description">
                                    <?php echo truncateText($ressource['description'], 100); ?>
                                </div>
                                
                                <div class="ressource-card-meta">
                                    <span>
                                        <?php 
                                            switch ($ressource['type']) {
                                                case RESSOURCE_FILE: echo 'Fichier'; break;
                                                case RESSOURCE_LINK: echo 'Lien'; break;
                                                case RESSOURCE_VIDEO: echo 'Vidéo'; break;
                                                case RESSOURCE_TEXT: echo 'Texte'; break;
                                                case RESSOURCE_QCM: echo 'QCM'; break;
                                                case RESSOURCE_GALLERY: echo 'Galerie'; break;
                                            }
                                        ?>
                                    </span>
                                    <span><?php echo formatDate($ressource['date_creation'], 'date'); ?></span>
                                </div>
                            </div>
                            
                            <div class="ressource-card-actions">
                                <a href="<?php echo BASE_URL; ?>/ressources/details.php?id=<?php echo $ressource['id']; ?>" class="btn btn-sm btn-primary">
                                    <i class="material-icons">visibility</i> Voir
                                </a>
                                
                                <?php if ($ressource['type'] === RESSOURCE_LINK || $ressource['type'] === RESSOURCE_VIDEO): ?>
                                    <a href="<?php echo htmlspecialchars($ressource['url']); ?>" target="_blank" class="btn btn-sm btn-accent">
                                        <i class="material-icons">open_in_new</i> Ouvrir
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($_SESSION['user_type'] === TYPE_PROFESSEUR && $seance['professeur_id'] == $_SESSION['user_id']): ?>
    <div class="mt-4">
        <a href="<?php echo BASE_URL; ?>/cahier/dupliquer.php?id=<?php echo $seance['id']; ?>" class="btn btn-accent">
            <i class="material-icons">content_copy</i> Dupliquer cette séance
        </a>
        
        <?php if (isset($seance['seance_parent_id']) && !empty($seance['seance_parent_id'])): ?>
            <a href="<?php echo BASE_URL; ?>/cahier/details.php?id=<?php echo $seance['seance_parent_id']; ?>" class="btn btn-primary">
                <i class="material-icons">visibility</i> Voir la séance d'origine
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
// Inclure le pied de page
require_once ROOT_PATH . '/includes/footer.php';
?>