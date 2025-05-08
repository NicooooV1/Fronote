<?php
/**
 * /index.php - Interface principale de messagerie
 */

// Inclure les fichiers nécessaires
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/message_functions.php';
require_once __DIR__ . '/includes/auth.php';

// Vérifier l'authentification
if (!isLoggedIn()) {
    header('Location: ' . LOGIN_URL);
    exit;
}

$user = $_SESSION['user'];
// Adaptation: utiliser 'profil' comme 'type' si 'type' n'existe pas
if (!isset($user['type']) && isset($user['profil'])) {
    $user['type'] = $user['profil'];
}

// Vérifier que le type est défini
if (!isset($user['type'])) {
    die("Erreur: Type d'utilisateur non défini dans la session");
}

// Définir le titre de la page
$pageTitle = 'Pronote - Messagerie';

// Traitement des actions rapides si demandé
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['conv_id'])) {
    $convId = (int)$_POST['conv_id'];
    
    switch ($_POST['action']) {
        case 'archive':
            archiveConversation($convId, $user['id'], $user['type']);
            redirect('index.php?folder=archives');
            break;
            
        case 'delete':
            deleteConversation($convId, $user['id'], $user['type']);
            redirect('index.php?folder=corbeille');
            break;
            
        case 'restore':
            restoreConversation($convId, $user['id'], $user['type']);
            redirect('index.php?folder=reception');
            break;
            
        case 'delete_permanently':
            deletePermanently($convId, $user['id'], $user['type']);
            redirect('index.php?folder=corbeille');
            break;
    }
}

// Récupérer le dossier courant
$currentFolder = isset($_GET['folder']) ? $_GET['folder'] : 'reception';

// Récupérer les conversations
$convs = getConversations($user['id'], $user['type'], $currentFolder);

// Liste des dossiers pour le menu (déplacé depuis sidebar.php pour compatibilité)
$folders = [
    'reception' => 'Boîte de réception',
    'envoyes' => 'Messages envoyés',
    'archives' => 'Archives',
    'information' => 'Informations',
    'corbeille' => 'Corbeille'
];

// Fonctionnalités disponibles selon le profil
$canSendAnnouncement = in_array($user['type'], ['vie_scolaire', 'administrateur']);
$isProfesseur = ($user['type'] === 'professeur');

// Si c'est une requête AJAX, renvoyer seulement le contenu partiel
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    // Inclure uniquement le template de la liste des conversations
    include 'templates/conversation-list.php';
    exit;
}

// Inclure l'en-tête
include 'templates/header.php';
?>

<div class="content">
    <!-- Barre latérale avec le menu -->
    <?php include 'templates/sidebar.php'; ?>

    <main>
        <h2><?= $folders[$currentFolder] ?? 'Messages' ?></h2>
        
        <!-- Liste des conversations -->
        <?php include 'templates/conversation-list.php'; ?>
    </main>
</div>

<?php
// Inclure le pied de page
include 'templates/footer.php';
?>