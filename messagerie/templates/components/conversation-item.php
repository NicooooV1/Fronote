<?php
/**
 * Composant d'élément de conversation dans la liste
 */

// Extraire les données de la conversation
$id = $conversation['id'];
$title = !empty($conversation['titre']) ? $conversation['titre'] : '(Sans titre)';
$preview = !empty($conversation['dernier_message']) ? $conversation['dernier_message'] : '(Pas de message)';
$dateCreation = isset($conversation['date_creation']) ? strtotime($conversation['date_creation']) : time();
$lastActivity = isset($conversation['date_dernier_message']) ? strtotime($conversation['date_dernier_message']) : time();
$isRead = isset($conversation['non_lus']) ? $conversation['non_lus'] == 0 : true;
$participants = $conversation['participants'] ?? [];
$type = $conversation['type'] ?? 'standard';

// Déterminer l'icône et la classe CSS selon le type
$typeClasses = [
    'standard' => '',
    'annonce' => 'announcement',
    'information' => 'info',
    'urgent' => 'urgent'
];

$typeIcons = [
    'standard' => 'comments',
    'annonce' => 'bullhorn',
    'information' => 'info-circle',
    'urgent' => 'exclamation-triangle'
];

// Classes CSS pour l'élément de conversation
$itemClass = 'conversation-item';
if (!$isRead) $itemClass .= ' unread';
if (isset($typeClasses[$type])) $itemClass .= ' ' . $typeClasses[$type];

// Make sure baseUrl is defined
if (!isset($baseUrl)) {
    $baseUrl = defined('BASE_URL') ? BASE_URL : '';
}
?>

<div class="<?= $itemClass ?>" data-id="<?= $id ?>">
    <div class="conversation-checkbox" onclick="event.stopPropagation();">
        <input type="checkbox" class="conversation-select" data-id="<?= $id ?>" data-read="<?= $isRead ? '1' : '0' ?>">
    </div>
    <div class="conversation-content">
        <a href="<?= $baseUrl ?>conversation.php?id=<?= $id ?>" class="conversation-link" style="display:block; text-decoration:none; color:inherit;">
            <div class="conversation-header">
                <div class="conversation-info">
                    <div class="conversation-title">
                        <?php if (!$isRead): ?><span class="unread-indicator"></span><?php endif; ?>
                        <?= htmlspecialchars($title) ?>
                    </div>
                    <?php if (!empty($participants)): ?>
                    <div class="conversation-participants">
                        <?php
                        $displayNames = [];
                        foreach ($participants as $p) {
                            if (count($displayNames) < 3) {
                                $displayNames[] = $p['nom_complet'];
                            }
                        }
                        echo htmlspecialchars(implode(', ', $displayNames));
                        if (count($participants) > 3) {
                            echo ' et ' . (count($participants) - 3) . ' autre(s)';
                        }
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="conversation-timestamp" title="<?= date('d/m/Y H:i', $lastActivity) ?>">
                    <?= isset($conversation['dernier_message']) ? date('d/m/Y H:i', strtotime($conversation['dernier_message'])) : date('d/m/Y H:i', time()) ?>
                </div>
            </div>
            <div class="conversation-body">
                <div class="conversation-preview">
                    <?= htmlspecialchars($preview) ?>
                </div>
            </div>
            <div class="conversation-footer">
                <div class="conversation-status">
                    <div class="conversation-type">
                        <i class="fas fa-<?= $typeIcons[$type] ?? 'comments' ?>"></i>
                        <?= ucfirst($type) ?>
                    </div>
                    <?php if (!$isRead): ?>
                    <span class="badge badge-primary"><?= isset($conversation['non_lus']) ? $conversation['non_lus'] : '!' ?> non lu(s)</span>
                    <?php endif; ?>
                </div>
                <div class="conversation-actions">
                    <button class="conversation-action" onclick="event.stopPropagation(); toggleQuickActions(<?= $id ?>)">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                </div>
            </div>
        </a>
    </div>
    <div class="quick-actions-menu" id="quick-actions-<?= $id ?>">
        <!-- Actions rapides ajoutées via JavaScript -->
    </div>
</div>