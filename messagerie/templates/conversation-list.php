<?php
/**
 * /templates/conversation-list.php - Liste des conversations
 */

// Vérifie si des conversations existent
if (empty($convs)): 
?>
<div class="empty-state">
    <i class="fas fa-inbox"></i>
    <p>Aucun message dans ce dossier</p>
</div>
<?php 
// Affichage spécifique pour la corbeille
elseif ($currentFolder === 'corbeille'): 
?>
<!-- Ajout des actions en masse pour la corbeille -->
<div class="bulk-actions">
    <label class="checkbox-container">
        <input type="checkbox" id="select-all-conversations">
        <span class="checkmark"></span>
        <strong>Tout sélectionner</strong>
    </label>
    
    <button id="delete-selected" class="btn warning" disabled>
        <i class="fas fa-trash-alt"></i> Supprimer les éléments sélectionnés (0)
    </button>
</div>

<div class="conversation-list">
    <?php foreach ($convs as $c): ?>
    <div class="conversation-item <?= $c['type'] === 'annonce' ? 'annonce' : '' ?>">
        <label class="checkbox-container conversation-selector">
            <input type="checkbox" class="conversation-checkbox" value="<?= (int)$c['id'] ?>">
            <span class="checkmark"></span>
        </label>
        
        <a href="conversation.php?id=<?= (int)$c['id'] ?>" class="conversation-content">
            <div class="conversation-icon <?= $c['type'] === 'annonce' ? 'annonce' : '' ?>">
                <i class="fas fa-<?= getConversationIcon($c['type']) ?>"></i>
            </div>
            <div class="conversation-header">
                <h3><?= htmlspecialchars($c['titre'] ?: 'Conversation #'.$c['id']) ?></h3>
            </div>
            <div class="conversation-meta">
                <span class="type"><?= htmlspecialchars(getConversationType($c['type'])) ?></span>
                <span class="date"><?= formatDate($c['dernier_message']) ?></span>
            </div>
        </a>
        
        <!-- Menu d'actions rapides -->
        <div class="quick-actions">
            <button class="quick-actions-btn" onclick="toggleQuickActions(<?= (int)$c['id'] ?>)">
                <i class="fas fa-ellipsis-v"></i>
            </button>
            <div class="quick-actions-menu" id="quick-actions-<?= (int)$c['id'] ?>">
                <form method="post" action="index.php">
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="conv_id" value="<?= (int)$c['id'] ?>">
                    <button type="submit">
                        <i class="fas fa-trash-restore"></i> Restaurer
                    </button>
                </form>
                
                <form method="post" action="index.php" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer définitivement cette conversation ? Cette action est irréversible.')">
                    <input type="hidden" name="action" value="delete_permanently">
                    <input type="hidden" name="conv_id" value="<?= (int)$c['id'] ?>">
                    <button type="submit" class="delete">
                        <i class="fas fa-trash-alt"></i> Supprimer définitivement
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php 
// Affichage normal pour les autres dossiers
else: 
?>
<div class="conversation-list">
    <?php foreach ($convs as $c): ?>
    <div class="conversation-item <?= $c['non_lus'] > 0 ? 'unread' : '' ?> <?= $c['type'] === 'annonce' ? 'annonce' : '' ?>">
        <a href="conversation.php?id=<?= (int)$c['id'] ?>" class="conversation-content">
            <div class="conversation-icon <?= $c['type'] === 'annonce' ? 'annonce' : '' ?>">
                <i class="fas fa-<?= getConversationIcon($c['type']) ?>"></i>
            </div>
            <div class="conversation-header">
                <h3><?= htmlspecialchars($c['titre'] ?: 'Conversation #'.$c['id']) ?></h3>
                <?php if ($c['non_lus'] > 0): ?>
                <span class="badge"><?= (int)$c['non_lus'] ?> nouveau(x)</span>
                <?php endif; ?>
            </div>
            <div class="conversation-meta">
                <span class="type"><?= htmlspecialchars(getConversationType($c['type'])) ?></span>
                <span class="date"><?= formatDate($c['dernier_message']) ?></span>
            </div>
        </a>
        
        <!-- Menu d'actions rapides -->
        <div class="quick-actions">
            <button class="quick-actions-btn" onclick="toggleQuickActions(<?= (int)$c['id'] ?>)">
                <i class="fas fa-ellipsis-v"></i>
            </button>
            <div class="quick-actions-menu" id="quick-actions-<?= (int)$c['id'] ?>">
                <?php if ($currentFolder !== 'archives'): ?>
                <form method="post" action="index.php">
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="conv_id" value="<?= (int)$c['id'] ?>">
                    <button type="submit">
                        <i class="fas fa-archive"></i> Archiver
                    </button>
                </form>
                <?php endif; ?>
                
                <form method="post" action="index.php">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="conv_id" value="<?= (int)$c['id'] ?>">
                    <button type="submit" class="delete">
                        <i class="fas fa-trash"></i> Supprimer
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>