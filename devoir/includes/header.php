<?php
/**
 * En-tête commun pour toutes les pages
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' . APP_NAME : APP_NAME; ?></title>
    
    <!-- Feuilles de style CSS -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/main.css">
    <?php if (isset($extraCss) && is_array($extraCss)): ?>
        <?php foreach ($extraCss as $css): ?>
            <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/<?php echo $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- Police Roboto de Google Fonts -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap">
    
    <!-- Icônes Material Design -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
    
    <!-- Font Awesome (pour les icônes supplémentaires) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <?php if (isset($includeCalendar) && $includeCalendar): ?>
        <!-- FullCalendar pour les vues de calendrier -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    <?php endif; ?>
</head>
<body>
    <!-- En-tête de page -->
    <header class="header">
        <div class="container header-container">
            <div class="logo">
                <a href="<?php echo BASE_URL; ?>/index.php"><?php echo APP_NAME; ?></a>
            </div>
            
            <nav class="main-nav">
                <?php if ($_SESSION['user_type'] === TYPE_ELEVE || $_SESSION['user_type'] === TYPE_PARENT'): ?>
                    <a href="<?php echo BASE_URL; ?>/devoirs/index.php">Devoirs</a>
                    <a href="<?php echo BASE_URL; ?>/cahier/calendrier.php">Cahier de texte</a>
                    <a href="<?php echo BASE_URL; ?>/notifications/index.php">
                        Notifications
                        <?php 
                        // Afficher le nombre de notifications non lues
                        require_once ROOT_PATH . '/models/Notification.php';
                        $notificationModel = new Notification();
                        $nbNotifs = $notificationModel->countUnreadNotifications($_SESSION['user_id']);
                        if ($nbNotifs > 0): 
                        ?>
                            <span class="badge badge-danger"><?php echo $nbNotifs; ?></span>
                        <?php endif; ?>
                    </a>
                <?php elseif ($_SESSION['user_type'] === TYPE_PROFESSEUR): ?>
                    <a href="<?php echo BASE_URL; ?>/devoirs/index.php">Devoirs</a>
                    <a href="<?php echo BASE_URL; ?>/cahier/calendrier.php">Cahier de texte</a>
                    <a href="<?php echo BASE_URL; ?>/ressources/index.php">Ressources</a>
                    <a href="<?php echo BASE_URL; ?>/classes/index.php">Classes</a>
                    <a href="<?php echo BASE_URL; ?>/notifications/index.php">
                        Notifications
                        <?php 
                        // Afficher le nombre de notifications non lues
                        require_once ROOT_PATH . '/models/Notification.php';
                        $notificationModel = new Notification();
                        $nbNotifs = $notificationModel->countUnreadNotifications($_SESSION['user_id']);
                        if ($nbNotifs > 0): 
                        ?>
                            <span class="badge badge-danger"><?php echo $nbNotifs; ?></span>
                        <?php endif; ?>
                    </a>
                <?php elseif ($_SESSION['user_type'] === TYPE_ADMIN): ?>
                    <a href="<?php echo BASE_URL; ?>/admin/users.php">Utilisateurs</a>
                    <a href="<?php echo BASE_URL; ?>/admin/classes.php">Classes</a>
                    <a href="<?php echo BASE_URL; ?>/admin/settings.php">Paramètres</a>
                <?php endif; ?>
            </nav>
            
            <div class="user-menu">
                <div class="user-menu-trigger">
                    <img src="<?php echo BASE_URL; ?>/assets/images/user-default.png" alt="Avatar">
                    <span><?php echo htmlspecialchars($_SESSION['user_fullname']); ?></span>
                    <i class="material-icons">arrow_drop_down</i>
                </div>
                <div class="user-menu-dropdown">
                    <a href="<?php echo BASE_URL; ?>/profile.php">
                        <i class="material-icons">person</i> Mon profil
                    </a>
                    <a href="<?php echo BASE_URL; ?>/settings.php">
                        <i class="material-icons">settings</i> Paramètres
                    </a>
                    <a href="<?php echo BASE_URL; ?>/help.php">
                        <i class="material-icons">help</i> Aide
                    </a>
                    <a href="<?php echo BASE_URL; ?>/logout.php">
                        <i class="material-icons">exit_to_app</i> Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Barre latérale (Sidebar) -->
    <aside class="sidebar">
        <div class="sidebar-menu">
            <?php if ($_SESSION['user_type'] === TYPE_ELEVE): ?>
                <div class="sidebar-menu-section">
                    <div class="sidebar-menu-section-title">Scolarité</div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/dashboard_eleve.php" class="<?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
                            <i class="material-icons">dashboard</i> Tableau de bord
                        </a>
                    </div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/devoirs/index.php" class="<?php echo $currentPage === 'devoirs' ? 'active' : ''; ?>">
                            <i class="material-icons">assignment</i> Devoirs
                        </a>
                    </div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/cahier/calendrier.php" class="<?php echo $currentPage === 'cahier' ? 'active' : ''; ?>">
                            <i class="material-icons">event_note</i> Cahier de texte
                        </a>
                    </div>
                </div>
                
                <div class="sidebar-menu-section">
                    <div class="sidebar-menu-section-title">Communication</div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/notifications/index.php" class="<?php echo $currentPage === 'notifications' ? 'active' : ''; ?>">
                            <i class="material-icons">notifications</i> Notifications
                            <?php if ($nbNotifs > 0): ?>
                                <span class="badge badge-danger"><?php echo $nbNotifs; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
                
            <?php elseif ($_SESSION['user_type'] === TYPE_PROFESSEUR): ?>
                <div class="sidebar-menu-section">
                    <div class="sidebar-menu-section-title">Enseignement</div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/dashboard_prof.php" class="<?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
                            <i class="material-icons">dashboard</i> Tableau de bord
                        </a>
                    </div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/devoirs/index.php" class="<?php echo $currentPage === 'devoirs' ? 'active' : ''; ?>">
                            <i class="material-icons">assignment</i> Devoirs
                        </a>
                    </div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/devoirs/creer.php" class="<?php echo $currentPage === 'devoirs_creer' ? 'active' : ''; ?>">
                            <i class="material-icons">add_circle</i> Créer un devoir
                        </a>
                    </div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/cahier/calendrier.php" class="<?php echo $currentPage === 'cahier' ? 'active' : ''; ?>">
                            <i class="material-icons">event_note</i> Cahier de texte
                        </a>
                    </div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/cahier/creer.php" class="<?php echo $currentPage === 'cahier_creer' ? 'active' : ''; ?>">
                            <i class="material-icons">add_circle</i> Créer une séance
                        </a>
                    </div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/cahier/chapitres.php" class="<?php echo $currentPage === 'chapitres' ? 'active' : ''; ?>">
                            <i class="material-icons">book</i> Chapitres
                        </a>
                    </div>
                </div>
                
                <div class="sidebar-menu-section">
                    <div class="sidebar-menu-section-title">Ressources</div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/ressources/index.php" class="<?php echo $currentPage === 'ressources' ? 'active' : ''; ?>">
                            <i class="material-icons">folder</i> Mes ressources
                        </a>
                    </div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/ressources/creer.php" class="<?php echo $currentPage === 'ressources_creer' ? 'active' : ''; ?>">
                            <i class="material-icons">add_circle</i> Ajouter une ressource
                        </a>
                    </div>
                </div>
                
                <div class="sidebar-menu-section">
                    <div class="sidebar-menu-section-title">Gestion</div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/classes/index.php" class="<?php echo $currentPage === 'classes' ? 'active' : ''; ?>">
                            <i class="material-icons">group</i> Mes classes
                        </a>
                    </div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/notifications/index.php" class="<?php echo $currentPage === 'notifications' ? 'active' : ''; ?>">
                            <i class="material-icons">notifications</i> Notifications
                            <?php if ($nbNotifs > 0): ?>
                                <span class="badge badge-danger"><?php echo $nbNotifs; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
                
            <?php elseif ($_SESSION['user_type'] === TYPE_PARENT): ?>
                <div class="sidebar-menu-section">
                    <div class="sidebar-menu-section-title">Suivi</div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/dashboard_parent.php" class="<?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
                            <i class="material-icons">dashboard</i> Tableau de bord
                        </a>
                    </div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/devoirs/index.php" class="<?php echo $currentPage === 'devoirs' ? 'active' : ''; ?>">
                            <i class="material-icons">assignment</i> Devoirs
                        </a>
                    </div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/cahier/calendrier.php" class="<?php echo $currentPage === 'cahier' ? 'active' : ''; ?>">
                            <i class="material-icons">event_note</i> Cahier de texte
                        </a>
                    </div>
                </div>
                
                <div class="sidebar-menu-section">
                    <div class="sidebar-menu-section-title">Mes enfants</div>
                    <?php 
                    // Récupérer la liste des enfants du parent
                    require_once ROOT_PATH . '/models/User.php';
                    $userModel = new User();
                    $enfants = $userModel->getEnfantsParent($_SESSION['user_id']);
                    
                    foreach ($enfants as $enfant):
                    ?>
                        <div class="sidebar-menu-item">
                            <a href="<?php echo BASE_URL; ?>/enfant_details.php?id=<?php echo $enfant['id']; ?>">
                                <i class="material-icons">person</i> <?php echo htmlspecialchars($enfant['first_name'] . ' ' . $enfant['last_name']); ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="sidebar-menu-section">
                    <div class="sidebar-menu-section-title">Communication</div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/notifications/index.php" class="<?php echo $currentPage === 'notifications' ? 'active' : ''; ?>">
                            <i class="material-icons">notifications</i> Notifications
                            <?php if ($nbNotifs > 0): ?>
                                <span class="badge badge-danger"><?php echo $nbNotifs; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
                
            <?php elseif ($_SESSION['user_type'] === TYPE_ADMIN): ?>
                <div class="sidebar-menu-section">
                    <div class="sidebar-menu-section-title">Administration</div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/admin/index.php" class="<?php echo $currentPage === 'admin_dashboard' ? 'active' : ''; ?>">
                            <i class="material-icons">dashboard</i> Tableau de bord
                        </a>
                    </div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/admin/users.php" class="<?php echo $currentPage === 'admin_users' ? 'active' : ''; ?>">
                            <i class="material-icons">people</i> Utilisateurs
                        </a>
                    </div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/admin/classes.php" class="<?php echo $currentPage === 'admin_classes' ? 'active' : ''; ?>">
                            <i class="material-icons">school</i> Classes
                        </a>
                    </div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/admin/matieres.php" class="<?php echo $currentPage === 'admin_matieres' ? 'active' : ''; ?>">
                            <i class="material-icons">subject</i> Matières
                        </a>
                    </div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/admin/settings.php" class="<?php echo $currentPage === 'admin_settings' ? 'active' : ''; ?>">
                            <i class="material-icons">settings</i> Paramètres
                        </a>
                    </div>
                </div>
                
                <div class="sidebar-menu-section">
                    <div class="sidebar-menu-section-title">Modules</div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/devoirs/index.php" class="<?php echo $currentPage === 'devoirs' ? 'active' : ''; ?>">
                            <i class="material-icons">assignment</i> Devoirs
                        </a>
                    </div>
                    <div class="sidebar-menu-item">
                        <a href="<?php echo BASE_URL; ?>/cahier/calendrier.php" class="<?php echo $currentPage === 'cahier' ? 'active' : ''; ?>">
                            <i class="material-icons">event_note</i> Cahier de texte
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </aside>
    
    <!-- Contenu principal -->
    <main class="main-content">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible">
                <?php echo $_SESSION['success']; ?>
                <button type="button" class="close" onclick="this.parentElement.style.display='none'">&times;</button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible">
                <?php echo $_SESSION['error']; ?>
                <button type="button" class="close" onclick="this.parentElement.style.display='none'">&times;</button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['errors']) && is_array($_SESSION['errors'])): ?>
            <div class="alert alert-danger alert-dismissible">
                <ul>
                    <?php foreach ($_SESSION['errors'] as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="close" onclick="this.parentElement.style.display='none'">&times;</button>
            </div>
            <?php unset($_SESSION['errors']); ?>
        <?php endif; ?>