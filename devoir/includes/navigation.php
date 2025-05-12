<?php
/**
 * Menu de navigation principal
 */
?>
<header class="header">
    <div class="container header-container">
        <div class="logo">
            <a href="<?php echo BASE_URL; ?>/index.php"><?php echo APP_NAME; ?></a>
        </div>
        
        <button type="button" class="toggle-menu" aria-label="Menu">
            <i class="material-icons">menu</i>
        </button>
        
        <nav class="main-nav">
            <?php if ($_SESSION['user_type'] === TYPE_ELEVE): ?>
                <a href="<?php echo BASE_URL; ?>/devoirs/">Devoirs</a>
                <a href="<?php echo BASE_URL; ?>/cahier/calendrier.php">Cahier de texte</a>
                <a href="<?php echo BASE_URL; ?>/notifications/">
                    Notifications
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
            <?php elseif ($_SESSION['user_type'] === TYPE_PROFESSEUR): ?>
                <a href="<?php echo BASE_URL; ?>/devoirs/">Devoirs</a>
                <a href="<?php echo BASE_URL; ?>/cahier/calendrier.php">Cahier de texte</a>
                <a href="<?php echo BASE_URL; ?>/ressources/">Ressources</a>
                <a href="<?php echo BASE_URL; ?>/classes/">Classes</a>
                <a href="<?php echo BASE_URL; ?>/notifications/">
                    Notifications
                    <?php if ($nbNotifs > 0): ?>
                        <span class="badge badge-danger"><?php echo $nbNotifs; ?></span>
                    <?php endif; ?>
                </a>
            <?php elseif ($_SESSION['user_type'] === TYPE_PARENT): ?>
                <a href="<?php echo BASE_URL; ?>/devoirs/">Devoirs</a>
                <a href="<?php echo BASE_URL; ?>/cahier/calendrier.php">Cahier de texte</a>
                <a href="<?php echo BASE_URL; ?>/notifications/">
                    Notifications
                    <?php if ($nbNotifs > 0): ?>
                        <span class="badge badge-danger"><?php echo $nbNotifs; ?></span>
                    <?php endif; ?>
                </a>
            <?php elseif ($_SESSION['user_type'] === TYPE_ADMIN): ?>
                <a href="<?php echo BASE_URL; ?>/admin/utilisateurs.php">Utilisateurs</a>
                <a href="<?php echo BASE_URL; ?>/admin/classes.php">Classes</a>
                <a href="<?php echo BASE_URL; ?>/admin/parametres.php">Paramètres</a>
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
                <a href="<?php echo BASE_URL; ?>/parametres.php">
                    <i class="material-icons">settings</i> Paramètres
                </a>
                <a href="<?php echo BASE_URL; ?>/aide.php">
                    <i class="material-icons">help</i> Aide
                </a>
                <a href="<?php echo BASE_URL; ?>/logout.php">
                    <i class="material-icons">exit_to_app</i> Déconnexion
                </a>
            </div>
        </div>
    </div>
</header>

<button type="button" class="toggle-sidebar" aria-label="Afficher/Masquer la barre latérale">
    <i class="material-icons">menu_open</i>
</button>