<?php
// Démarrer la mise en mémoire tampon de sortie pour éviter l'erreur "headers already sent"
ob_start();

// Inclusion des fichiers nécessaires
include 'includes/db.php';
include 'includes/auth.php';
include 'includes/header.php';
include 'includes/calendar_functions.php';
include 'includes/event_helpers.php';

// Récupérer les informations de l'utilisateur connecté
$user = $_SESSION['user'];
$user_fullname = $user['prenom'] . ' ' . $user['nom'];
$user_role = $user['profil'];

// Récupérer les paramètres de filtrage et de pagination
$filter_type = isset($_GET['type']) ? $_GET['type'] : '';
$period = isset($_GET['period']) ? $_GET['period'] : 'upcoming';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 20; // Nombre d'événements par page

// Récupérer les événements
$events = [];

// Vérifier si la table evenements existe
$table_exists = false;
try {
    $stmt_check = $pdo->query("SHOW TABLES LIKE 'evenements'");
    $table_exists = $stmt_check->rowCount() > 0;
} catch (PDOException $e) {
    $table_exists = false;
}

// Si la table existe, récupérer les événements
if ($table_exists) {
    // Déterminer la période de filtrage
    $now = date('Y-m-d H:i:s');
    $date_filter = "";
    $params = [];
    
    if ($period === 'past') {
        $date_filter = "date_debut < ?";
        $params[] = $now;
    } elseif ($period === 'upcoming') {
        $date_filter = "date_debut >= ?";
        $params[] = $now;
    }
    
    // Construire la requête SQL en fonction du rôle de l'utilisateur
    if ($user_role === 'eleve') {
        // Pour un élève, récupérer ses événements et ceux de sa classe
        $classe = ''; // La classe de l'élève (à adapter selon votre système)
        
        $sql = "SELECT * FROM evenements 
                WHERE " . ($date_filter ? "($date_filter) AND " : "") . "
                (visibilite = 'public' 
                    OR visibilite = 'eleves' 
                    OR visibilite LIKE '%élèves%'
                    OR classes LIKE ? 
                    OR createur = ?)";
        
        if (!empty($filter_type)) {
            $sql .= " AND type_evenement = ?";
            $params[] = "%" . $classe . "%";
            $params[] = $user_fullname;
            $params[] = $filter_type;
        } else {
            $params[] = "%" . $classe . "%";
            $params[] = $user_fullname;
        }
        
        $sql .= " ORDER BY date_debut " . ($period === 'past' ? 'DESC' : 'ASC');
        
    } elseif ($user_role === 'professeur') {
        // Pour un professeur, récupérer ses événements et les événements publics
        $sql = "SELECT * FROM evenements 
                WHERE " . ($date_filter ? "($date_filter) AND " : "") . "
                (visibilite = 'public' 
                    OR visibilite = 'professeurs' 
                    OR visibilite LIKE '%professeurs%'
                    OR createur = ?)";
        
        if (!empty($filter_type)) {
            $sql .= " AND type_evenement = ?";
            $params[] = $user_fullname;
            $params[] = $filter_type;
        } else {
            $params[] = $user_fullname;
        }
        
        $sql .= " ORDER BY date_debut " . ($period === 'past' ? 'DESC' : 'ASC');
        
    } else {
        // Pour les administrateurs, vie scolaire et autres rôles, montrer tous les événements
        $sql = "SELECT * FROM evenements 
                WHERE " . ($date_filter ? "$date_filter" : "1=1");
        
        if (!empty($filter_type)) {
            $sql .= " AND type_evenement = ?";
            $params[] = $filter_type;
        }
        
        $sql .= " ORDER BY date_debut " . ($period === 'past' ? 'DESC' : 'ASC');
    }
    
    // Ajouter la pagination
    $sql .= " LIMIT " . (($page - 1) * $per_page) . ", " . $per_page;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Compter le nombre total d'événements pour la pagination
    $count_sql = str_replace("SELECT *", "SELECT COUNT(*)", $sql);
    $count_sql = preg_replace('/LIMIT \d+, \d+/', '', $count_sql);
    
    $stmt_count = $pdo->prepare($count_sql);
    $stmt_count->execute($params);
    $total_events = $stmt_count->fetchColumn();
    
    $total_pages = ceil($total_events / $per_page);
}

// Types d'événements pour le filtre
$types_evenements = [
    'cours' => 'Cours',
    'devoirs' => 'Devoirs',
    'reunion' => 'Réunion',
    'examen' => 'Examen',
    'sortie' => 'Sortie scolaire',
    'autre' => 'Autre'
];
?>

<div class="container">
    <div class="user-info">
        <p>Connecté en tant que: <?= htmlspecialchars($user_fullname) ?> (<?= htmlspecialchars($user_role) ?>)</p>
    </div>

    <?php if (canManageEvents()): ?>
    <div class="actions">
        <a href="ajouter_evenement.php" class="button">Ajouter un événement</a>
    </div>
    <?php endif; ?>

    <div class="list-filters">
        <h2>Liste des événements</h2>
        
        <div class="filter-row">
            <div class="filter-group">
                <label>Période:</label>
                <div class="filter-options">
                    <a href="?period=upcoming<?= !empty($filter_type) ? '&type=' . $filter_type : '' ?>" 
                       class="button <?= $period === 'upcoming' ? '' : 'button-secondary' ?>">À venir</a>
                    <a href="?period=past<?= !empty($filter_type) ? '&type=' . $filter_type : '' ?>" 
                       class="button <?= $period === 'past' ? '' : 'button-secondary' ?>">Passés</a>
                    <a href="?period=all<?= !empty($filter_type) ? '&type=' . $filter_type : '' ?>" 
                       class="button <?= $period === 'all' ? '' : 'button-secondary' ?>">Tous</a>
                </div>
            </div>
            
            <div class="filter-group">
                <label>Type d'événement:</label>
                <div class="filter-options">
                    <a href="?period=<?= $period ?>" 
                       class="button <?= empty($filter_type) ? '' : 'button-secondary' ?>">Tous</a>
                    <?php foreach ($types_evenements as $type => $label): ?>
                        <a href="?period=<?= $period ?>&type=<?= $type ?>" 
                           class="button <?= $filter_type === $type ? '' : 'button-secondary' ?>"><?= $label ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="event-list">
        <?php if (count($events) > 0): ?>
            <?php foreach ($events as $event): ?>
                <?php
                $event_debut = new DateTime($event['date_debut']);
                $event_fin = new DateTime($event['date_fin']);
                $event_class = 'event-' . strtolower($event['type_evenement']);
                
                if ($event['statut'] === 'annulé') {
                    $event_class .= ' event-cancelled';
                } elseif ($event['statut'] === 'reporté') {
                    $event_class .= ' event-postponed';
                }
                ?>
                <div class="event-list-item <?= $event_class ?>">
                    <div class="event-list-date">
                        <?php if ($event_debut->format('Y-m-d') === $event_fin->format('Y-m-d')): ?>
                            <div><?= $event_debut->format('d/m/Y') ?></div>
                            <div><?= $event_debut->format('H:i') ?> - <?= $event_fin->format('H:i') ?></div>
                        <?php else: ?>
                            <div>Du <?= $event_debut->format('d/m/Y H:i') ?></div>
                            <div>au <?= $event_fin->format('d/m/Y H:i') ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="event-list-details">
                        <div class="event-list-title"><?= htmlspecialchars($event['titre']) ?></div>
                        
                        <?php if (!empty($event['lieu'])): ?>
                            <div class="event-list-location">Lieu: <?= htmlspecialchars($event['lieu']) ?></div>
                        <?php endif; ?>
                        
                        <div class="event-list-creator">Par: <?= htmlspecialchars($event['createur']) ?></div>
                        
                        <?php if (!empty($event['matieres'])): ?>
                            <div class="event-list-subject">Matière: <?= htmlspecialchars($event['matieres']) ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="event-list-actions">
                        <a href="details_evenement.php?id=<?= $event['id'] ?>" class="button">Voir</a>
                        
                        <?php if (canEditEvent($event)): ?>
                            <a href="modifier_evenement.php?id=<?= $event['id'] ?>" class="button">Modifier</a>
                        <?php endif; ?>
                        
                        <?php if (canDeleteEvent($event)): ?>
                            <a href="supprimer_evenement.php?id=<?= $event['id'] ?>" class="button button-secondary" 
                               onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet événement ?');">Supprimer</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&period=<?= $period ?><?= !empty($filter_type) ? '&type=' . $filter_type : '' ?>" 
                           class="button button-secondary">Précédent</a>
                    <?php endif; ?>
                    
                    <div class="page-info">Page <?= $page ?> sur <?= $total_pages ?></div>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?>&period=<?= $period ?><?= !empty($filter_type) ? '&type=' . $filter_type : '' ?>" 
                           class="button button-secondary">Suivant</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
        <?php else: ?>
            <div class="no-events">
                <p>Aucun événement trouvé pour les critères sélectionnés.</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="calendar-view-options">
        <a href="agenda_jour.php" class="button button-secondary">Vue Jour</a>
        <a href="agenda_semaine.php" class="button button-secondary">Vue Semaine</a>
        <a href="agenda.php" class="button button-secondary">Vue Mois</a>
        <a href="agenda_liste.php" class="button">Vue Liste</a>
    </div>
</div>

</body>
</html>

<?php
// Terminer la mise en mémoire tampon et envoyer la sortie
ob_end_flush();
?>