<?php
/**
 * /templates/conversation-list.php - Liste des conversations avec actions en masse
 */

// Vérifie si des conversations existent
if (empty($convs)): 
?>
<div class="empty-state">
    <i class="fas fa-inbox"></i>
    <p>Aucun message dans ce dossier</p>
</div>
<?php else: ?>

<!-- Section des actions en masse pour tous les dossiers -->
<div class="bulk-actions">
    <label class="checkbox-container">
        <input type="checkbox" id="select-all-conversations">
        <span class="checkmark"></span>
        <strong>Tout sélectionner</strong>
    </label>
    
    <div class="bulk-action-buttons">
        <?php if ($currentFolder !== 'archives'): ?>
        <button data-action="archive" data-icon="archive" data-action-text="Archiver" class="bulk-action-btn btn secondary" disabled>
            <i class="fas fa-archive"></i> Archiver (0)
        </button>
        <?php endif; ?>
        
        <?php if ($currentFolder !== 'corbeille'): ?>
        <button data-action="delete" data-icon="trash" data-action-text="Supprimer" class="bulk-action-btn btn warning" disabled>
            <i class="fas fa-trash"></i> Supprimer (0)
        </button>
        <?php endif; ?>
        
        <?php if ($currentFolder === 'corbeille'): ?>
        <button data-action="restore" data-icon="trash-restore" data-action-text="Restaurer" class="bulk-action-btn btn primary" disabled>
            <i class="fas fa-trash-restore"></i> Restaurer (0)
        </button>
        
        <button data-action="delete_permanently" data-icon="trash-alt" data-action-text="Supprimer définitivement" class="bulk-action-btn btn warning" disabled>
            <i class="fas fa-trash-alt"></i> Supprimer définitivement (0)
        </button>
        <?php endif; ?>
        
        <?php if ($currentFolder !== 'corbeille'): ?>
        <button data-action="mark_read" data-icon="envelope-open" data-action-text="Marquer comme lu" class="bulk-action-btn btn secondary" disabled>
            <i class="fas fa-envelope-open"></i> Marquer comme lu (0)
        </button>
        
        <button data-action="mark_unread" data-icon="envelope" data-action-text="Marquer comme non lu" class="bulk-action-btn btn secondary" disabled>
            <i class="fas fa-envelope"></i> Marquer comme non lu (0)
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="conversation-list">
    <?php foreach ($convs as $c): ?>
    <div class="conversation-item <?= $c['non_lus'] > 0 ? 'unread' : '' ?> <?= $c['type'] === 'annonce' ? 'annonce' : '' ?>" data-is-read="<?= $c['non_lus'] == 0 ? '1' : '0' ?>">
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
                <?php if ($c['non_lus'] > 0): ?>
                <span class="badge">
                    <?php if ((int)$c['non_lus'] === 1): ?>
                        1 NOUVEAU
                    <?php else: ?>
                        <?= (int)$c['non_lus'] ?> NOUVEAUX
                    <?php endif; ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="conversation-meta">
                <?php 
                // Afficher le statut correct - MODIFIÉ pour prioritiser le type annonce
                $statusClass = '';
                $statusLabel = '';
                
                if ($c['type'] === 'annonce') {
                    // Si c'est une annonce, on affiche "Annonce" peu importe le statut
                    $statusClass = 'annonce';
                    $statusLabel = 'Annonce';
                } elseif (isset($c['status'])) {
                    // Sinon on utilise le statut normal
                    $statusClass = $c['status'];
                    $statusLabel = getMessageStatusLabel($c['status']);
                } else {
                    // Si pas de statut, on utilise le type
                    $statusLabel = getConversationType($c['type']);
                }
                ?>
                <span class="message-status <?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                <span class="date"><?= formatDate($c['dernier_message']) ?></span>
            </div>
        </a>
        
        <!-- Menu d'actions rapides -->
        <div class="quick-actions">
            <button class="quick-actions-btn" onclick="toggleQuickActions(<?= (int)$c['id'] ?>)">
                <i class="fas fa-ellipsis-v"></i>
            </button>
            <div class="quick-actions-menu" id="quick-actions-<?= (int)$c['id'] ?>">
                <?php if ($currentFolder === 'corbeille'): ?>
                <!-- Actions pour la corbeille -->
                <button type="button" onclick="performBulkAction('restore', [<?= (int)$c['id'] ?>])">
                    <i class="fas fa-trash-restore"></i> Restaurer
                </button>
                
                <button type="button" onclick="performBulkAction('delete_permanently', [<?= (int)$c['id'] ?>])" class="delete">
                    <i class="fas fa-trash-alt"></i> Supprimer définitivement
                </button>
                <?php else: ?>
                <!-- Actions pour les autres dossiers -->
                <?php if ($c['non_lus'] > 0): ?>
                <button type="button" onclick="markConversationAsRead(<?= (int)$c['id'] ?>)">
                    <i class="fas fa-envelope-open"></i> Marquer comme lu
                </button>
                <?php else: ?>
                <button type="button" onclick="markConversationAsUnread(<?= (int)$c['id'] ?>)">
                    <i class="fas fa-envelope"></i> Marquer comme non lu
                </button>
                <?php endif; ?>
                
                <?php if ($currentFolder !== 'archives'): ?>
                <button type="button" onclick="performBulkAction('archive', [<?= (int)$c['id'] ?>])">
                    <i class="fas fa-archive"></i> Archiver
                </button>
                <?php endif; ?>
                
                <button type="button" onclick="performBulkAction('delete', [<?= (int)$c['id'] ?>])" class="delete">
                    <i class="fas fa-trash"></i> Supprimer
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>