<?php
declare(strict_types=1);
// Boot standardisé — fournit $user, $user_role, $user_fullname, $user_initials, $pdo, $isAdmin, $rootPrefix
$pageTitle  = 'Accueil';
$activePage = 'accueil';
require_once __DIR__ . '/../API/module_boot.php';

require_once __DIR__ . '/includes/DashboardService.php';

$classe     = $user['classe'] ?? '';
$aujourdhui = date('d/m/Y');
$trimestre  = function_exists('getTrimestre') ? getTrimestre() : '';
$jours      = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
$jour       = $jours[date('w')];
$dashboard = new DashboardService($pdo);

// Cache etablissement (REF-4)
$etablissement_data = DashboardService::getEtablissementData();
$nom_etablissement  = $etablissement_data['nom'] ?? 'Etablissement Scolaire';

// Greeting contextuel (FEAT-6)
$greeting = DashboardService::getGreeting();

// M104 — Widget-based dashboard
$userId = (int) ($user['id'] ?? 0);
$userWidgets    = $dashboard->getUserWidgets($userId, $user_role);
$availableAll   = $dashboard->getAvailableWidgets($user_role);

// Pre-render widget data for each visible widget
$widgetDataMap = [];
foreach ($userWidgets as $w) {
    if (!empty($w['visible'])) {
        $widgetDataMap[$w['widget_key']] = $dashboard->renderWidgetData($w['widget_key'], $userId, $user_role);
    }
}

// Determination admin
$isAdmin = $user_role === 'administrateur';

// Configuration des templates partages
$pageTitle  = 'Tableau de bord';
$activePage = 'accueil';
$extraCss   = [$rootPrefix . 'assets/css/accueil.css'];

include __DIR__ . '/../templates/shared_header.php';
include __DIR__ . '/../templates/shared_topbar.php';
?>

        <!-- Main Dashboard Content -->
        <div class="dashboard-content">

            <?php
            // Flash error_message (posé par requireRole/redirect()/etc.) — affichage UI
            // pour qu'une redirection silencieuse ne reste pas inexplicable.
            if (!empty($_SESSION['error_message'])):
                $_flashMsg = $_SESSION['error_message'];
                unset($_SESSION['error_message']);
            ?>
            <div class="alert alert-warning" style="margin:16px 0;padding:12px 16px;border-radius:8px;background:#fef3c7;color:#92400e;border:1px solid #fcd34d">
                <i class="fas fa-exclamation-triangle"></i>
                <?= htmlspecialchars($_flashMsg) ?>
            </div>
            <?php endif; ?>

            <!-- Welcome Banner -->
            <div class="welcome-banner">
                <div class="welcome-content">
                    <h2><?= $greeting ?>, <?= htmlspecialchars($user_fullname) ?></h2>
                    <?php if (!empty($classe)): ?>
                    <p>Classe de <?= htmlspecialchars($classe) ?></p>
                    <?php endif; ?>
                    <p class="welcome-date"><?= $jour . ' ' . $aujourdhui ?> - <?= $trimestre ?></p>
                </div>
                <div class="welcome-actions">
                    <button type="button" class="btn-customize" id="btnPersonnaliser" title="Personnaliser le tableau de bord">
                        <i class="fas fa-sliders-h"></i> Personnaliser
                    </button>
                </div>
            </div>

            <?php
            // Actions rapides RÔLE-CONSCIENTES : mettent en avant les gestes clés de chaque
            // profil. Pour un professeur, « Faire l'appel » et « Mon emploi du temps » sont
            // en vedette (même proéminence). Les liens pointent vers les pages des modules.
            $quickActionSets = [
                'professeur' => [
                    ['label' => "Faire l'appel",        'icon' => 'fas fa-clipboard-check', 'route' => 'modules/appel/appel.php',                       'desc' => 'Présences du cours', 'featured' => true],
                    ['label' => 'Mon emploi du temps',  'icon' => 'fas fa-calendar-alt',    'route' => 'modules/emploi_du_temps/emploi_du_temps.php',    'desc' => 'Vos cours de la semaine', 'featured' => true],
                    ['label' => 'Cahier de textes',     'icon' => 'fas fa-book-open',       'route' => 'modules/cahierdetextes/cahierdetextes.php',      'desc' => 'Contenus & devoirs'],
                    ['label' => 'Saisir des notes',     'icon' => 'fas fa-pen-to-square',   'route' => 'modules/notes/notes.php',                       'desc' => 'Évaluations'],
                ],
                'vie_scolaire' => [
                    ['label' => "Faire l'appel",        'icon' => 'fas fa-clipboard-check', 'route' => 'modules/appel/appel.php',                       'desc' => 'Présences', 'featured' => true],
                    ['label' => 'Absences',             'icon' => 'fas fa-user-clock',      'route' => 'modules/absences/absences.php',                 'desc' => 'Gestion & justificatifs', 'featured' => true],
                    ['label' => 'Emploi du temps',      'icon' => 'fas fa-calendar-alt',    'route' => 'modules/emploi_du_temps/emploi_du_temps.php',    'desc' => 'Vue établissement'],
                    ['label' => 'Messagerie',           'icon' => 'fas fa-envelope',        'route' => 'modules/messagerie/index.php',                  'desc' => 'Vos messages'],
                ],
                'eleve' => [
                    ['label' => 'Mon emploi du temps',  'icon' => 'fas fa-calendar-alt',    'route' => 'modules/emploi_du_temps/emploi_du_temps.php',    'desc' => 'Vos cours', 'featured' => true],
                    ['label' => 'Mes notes',            'icon' => 'fas fa-chart-line',      'route' => 'modules/notes/notes.php',                       'desc' => 'Vos résultats', 'featured' => true],
                    ['label' => 'Cahier de textes',     'icon' => 'fas fa-book-open',       'route' => 'modules/cahierdetextes/cahierdetextes.php',      'desc' => 'Devoirs à faire'],
                    ['label' => 'Messagerie',           'icon' => 'fas fa-envelope',        'route' => 'modules/messagerie/index.php',                  'desc' => 'Vos messages'],
                ],
                'parent' => [
                    ['label' => 'Emploi du temps',      'icon' => 'fas fa-calendar-alt',    'route' => 'modules/emploi_du_temps/emploi_du_temps.php',    'desc' => 'Cours de vos enfants', 'featured' => true],
                    ['label' => 'Notes',                'icon' => 'fas fa-chart-line',      'route' => 'modules/notes/notes.php',                       'desc' => 'Résultats', 'featured' => true],
                    ['label' => 'Absences',             'icon' => 'fas fa-user-clock',      'route' => 'modules/absences/absences.php',                 'desc' => 'Suivi'],
                    ['label' => 'Messagerie',           'icon' => 'fas fa-envelope',        'route' => 'modules/messagerie/index.php',                  'desc' => "Contacter l'établissement"],
                ],
            ];
            $quickActions = $quickActionSets[$user_role] ?? [];
            ?>
            <?php if ($quickActions): ?>
            <section class="quick-actions" aria-label="Actions rapides">
                <?php foreach ($quickActions as $qa): ?>
                <a class="qa-card<?= !empty($qa['featured']) ? ' qa-card--featured' : '' ?>" href="<?= htmlspecialchars($rootPrefix . $qa['route']) ?>">
                    <span class="qa-card__icon"><i class="<?= htmlspecialchars($qa['icon']) ?>"></i></span>
                    <span class="qa-card__body">
                        <span class="qa-card__label"><?= htmlspecialchars($qa['label']) ?></span>
                        <span class="qa-card__desc"><?= htmlspecialchars($qa['desc']) ?></span>
                    </span>
                    <i class="fas fa-chevron-right qa-card__arrow" aria-hidden="true"></i>
                </a>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

            <!-- Barre d'édition du tableau de bord (mode personnalisation) -->
            <div class="dashboard-edit-toolbar" id="dashboardEditToolbar" role="toolbar" aria-label="Édition du tableau de bord">
                <span class="det-hint"><i class="fas fa-arrows-up-down-left-right"></i> Glissez pour réordonner, ajustez la taille de chaque widget</span>
                <button type="button" class="det-btn" data-edit-action="add"><i class="fas fa-plus"></i> Ajouter</button>
                <button type="button" class="det-btn" data-edit-action="reset"><i class="fas fa-rotate-left"></i> Réinitialiser</button>
                <button type="button" class="det-btn" data-edit-action="cancel">Annuler</button>
                <button type="button" class="det-btn det-btn--primary" data-edit-action="done"><i class="fas fa-check"></i> Terminé</button>
            </div>

            <!-- Panneau « Ajouter un widget » -->
            <div class="add-widget-panel" id="addWidgetPanel">
                <h3><i class="fas fa-plus-circle"></i> Ajouter un widget</h3>
                <div class="add-widget-grid" id="addWidgetGrid"></div>
            </div>

            <!-- iPhone-style widget grid -->
            <div class="widget-grid" id="widgetGrid">
                <?php foreach ($userWidgets as $idx => $widget):
                    if (empty($widget['visible'])) continue;
                    $wKey   = $widget['widget_key'];
                    $wType  = $widget['type'] ?? 'list';
                    $wLabel = $widget['label'] ?? $wKey;
                    $wIcon  = $widget['icon'] ?? 'fas fa-puzzle-piece';
                    $wMin    = max(1, (int) ($widget['min_width'] ?? 1));
                    $wMax    = min(4, max($wMin, (int) ($widget['max_width'] ?? 4)));
                    $wWidth  = max($wMin, min($wMax, (int) ($widget['width'] ?? $widget['default_width'] ?? 2)));
                    $wHeight = max(1, min(3, (int) ($widget['height'] ?? $widget['default_height'] ?? 1)));
                    $wData  = $widgetDataMap[$wKey] ?? ['type' => 'empty', 'items' => []];
                    // Empty-state: a widget whose normalized payload has no items/value
                    // gets the .is-empty class so it collapses instead of rendering a tall box.
                    $wIsEmpty = empty($wData['items'])
                        && !array_key_exists('value', $wData)
                        && empty($wData['reunions']) && empty($wData['notes'])
                        && empty($wData['devoirs']) && empty($wData['tickets']);
                    $sizeClass = match(true) {
                        $wWidth >= 4 => 'widget-size-xlarge',
                        $wWidth >= 3 => 'widget-size-large',
                        $wWidth >= 2 => 'widget-size-medium',
                        default      => 'widget-size-small',
                    };
                ?>
                <div class="widget-card <?= $sizeClass ?><?= $wIsEmpty ? ' is-empty' : '' ?>"
                     style="--w:<?= $wWidth ?>;--h:<?= $wHeight ?>"
                     data-widget-key="<?= htmlspecialchars($wKey) ?>"
                     data-widget-type="<?= htmlspecialchars($wType) ?>"
                     data-position="<?= $idx ?>"
                     data-width="<?= $wWidth ?>" data-height="<?= $wHeight ?>"
                     data-min="<?= $wMin ?>" data-max="<?= $wMax ?>"
                     data-label="<?= htmlspecialchars($wLabel) ?>" data-icon="<?= htmlspecialchars($wIcon) ?>"
                     draggable="true">
                    <div class="widget-card-header">
                        <div class="widget-card-title">
                            <i class="<?= htmlspecialchars($wIcon) ?>"></i>
                            <span><?= htmlspecialchars($wLabel) ?></span>
                        </div>
                        <div class="widget-card-actions">
                            <button type="button" class="widget-btn widget-btn-minimize" title="Reduire" data-widget-action="toggle">
                                <i class="fas fa-chevron-up"></i>
                            </button>
                            <button type="button" class="widget-btn widget-btn-drag" title="Deplacer">
                                <i class="fas fa-grip-vertical"></i>
                            </button>
                        </div>
                    </div>
                    <div class="widget-card-body">
                        <?php
                        // Render based on widget type
                        switch ($wType) {
                            case 'stats':
                                renderStatWidget($wData);
                                break;
                            case 'chart':
                                renderChartWidget($wData, $wKey);
                                break;
                            case 'list':
                                renderListWidget($wData, $wKey);
                                break;
                            case 'calendar':
                                renderCalendarWidget($wData);
                                break;
                            case 'shortcut':
                                renderShortcutWidget($wData);
                                break;
                            default:
                                renderListWidget($wData, $wKey);
                                break;
                        }
                        ?>
                    </div>
                    <?php if (!empty($wData['link'])): ?>
                    <div class="widget-card-footer">
                        <a href="<?= htmlspecialchars($wData['link']) ?>" class="widget-footer-link">
                            <?= htmlspecialchars($wData['link_label'] ?? 'Voir plus') ?> <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                    <div class="widget-edit-bar">
                        <div class="widget-edit-group">
                            <span class="weg-label">Largeur</span>
                            <button type="button" class="widget-edit-btn" data-resize="w-minus" title="Réduire la largeur"><i class="fas fa-minus"></i></button>
                            <span class="widget-edit-val" data-val="w"><?= $wWidth ?></span>
                            <button type="button" class="widget-edit-btn" data-resize="w-plus" title="Élargir"><i class="fas fa-plus"></i></button>
                        </div>
                        <div class="widget-edit-group">
                            <span class="weg-label">Hauteur</span>
                            <button type="button" class="widget-edit-btn" data-resize="h-minus" title="Réduire la hauteur"><i class="fas fa-minus"></i></button>
                            <span class="widget-edit-val" data-val="h"><?= $wHeight ?></span>
                            <button type="button" class="widget-edit-btn" data-resize="h-plus" title="Agrandir la hauteur"><i class="fas fa-plus"></i></button>
                        </div>
                        <button type="button" class="widget-edit-btn widget-edit-remove" data-resize="remove" title="Retirer ce widget"><i class="fas fa-eye-slash"></i></button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        </div>

        <!-- Modal Personnaliser le dashboard -->
        <div class="modal-overlay is-hidden" id="modalCustomize">
            <div class="modal-customize">
                <div class="modal-customize-header">
                    <h2><i class="fas fa-sliders-h"></i> Personnaliser le tableau de bord</h2>
                    <button type="button" class="modal-close-btn" data-customize-action="close">&times;</button>
                </div>
                <div class="modal-customize-body">
                    <p class="modal-customize-hint">Activez ou desactivez les widgets, puis reordonnez-les par glisser-deposer.</p>
                    <div class="customize-widget-list" id="customizeWidgetList">
                        <!-- Filled by JS -->
                    </div>
                </div>
                <div class="modal-customize-footer">
                    <button type="button" class="btn-secondary" data-customize-action="close">Annuler</button>
                    <button type="button" class="btn-primary" data-customize-action="save">
                        <i class="fas fa-save"></i> Enregistrer
                    </button>
                </div>
            </div>
        </div>

<?php
// --- PHP widget renderers ---

function renderStatWidget(array $data): void
{
    if (isset($data['type']) && $data['type'] === 'stats_grid' && !empty($data['items'])) {
        echo '<div class="widget-stats-grid">';
        foreach ($data['items'] as $card) {
            $color = $card['color'] ?? 'primary';
            echo '<div class="widget-stat-item widget-stat-' . htmlspecialchars($color) . '">';
            echo '  <div class="widget-stat-icon"><i class="' . htmlspecialchars($card['icon'] ?? 'fas fa-info') . '"></i></div>';
            echo '  <div class="widget-stat-info">';
            echo '    <div class="widget-stat-value">' . htmlspecialchars((string)($card['value'] ?? '-')) . '</div>';
            echo '    <div class="widget-stat-label">' . htmlspecialchars($card['label'] ?? '') . '</div>';
            echo '  </div>';
            echo '</div>';
        }
        echo '</div>';
        return;
    }

    // Single stat
    $value = $data['value'] ?? 0;
    $label = $data['label'] ?? '';
    $icon  = $data['icon'] ?? 'fas fa-info-circle';
    $color = $data['color'] ?? 'primary';
    $trend = $data['trend'] ?? null;

    echo '<div class="widget-stat-single widget-stat-' . htmlspecialchars($color) . '">';
    echo '  <div class="widget-stat-big-icon"><i class="' . htmlspecialchars($icon) . '"></i></div>';
    echo '  <div class="widget-stat-big-value">' . htmlspecialchars((string) $value) . '</div>';
    echo '  <div class="widget-stat-big-label">' . htmlspecialchars($label) . '</div>';
    if ($trend !== null) {
        $trendClass = $trend > 0 ? 'trend-up' : ($trend < 0 ? 'trend-down' : 'trend-neutral');
        $trendIcon  = $trend > 0 ? 'fa-arrow-up' : ($trend < 0 ? 'fa-arrow-down' : 'fa-minus');
        echo '  <div class="widget-stat-trend ' . $trendClass . '"><i class="fas ' . $trendIcon . '"></i> ' . abs((float) ($trend)) . '%</div>';
    }
    echo '</div>';
}

function renderChartWidget(array $data, string $widgetKey): void
{
    $items = $data['items'] ?? [];

    // Normaliser en paires {label, value} à partir de clés usuelles.
    $rows = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $label = $it['label'] ?? $it['nom'] ?? $it['title'] ?? $it['name'] ?? '';
        $value = $it['value'] ?? $it['count'] ?? $it['total'] ?? $it['nb'] ?? null;
        if ($value === null || !is_numeric($value)) continue;
        $rows[] = ['label' => (string) $label, 'value' => (float) $value];
    }

    if (empty($rows)) {
        echo '<div class="empty-widget-message"><i class="fas fa-chart-area"></i><p>Aucune donnée à afficher</p></div>';
        return;
    }

    $max = max(array_map(static fn($r) => $r['value'], $rows)) ?: 1;

    echo '<div class="widget-chart-bars" data-widget="' . htmlspecialchars($widgetKey) . '">';
    foreach ($rows as $r) {
        $pct = max(2, (int) round((float) ($r['value'] / $max * 100)));
        $val = rtrim(rtrim(number_format((float) ($r['value']), 1, '.', ' '), '0'), '.');
        echo '<div class="chart-bar-row">';
        echo '  <span class="chart-bar-label">' . htmlspecialchars($r['label']) . '</span>';
        echo '  <span class="chart-bar-track"><span class="chart-bar-fill" style="width:' . $pct . '%"></span></span>';
        echo '  <span class="chart-bar-value">' . htmlspecialchars($val) . '</span>';
        echo '</div>';
    }
    echo '</div>';
}

function renderListWidget(array $data, string $widgetKey): void
{
    $items = $data['items'] ?? [];

    if (empty($items)) {
        echo '<div class="empty-widget-message">';
        echo '  <i class="fas fa-info-circle"></i>';
        echo '  <p>Aucun element a afficher</p>';
        echo '</div>';
        return;
    }

    echo '<div class="widget-list-scroll">';
    echo '<ul class="widget-list">';

    foreach ($items as $item) {
        echo '<li class="widget-list-item">';

        // Determine rendering based on widget key and available fields
        if ($widgetKey === 'dernieres_notes' && isset($item['note'])) {
            $noteSur = $item['note_sur'] ?? 20;
            echo '<div class="widget-list-badge badge-primary">' . htmlspecialchars($item['note']) . '/' . $noteSur . '</div>';
            echo '<div class="widget-list-info">';
            echo '  <div class="widget-list-title">' . htmlspecialchars($item['nom_matiere'] ?? '') . '</div>';
            echo '  <div class="widget-list-sub">' . (!empty($item['date_creation']) ? date('d/m/Y', strtotime($item['date_creation'])) : '') . '</div>';
            echo '</div>';
        } elseif ($widgetKey === 'devoirs_a_faire' && isset($item['date_rendu'])) {
            echo '<div class="widget-list-badge badge-success">' . date('d/m', strtotime($item['date_rendu'])) . '</div>';
            echo '<div class="widget-list-info">';
            echo '  <div class="widget-list-title">' . htmlspecialchars($item['titre'] ?? '') . '</div>';
            echo '  <div class="widget-list-sub">' . htmlspecialchars(($item['nom_matiere'] ?? '') . ' - ' . ($item['nom_professeur'] ?? '')) . '</div>';
            echo '</div>';
        } elseif (($widgetKey === 'prochains_evenements' || $widgetKey === 'reunions_a_venir') && isset($item['date_debut'])) {
            $typeEvt = strtolower($item['type_evenement'] ?? 'autre');
            echo '<div class="widget-list-badge badge-info">' . date('d/m', strtotime($item['date_debut'])) . '</div>';
            echo '<div class="widget-list-info">';
            echo '  <div class="widget-list-title">' . htmlspecialchars($item['titre'] ?? '') . '</div>';
            echo '  <div class="widget-list-sub">' . date('H:i', strtotime($item['date_debut']));
            if (!empty($item['date_fin'])) echo ' - ' . date('H:i', strtotime($item['date_fin']));
            echo '</div>';
            echo '</div>';
        } elseif ($widgetKey === 'annonces_recentes' && isset($item['titre'])) {
            $priorite = $item['priorite'] ?? 'normale';
            $badgeClass = $priorite === 'urgente' ? 'badge-danger' : ($priorite === 'importante' ? 'badge-warning' : 'badge-info');
            echo '<div class="widget-list-badge ' . $badgeClass . '"><i class="fas fa-bullhorn"></i></div>';
            echo '<div class="widget-list-info">';
            echo '  <div class="widget-list-title">' . htmlspecialchars($item['titre']) . '</div>';
            echo '  <div class="widget-list-sub">' . htmlspecialchars($item['auteur'] ?? '') . (!empty($item['date_publication']) ? ' - ' . date('d/m/Y', strtotime($item['date_publication'])) : '') . '</div>';
            echo '</div>';
        } elseif ($widgetKey === 'absences_du_jour' && isset($item['nom_eleve'])) {
            echo '<div class="widget-list-badge badge-danger"><i class="fas fa-user-times"></i></div>';
            echo '<div class="widget-list-info">';
            echo '  <div class="widget-list-title">' . htmlspecialchars($item['nom_eleve']) . '</div>';
            echo '  <div class="widget-list-sub">' . htmlspecialchars($item['classe'] ?? '') . ' - ' . htmlspecialchars($item['statut'] ?? '') . '</div>';
            echo '</div>';
        } else {
            // Generic fallback
            echo '<div class="widget-list-info">';
            $title = $item['titre'] ?? $item['nom_eleve'] ?? $item['label'] ?? '';
            $sub   = $item['description'] ?? $item['date_debut'] ?? '';
            echo '  <div class="widget-list-title">' . htmlspecialchars((string) $title) . '</div>';
            if ($sub) echo '  <div class="widget-list-sub">' . htmlspecialchars((string) $sub) . '</div>';
            echo '</div>';
        }

        echo '</li>';
    }

    echo '</ul>';
    echo '</div>';
}

function renderCalendarWidget(array $data): void
{
    $items = $data['items'] ?? [];

    if (empty($items)) {
        echo '<div class="widget-calendar-mini">';
        echo '  <div class="calendar-today">';
        echo '    <div class="calendar-today-day">' . date('d') . '</div>';
        $moisFr = ['', 'janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin', 'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'];
    echo '    <div class="calendar-today-month">' . ($moisFr[(int)date('n')] ?? '') . ' ' . date('Y') . '</div>';
        echo '  </div>';
        echo '  <div class="empty-widget-message">';
        echo '    <i class="fas fa-check-circle"></i>';
        echo '    <p>Aucun cours aujourd\'hui</p>';
        echo '  </div>';
        echo '</div>';
        return;
    }

    echo '<div class="widget-calendar-mini">';
    echo '  <div class="calendar-day-header">';
    $joursFr = ['', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    $jourNum = (int) date('N');
    echo '    <span class="calendar-day-name">' . ($joursFr[$jourNum] ?? '') . ' ' . date('d/m/Y') . '</span>';
    echo '  </div>';
    echo '  <div class="calendar-timeline">';

    foreach ($items as $cours) {
        $hDebut = $cours['heure_debut'] ?? '';
        $hFin   = $cours['heure_fin'] ?? '';
        $matiere = $cours['matiere'] ?? '';
        $lieu    = $cours['salle'] ?? $cours['classe'] ?? '';
        $prof    = $cours['professeur'] ?? '';

        echo '<div class="calendar-slot">';
        echo '  <div class="calendar-slot-time">';
        if ($hDebut) echo htmlspecialchars(substr($hDebut, 0, 5));
        echo '  </div>';
        echo '  <div class="calendar-slot-content">';
        echo '    <div class="calendar-slot-title">' . htmlspecialchars($matiere) . '</div>';
        echo '    <div class="calendar-slot-sub">';
        $parts = array_filter([$lieu, $prof]);
        echo htmlspecialchars(implode(' - ', $parts));
        echo '    </div>';
        echo '  </div>';
        echo '</div>';
    }

    echo '  </div>';
    echo '</div>';
}

function renderShortcutWidget(array $data): void
{
    $items = $data['items'] ?? [];

    if (empty($items)) {
        echo '<div class="empty-widget-message"><i class="fas fa-info-circle"></i><p>Aucun raccourci</p></div>';
        return;
    }

    echo '<div class="widget-shortcuts-grid">';
    foreach ($items as $mod) {
        $href  = $mod['href'] ?? '#';
        $icon  = $mod['icon'] ?? 'fas fa-link';
        $title = $mod['title'] ?? '';
        echo '<a href="' . htmlspecialchars($href) . '" class="widget-shortcut-item">';
        echo '  <div class="widget-shortcut-icon"><i class="' . htmlspecialchars($icon) . '"></i></div>';
        echo '  <span>' . htmlspecialchars($title) . '</span>';
        echo '</a>';
    }
    echo '</div>';
}

// Pass data to JS
$jsWidgetConfig = [];
foreach ($userWidgets as $idx => $w) {
    $jsWidgetConfig[] = [
        'widget_key' => $w['widget_key'],
        'position_x' => (int) ($w['position_x'] ?? 0),
        'position_y' => (int) ($w['position_y'] ?? $idx),
        'width'      => (int) ($w['width'] ?? $w['default_width'] ?? 2),
        'height'     => (int) ($w['height'] ?? $w['default_height'] ?? 1),
        'visible'    => (int) ($w['visible'] ?? 1),
        'label'      => $w['label'] ?? '',
        'icon'       => $w['icon'] ?? '',
        'type'       => $w['type'] ?? 'list',
    ];
}

$jsAvailableWidgets = [];
foreach ($availableAll as $aw) {
    $jsAvailableWidgets[] = [
        'widget_key'  => $aw['widget_key'],
        'label'       => $aw['label'],
        'description' => $aw['description'] ?? '',
        'icon'        => $aw['icon'] ?? 'fas fa-puzzle-piece',
        'type'        => $aw['type'] ?? 'list',
    ];
}

$_nonceAttr = ' nonce="' . htmlspecialchars(csp_nonce(), ENT_QUOTES) . '"';
$extraScriptHtml = '<script' . $_nonceAttr . '>
window.DASHBOARD_CONFIG = ' . json_encode($jsWidgetConfig, JSON_HEX_TAG | JSON_HEX_AMP) . ';
window.DASHBOARD_AVAILABLE = ' . json_encode($jsAvailableWidgets, JSON_HEX_TAG | JSON_HEX_AMP) . ';
window.DASHBOARD_CSRF = ' . json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP) . ';
</script>
<script' . $_nonceAttr . '>
(function() {
    "use strict";

    var grid = document.getElementById("widgetGrid");
    var content = document.querySelector(".dashboard-content") || document.body;
    var editToolbar = document.getElementById("dashboardEditToolbar");
    var addPanel = document.getElementById("addWidgetPanel");
    var addGrid = document.getElementById("addWidgetGrid");
    var btnCustomize = document.getElementById("btnPersonnaliser");
    var editing = false;
    var dragSrcEl = null;

    // ── Helpers ──────────────────────────────────────────────────────────
    function escapeHtml(str) {
        if (!str) return "";
        var d = document.createElement("div");
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }
    function showToast(message, type) {
        var t = document.createElement("div");
        t.className = "dashboard-toast toast-" + (type || "info");
        t.textContent = message;
        document.body.appendChild(t);
        setTimeout(function() { t.classList.add("toast-visible"); }, 10);
        setTimeout(function() { t.classList.remove("toast-visible"); setTimeout(function() { t.remove(); }, 300); }, 2500);
    }
    function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }
    function cards() { return Array.prototype.slice.call(grid.querySelectorAll(".widget-card")); }

    // ── Drag & drop pour réordonner (mode édition uniquement) ────────────
    function handleDragStart(e) {
        if (!editing) { e.preventDefault(); return; }
        dragSrcEl = this;
        this.classList.add("widget-dragging");
        e.dataTransfer.effectAllowed = "move";
        try { e.dataTransfer.setData("text/plain", this.dataset.widgetKey); } catch (x) {}
    }
    function handleDragOver(e) { if (!editing) return; e.preventDefault(); e.dataTransfer.dropEffect = "move"; this.classList.add("widget-drag-over"); return false; }
    function handleDragEnter(e) { if (editing) this.classList.add("widget-drag-over"); }
    function handleDragLeave(e) { this.classList.remove("widget-drag-over"); }
    function handleDrop(e) {
        e.stopPropagation(); e.preventDefault();
        this.classList.remove("widget-drag-over");
        if (editing && dragSrcEl && dragSrcEl !== this) {
            var all = cards();
            var from = all.indexOf(dragSrcEl), to = all.indexOf(this);
            if (from < to) grid.insertBefore(dragSrcEl, this.nextSibling);
            else grid.insertBefore(dragSrcEl, this);
        }
        return false;
    }
    function handleDragEnd() {
        this.classList.remove("widget-dragging");
        cards().forEach(function(c) { c.classList.remove("widget-drag-over"); });
    }
    function bindCard(card) {
        card.addEventListener("dragstart", handleDragStart, false);
        card.addEventListener("dragenter", handleDragEnter, false);
        card.addEventListener("dragover", handleDragOver, false);
        card.addEventListener("dragleave", handleDragLeave, false);
        card.addEventListener("drop", handleDrop, false);
        card.addEventListener("dragend", handleDragEnd, false);
    }

    // ── Réduire / déplier un widget ──────────────────────────────────────
    function toggleWidgetBody(btn) {
        var card = btn.closest(".widget-card");
        var body = card.querySelector(".widget-card-body");
        var footer = card.querySelector(".widget-card-footer");
        var icon = btn.querySelector("i");
        if (card.classList.contains("widget-minimized")) {
            card.classList.remove("widget-minimized");
            if (body) body.classList.remove("is-hidden");
            if (footer) footer.classList.remove("is-hidden");
            if (icon) icon.className = "fas fa-chevron-up";
        } else {
            card.classList.add("widget-minimized");
            if (body) body.classList.add("is-hidden");
            if (footer) footer.classList.add("is-hidden");
            if (icon) icon.className = "fas fa-chevron-down";
        }
    }

    // ── Redimensionnement (largeur/hauteur) ──────────────────────────────
    function applySize(card) {
        var w = parseInt(card.dataset.width, 10) || 2;
        var h = parseInt(card.dataset.height, 10) || 1;
        card.style.setProperty("--w", w);
        card.style.setProperty("--h", h);
        var wv = card.querySelector("[data-val=w]"); if (wv) wv.textContent = w;
        var hv = card.querySelector("[data-val=h]"); if (hv) hv.textContent = h;
        // désactiver les boutons aux bornes
        var min = parseInt(card.dataset.min, 10) || 1, max = parseInt(card.dataset.max, 10) || 4;
        var b;
        b = card.querySelector("[data-resize=w-minus]"); if (b) b.disabled = (w <= min);
        b = card.querySelector("[data-resize=w-plus]");  if (b) b.disabled = (w >= max);
        b = card.querySelector("[data-resize=h-minus]"); if (b) b.disabled = (h <= 1);
        b = card.querySelector("[data-resize=h-plus]");  if (b) b.disabled = (h >= 3);
    }
    function handleResize(btn) {
        var card = btn.closest(".widget-card");
        if (!card) return;
        var act = btn.getAttribute("data-resize");
        var w = parseInt(card.dataset.width, 10) || 2;
        var h = parseInt(card.dataset.height, 10) || 1;
        var min = parseInt(card.dataset.min, 10) || 1, max = parseInt(card.dataset.max, 10) || 4;
        if (act === "w-minus") card.dataset.width = clamp(w - 1, min, max);
        else if (act === "w-plus") card.dataset.width = clamp(w + 1, min, max);
        else if (act === "h-minus") card.dataset.height = clamp(h - 1, 1, 3);
        else if (act === "h-plus") card.dataset.height = clamp(h + 1, 1, 3);
        else if (act === "remove") { removeWidget(card); return; }
        applySize(card);
    }

    // ── Ajouter / retirer ────────────────────────────────────────────────
    function currentKeys() {
        var m = {}; cards().forEach(function(c) { m[c.dataset.widgetKey] = true; }); return m;
    }
    function buildAddPanel() {
        if (!addGrid) return;
        var present = currentKeys();
        var hidden = (window.DASHBOARD_AVAILABLE || []).filter(function(w) { return !present[w.widget_key]; });
        addGrid.innerHTML = "";
        if (!hidden.length) { addGrid.innerHTML = "<p class=\"add-widget-empty\">Tous les widgets sont déjà sur le tableau de bord.</p>"; return; }
        hidden.forEach(function(w) {
            var b = document.createElement("button");
            b.type = "button";
            b.className = "add-widget-item";
            b.setAttribute("data-add-widget", w.widget_key);
            b.innerHTML = "<i class=\"aw-ico " + escapeHtml(w.icon) + "\"></i><span>" + escapeHtml(w.label) + "</span><i class=\"fas fa-plus aw-add\"></i>";
            addGrid.appendChild(b);
        });
    }
    function removeWidget(card) {
        card.parentNode.removeChild(card);
        buildAddPanel();
    }
    function addWidget(key) {
        var meta = (window.DASHBOARD_AVAILABLE || []).filter(function(w) { return w.widget_key === key; })[0];
        if (!meta) return;
        var card = document.createElement("div");
        card.className = "widget-card is-new";
        card.style.setProperty("--w", 2); card.style.setProperty("--h", 1);
        card.setAttribute("draggable", "true");
        card.dataset.widgetKey = key;
        card.dataset.widgetType = meta.type || "list";
        card.dataset.width = 2; card.dataset.height = 1; card.dataset.min = 1; card.dataset.max = 4;
        card.dataset.label = meta.label; card.dataset.icon = meta.icon;
        card.innerHTML =
            "<div class=\"widget-card-header\"><div class=\"widget-card-title\"><i class=\"" + escapeHtml(meta.icon) + "\"></i><span>" + escapeHtml(meta.label) + "</span></div>" +
            "<div class=\"widget-card-actions\"><button type=\"button\" class=\"widget-btn widget-btn-drag\" title=\"Déplacer\"><i class=\"fas fa-grip-vertical\"></i></button></div></div>" +
            "<div class=\"widget-card-body\"><p class=\"add-widget-empty\"><i class=\"fas fa-circle-info\"></i> Sera affiché après enregistrement.</p></div>" +
            "<div class=\"widget-edit-bar\"><div class=\"widget-edit-group\"><span class=\"weg-label\">Largeur</span>" +
            "<button type=\"button\" class=\"widget-edit-btn\" data-resize=\"w-minus\"><i class=\"fas fa-minus\"></i></button><span class=\"widget-edit-val\" data-val=\"w\">2</span>" +
            "<button type=\"button\" class=\"widget-edit-btn\" data-resize=\"w-plus\"><i class=\"fas fa-plus\"></i></button></div>" +
            "<div class=\"widget-edit-group\"><span class=\"weg-label\">Hauteur</span>" +
            "<button type=\"button\" class=\"widget-edit-btn\" data-resize=\"h-minus\"><i class=\"fas fa-minus\"></i></button><span class=\"widget-edit-val\" data-val=\"h\">1</span>" +
            "<button type=\"button\" class=\"widget-edit-btn\" data-resize=\"h-plus\"><i class=\"fas fa-plus\"></i></button></div>" +
            "<button type=\"button\" class=\"widget-edit-btn widget-edit-remove\" data-resize=\"remove\"><i class=\"fas fa-eye-slash\"></i></button></div>";
        grid.appendChild(card);
        bindCard(card);
        applySize(card);
        buildAddPanel();
    }

    // ── Collecte + sauvegarde ────────────────────────────────────────────
    function collectLayout() {
        var layout = [];
        cards().forEach(function(card, idx) {
            layout.push({
                widget_key: card.dataset.widgetKey,
                position_x: 0, position_y: idx,
                width: parseInt(card.dataset.width, 10) || 2,
                height: parseInt(card.dataset.height, 10) || 1,
                visible: 1
            });
        });
        // widgets retirés (disponibles mais absents de la grille) → visible 0
        var present = currentKeys();
        (window.DASHBOARD_AVAILABLE || []).forEach(function(w) {
            if (!present[w.widget_key]) layout.push({ widget_key: w.widget_key, position_x: 0, position_y: 99, width: 2, height: 1, visible: 0 });
        });
        return layout;
    }
    function postLayout(layout, cb) {
        fetch("ajax_dashboard.php", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action: "save_layout", csrf_token: window.DASHBOARD_CSRF, layout: layout })
        }).then(function(r) { return r.json(); }).then(function(d) { cb(!!(d && d.success), d); }).catch(function() { cb(false, null); });
    }
    function resetLayout() {
        if (!window.confirm("Réinitialiser le tableau de bord à sa disposition par défaut ?")) return;
        fetch("ajax_dashboard.php", {
            method: "POST", headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action: "reset_layout", csrf_token: window.DASHBOARD_CSRF })
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d && d.success) { showToast("Tableau de bord réinitialisé", "success"); setTimeout(function() { location.reload(); }, 500); }
            else showToast("Échec de la réinitialisation", "error");
        }).catch(function() { showToast("Erreur réseau", "error"); });
    }

    // ── Mode édition ─────────────────────────────────────────────────────
    function enterEditMode() {
        editing = true;
        content.classList.add("dashboard-editing");
        cards().forEach(function(c) { c.setAttribute("draggable", "true"); applySize(c); });
        buildAddPanel();
        if (btnCustomize) btnCustomize.classList.add("is-active");
        if (editToolbar) editToolbar.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }
    function exitEditMode(save) {
        if (save) {
            postLayout(collectLayout(), function(ok) {
                if (ok) { showToast("Tableau de bord enregistré", "success"); setTimeout(function() { location.reload(); }, 500); }
                else showToast("Échec de l\'enregistrement", "error");
            });
        } else {
            location.reload();
        }
    }

    // ── Événements ───────────────────────────────────────────────────────
    document.addEventListener("click", function(e) {
        var tgl = e.target.closest("[data-widget-action]");
        if (tgl && tgl.getAttribute("data-widget-action") === "toggle" && !editing) { toggleWidgetBody(tgl); return; }
        var rz = e.target.closest("[data-resize]");
        if (rz) { e.preventDefault(); handleResize(rz); return; }
        var aw = e.target.closest("[data-add-widget]");
        if (aw) { addWidget(aw.getAttribute("data-add-widget")); return; }
        var ea = e.target.closest("[data-edit-action]");
        if (ea) {
            var a = ea.getAttribute("data-edit-action");
            if (a === "done") exitEditMode(true);
            else if (a === "cancel") exitEditMode(false);
            else if (a === "reset") resetLayout();
            else if (a === "add" && addPanel) addPanel.classList.toggle("is-open");
            return;
        }
    });

    if (btnCustomize) btnCustomize.addEventListener("click", function() { if (editing) exitEditMode(true); else enterEditMode(); });

    document.addEventListener("DOMContentLoaded", function() {
        cards().forEach(function(card) { bindCard(card); });
    });
    cards().forEach(function(card) { bindCard(card); });
})();
</script>';

include __DIR__ . '/../templates/shared_footer.php';
ob_end_flush();
?>
