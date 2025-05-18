<?php
/**
 * Barre latérale avec le menu de navigation
 * Version harmonisée avec les autres modules Pronote
 */

// Liste des dossiers pour le menu
$folders = [
    'information' => 'Informations',
    'reception' => 'Boîte de réception',
    'envoyes' => 'Messages envoyés',
    'archives' => 'Archives',
    'corbeille' => 'Corbeille'
];

// S'assurer que user est défini et que le type est présent
if (!isset($user)) {
    $user = $_SESSION['user'] ?? [];
}

// Définir le type s'il n'est pas défini
if (!isset($user['type']) && isset($user['profil'])) {
    $user['type'] = $user['profil'];
} elseif (!isset($user['type'])) {
    $user['type'] = 'eleve'; // Valeur par défaut
}

// Fonctionnalités disponibles selon le profil
$canSendAnnouncement = isset($user) && in_array($user['type'], ['vie_scolaire', 'administrateur']);
$isProfesseur = isset($user) && $user['type'] === 'professeur';
?>

<div class="sidebar">
    <!-- Logo et titre de l'application -->
    <div class="logo-container">
        <div class="app-logo">
            <i class="fas fa-envelope"></i>
        </div>
        <div class="app-title">Messagerie</div>
    </div>

    <!-- Menu de navigation des dossiers -->
    <div class="sidebar-section">
        <div class="sidebar-section-header">Dossiers</div>
        <div class="folder-menu">
            <?php foreach ($folders as $key => $name): ?>
            <a href="index.php?folder=<?= $key ?>" class="<?= $currentFolder === $key ? 'active' : '' ?>">
                <i class="fas fa-<?= getFolderIcon($key) ?>"></i> <?= htmlspecialchars($name) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Actions principales -->
    <div class="sidebar-section">
        <div class="sidebar-section-header">Actions</div>
        <a href="new_message.php" class="create-button">
            <i class="fas fa-pen"></i> Nouveau message
        </a>
        
        <?php if ($isProfesseur): ?>
        <a href="class_message.php" class="button button-secondary">
            <i class="fas fa-graduation-cap"></i> Message à la classe
        </a>
        <?php endif; ?>
        
        <?php if ($canSendAnnouncement): ?>
        <a href="new_announcement.php" class="button button-secondary">
            <i class="fas fa-bullhorn"></i> Nouvelle annonce
        </a>
        <?php endif; ?>
    </div>

    <!-- Navigation vers d'autres modules -->
    <div class="sidebar-section">
        <div class="sidebar-section-header">Autres modules</div>
        <a href="../notes/notes.php" class="module-link notes">
            <i class="fas fa-chart-bar"></i> Notes
        </a>
        <a href="../absences/absences.php" class="module-link absences">
            <i class="fas fa-calendar-times"></i> Absences
        </a>
        <a href="../agenda/agenda.php" class="module-link agenda">
            <i class="fas fa-calendar"></i> Agenda
        </a>
        <a href="../cahierdetextes/cahierdetextes.php" class="module-link cahier">
            <i class="fas fa-book"></i> Cahier de textes
        </a>
        <a href="../accueil/accueil.php" class="module-link home">
            <i class="fas fa-home"></i> Accueil
        </a>
    </div>
</div>