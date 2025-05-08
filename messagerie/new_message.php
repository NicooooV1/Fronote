<?php
/**
 * /new_message.php - Création d'un nouveau message
 */

// Inclure les fichiers nécessaires
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/message_functions.php';
require_once __DIR__ . '/includes/auth.php';

// Vérifier l'authentification
$user = requireAuth();

// Définir le titre de la page
$pageTitle = 'Nouveau message';

$error = '';
$success = '';

// Traitement du formulaire d'envoi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $destinataires = isset($_POST['destinataires']) ? $_POST['destinataires'] : [];
        $titre = isset($_POST['titre']) ? trim($_POST['titre']) : '';
        $contenu = isset($_POST['contenu']) ? trim($_POST['contenu']) : '';
        $importance = isset($_POST['importance']) ? $_POST['importance'] : 'normal';
        $accuseReception = isset($_POST['accuse_reception']) && $_POST['accuse_reception'] === 'on';
        
        if (empty($destinataires)) {
            throw new Exception("Veuillez sélectionner au moins un destinataire");
        }
        
        if (empty($titre)) {
            throw new Exception("Le titre est obligatoire");
        }
        
        if (empty($contenu)) {
            throw new Exception("Le message ne peut pas être vide");
        }
        
        // Traitement des destinataires
        $participants = [];
        foreach ($destinataires as $dest) {
            list($destType, $destId) = explode('_', $dest);
            
            // Vérification pour éviter l'envoi à soi-même
            if ($destId == $user['id'] && $destType == $user['type']) {
                throw new Exception("Vous ne pouvez pas vous envoyer un message à vous-même");
            }
            
            $participants[] = ['id' => $destId, 'type' => $destType];
        }
        
        // Création de la conversation
        $convId = createConversation(
            $titre, 
            count($participants) > 1 ? 'groupe' : 'individuelle',
            $user['id'],
            $user['type'],
            $participants
        );
        
        // Envoi du message
        $filesData = isset($_FILES['attachments']) ? $_FILES['attachments'] : [];
        
        // Vérifier si l'utilisateur peut définir l'importance
        if (!canSetMessageImportance($user['type'])) {
            $importance = 'normal';
        }
        
        // Vérifier si l'utilisateur peut demander un accusé de réception
        if ($user['type'] === 'eleve') {
            $accuseReception = false;
        }
        
        addMessage(
            $convId,
            $user['id'],
            $user['type'],
            $contenu,
            $importance,
            false, // Est annonce
            false, // Notification obligatoire
            false, // Accusé de réception - toujours désactivé
            null, // Parent message ID
            'standard', // Type message
            $filesData
        );
        
        $success = "Votre message a été envoyé avec succès";
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Récupérer la liste des destinataires potentiels
$destinataires_disponibles = [];

// Élèves
$query = $pdo->query("SELECT id, CONCAT(prenom, ' ', nom, ' (', classe, ')') as nom_complet FROM eleves ORDER BY nom");
if ($query) {
    $destinataires_disponibles['eleve'] = $query->fetchAll();
}

// Parents
$query = $pdo->query("SELECT id, CONCAT(prenom, ' ', nom) as nom_complet FROM parents ORDER BY nom");
if ($query) {
    $destinataires_disponibles['parent'] = $query->fetchAll();
}

// Professeurs
$query = $pdo->query("SELECT id, CONCAT(prenom, ' ', nom, ' (', matiere, ')') as nom_complet FROM professeurs ORDER BY nom");
if ($query) {
    $destinataires_disponibles['professeur'] = $query->fetchAll();
}

// Vie scolaire
$query = $pdo->query("SELECT id, CONCAT(prenom, ' ', nom) as nom_complet FROM vie_scolaire ORDER BY nom");
if ($query) {
    $destinataires_disponibles['vie_scolaire'] = $query->fetchAll();
}

// Administrateurs
$query = $pdo->query("SELECT id, CONCAT(prenom, ' ', nom) as nom_complet FROM administrateurs ORDER BY nom");
if ($query) {
    $destinataires_disponibles['administrateur'] = $query->fetchAll();
}

// Inclure l'en-tête
include 'templates/header.php';

/**
 * Obtient le label du type de destinataire
 * @param string $type Type de destinataire
 * @return string Label formaté
 */
function getRecipientTypeLabel($type) {
    $labels = [
        'eleve' => 'Élèves',
        'parent' => 'Parents',
        'professeur' => 'Professeurs',
        'vie_scolaire' => 'Vie scolaire',
        'administrateur' => 'Administrateurs'
    ];
    return $labels[$type] ?? ucfirst($type);
}
?>

<div class="content">
    <?php if (!empty($success)): ?>
    <div class="alert success">
        <p><?= htmlspecialchars($success) ?></p>
        <div style="margin-top: 15px">
            <a href="index.php" class="btn primary">Retour à la messagerie</a>
        </div>
    </div>
    <?php else: ?>
    
    <?php if (!empty($error)): ?>
    <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <div class="form-container">
        <div class="form-header">
            <div class="form-icon message">
                <i class="fas fa-envelope"></i>
            </div>
            <h2 class="form-title">Composer un nouveau message</h2>
        </div>
        
        <form method="post" enctype="multipart/form-data" id="messageForm">
            <div class="form-group">
                <label for="destinataires">Destinataires</label>
                <div class="multiselect-container">
                    <div class="search-box">
                        <input type="text" id="search-recipients" placeholder="Rechercher des destinataires..." onkeyup="filterRecipients()">
                    </div>
                    
                    <div class="recipient-list">
                        <?php foreach ($destinataires_disponibles as $type => $liste): ?>
                        <?php if (!empty($liste)): ?>
                        <div class="recipient-category" data-type="<?= $type ?>">
                            <div class="category-title"><?= getRecipientTypeLabel($type) ?></div>
                            <?php foreach ($liste as $dest): ?>
                            <div class="recipient-item">
                                <input type="checkbox" name="destinataires[]" id="dest_<?= $type ?>_<?= $dest['id'] ?>" 
                                       value="<?= $type ?>_<?= $dest['id'] ?>" 
                                       onchange="updateSelectedRecipients()">
                                <label for="dest_<?= $type ?>_<?= $dest['id'] ?>"><?= htmlspecialchars($dest['nom_complet']) ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="selected-recipients" id="selected-recipients-container">
                        <!-- Les tags de destinataires sélectionnés apparaîtront ici -->
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="titre">Titre</label>
                <input type="text" name="titre" id="titre" required>
            </div>
            
            <div class="form-group">
                <label for="contenu">Message</label>
                <textarea name="contenu" id="contenu" required></textarea>
            </div>
            
            <div class="options-group">
                <?php if (canSetMessageImportance($user['type'])): ?>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="importance">Importance</label>
                    <select name="importance" id="importance">
                        <option value="normal">Normal</option>
                        <option value="important">Important</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>
                <?php endif; ?>
                
                <?php if ($user['type'] !== 'eleve'): ?>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="checkbox-container">
                        <input type="checkbox" name="accuse_reception" id="accuse_reception">
                        <span class="checkmark"></span>
                        Accusé de réception
                    </label>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="attachments">Pièces jointes</label>
                <div class="file-upload">
                    <input type="file" name="attachments[]" id="attachments" multiple>
                    <label for="attachments">
                        <i class="fas fa-paperclip"></i> Sélectionner des fichiers
                    </label>
                </div>
                <div id="file-list"></div>
            </div>
            
            <div class="form-footer">
                <div class="form-actions">
                    <button type="submit" class="btn primary">Envoyer</button>
                    <a href="index.php" class="btn cancel">Annuler</a>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php
// Inclure le pied de page
include 'templates/footer.php';
?>