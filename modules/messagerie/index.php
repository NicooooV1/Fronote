<?php
declare(strict_types=1);
/**
 * Interface principale de messagerie
 */

// Charger la configuration (qui charge l'API)
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/core/utils.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/csrf.php';
require_once __DIR__ . '/core/validator.php';

// Vérifier l'authentification via l'API
$user = requireAuth();

// La connexion PDO est déjà disponible via config.php
require_once __DIR__ . '/models/conversation.php';
require_once __DIR__ . '/models/notification.php';

// Définir le titre de la page
$pageTitle = 'Fronote - Messagerie';

// Traitement des actions POST (avec CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['conv_id'])) {
    csrf_verify();
    $convId = Validator::id($_POST['conv_id']);

    if ($convId) {
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
                redirect('index.php');
                break;
        }
    }
}

// Récupérer le dossier courant
$currentFolder = Validator::folder($_GET['folder'] ?? null);

// Titre du dossier
$folderTitles = [
    'archives' => 'Archives',
    'envoyes' => 'Messages envoyés',
    'corbeille' => 'Corbeille',
    'information' => 'Informations & Annonces',
    'reception' => 'Boîte de réception'
];

$folderTitle = $folderTitles[$currentFolder] ?? 'Boîte de réception';
$pageTitle = 'Fronote - Messagerie - ' . $folderTitle;

// Pagination
$page = Validator::positiveInt($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Recherche
$searchQuery = isset($_GET['q']) ? trim($_GET['q']) : '';
$isSearch = !empty($searchQuery) && strlen($searchQuery) >= 2;

// Récupérer les conversations
if ($isSearch) {
    $result = searchConversations($user['id'], $user['type'], $searchQuery, $limit, $offset);
    $conversations = $result;
    $totalConversations = count($result);
    $hasMore = false;
} else {
    $result = getConversations($user['id'], $user['type'], $currentFolder, $limit, $offset);
    $conversations = $result['conversations'];
    $totalConversations = $result['total'];
    $hasMore = $result['has_more'];
}

// Épinglées d'abord : indicateur + ordre. usort est stable en PHP 8, donc l'ordre
// relatif d'origine (date décroissante) est conservé entre conversations de même
// état. is_pinned est fourni par le modèle (null-safe tant que la colonne n'existe pas).
if (!empty($conversations)) {
    usort($conversations, function ($a, $b) {
        return (int) !empty($b['is_pinned']) <=> (int) !empty($a['is_pinned']);
    });
}

$totalPages = max(1, ceil((float) ($totalConversations / $limit)));

// Si requête AJAX
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    echo json_encode([
        'conversations' => $conversations,
        'total' => $totalConversations,
        'has_more' => $hasMore,
        'page' => $page,
        'csrf_token' => csrf_token()
    ]);
    exit;
}

// Inclure l'en-tête
include 'templates/header.php';
?>

<?php
/*
 * Feuille de style partagée de la messagerie (vocabulaire .msg-app / .conv-*).
 * Chargée ici de façon idempotente : si le header l'a déjà déclarée dans $extraCss,
 * on ne la ré-émet pas. Ancrée à la racine du dépôt + versionnée via asset_url().
 */
if (!in_array('assets/css/messagerie.css', $extraCss ?? [], true)):
?>
<link rel="stylesheet" href="<?= h(asset_url('assets/css/messagerie.css')) ?>">
<?php endif; ?>

<?php
/* ─────────────────────────────────────────────────────────────────────────
 * Helpers d'affichage locaux à la liste (idempotents).
 * Avatars = cercle d'initiales coloré par « tier » de rôle de l'interlocuteur.
 * Les couleurs des tiers sont définies dans messagerie.css (contrat data-role).
 * ───────────────────────────────────────────────────────────────────────── */
if (!function_exists('msg_role_tier')) {
    function msg_role_tier(?string $type): string {
        switch ($type) {
            case 'plateforme':     return 'plateforme';
            case 'direction':      return 'direction';
            case 'administrateur': return 'direction';
            case 'administratif':  return 'administratif';
            case 'vie_scolaire':   return 'vie_scolaire';
            case 'pedagogique':    return 'pedagogique';
            case 'enseignant':
            case 'professeur':     return 'enseignant';
            case 'famille':
            case 'parent':         return 'famille';
            case 'eleve':          return 'eleve';
            default:               return 'eleve';
        }
    }
}
if (!function_exists('msg_initials')) {
    function msg_initials(?string $name): string {
        $name = trim((string) $name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/u', $name) ?: [$name];
        $ini = mb_substr($parts[0], 0, 1);
        if (isset($parts[1])) {
            $ini .= mb_substr($parts[1], 0, 1);
        }
        $ini = mb_strtoupper($ini);
        return $ini !== '' ? $ini : '?';
    }
}
if (!function_exists('msg_counterpart')) {
    // Interlocuteur mis en avant : premier participant distinct de soi-même.
    function msg_counterpart(array $conversation, array $me): array {
        $participants = $conversation['participants'] ?? [];
        if (is_array($participants) && !empty($participants)) {
            foreach ($participants as $p) {
                $isSelf = (($p['user_id'] ?? null) == ($me['id'] ?? null))
                       && (($p['user_type'] ?? null) === ($me['type'] ?? null));
                if (!$isSelf) {
                    return $p;
                }
            }
            return $participants[0];
        }
        return [];
    }
}

/*
 * Rendu d'une ligne de conversation (.conv-item).
 * Préserve TOUS les hooks JS/CSRF câblés : .conversation-item / .conversation-select /
 * .conversation-checkbox (inbox.js), .conv-action-pin / .conv-action-mute
 * (data-conv-id/-pinned/-muted), .quick-actions-btn + #quick-actions-<id> (data-fr-*),
 * data-conv-id / data-id sur la ligne.
 */
$renderConvItem = function (array $conversation) use ($user, $currentFolder): void {
    $cId       = (int) ($conversation['id'] ?? 0);
    $titre     = (string) ($conversation['titre'] ?? 'Sans titre');
    $apercu    = (string) ($conversation['apercu'] ?? '');
    $nonLus    = (int) ($conversation['non_lus'] ?? 0);
    $status    = (string) ($conversation['status'] ?? 'normal');
    $dernier   = $conversation['dernier_message'] ?? null;
    $isRead    = $nonLus === 0;
    $dateText  = $dernier ? formatTimeAgo($dernier) : 'Jamais';

    $cPinned = !empty($conversation['is_pinned']);
    $cMutedUntil = $conversation['muted_until'] ?? null;
    $cMuted = $cMutedUntil !== null && $cMutedUntil !== '' && strtotime((string) $cMutedUntil) > time();

    // Interlocuteur → avatar (initiales + tier de rôle)
    $other = msg_counterpart($conversation, $user);
    $otherName = $other['nom_complet'] ?? '';
    $tier = msg_role_tier($other['user_type'] ?? null);
    $initials = msg_initials($otherName !== '' ? $otherName : $titre);

    // Libellé des participants (survol de l'avatar + repli d'aperçu)
    $names = [];
    if (!empty($conversation['participants']) && is_array($conversation['participants'])) {
        foreach (array_slice($conversation['participants'], 0, 3) as $p) {
            if (!empty($p['nom_complet'])) {
                $names[] = $p['nom_complet'];
            }
        }
        if (count($conversation['participants']) > 3) {
            $names[] = '+' . (count($conversation['participants']) - 3);
        }
    }
    $participantsText = !empty($names) ? implode(', ', $names) : 'Aucun participant';
    $previewText = $apercu !== '' ? truncate($apercu, 90) : $participantsText;

    $rowClasses = ['conv-item', 'conversation-item'];
    if (!$isRead)  { $rowClasses[] = 'is-unread'; $rowClasses[] = 'unread'; }
    if ($cPinned)  { $rowClasses[] = 'is-pinned'; }
    if ($cMuted)   { $rowClasses[] = 'is-muted'; }
    if ($status === 'important' || $status === 'urgent') { $rowClasses[] = $status; }
    ?>
    <article class="<?= implode(' ', $rowClasses) ?>" data-conv-id="<?= $cId ?>" data-id="<?= $cId ?>">
        <div class="conversation-checkbox conv-item__select" title="Sélectionner">
            <input type="checkbox" class="conversation-select"
                   data-id="<?= $cId ?>" data-read="<?= $isRead ? '1' : '0' ?>"
                   aria-label="Sélectionner la conversation <?= h($titre) ?>">
        </div>

        <a href="conversation.php?id=<?= $cId ?>" class="conv-item__link">
            <span class="msg-avatar msg-avatar--<?= h($tier) ?>" data-role="<?= h($tier) ?>"
                  title="<?= h($participantsText) ?>" aria-hidden="true"><?= h($initials) ?></span>

            <span class="conv-item__body">
                <span class="conv-item__top">
                    <span class="conv-item__name"><?= h($titre) ?></span>
                    <span class="conv-item__time"><?= h($dateText) ?></span>
                </span>
                <span class="conv-item__bottom">
                    <span class="conv-item__preview"><?= h($previewText) ?></span>
                    <span class="conv-item__flags">
                        <?php if ($cPinned): ?>
                        <i class="fas fa-thumbtack conv-flag conv-flag--pin" title="Épinglée" aria-hidden="true"></i>
                        <?php endif; ?>
                        <?php if ($cMuted): ?>
                        <i class="fas fa-bell-slash conv-flag conv-flag--mute" title="En sourdine" aria-hidden="true"></i>
                        <?php endif; ?>
                        <?php if (!$isRead): ?>
                        <span class="conv-item__badge" aria-label="<?= $nonLus ?> non lu(s)"><?= $nonLus ?></span>
                        <?php endif; ?>
                    </span>
                </span>
                <?php if ($status === 'important' || $status === 'urgent'): ?>
                <span class="conv-item__tags">
                    <span class="conv-tag conv-tag--<?= h($status) ?>"><?= h(ucfirst($status)) ?></span>
                </span>
                <?php endif; ?>
            </span>
        </a>

        <div class="conv-item__actions">
            <button type="button" class="conv-action-pin" data-conv-id="<?= $cId ?>" data-pinned="<?= $cPinned ? '1' : '0' ?>"
                    aria-label="<?= $cPinned ? 'Désépingler' : 'Épingler' ?>" title="<?= $cPinned ? 'Désépingler' : 'Épingler' ?>">
                <i class="fas fa-thumbtack" aria-hidden="true"></i>
            </button>
            <button type="button" class="conv-action-mute" data-conv-id="<?= $cId ?>" data-muted="<?= $cMuted ? '1' : '0' ?>"
                    aria-label="<?= $cMuted ? 'Réactiver les notifications' : 'Couper les notifications' ?>"
                    title="<?= $cMuted ? 'Réactiver les notifications' : 'Couper les notifications' ?>">
                <i class="fas fa-bell<?= $cMuted ? '-slash' : '' ?>" aria-hidden="true"></i>
            </button>

            <div class="conv-item__more">
                <button type="button" class="quick-actions-btn" data-fr-click="toggleQuickActions"
                        data-fr-args='[<?= $cId ?>]' data-fr-prevent="1" aria-label="Plus d'actions">
                    <i class="fas fa-ellipsis-v" aria-hidden="true"></i>
                </button>
                <div class="quick-actions-menu" id="quick-actions-<?= $cId ?>">
                    <?php if ($currentFolder !== 'corbeille'): ?>
                    <a href="#" data-fr-click="markConversationAsRead" data-fr-args='[<?= $cId ?>]' data-fr-prevent="1">
                        <i class="fas fa-envelope-open"></i> Marquer comme lu
                    </a>
                    <a href="#" data-fr-click="markConversationAsUnread" data-fr-args='[<?= $cId ?>]' data-fr-prevent="1">
                        <i class="fas fa-envelope"></i> Marquer comme non lu
                    </a>
                    <a href="#" data-fr-click="archiveConversation" data-fr-args='[<?= $cId ?>]' data-fr-prevent="1">
                        <i class="fas fa-archive"></i> <?= __('messagerie.archive') ?>
                    </a>
                    <a href="#" data-fr-click="confirmDelete" data-fr-args='[<?= $cId ?>]' data-fr-prevent="1" class="danger">
                        <i class="fas fa-trash"></i> <?= __('btn.delete') ?>
                    </a>
                    <?php else: ?>
                    <a href="#" data-fr-click="restoreConversation" data-fr-args='[<?= $cId ?>]' data-fr-prevent="1">
                        <i class="fas fa-undo"></i> Restaurer
                    </a>
                    <a href="#" data-fr-click="confirmDeletePermanently" data-fr-args='[<?= $cId ?>]' data-fr-prevent="1" class="danger">
                        <i class="fas fa-trash-alt"></i> Supprimer définitivement
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </article>
    <?php
};

// Partition épinglées / autres (l'ordre interne — récence — est déjà appliqué).
$pinnedConvs = [];
$otherConvs  = [];
foreach ($conversations as $conv) {
    if (!empty($conv['is_pinned'])) {
        $pinnedConvs[] = $conv;
    } else {
        $otherConvs[] = $conv;
    }
}
?>

<div class="msg-app msg-app--list">
    <aside class="conv-list" aria-label="Liste des conversations">

        <div class="conv-list__head">
            <h1 class="conv-list__title"><?= h($folderTitle) ?></h1>
            <span class="conv-list__count" title="<?= (int) $totalConversations ?> conversation(s)"><?= (int) $totalConversations ?></span>
            <?php if (($user['type'] ?? '') === 'administrateur'): ?>
            <span class="conv-list__admin">
                <a href="new_announcement.php" class="conv-tool-btn" title="Nouvelle annonce" aria-label="Nouvelle annonce">
                    <i class="fas fa-bullhorn"></i>
                </a>
                <a href="../../admin/messagerie/moderation.php" class="conv-tool-btn" title="Modération" aria-label="Modération">
                    <i class="fas fa-shield-alt"></i>
                </a>
            </span>
            <?php endif; ?>
        </div>

        <!-- Onglets dossiers -->
        <nav class="conv-folders" aria-label="Dossiers de la messagerie">
            <?php
            $convFolderTabs = [
                'reception'   => ['Réception', 'fa-inbox'],
                'envoyes'     => ['Envoyés',   'fa-paper-plane'],
                'archives'    => ['Archivées', 'fa-box-archive'],
                'corbeille'   => ['Corbeille', 'fa-trash'],
                'information' => ['Annonces',  'fa-bullhorn'],
            ];
            foreach ($convFolderTabs as $fKey => $fMeta):
                $fActive = ($currentFolder === $fKey);
            ?>
            <a class="conv-folder<?= $fActive ? ' is-active' : '' ?>"
               href="index.php?folder=<?= h($fKey) ?>"
               <?= $fActive ? 'aria-current="page"' : '' ?>>
                <i class="fas <?= h($fMeta[1]) ?>" aria-hidden="true"></i>
                <span class="conv-folder__label"><?= h($fMeta[0]) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>

        <!-- Recherche -->
        <form method="get" action="index.php" class="conv-search" role="search">
            <input type="hidden" name="folder" value="<?= h($currentFolder) ?>">
            <i class="fas fa-search conv-search__icon" aria-hidden="true"></i>
            <input type="text" name="q" class="conv-search__input"
                   placeholder="Rechercher dans les conversations…"
                   value="<?= h($searchQuery) ?>" minlength="2" autocomplete="off"
                   aria-label="Rechercher dans les conversations">
            <?php if ($isSearch): ?>
            <a href="index.php?folder=<?= h($currentFolder) ?>" class="conv-search__clear" title="Effacer la recherche" aria-label="Effacer la recherche">
                <i class="fas fa-times"></i>
            </a>
            <?php endif; ?>
        </form>

        <?php if ($isSearch): ?>
        <p class="conv-search__info">
            <i class="fas fa-info-circle" aria-hidden="true"></i>
            <?= (int) $totalConversations ?> résultat(s) pour « <?= h($searchQuery) ?> »
        </p>
        <?php endif; ?>

        <!-- Barre d'actions groupées (pilotée par inbox.js : masquée tant qu'aucune sélection) -->
        <div class="conv-bulk">
            <label class="conv-bulk__all" title="Tout sélectionner">
                <input type="checkbox" id="select-all-conversations">
                <span class="conv-bulk__all-text">Tout sélectionner</span>
            </label>
            <div class="conv-bulk__btns">
                <?php if ($currentFolder !== 'corbeille'): ?>
                <button class="bulk-action-btn" data-action="mark_read" data-action-text="Marquer comme lu" data-icon="envelope-open" disabled>
                    <i class="fas fa-envelope-open"></i> Marquer comme lu (0)
                </button>
                <button class="bulk-action-btn" data-action="mark_unread" data-action-text="Marquer comme non lu" data-icon="envelope" disabled>
                    <i class="fas fa-envelope"></i> Marquer comme non lu (0)
                </button>
                <?php endif; ?>

                <button class="bulk-action-btn" data-action="archive" data-action-text="Archiver" data-icon="archive" disabled>
                    <i class="fas fa-archive"></i> Archiver (0)
                </button>

                <?php if ($currentFolder !== 'corbeille'): ?>
                <button class="bulk-action-btn danger" data-action="delete" data-action-text="Supprimer" data-icon="trash-alt" disabled>
                    <i class="fas fa-trash-alt"></i> Supprimer (0)
                </button>
                <?php else: ?>
                <button class="bulk-action-btn" data-action="restore" data-action-text="Restaurer" data-icon="undo" disabled>
                    <i class="fas fa-undo"></i> Restaurer (0)
                </button>
                <button class="bulk-action-btn danger" data-action="delete_permanently" data-action-text="Suppr. définitivement" data-icon="trash-alt" disabled>
                    <i class="fas fa-trash-alt"></i> Suppr. définitivement (0)
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Corps scrollable de la liste -->
        <div class="conv-list__body">
            <?php if (empty($conversations)): ?>
            <div class="conv-empty">
                <i class="fas fa-inbox" aria-hidden="true"></i>
                <p>Aucune conversation dans ce dossier</p>
            </div>
            <?php else: ?>

                <?php if (!empty($pinnedConvs)): ?>
                <section class="conv-section conv-section--pinned">
                    <h2 class="conv-section__label">
                        <i class="fas fa-thumbtack" aria-hidden="true"></i> Épinglées
                    </h2>
                    <?php foreach ($pinnedConvs as $conversation) { $renderConvItem($conversation); } ?>
                </section>
                <?php endif; ?>

                <?php if (!empty($otherConvs)): ?>
                <section class="conv-section">
                    <?php if (!empty($pinnedConvs)): ?>
                    <h2 class="conv-section__label">Conversations</h2>
                    <?php endif; ?>
                    <?php foreach ($otherConvs as $conversation) { $renderConvItem($conversation); } ?>
                </section>
                <?php endif; ?>

            <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav class="conv-pagination" aria-label="Pagination">
            <?php if ($page > 1): ?>
            <a href="?folder=<?= h($currentFolder) ?>&page=<?= $page - 1 ?><?= $isSearch ? '&q=' . urlencode($searchQuery) : '' ?>" class="conv-pagination__btn">
                <i class="fas fa-chevron-left" aria-hidden="true"></i> Précédent
            </a>
            <?php endif; ?>

            <span class="conv-pagination__info">Page <?= $page ?> / <?= $totalPages ?></span>

            <?php if ($hasMore): ?>
            <a href="?folder=<?= h($currentFolder) ?>&page=<?= $page + 1 ?><?= $isSearch ? '&q=' . urlencode($searchQuery) : '' ?>" class="conv-pagination__btn">
                Suivant <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>

    </aside>

    <!-- Volet droit : espace de la conversation active (rendu par conversation.php).
         Sur la liste, on affiche un état vide accueillant ; masqué en simple-colonne mobile. -->
    <section class="msg-thread msg-thread--empty" aria-hidden="true">
        <div class="conv-empty conv-empty--pane">
            <i class="fas fa-comments" aria-hidden="true"></i>
            <p>Sélectionnez une conversation pour l'afficher</p>
        </div>
    </section>
</div>

<?php include 'templates/footer.php'; ?>
