<?php
/**
 * Liste des participants d'une conversation
 * 
 * @param array $participants Les participants à afficher
 * @param array $user L'utilisateur connecté
 * @param bool $isAdmin Si l'utilisateur est administrateur
 * @param bool $isModerator Si l'utilisateur est modérateur
 * @param bool $isDeleted Si la conversation est supprimée
 */
?>

<!-- Titre de la section avec bouton d'ajout -->
<h3>
    <span>Participants</span>
    <?php if (!$isDeleted && $isModerator): ?>
    <button id="add-participant-btn" class="btn-icon"><i class="fas fa-plus-circle"></i></button>
    <?php endif; ?>
</h3>

<ul class="participants-list">
    <?php 
    // Regrouper les participants par statut
    $admins = [];
    $moderators = [];
    $normal = [];
    $left = [];
    
    foreach ($participants as $p) {
        if ($p['a_quitte']) {
            $left[] = $p;
        } elseif ($p['est_administrateur']) {
            $admins[] = $p;
        } elseif ($p['est_moderateur']) {
            $moderators[] = $p;
        } else {
            $normal[] = $p;
        }
    }
    
    // Fonction d'affichage d'un participant
    function displayParticipant($p, $user, $isAdmin, $isModerator, $isDeleted) {
        $isCurrentUser = ($p['utilisateur_id'] == $user['id'] && $p['utilisateur_type'] == $user['type']);
        $canManage = ($isAdmin || ($isModerator && !$p['est_administrateur'])) && !$isDeleted && !$isCurrentUser;
        ?>
        <li class="participant-item<?= $isCurrentUser ? ' current' : '' ?><?= $p['a_quitte'] ? ' left' : '' ?>">
            <div class="participant-info">
                <span class="participant-name"><?= htmlspecialchars($p['nom_complet']) ?></span>
                <span class="participant-type"><?= getParticipantType($p['utilisateur_type']) ?></span>
                
                <?php if ($p['est_administrateur']): ?>
                <span class="admin-tag">Admin</span>
                <?php elseif ($p['est_moderateur']): ?>
                <span class="mod-tag">Mod</span>
                <?php endif; ?>
                
                <?php if ($p['a_quitte']): ?>
                <span class="left-tag">A quitté</span>
                <?php endif; ?>
                
                <?php if ($isCurrentUser): ?>
                <span class="current-tag">Vous</span>
                <?php endif; ?>
            </div>
            
            <?php if ($canManage): ?>
            <div class="participant-actions">
                <?php if (!$p['est_moderateur']): ?>
                <button class="action-btn promote-btn" title="Promouvoir en modérateur" 
                        onclick="promoteToModerator(<?= $p['id'] ?>)">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <?php else: ?>
                <button class="action-btn demote-btn" title="Rétrograder" 
                        onclick="demoteFromModerator(<?= $p['id'] ?>)">
                    <i class="fas fa-chevron-down"></i>
                </button>
                <?php endif; ?>
                
                <button class="action-btn remove-btn" title="Retirer le participant" 
                        onclick="removeParticipant(<?= $p['id'] ?>)">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <?php endif; ?>
        </li>
        <?php
    }
    
    // Afficher les administrateurs en premier
    foreach ($admins as $p) {
        displayParticipant($p, $user, $isAdmin, $isModerator, $isDeleted);
    }
    
    // Puis les modérateurs
    foreach ($moderators as $p) {
        displayParticipant($p, $user, $isAdmin, $isModerator, $isDeleted);
    }
    
    // Puis les participants normaux
    foreach ($normal as $p) {
        displayParticipant($p, $user, $isAdmin, $isModerator, $isDeleted);
    }
    
    // Enfin, les participants qui ont quitté (si on les affiche)
    if (!empty($left)) {
        echo '<li class="participant-divider">Participants ayant quitté</li>';
        foreach ($left as $p) {
            displayParticipant($p, $user, $isAdmin, $isModerator, $isDeleted);
        }
    }
    ?>
</ul>