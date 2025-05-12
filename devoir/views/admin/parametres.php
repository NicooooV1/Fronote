<?php
/**
 * Vue pour les paramètres du système (Administration)
 */

// Définir le titre de la page et les fichiers CSS/JS supplémentaires
$pageTitle = "Paramètres du système";
$extraCss = [];
$extraJs = [];
$currentPage = "admin_settings";

// Vérifier que l'utilisateur est administrateur
if ($_SESSION['user_type'] !== TYPE_ADMIN) {
    // Rediriger vers la page d'accueil si l'utilisateur n'est pas administrateur
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Inclure l'en-tête
require_once ROOT_PATH . '/includes/header.php';
?>

<div class="page-header">
    <h1>Paramètres du système</h1>
</div>

<!-- Formulaire des paramètres généraux -->
<div class="card mb-4">
    <div class="card-header">
        <h2>Paramètres généraux</h2>
    </div>
    <div class="card-body">
        <form action="<?php echo BASE_URL; ?>/admin/update_parametres.php" method="POST" class="settings-form">
            <input type="hidden" name="section" value="general">
            
            <div class="form-group">
                <label for="app_name" class="form-label">Nom de l'application</label>
                <input type="text" name="app_name" id="app_name" class="form-control" value="<?php echo htmlspecialchars($settings['app_name']); ?>" required>
                <small class="form-text text-muted">Ce nom apparaîtra dans le titre des pages et en-têtes.</small>
            </div>
            
            <div class="form-group">
                <label for="etablissement_nom" class="form-label">Nom de l'établissement</label>
                <input type="text" name="etablissement_nom" id="etablissement_nom" class="form-control" value="<?php echo htmlspecialchars($settings['etablissement_nom']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="annee_scolaire" class="form-label">Année scolaire en cours</label>
                <select name="annee_scolaire" id="annee_scolaire" class="form-select" required>
                    <?php 
                        $currentYear = date('Y');
                        for ($i = 0; $i < 5; $i++) {
                            $year = $currentYear - $i;
                            $annee = $year . '-' . ($year + 1);
                            $selected = ($settings['annee_scolaire'] == $annee) ? 'selected' : '';
                            echo "<option value=\"$annee\" $selected>$annee</option>";
                        }
                    ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="items_per_page" class="form-label">Nombre d'éléments par page</label>
                <input type="number" name="items_per_page" id="items_per_page" class="form-control" value="<?php echo $settings['items_per_page']; ?>" min="5" max="100" required>
                <small class="form-text text-muted">Nombre d'éléments à afficher dans les listes paginées.</small>
            </div>
            
            <div class="form-group">
                <div class="form-check">
                    <input type="checkbox" name="maintenance_mode" id="maintenance_mode" class="form-check-input" value="1" <?php echo $settings['maintenance_mode'] ? 'checked' : ''; ?>>
                    <label for="maintenance_mode" class="form-check-label">Mode maintenance</label>
                </div>
                <small class="form-text text-muted">Si activé, seuls les administrateurs pourront accéder à l'application.</small>
            </div>
            
            <div class="form-actions mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="material-icons">save</i> Enregistrer les paramètres
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Paramètres des notifications -->
<div class="card mb-4">
    <div class="card-header">
        <h2>Paramètres des notifications</h2>
    </div>
    <div class="card-body">
        <form action="<?php echo BASE_URL; ?>/admin/update_parametres.php" method="POST" class="settings-form">
            <input type="hidden" name="section" value="notifications">
            
            <div class="form-group">
                <div class="form-check">
                    <input type="checkbox" name="enable_email_notifications" id="enable_email_notifications" class="form-check-input" value="1" <?php echo $settings['enable_email_notifications'] ? 'checked' : ''; ?>>
                    <label for="enable_email_notifications" class="form-check-label">Activer les notifications par email</label>
                </div>
            </div>
            
            <div class="form-group">
                <label for="notification_sender" class="form-label">Adresse email d'expédition</label>
                <input type="email" name="notification_sender" id="notification_sender" class="form-control" value="<?php echo htmlspecialchars($settings['notification_sender']); ?>">
                <small class="form-text text-muted">Adresse utilisée comme expéditeur pour les emails de notification.</small>
            </div>
            
            <div class="form-group">
                <div class="form-check">
                    <input type="checkbox" name="notify_new_devoir" id="notify_new_devoir" class="form-check-input" value="1" <?php echo $settings['notify_new_devoir'] ? 'checked' : ''; ?>>
                    <label for="notify_new_devoir" class="form-check-label">Notifier les nouveaux devoirs</label>
                </div>
            </div>
            
            <div class="form-group">
                <div class="form-check">
                    <input type="checkbox" name="notify_new_seance" id="notify_new_seance" class="form-check-input" value="1" <?php echo $settings['notify_new_seance'] ? 'checked' : ''; ?>>
                    <label for="notify_new_seance" class="form-check-label">Notifier les nouvelles séances</label>
                </div>
            </div>
            
            <div class="form-group">
                <div class="form-check">
                    <input type="checkbox" name="notify_devoir_rappel" id="notify_devoir_rappel" class="form-check-input" value="1" <?php echo $settings['notify_devoir_rappel'] ? 'checked' : ''; ?>>
                    <label for="notify_devoir_rappel" class="form-check-label">Envoyer des rappels pour les devoirs</label>
                </div>
                <small class="form-text text-muted">Envoie un rappel avant la date limite des devoirs.</small>
            </div>
            
            <div class="form-group">
                <label for="devoir_rappel_jours" class="form-label">Jours avant échéance pour le rappel</label>
                <input type="number" name="devoir_rappel_jours" id="devoir_rappel_jours" class="form-control" value="<?php echo $settings['devoir_rappel_jours']; ?>" min="1" max="7">
                <small class="form-text text-muted">Nombre de jours avant la date limite pour envoyer un rappel.</small>
            </div>
            
            <div class="form-actions mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="material-icons">save</i> Enregistrer les paramètres
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Paramètres du cahier de texte -->
<div class="card mb-4">
    <div class="card-header">
        <h2>Paramètres du cahier de texte</h2>
    </div>
    <div class="card-body">
        <form action="<?php echo BASE_URL; ?>/admin/update_parametres.php" method="POST" class="settings-form">
            <input type="hidden" name="section" value="cahier">
            
            <div class="form-group">
                <label for="calendar_start_hour" class="form-label">Heure de début du calendrier</label>
                <select name="calendar_start_hour" id="calendar_start_hour" class="form-select">
                    <?php for ($i = 6; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo ($settings['calendar_start_hour'] == $i) ? 'selected' : ''; ?>><?php echo $i; ?>:00</option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="calendar_end_hour" class="form-label">Heure de fin du calendrier</label>
                <select name="calendar_end_hour" id="calendar_end_hour" class="form-select">
                    <?php for ($i = 15; $i <= 22; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo ($settings['calendar_end_hour'] == $i) ? 'selected' : ''; ?>><?php echo $i; ?>:00</option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="default_calendar_view" class="form-label">Vue par défaut du calendrier</label>
                <select name="default_calendar_view" id="default_calendar_view" class="form-select">
                    <option value="month" <?php echo ($settings['default_calendar_view'] == 'month') ? 'selected' : ''; ?>>Mois</option>
                    <option value="week" <?php echo ($settings['default_calendar_view'] == 'week') ? 'selected' : ''; ?>>Semaine</option>
                    <option value="day" <?php echo ($settings['default_calendar_view'] == 'day') ? 'selected' : ''; ?>>Jour</option>
                </select>
            </div>
            
            <div class="form-actions mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="material-icons">save</i> Enregistrer les paramètres
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Paramètres de téléchargement des fichiers -->
<div class="card mb-4">
    <div class="card-header">
        <h2>Paramètres de téléchargement</h2>
    </div>
    <div class="card-body">
        <form action="<?php echo BASE_URL; ?>/admin/update_parametres.php" method="POST" class="settings-form">
            <input type="hidden" name="section" value="upload">
            
            <div class="form-group">
                <label for="max_upload_size" class="form-label">Taille maximale de téléchargement (en Mo)</label>
                <input type="number" name="max_upload_size" id="max_upload_size" class="form-control" value="<?php echo $settings['max_upload_size'] / (1024 * 1024); ?>" min="1" max="100">
                <small class="form-text text-muted">Taille maximale autorisée pour les fichiers téléchargés (en mégaoctets).</small>
            </div>
            
            <div class="form-group">
                <label for="allowed_extensions" class="form-label">Extensions autorisées</label>
                <input type="text" name="allowed_extensions" id="allowed_extensions" class="form-control" value="<?php echo htmlspecialchars(implode(', ', $settings['allowed_extensions'])); ?>">
                <small class="form-text text-muted">Liste des extensions de fichier autorisées, séparées par des virgules.</small>
            </div>
            
            <div class="form-actions mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="material-icons">save</i> Enregistrer les paramètres
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Maintenance du système -->
<div class="card mb-4">
    <div class="card-header">
        <h2>Maintenance du système</h2>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col">
                <div class="card">
                    <div class="card-header">
                        <h3>Sauvegarde de la base de données</h3>
                    </div>
                    <div class="card-body">
                        <p>Créer une sauvegarde complète de la base de données.</p>
                        <a href="<?php echo BASE_URL; ?>/admin/backup.php" class="btn btn-primary">
                            <i class="material-icons">backup</i> Lancer la sauvegarde
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col">
                <div class="card">
                    <div class="card-header">
                        <h3>Nettoyage des fichiers</h3>
                    </div>
                    <div class="card-body">
                        <p>Supprimer les fichiers temporaires et inutilisés.</p>
                        <a href="<?php echo BASE_URL; ?>/admin/cleanup.php" class="btn btn-warning">
                            <i class="material-icons">cleaning_services</i> Nettoyer les fichiers
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col">
                <div class="card">
                    <div class="card-header">
                        <h3>Optimisation de la base de données</h3>
                    </div>
                    <div class="card-body">
                        <p>Optimiser les tables de la base de données.</p>
                        <a href="<?php echo BASE_URL; ?>/admin/optimize.php" class="btn btn-accent">
                            <i class="material-icons">tune</i> Optimiser la base de données
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Inclure le pied de page
require_once ROOT_PATH . '/includes/footer.php';
?>