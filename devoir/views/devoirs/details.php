<?php
/**
 * Vue pour afficher les détails d'un devoir
 */

// Définir le titre de la page et les fichiers CSS/JS supplémentaires
$pageTitle = "Détails du devoir";
$extraCss = ["devoirs.css"];
$extraJs = ["devoirs.js", "upload.js"];
$currentPage = "devoirs";

// Inclure l'en-tête
require_once ROOT_PATH . '/includes/header.php';
?>

<div class="page-header">
    <div class="devoir-detail-header">
        <h1 class="devoir-detail-title"><?php echo htmlspecialchars($devoir['titre']); ?></h1>

        <div class="devoir-detail-actions">
            <?php if ($_SESSION['user_type'] === TYPE_PROFESSEUR && $devoir['auteur_id'] == $_SESSION['user_id']): ?>
                <a href="<?php echo BASE_URL; ?>/devoirs/editer.php?id=<?php echo $devoir['id']; ?>" class="btn btn-accent">
                    <i class="material-icons">edit</i> Modifier
                </a>
                
                <form action="<?php echo BASE_URL; ?>/devoirs/supprimer.php" method="POST" style="display: inline-block;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce devoir ?');">
                    <input type="hidden" name="id" value="<?php echo $devoir['id']; ?>">
                    <button type="submit" class="btn btn-danger">
                        <i class="material-icons">delete</i> Supprimer
                    </button>
                </form>
            <?php endif; ?>
            
            <a href="<?php echo BASE_URL; ?>/devoirs/index.php" class="btn btn-primary">
                <i class="material-icons">arrow_back</i> Retour à la liste
            </a>
        </div>
    </div>
</div>

<div class="devoir-detail-container">
    <!-- Informations principales du devoir -->
    <div class="card devoir-detail-info">
        <div class="row">
            <div class="col">
                <div class="devoir-detail-item">
                    <div class="devoir-detail-label">Classe</div>
                    <div class="devoir-detail-value"><?php echo htmlspecialchars($devoir['classe_nom']); ?></div>
                </div>
            </div>
            
            <div class="col">
                <div class="devoir-detail-item">
                    <div class="devoir-detail-label">Date de début</div>
                    <div class="devoir-detail-value"><?php echo formatDate($devoir['date_debut'], 'datetime'); ?></div>
                </div>
            </div>
            
            <div class="col">
                <div class="devoir-detail-item">
                    <div class="devoir-detail-label">Date limite</div>
                    <div class="devoir-detail-value"><?php echo formatDate($devoir['date_limite'], 'datetime'); ?></div>
                </div>
            </div>
            
            <div class="col">
                <div class="devoir-detail-item">
                    <div class="devoir-detail-label">Statut</div>
                    <div class="devoir-detail-value">
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
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mt-3">
            <div class="col">
                <div class="devoir-detail-item">
                    <div class="devoir-detail-label">Auteur</div>
                    <div class="devoir-detail-value"><?php echo htmlspecialchars($devoir['auteur_prenom'] . ' ' . $devoir['auteur_nom']); ?></div>
                </div>
            </div>
            
            <div class="col">
                <div class="devoir-detail-item">
                    <div class="devoir-detail-label">Date de création</div>
                    <div class="devoir-detail-value"><?php echo formatDate($devoir['date_creation'], 'datetime'); ?></div>
                </div>
            </div>
            
            <div class="col">
                <div class="devoir-detail-item">
                    <div class="devoir-detail-label">Travail de groupe</div>
                    <div class="devoir-detail-value"><?php echo $devoir['travail_groupe'] ? 'Oui' : 'Non'; ?></div>
                </div>
            </div>
            
            <div class="col">
                <div class="devoir-detail-item">
                    <div class="devoir-detail-label">Obligatoire</div>
                    <div class="devoir-detail-value"><?php echo $devoir['est_obligatoire'] ? 'Oui' : 'Non'; ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Description du devoir -->
    <div class="card devoir-detail-content mt-4">
        <h2>Description</h2>
        <div class="devoir-detail-text">
            <?php echo nl2br(htmlspecialchars($devoir['description'])); ?>
        </div>
        
        <?php if (!empty($devoir['instructions'])): ?>
            <h2 class="mt-4">Instructions</h2>
            <div class="devoir-detail-instructions">
                <?php echo nl2br(htmlspecialchars($devoir['instructions'])); ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Pièces jointes du devoir -->
    <?php if (!empty($piecesJointes)): ?>
        <div class="card devoir-pieces-jointes mt-4">
            <h2>Pièces jointes</h2>
            <div class="devoir-pieces-jointes-list">
                <?php foreach ($piecesJointes as $pieceJointe): ?>
                    <div class="devoir-piece-jointe">
                        <div class="devoir-piece-jointe-preview">
                            <?php if ($pieceJointe['type'] === 'PDF'): ?>
                                <i class="material-icons devoir-piece-jointe-icon">picture_as_pdf</i>
                            <?php elseif ($pieceJointe['type'] === 'IMG'): ?>
                                <i class="material-icons devoir-piece-jointe-icon">image</i>
                            <?php elseif ($pieceJointe['type'] === 'DOC'): ?>
                                <i class="material-icons devoir-piece-jointe-icon">description</i>
                            <?php else: ?>
                                <i class="material-icons devoir-piece-jointe-icon">insert_drive_file</i>
                            <?php endif; ?>
                        </div>
                        <div class="devoir-piece-jointe-info">
                            <div class="devoir-piece-jointe-nom"><?php echo htmlspecialchars($pieceJointe['nom']); ?></div>
                            <div class="devoir-piece-jointe-actions">
                                <a href="<?php echo BASE_URL; ?>/uploads/devoirs/<?php echo $pieceJointe['fichier']; ?>" class="btn btn-sm btn-primary" download="<?php echo htmlspecialchars($pieceJointe['nom']); ?>">
                                    <i class="material-icons">download</i> Télécharger
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if ($_SESSION['user_type'] === TYPE_ELEVE): ?>
        <!-- Section pour les rendus (élève) -->
        <div class="card mt-4">
            <h2>Mon rendu</h2>
            
            <?php if ($rendu): ?>
                <!-- Détails du rendu -->
                <div class="rendu-details">
                    <div class="rendu-info">
                        <p><strong>Date de rendu:</strong> <?php echo formatDate($rendu['date_rendu'], 'datetime'); ?></p>
                        <p><strong>Statut:</strong> 
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
                        </p>
                        
                        <?php if ($rendu['statut'] === STATUT_CORRIGE): ?>
                            <p><strong>Note:</strong> <?php echo $rendu['note'] ? $rendu['note'] : 'Non noté'; ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($rendu['commentaire'])): ?>
                        <div class="rendu-commentaire mb-3">
                            <h3>Mon commentaire:</h3>
                            <div class="rendu-commentaire-texte">
                                <?php echo nl2br(htmlspecialchars($rendu['commentaire'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($rendu['commentaire_prof'])): ?>
                        <div class="rendu-correction mb-3">
                            <h3>Commentaire du professeur:</h3>
                            <div class="rendu-correction-texte">
                                <?php echo nl2br(htmlspecialchars($rendu['commentaire_prof'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($rendu['fichiers'])): ?>
                        <h3>Fichiers rendus:</h3>
                        <div class="rendu-fichiers">
                            <?php foreach ($rendu['fichiers'] as $fichier): ?>
                                <div class="rendu-fichier mb-2">
                                    <div class="rendu-fichier-icon">
                                        <?php if (strtolower(pathinfo($fichier['nom'], PATHINFO_EXTENSION)) === 'pdf'): ?>
                                            <i class="material-icons">picture_as_pdf</i>
                                        <?php elseif (in_array(strtolower(pathinfo($fichier['nom'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                            <i class="material-icons">image</i>
                                        <?php elseif (in_array(strtolower(pathinfo($fichier['nom'], PATHINFO_EXTENSION)), ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'])): ?>
                                            <i class="material-icons">description</i>
                                        <?php else: ?>
                                            <i class="material-icons">insert_drive_file</i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="rendu-fichier-info">
                                        <div class="rendu-fichier-nom"><?php echo htmlspecialchars($fichier['nom']); ?></div>
                                    </div>
                                    <div class="rendu-fichier-actions">
                                        <a href="<?php echo BASE_URL; ?>/uploads/rendus/<?php echo $fichier['fichier']; ?>" class="btn btn-sm btn-primary" download="<?php echo htmlspecialchars($fichier['nom']); ?>">
                                            <i class="material-icons">download</i> Télécharger
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($rendu['statut'] !== STATUT_CORRIGE && time() < strtotime($devoir['date_limite'])): ?>
                    <div class="mt-3">
                        <a href="<?php echo BASE_URL; ?>/devoirs/editer_rendu.php?id=<?php echo $rendu['id']; ?>" class="btn btn-accent">
                            <i class="material-icons">edit</i> Modifier mon rendu
                        </a>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <!-- Formulaire pour soumettre un rendu -->
                <?php if (time() < strtotime($devoir['date_limite'])): ?>
                    <div class="rendu-form">
                        <form action="<?php echo BASE_URL; ?>/devoirs/rendre.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="devoir_id" value="<?php echo $devoir['id']; ?>">
                            
                            <div class="form-group">
                                <label for="commentaire" class="form-label">Commentaire (facultatif)</label>
                                <textarea name="commentaire" id="commentaire" class="form-control" rows="4"></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Fichiers</label>
                                <div class="dropzone">
                                    <div class="dropzone-placeholder">
                                        <div class="dropzone-icon">
                                            <i class="material-icons">cloud_upload</i>
                                        </div>
                                        <div class="dropzone-text">
                                            Glissez et déposez des fichiers ici ou cliquez pour sélectionner
                                        </div>
                                        <div class="dropzone-info">
                                            Formats acceptés: PDF, Word, Excel, PowerPoint, Image
                                        </div>
                                    </div>
                                    <input type="file" name="fichiers[]" class="dropzone-input" multiple>
                                    <div class="file-list" style="display: none;"></div>
                                </div>
                            </div>
                            
                            <div class="form-group mt-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="material-icons">assignment_turned_in</i> Rendre le devoir
                                </button>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <p>La date limite pour rendre ce devoir est dépassée.</p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
    <?php elseif ($_SESSION['user_type'] === TYPE_PROFESSEUR && $devoir['auteur_id'] == $_SESSION['user_id']): ?>
        <!-- Section pour les rendus (professeur) -->
        <div class="card mt-4">
            <h2>Rendus des élèves</h2>
            
            <?php if (empty($rendus)): ?>
                <div class="alert alert-info">
                    <p>Aucun élève n'a encore rendu ce devoir.</p>
                </div>
            <?php else: ?>
                <!-- Statistiques des rendus -->
                <div class="stats-grid mb-3">
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
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Élève</th>
                                <th>Date de rendu</th>
                                <th>Statut</th>
                                <th>Note</th>
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
                                    <td>
                                        <a href="<?php echo BASE_URL; ?>/devoirs/rendu_details.php?id=<?php echo $rendu['id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="material-icons">visibility</i> Détails
                                        </a>
                                        
                                        <?php if ($rendu['statut'] === STATUT_RENDU): ?>
                                            <a href="<?php echo BASE_URL; ?>/devoirs/corriger.php?id=<?php echo $rendu['id']; ?>" class="btn btn-sm btn-accent">
                                                <i class="material-icons">grading</i> Corriger
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Lien vers la page complète des rendus -->
                <div class="mt-3">
                    <a href="<?php echo BASE_URL; ?>/devoirs/rendus.php?devoir_id=<?php echo $devoir['id']; ?>" class="btn btn-primary">
                        <i class="material-icons">list</i> Voir tous les rendus
                    </a>
                    
                    <?php if ($stats['total_rendus'] > 0): ?>
                        <a href="<?php echo BASE_URL; ?>/devoirs/export_rendus.php?devoir_id=<?php echo $devoir['id']; ?>" class="btn btn-accent">
                            <i class="material-icons">download</i> Exporter les rendus
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php elseif ($_SESSION['user_type'] === TYPE_PARENT): ?>
        <!-- Section pour les rendus (parent) -->
        <div class="card mt-4">
            <h2>Rendu de votre enfant</h2>
            
            <?php if ($rendu): ?>
                <!-- Détails du rendu -->
                <div class="rendu-details">
                    <div class="rendu-info">
                        <p><strong>Date de rendu:</strong> <?php echo formatDate($rendu['date_rendu'], 'datetime'); ?></p>
                        <p><strong>Statut:</strong> 
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
                        </p>
                        
                        <?php if ($rendu['statut'] === STATUT_CORRIGE): ?>
                            <p><strong>Note:</strong> <?php echo $rendu['note'] ? $rendu['note'] : 'Non noté'; ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($rendu['commentaire'])): ?>
                        <div class="rendu-commentaire mb-3">
                            <h3>Commentaire de l'élève:</h3>
                            <div class="rendu-commentaire-texte">
                                <?php echo nl2br(htmlspecialchars($rendu['commentaire'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($rendu['commentaire_prof'])): ?>
                        <div class="rendu-correction mb-3">
                            <h3>Commentaire du professeur:</h3>
                            <div class="rendu-correction-texte">
                                <?php echo nl2br(htmlspecialchars($rendu['commentaire_prof'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($rendu['fichiers'])): ?>
                        <h3>Fichiers rendus:</h3>
                        <div class="rendu-fichiers">
                            <?php foreach ($rendu['fichiers'] as $fichier): ?>
                                <div class="rendu-fichier mb-2">
                                    <div class="rendu-fichier-icon">
                                        <?php if (strtolower(pathinfo($fichier['nom'], PATHINFO_EXTENSION)) === 'pdf'): ?>
                                            <i class="material-icons">picture_as_pdf</i>
                                        <?php elseif (in_array(strtolower(pathinfo($fichier['nom'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                            <i class="material-icons">image</i>
                                        <?php elseif (in_array(strtolower(pathinfo($fichier['nom'], PATHINFO_EXTENSION)), ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'])): ?>
                                            <i class="material-icons">description</i>
                                        <?php else: ?>
                                            <i class="material-icons">insert_drive_file</i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="rendu-fichier-info">
                                        <div class="rendu-fichier-nom"><?php echo htmlspecialchars($fichier['nom']); ?></div>
                                    </div>
                                    <div class="rendu-fichier-actions">
                                        <a href="<?php echo BASE_URL; ?>/uploads/rendus/<?php echo $fichier['fichier']; ?>" class="btn btn-sm btn-primary" download="<?php echo htmlspecialchars($fichier['nom']); ?>">
                                            <i class="material-icons">download</i> Télécharger
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <p>Votre enfant n'a pas encore rendu ce devoir.</p>
                    <?php if (time() < strtotime($devoir['date_limite'])): ?>
                        <p>La date limite est le <?php echo formatDate($devoir['date_limite'], 'datetime'); ?>.</p>
                    <?php else: ?>
                        <p>La date limite était le <?php echo formatDate($devoir['date_limite'], 'datetime'); ?> et est dépassée.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php
// Inclure le pied de page
require_once ROOT_PATH . '/includes/footer.php';
?>