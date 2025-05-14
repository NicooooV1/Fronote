<?php
// includes/sidebar.php - Barre latérale commune à toutes les pages
if (!defined('INCLUDED')) {
    exit('Accès direct au fichier non autorisé');
}

// Déterminer la page active
$currentPage = basename($_SERVER['PHP_SELF']);

// Fonction pour récupérer le nombre de devoirs non lus (implémentation basique)
// Cette fonction n'était probablement pas correctement définie
function getUnreadHomeWorksCount() {
    // Si vous utilisez une session pour stocker cette information
    return isset($_SESSION['unread_homework_count']) ? $_SESSION['unread_homework_count'] : 0;
    
    // Alternative: requête à la base de données
    /*
    global $pdo, $userId;
    
    if (!$userId) return 0;
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM devoirs d
        LEFT JOIN devoirs_status ds ON d.id = ds.id_devoir AND ds.id_eleve = ?
        WHERE ds.id IS NULL OR ds.status = 'non_fait'
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
    */
}
?>

<!-- Barre latérale -->
<aside class="pronote-sidebar">
    <nav>
        <ul class="pronote-nav">
            <li class="pronote-nav-item <?= $currentPage === 'index.php' ? 'active' : '' ?>">
                <a href="index.php" class="pronote-nav-link">
                    <i class="fas fa-home"></i>
                    <span>Accueil</span>
                </a>
            </li>
            <li class="pronote-nav-item <?= $currentPage === 'emploi_du_temps.php' ? 'active' : '' ?>">
                <a href="emploi_du_temps.php" class="pronote-nav-link">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Emploi du temps</span>
                </a>
            </li>
            <li class="pronote-nav-item <?= ($currentPage === 'cahier_texte.php' || $currentPage === 'devoirs.php') ? 'active' : '' ?>">
                <a href="cahier_texte.php" class="pronote-nav-link">
                    <i class="fas fa-book"></i>
                    <span>Cahier de texte</span>
                    <?php if (getUnreadHomeWorksCount() > 0): ?>
                    <div class="pronote-badge"><?= getUnreadHomeWorksCount() ?></div>
                    <?php endif; ?>
                </a>
            </li>
            <li class="pronote-nav-item <?= $currentPage === 'notes.php' ? 'active' : '' ?>">
                <a href="notes.php" class="pronote-nav-link">
                    <i class="fas fa-graduation-cap"></i>
                    <span>Notes</span>
                </a>
            </li>
            <li class="pronote-nav-item <?= $currentPage === 'competences.php' ? 'active' : '' ?>">
                <a href="competences.php" class="pronote-nav-link">
                    <i class="fas fa-clipboard-check"></i>
                    <span>Compétences</span>
                </a>
            </li>
            <li class="pronote-nav-item <?= $currentPage === 'messages.php' ? 'active' : '' ?>">
                <a href="messages.php" class="pronote-nav-link">
                    <i class="fas fa-inbox"></i>
                    <span>Messages</span>
                </a>
            </li>
        </ul>
    </nav>
</aside>