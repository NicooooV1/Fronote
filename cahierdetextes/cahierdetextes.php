<?php
// Démarrer la mise en mémoire tampon
ob_start();

// Inclure les fichiers nécessaires
require_once 'includes/auth.php';
require_once 'includes/db.php';

// Vérifier si l'utilisateur est connecté
if (!isLoggedIn()) {
    header('Location: ../login/public/index.php');
    exit;
}

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: ../login/public/index.php');
    exit;
}

$user_role = $user['profil'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_initials = strtoupper(mb_substr($user['prenom'], 0, 1) . mb_substr($user['nom'], 0, 1));

// Initialiser les variables pour éviter les notices
$order = [];
$order['field'] = isset($_GET['order']) ? $_GET['order'] : 'date_rendu';
$order['direction'] = isset($_GET['dir']) && $_GET['dir'] === 'asc' ? 'asc' : 'desc';
$filterClass = isset($_GET['classe']) ? $_GET['classe'] : '';
$filterMatiere = isset($_GET['matiere']) ? $_GET['matiere'] : '';
$filterProfesseur = isset($_GET['professeur']) ? $_GET['professeur'] : '';
$displayMode = isset($_GET['mode']) ? $_GET['mode'] : 'list';

// Date actuelle pour les calculs de délais
$aujourdhui = new DateTime();

// Charger la liste des devoirs
try {
    // Vérifier si la table existe
    $tableExists = false;
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE 'devoirs'");
        $tableExists = $checkTable->rowCount() > 0;
    } catch (PDOException $e) {
        $tableExists = false;
    }
    
    if (!$tableExists) {
        // Créer la table si elle n'existe pas
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS devoirs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                titre VARCHAR(255) NOT NULL,
                description TEXT,
                classe VARCHAR(50) NOT NULL,
                nom_matiere VARCHAR(100) NOT NULL,
                nom_professeur VARCHAR(100) NOT NULL,
                date_ajout DATE NOT NULL,
                date_rendu DATE NOT NULL,
                date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
    
    // Récupération des devoirs selon le rôle de l'utilisateur
    if (isStudent()) {
        $stmt_eleve = $pdo->prepare('SELECT classe FROM eleves WHERE prenom = ? AND nom = ?');
        $stmt_eleve->execute([$user['prenom'], $user['nom']]);
        $eleve_data = $stmt_eleve->fetch();
        $classe_eleve = $eleve_data ? $eleve_data['classe'] : '';
        
        if (empty($classe_eleve)) {
            switch ($order['field']) {
                case 'nom_matiere':
                    $sql = 'SELECT * FROM devoirs ORDER BY nom_matiere ' . $order['direction'] . ', date_rendu ASC';
                    break;
                case 'date_ajout':
                    $sql = 'SELECT * FROM devoirs ORDER BY date_ajout ' . $order['direction'];
                    break;
                default:
                    $sql = 'SELECT * FROM devoirs ORDER BY date_rendu ' . $order['direction'];
            }
            
            $stmt = $pdo->query($sql);
        } else {
            switch ($order['field']) {
                case 'nom_matiere':
                    $sql = 'SELECT * FROM devoirs WHERE classe = ? ORDER BY nom_matiere ' . $order['direction'] . ', date_rendu ASC';
                    break;
                case 'date_ajout':
                    $sql = 'SELECT * FROM devoirs WHERE classe = ? ORDER BY date_ajout ' . $order['direction'];
                    break;
                default:
                    $sql = 'SELECT * FROM devoirs WHERE classe = ? ORDER BY date_rendu ' . $order['direction'];
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$classe_eleve]);
        }
    }
    elseif (isParent()) {
        // Future implementation: get children's classes
        switch ($order['field']) {
            case 'nom_matiere':
                $sql = 'SELECT * FROM devoirs ORDER BY nom_matiere ' . $order['direction'] . ', date_rendu ASC';
                break;
            case 'classe':
                $sql = 'SELECT * FROM devoirs ORDER BY classe ' . $order['direction'] . ', date_rendu ASC';
                break;
            case 'date_ajout':
                $sql = 'SELECT * FROM devoirs ORDER BY date_ajout ' . $order['direction'];
                break;
            default:
                $sql = 'SELECT * FROM devoirs ORDER BY date_rendu ' . $order['direction'];
        }
        
        $stmt = $pdo->query($sql);
    }
    elseif (isTeacher()) {
        switch ($order['field']) {
            case 'nom_matiere':
                $sql = 'SELECT * FROM devoirs WHERE nom_professeur = ? ORDER BY nom_matiere ' . $order['direction'] . ', date_rendu ASC';
                break;
            case 'classe':
                $sql = 'SELECT * FROM devoirs WHERE nom_professeur = ? ORDER BY classe ' . $order['direction'] . ', date_rendu ASC';
                break;
            case 'date_ajout':
                $sql = 'SELECT * FROM devoirs WHERE nom_professeur = ? ORDER BY date_ajout ' . $order['direction'];
                break;
            default:
                $sql = 'SELECT * FROM devoirs WHERE nom_professeur = ? ORDER BY date_rendu ' . $order['direction'];
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_fullname]);
    }
    else {
        switch ($order['field']) {
            case 'nom_matiere':
                $sql = 'SELECT * FROM devoirs ORDER BY nom_matiere ' . $order['direction'] . ', date_rendu ASC';
                break;
            case 'classe':
                $sql = 'SELECT * FROM devoirs ORDER BY classe ' . $order['direction'] . ', date_rendu ASC';
                break;
            case 'date_ajout':
                $sql = 'SELECT * FROM devoirs ORDER BY date_ajout ' . $order['direction'];
                break;
            default:
                $sql = 'SELECT * FROM devoirs ORDER BY date_rendu ' . $order['direction'];
        }
        
        $stmt = $pdo->query($sql);
    }
    
    // Récupérer tous les devoirs pour les statistiques et les filtres
    $devoirs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculer les statistiques
    $totalDevoirs = count($devoirs);
    $urgentDevoirs = 0;
    $soonDevoirs = 0;
    $expiredDevoirs = 0;
    
    // Extraire les valeurs uniques pour les filtres
    $classes = [];
    $matieres = [];
    $professeurs = [];
    
    foreach ($devoirs as $devoir) {
        // Statistiques
        $date_rendu = new DateTime($devoir['date_rendu']);
        $diff = $aujourdhui->diff($date_rendu);
        
        if ($date_rendu < $aujourdhui) {
            $expiredDevoirs++;
        } elseif ($diff->days <= 3) {
            $urgentDevoirs++;
        } elseif ($diff->days <= 7) {
            $soonDevoirs++;
        }
        
        // Valeurs uniques pour filtres
        if (!in_array($devoir['classe'], $classes)) {
            $classes[] = $devoir['classe'];
        }
        
        if (!in_array($devoir['nom_matiere'], $matieres)) {
            $matieres[] = $devoir['nom_matiere'];
        }
        
        if (!in_array($devoir['nom_professeur'], $professeurs)) {
            $professeurs[] = $devoir['nom_professeur'];
        }
    }
    
    // Trier les listes
    sort($classes);
    sort($matieres);
    sort($professeurs);
    
} catch (PDOException $e) {
    // Journal d'erreurs
    error_log("Erreur dans cahierdetextes.php: " . $e->getMessage());
    $devoirs = [];
    $totalDevoirs = 0;
    $urgentDevoirs = 0;
    $soonDevoirs = 0;
    $expiredDevoirs = 0;
    $classes = [];
    $matieres = [];
    $professeurs = [];
}

// Fonction pour vérifier l'existence des fonctions d'autorisation
if (!function_exists('canManageDevoirs')) {
    function canManageDevoirs() {
        $role = isset($_SESSION['user']) ? $_SESSION['user']['profil'] : '';
        return in_array($role, ['administrateur', 'professeur', 'vie_scolaire']);
    }
}

// Variables pour le template
$pageTitle = "Cahier de Textes";
$moduleClass = "cahier";
$moduleColor = "var(--accent-cahier)";

// Actions du header
$headerActions = <<<HTML
<div class="view-toggle">
    <a href="?mode=list" class="view-toggle-option <?= $displayMode !== 'calendar' ? 'active' : '' ?>">
        <i class="fas fa-list"></i> Liste
    </a>
    <a href="?mode=calendar" class="view-toggle-option <?= $displayMode === 'calendar' ? 'active' : '' ?>">
        <i class="fas fa-calendar-alt"></i> Calendrier
    </a>
</div>
HTML;

// Contenu de la sidebar
$sidebarContent = <<<HTML
<div class="sidebar-section">
    <div class="sidebar-title">Filtres</div>
    <div class="sidebar-menu">
        <div class="sidebar-link filter-link" data-filter="urgent">
            <i class="fas fa-exclamation-circle"></i> Urgent (à rendre dans 3 jours)
        </div>
        <div class="sidebar-link filter-link" data-filter="soon">
            <i class="fas fa-clock"></i> À rendre cette semaine
        </div>
        <div class="sidebar-link filter-link" data-filter="all">
            <i class="fas fa-list"></i> Tous les devoirs
        </div>
    </div>
</div>

<div class="sidebar-section">
    <div class="sidebar-title">Actions</div>
    <div class="sidebar-menu">
        <a href="cahierdetextes.php" class="sidebar-link active">
            <i class="fas fa-list"></i> Liste des devoirs
        </a>
        
        <?php if (canManageDevoirs()): ?>
        <a href="ajouter_devoir.php" class="sidebar-link">
            <i class="fas fa-plus"></i> Ajouter un devoir
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="sidebar-section">
    <div class="sidebar-title">Autres modules</div>
    <div class="sidebar-menu">
        <a href="../notes/notes.php" class="sidebar-link">
            <i class="fas fa-chart-bar"></i> Notes
        </a>
        <a href="../absences/absences.php" class="sidebar-link">
            <i class="fas fa-calendar-times"></i> Absences
        </a>
        <a href="../agenda/agenda.php" class="sidebar-link">
            <i class="fas fa-calendar-alt"></i> Agenda
        </a>
        <a href="../messagerie/index.php" class="sidebar-link">
            <i class="fas fa-envelope"></i> Messagerie
        </a>
        <a href="../accueil/accueil.php" class="sidebar-link">
            <i class="fas fa-home"></i> Accueil
        </a>
    </div>
</div>
HTML;

include '../assets/css/templates/header-template.php';
?>

<!-- Bannière de bienvenue -->
<div class="welcome-banner">
    <div class="welcome-content">
        <h2>Cahier de Textes</h2>
        <p>Consultez et gérez les devoirs à faire</p>
    </div>
    <div class="welcome-icon">
        <i class="fas fa-book"></i>
    </div>
</div>

<!-- Dashboard des devoirs -->
<div class="devoirs-dashboard">
    <div class="summary-card total-summary">
        <div class="summary-icon">
            <i class="fas fa-book"></i>
        </div>
        <div class="summary-content">
            <div class="summary-value"><?= $totalDevoirs ?></div>
            <div class="summary-label">Total des devoirs</div>
        </div>
    </div>
    
    <div class="summary-card urgent-summary">
        <div class="summary-icon">
            <i class="fas fa-exclamation-circle"></i>
        </div>
        <div class="summary-content">
            <div class="summary-value"><?= $urgentDevoirs ?></div>
            <div class="summary-label">Devoirs urgents (< 3 jours)</div>
        </div>
    </div>
    
    <div class="summary-card">
        <div class="summary-icon">
            <i class="fas fa-clock"></i>
        </div>
        <div class="summary-content">
            <div class="summary-value"><?= $soonDevoirs ?></div>
            <div class="summary-label">À rendre cette semaine</div>
        </div>
    </div>
</div>

<!-- Barre de filtres -->
<div class="filter-toolbar">
    <div class="filter-buttons">
        <a href="?order=date_rendu" class="btn <?= (!isset($_GET['order']) || $_GET['order'] == 'date_rendu') ? 'btn-primary' : 'btn-secondary' ?>">
            <i class="fas fa-calendar-day"></i> Par date de rendu
        </a>
        <a href="?order=date_ajout" class="btn <?= (isset($_GET['order']) && $_GET['order'] == 'date_ajout') ? 'btn-primary' : 'btn-secondary' ?>">
            <i class="fas fa-clock"></i> Par date d'ajout
        </a>
        <a href="?order=nom_matiere" class="btn <?= (isset($_GET['order']) && $_GET['order'] == 'nom_matiere') ? 'btn-primary' : 'btn-secondary' ?>">
            <i class="fas fa-book"></i> Par matière
        </a>
        <?php if (!isStudent() && !isParent()): ?>
        <a href="?order=classe" class="btn <?= (isset($_GET['order']) && $_GET['order'] == 'classe') ? 'btn-primary' : 'btn-secondary' ?>">
            <i class="fas fa-users"></i> Par classe
        </a>
        <?php endif; ?>
    </div>
    
    <?php if (canManageDevoirs()): ?>
    <div>
        <a href="ajouter_devoir.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Ajouter un devoir
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Vue en liste -->
<?php if ($displayMode !== 'calendar'): ?>
<div class="section">
    <?php if (empty($devoirs)): ?>
        <div class="alert-banner alert-info">
            <i class="fas fa-info-circle"></i>
            <div>Aucun devoir n'a été ajouté pour le moment.</div>
        </div>
    <?php else: ?>
        <div class="devoirs-list">
            <?php foreach ($devoirs as $devoir): ?>
                <?php
                // Calculer le statut du devoir
                $date_rendu = new DateTime($devoir['date_rendu']);
                $aujourdhui = new DateTime();
                $diff = $aujourdhui->diff($date_rendu);
                
                $statusClass = '';
                $statusBadge = '';
                $statusIcon = '';
                
                if ($date_rendu < $aujourdhui) {
                    $statusClass = 'expired';
                    $statusBadge = '<span class="badge badge-expired"><i class="fas fa-times-circle"></i>Expiré</span>';
                    $statusIcon = '<i class="fas fa-history"></i>';
                } elseif ($diff->days <= 3) {
                    $statusClass = 'urgent';
                    $statusBadge = '<span class="badge badge-urgent"><i class="fas fa-exclamation-circle"></i>Urgent</span>';
                    $statusIcon = '<i class="fas fa-exclamation-circle"></i>';
                } elseif ($diff->days <= 7) {
                    $statusClass = 'soon';
                    $statusBadge = '<span class="badge badge-soon"><i class="fas fa-clock"></i>Cette semaine</span>';
                    $statusIcon = '<i class="fas fa-clock"></i>';
                } else {
                    $statusIcon = '<i class="fas fa-book"></i>';
                }
                ?>
                <div class="devoir-card <?= $statusClass ?>" data-date="<?= $devoir['date_rendu'] ?>">
                    <div class="card-header">
                        <div class="devoir-title">
                            <?= $statusIcon ?> <?= htmlspecialchars($devoir['titre']) ?>
                        </div>
                        <div class="devoir-meta">
                            <span>Ajouté le: <?= date('d/m/Y', strtotime($devoir['date_ajout'])) ?></span>
                            <?= $statusBadge ?>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <div class="devoir-info-grid">
                            <div class="devoir-info">
                                <div class="info-label">Classe:</div>
                                <div class="info-value"><?= htmlspecialchars($devoir['classe']) ?></div>
                            </div>
                            
                            <div class="devoir-info">
                                <div class="info-label">Matière:</div>
                                <div class="info-value"><?= htmlspecialchars($devoir['nom_matiere']) ?></div>
                            </div>
                            
                            <div class="devoir-info">
                                <div class="info-label">Professeur:</div>
                                <div class="info-value"><?= htmlspecialchars($devoir['nom_professeur']) ?></div>
                            </div>
                            
                            <div class="devoir-info">
                                <div class="info-label">À rendre pour le:</div>
                                <div class="info-value date-rendu <?= $statusClass ?>">
                                    <?= date('d/m/Y', strtotime($devoir['date_rendu'])) ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="devoir-description">
                            <h4>Description:</h4>
                            <p><?= nl2br(htmlspecialchars($devoir['description'])) ?></p>
                        </div>
                        
                        <?php if (canManageDevoirs()): ?>
                            <!-- Si c'est un professeur, vérifier qu'il est bien l'auteur du devoir -->
                            <?php if (!isTeacher() || (isTeacher() && $devoir['nom_professeur'] == $user_fullname)): ?>
                                <div class="card-actions">
                                    <a href="modifier_devoir.php?id=<?= $devoir['id'] ?>" class="btn btn-secondary">
                                        <i class="fas fa-edit"></i> Modifier
                                    </a>
                                    <a href="supprimer_devoir.php?id=<?= $devoir['id'] ?>" class="btn btn-danger" 
                                       onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce devoir ?');">
                                        <i class="fas fa-trash"></i> Supprimer
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Vue en calendrier -->
<?php else: ?>
<div class="section">
    <?php
    // Génération du calendrier
    $current_month = date('n');
    $current_year = date('Y');
    
    if (isset($_GET['month']) && isset($_GET['year'])) {
        $month = (int)$_GET['month'];
        $year = (int)$_GET['year'];
    } else {
        $month = $current_month;
        $year = $current_year;
    }
    
    // Premier jour du mois
    $first_day = mktime(0, 0, 0, $month, 1, $year);
    
    // Nombre de jours dans le mois
    $num_days = date('t', $first_day);
    
    // Jour de la semaine du premier jour (0=dimanche, 6=samedi)
    $day_of_week = date('w', $first_day);
    
    // Ajuster pour commencer par lundi
    if ($day_of_week == 0) {
        $day_of_week = 6;
    } else {
        $day_of_week--;
    }
    
    // Mois et année précédents/suivants
    $prev_month = $month - 1;
    $prev_year = $year;
    if ($prev_month <= 0) {
        $prev_month = 12;
        $prev_year--;
    }
    
    $next_month = $month + 1;
    $next_year = $year;
    if ($next_month > 12) {
        $next_month = 1;
        $next_year++;
    }
    
    // Noms des mois en français
    $months = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars',
        4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
        7 => 'Juillet', 8 => 'Août', 9 => 'Septembre',
        10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
    ];
    
    // Noms des jours en français
    $days = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
    
    // Organiser les devoirs par date
    $devoirs_by_date = [];
    foreach ($devoirs as $devoir) {
        $date = $devoir['date_rendu'];
        if (!isset($devoirs_by_date[$date])) {
            $devoirs_by_date[$date] = [];
        }
        $devoirs_by_date[$date][] = $devoir;
    }
    ?>
    
    <div class="calendar-container">
        <div class="calendar-header">
            <div class="calendar-title">
                <?= $months[$month] ?> <?= $year ?>
            </div>
            <div class="calendar-nav">
                <a href="?mode=calendar&month=<?= $prev_month ?>&year=<?= $prev_year ?>" class="calendar-nav-btn">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <a href="?mode=calendar&month=<?= $current_month ?>&year=<?= $current_year ?>" class="calendar-nav-btn">
                    <i class="fas fa-circle"></i>
                </a>
                <a href="?mode=calendar&month=<?= $next_month ?>&year=<?= $next_year ?>" class="calendar-nav-btn">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        </div>
        
        <div class="calendar-grid">
            <?php foreach ($days as $day): ?>
                <div class="calendar-weekday"><?= $day ?></div>
            <?php endforeach; ?>
            
            <?php
            // Cases vides avant le premier jour du mois
            for ($i = 0; $i < $day_of_week; $i++) {
                echo '<div class="calendar-day other-month"></div>';
            }
            
            // Jours du mois
            for ($day = 1; $day <= $num_days; $day++) {
                $date = date('Y-m-d', mktime(0, 0, 0, $month, $day, $year));
                $is_today = ($day == date('j') && $month == date('n') && $year == date('Y'));
                
                echo '<div class="calendar-day' . ($is_today ? ' today' : '') . '">';
                echo '<div class="calendar-date">' . $day . '</div>';
                
                // Afficher les devoirs pour cette date
                if (isset($devoirs_by_date[$date])) {
                    foreach ($devoirs_by_date[$date] as $devoir) {
                        // Déterminer le statut du devoir
                        $date_rendu = new DateTime($devoir['date_rendu']);
                        $aujourdhui = new DateTime();
                        $diff = $aujourdhui->diff($date_rendu);
                        
                        $statusClass = '';
                        if ($date_rendu < $aujourdhui) {
                            $statusClass = 'expired';
                        } elseif ($diff->days <= 3) {
                            $statusClass = 'urgent';
                        } elseif ($diff->days <= 7) {
                            $statusClass = 'soon';
                        }
                        
                        echo '<div class="calendar-event ' . $statusClass . '" 
                                  title="' . htmlspecialchars($devoir['titre'] . ' - ' . $devoir['nom_matiere']) . '">' .
                              htmlspecialchars($devoir['titre']) .
                             '</div>';
                    }
                }
                
                echo '</div>';
                
                // Nouvelle ligne pour dimanche
                if (($day_of_week + $day) % 7 == 0) {
                    // echo '</div><div class="calendar-grid">';
                }
            }
            
            // Cases vides après le dernier jour du mois
            $remaining_days = 7 - (($day_of_week + $num_days) % 7);
            if ($remaining_days < 7) {
                for ($i = 0; $i < $remaining_days; $i++) {
                    echo '<div class="calendar-day other-month"></div>';
                }
            }
            ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Filtrage des devoirs
document.addEventListener('DOMContentLoaded', function() {
    // Filtres de la sidebar
    const filterLinks = document.querySelectorAll('.filter-link');
    const devoirItems = document.querySelectorAll('.devoir-card');
    
    filterLinks.forEach(link => {
        link.addEventListener('click', function() {
            const filter = this.getAttribute('data-filter');
            
            // Retirer la classe active de tous les liens
            filterLinks.forEach(l => l.classList.remove('active'));
            
            // Ajouter la classe active au lien cliqué
            this.classList.add('active');
            
            // Filtrer les devoirs
            devoirItems.forEach(item => {
                if (filter === 'all') {
                    item.style.display = '';
                } else if (filter === 'urgent' && item.classList.contains('urgent')) {
                    item.style.display = '';
                } else if (filter === 'soon' && (item.classList.contains('soon') || item.classList.contains('urgent'))) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    });
    
    // Pour fermer les messages d'alerte
    document.querySelectorAll('.alert-close').forEach(button => {
        button.addEventListener('click', function() {
            const alert = this.parentElement;
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        });
    });
    
    // Animations sur les cartes de devoirs au scroll
    const animateOnScroll = () => {
        const cards = document.querySelectorAll('.devoir-card');
        cards.forEach(card => {
            const cardPosition = card.getBoundingClientRect().top;
            const screenHeight = window.innerHeight;
            
            if (cardPosition < screenHeight * 0.9) {
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }
        });
    };
    
    // Initial check
    animateOnScroll();
    
    // Listen for scroll events
    window.addEventListener('scroll', animateOnScroll);
});
</script>

<?php
include '../assets/css/templates/footer-template.php';
ob_end_flush();
?>