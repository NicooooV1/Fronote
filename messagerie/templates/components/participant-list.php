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
    
    // Afficher les administrateurs d'abord, puis modérateurs, puis normaux, puis ceux qui ont quitté
    foreach (array_merge($admins, $moderators, $normal, $left) as $p): 
        // Définir les classes CSS pour le participant
        $cssClass = [];
        if ($p['a_quitte']) $cssClass[] = 'left';
        elseif ($p['est_administrateur']) $cssClass[] = 'admin';
        elseif ($p['est_moderateur']) $cssClass[] = 'mod';
        
        $cssClassStr = implode(' ', $cssClass);
    ?>
    <li class="<?= $cssClassStr ?>">
        <i class="fas fa-user-<?= getParticipantIcon($p['utilisateur_type']) ?>"></i>
        <span class="participant-name"><?= htmlspecialchars($p['nom_complet'] ?: 'Utilisateur inconnu') ?></span>
        
        <span class="participant-type"><?= getParticipantType($p['utilisateur_type']) ?></span>
        
        <?php if ($p['a_quitte']): ?>
        <span class="left-tag">A quitté</span>
        <?php elseif ($p['est_administrateur']): ?>
        <span class="admin-tag">Admin</span>
        <?php elseif ($p['est_moderateur']): ?>
        <span class="mod-tag">Mod</span>
        <?php endif; ?>
        
        <?php if (!$isDeleted && $isAdmin && !$p['est_administrateur'] && !$p['a_quitte'] && $p['utilisateur_id'] != $user['id']): ?>
            <?php if ($p['est_moderateur']): ?>
            <button class="action-btn" onclick="demoteFromModerator(<?= (int)$p['id'] ?>)" title="Rétrograder">
                <i class="fas fa-level-down-alt"></i>
            </button>
            <?php else: ?>
            <button class="action-btn" onclick="promoteToModerator(<?= (int)$p['id'] ?>)" title="Promouvoir">
                <i class="fas fa-user-shield"></i>
            </button>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if (!$isDeleted && $isModerator && !$p['est_administrateur'] && !$p['a_quitte'] && $p['utilisateur_id'] != $user['id']): ?>
        <button class="action-btn remove" onclick="removeParticipant(<?= (int)$p['id'] ?>)" title="Supprimer">
            <i class="fas fa-user-minus"></i>
        </button>
        <?php endif; ?>
    </li>
    <?php endforeach; ?>
</ul>