<?php
/**
 * Barre latérale avec le menu de navigation
 */

// Liste des dossiers pour le menu
$folders = [
    'information' => 'Informations',
    'reception' => 'Boîte de réception',
    'envoyes' => 'Messages envoyés',
    'archives' => 'Archives',
    'corbeille' => 'Corbeille'
];

// Fonctionnalités disponibles selon le profil
$canSendAnnouncement = isset($user) && in_array($user['type'], ['vie_scolaire', 'administrateur']);
$isProfesseur = isset($user) && $user['type'] === 'professeur';
?>

<nav class="sidebar">
    <div class="folder-menu">
        <?php foreach ($folders as $key => $name): ?>
        <a href="index.php?folder=<?= $key ?>" class="<?= $currentFolder === $key ? 'active' : '' ?>">
            <i class="fas fa-<?= getFolderIcon($key) ?>"></i> <?= htmlspecialchars($name) ?>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="action-buttons">
        <a href="new_message.php" class="btn primary">
            <i class="fas fa-pen"></i> Nouveau message
        </a>
        
        <?php if ($isProfesseur): ?>
        <a href="class_message.php" class="btn secondary">
            <i class="fas fa-graduation-cap"></i> Message à la classe
        </a>
        <?php endif; ?>
        
        <?php if ($canSendAnnouncement): ?>
        <a href="new_announcement.php" class="btn warning">
            <i class="fas fa-bullhorn"></i> Nouvelle annonce
        </a>
        <?php endif; ?>
    </div>
</nav>