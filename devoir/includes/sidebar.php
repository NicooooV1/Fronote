<?php
/**
 * Barre latérale de navigation
 */
$currentPath = $_SERVER['REQUEST_URI'];
?>
<aside class="sidebar">
    <div class="sidebar-menu">
        <?php if ($_SESSION['user_type'] === TYPE_ELEVE): ?>
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-section-title">Scolarité</div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/dashboard.php" class="<?php echo strpos($currentPath, '/dashboard') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">dashboard</i> Tableau de bord
                    </a>
                </div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/devoirs/" class="<?php echo strpos($currentPath, '/devoirs') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">assignment</i> Devoirs
                    </a>
                </div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/cahier/calendrier.php" class="<?php echo strpos($currentPath, '/cahier') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">event_note</i> Cahier de texte
                    </a>
                </div>
            </div>
            
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-section-title">Communication</div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/notifications/" class="<?php echo strpos($currentPath, '/notifications') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">notifications</i> Notifications
                        <?php 
                        // Récupérer le nombre de notifications non lues
                        require_once ROOT_PATH . '/models/Notification.php';
                        $notificationModel = new Notification();
                        $nbNotifs = $notificationModel->countUnreadNotifications($_SESSION['user_id']);
                        if ($nbNotifs > 0): 
                        ?>
                            <span class="badge badge-danger"><?php echo $nbNotifs; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
            
        <?php elseif ($_SESSION['user_type'] === TYPE_PROFESSEUR): ?>
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-section-title">Enseignement</div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/dashboard.php" class="<?php echo strpos($currentPath, '/dashboard') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">dashboard</i> Tableau de bord
                    </a>
                </div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/devoirs/" class="<?php echo strpos($currentPath, '/devoirs') !== false && strpos($currentPath, '/creer') === false ? 'active' : ''; ?>">
                        <i class="material-icons">assignment</i> Devoirs
                    </a>
                </div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/devoirs/creer.php" class="<?php echo strpos($currentPath, '/devoirs/creer') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">add_circle</i> Créer un devoir
                    </a>
                </div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/cahier/calendrier.php" class="<?php echo strpos($currentPath, '/cahier') !== false && strpos($currentPath, '/creer') === false ? 'active' : ''; ?>">
                        <i class="material-icons">event_note</i> Cahier de texte
                    </a>
                </div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/cahier/creer.php" class="<?php echo strpos($currentPath, '/cahier/creer') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">add_circle</i> Créer une séance
                    </a>
                </div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/cahier/chapitres.php" class="<?php echo strpos($currentPath, '/chapitres') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">book</i> Chapitres
                    </a>
                </div>
            </div>
            
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-section-title">Ressources</div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/ressources/" class="<?php echo strpos($currentPath, '/ressources') !== false && strpos($currentPath, '/creer') === false ? 'active' : ''; ?>">
                        <i class="material-icons">folder</i> Mes ressources
                    </a>
                </div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/ressources/creer.php" class="<?php echo strpos($currentPath, '/ressources/creer') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">add_circle</i> Ajouter une ressource
                    </a>
                </div>
            </div>
            
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-section-title">Gestion</div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/classes/" class="<?php echo strpos($currentPath, '/classes') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">group</i> Mes classes
                    </a>
                </div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/notifications/" class="<?php echo strpos($currentPath, '/notifications') !== false ? 'active' : ''; ?>">
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
                    <a href="<?php echo BASE_URL; ?>/dashboard.php" class="<?php echo strpos($currentPath, '/dashboard') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">dashboard</i> Tableau de bord
                    </a>
                </div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/devoirs/" class="<?php echo strpos($currentPath, '/devoirs') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">assignment</i> Devoirs
                    </a>
                </div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/cahier/calendrier.php" class="<?php echo strpos($currentPath, '/cahier') !== false ? 'active' : ''; ?>">
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
                        <a href="<?php echo BASE_URL; ?>/enfant.php?id=<?php echo $enfant['id']; ?>" class="<?php echo isset($_GET['id']) && $_GET['id'] == $enfant['id'] ? 'active' : ''; ?>">
                            <i class="material-icons">person</i> <?php echo htmlspecialchars($enfant['first_name'] . ' ' . $enfant['last_name']); ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-section-title">Communication</div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/notifications/" class="<?php echo strpos($currentPath, '/notifications') !== false ? 'active' : ''; ?>">
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
                    <a href="<?php echo BASE_URL; ?>/admin/" class="<?php echo $currentPath === '/admin/' || $currentPath === '/admin/index.php' ? 'active' : ''; ?>">
                        <i class="material-icons">dashboard</i> Tableau de bord
                    </a>
                </div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/admin/utilisateurs.php" class="<?php echo strpos($currentPath, '/admin/utilisateurs') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">people</i> Utilisateurs
                    </a>
                </div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/admin/classes.php" class="<?php echo strpos($currentPath, '/admin/classes') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">school</i> Classes
                    </a>
                </div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/admin/matieres.php" class="<?php echo strpos($currentPath, '/admin/matieres') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">subject</i> Matières
                    </a>
                </div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/admin/parametres.php" class="<?php echo strpos($currentPath, '/admin/parametres') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">settings</i> Paramètres
                    </a>
                </div>
            </div>
            
            <div class="sidebar-menu-section">
                <div class="sidebar-menu-section-title">Modules</div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/devoirs/" class="<?php echo strpos($currentPath, '/devoirs') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">assignment</i> Devoirs
                    </a>
                </div>
                <div class="sidebar-menu-item">
                    <a href="<?php echo BASE_URL; ?>/cahier/calendrier.php" class="<?php echo strpos($currentPath, '/cahier') !== false ? 'active' : ''; ?>">
                        <i class="material-icons">event_note</i> Cahier de texte
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</aside>