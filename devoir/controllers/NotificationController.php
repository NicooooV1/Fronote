<?php
/**
 * Contrôleur pour la gestion des notifications
 */
class NotificationController {
    private $notificationModel;
    private $db;
    
    /**
     * Constructeur
     */
    public function __construct() {
        // Initialisation des modèles
        require_once ROOT_PATH . '/utils/Notification.php';
        
        $this->notificationModel = new Notification();
        $this->db = Database::getInstance();
        
        // Vérifier que l'utilisateur est connecté
        if (!isset($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
    }
    
    /**
     * Affiche la liste des notifications de l'utilisateur
     */
    public function index() {
        // Récupérer les paramètres de pagination
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $perPage = ITEMS_PER_PAGE;
        
        // Filtrer par notifications non lues si demandé
        $onlyUnread = isset($_GET['unread']) && $_GET['unread'] == 1;
        
        // Récupérer les notifications de l'utilisateur
        $notifications = $this->notificationModel->getUserNotifications(
            $_SESSION['user_id'], 
            $page, 
            $perPage, 
            $onlyUnread
        );
        
        // Compter le nombre total de notifications
        $totalNotifications = $this->notificationModel->countUserNotifications(
            $_SESSION['user_id'], 
            $onlyUnread
        );
        
        // Calculer le nombre total de pages
        $totalPages = ceil($totalNotifications / $perPage);
        
        // Construire la chaîne de requête pour la pagination
        $queryParams = [];
        if ($onlyUnread) {
            $queryParams['unread'] = 1;
        }
        
        // Charger la vue
        require_once ROOT_PATH . '/views/notifications/index.php';
    }
    
    /**
     * Affiche les détails d'une notification
     */
    public function details() {
        // Récupérer l'ID de la notification depuis l'URL
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        
        if ($id === 0) {
            $_SESSION['error'] = "ID de notification non spécifié.";
            header('Location: ' . BASE_URL . '/notifications/index.php');
            exit;
        }
        
        // Récupérer les informations de la notification
        $notification = $this->notificationModel->getNotificationById($id);
        
        if (!$notification) {
            $_SESSION['error'] = "Notification non trouvée.";
            header('Location: ' . BASE_URL . '/notifications/index.php');
            exit;
        }
        
        // Vérifier que l'utilisateur est bien le destinataire de la notification
        if ($notification['destinataire_id'] != $_SESSION['user_id']) {
            $_SESSION['error'] = "Vous n'êtes pas autorisé à accéder à cette notification.";
            header('Location: ' . BASE_URL . '/notifications/index.php');
            exit;
        }
        
        // Marquer la notification comme lue
        if ($notification['lu'] == 0) {
            $this->notificationModel->markAsRead($id, $_SESSION['user_id']);
            $notification['lu'] = 1;
        }
        
        // Récupérer les informations associées (devoir, séance, etc.)
        if (!empty($notification['devoir_id'])) {
            require_once ROOT_PATH . '/models/Devoir.php';
            $devoirModel = new Devoir();
            $devoir = $devoirModel->getDevoirById($notification['devoir_id']);
            $notification['devoir'] = $devoir;
        }
        
        if (!empty($notification['seance_id'])) {
            require_once ROOT_PATH . '/models/Seance.php';
            $seanceModel = new Seance();
            $seance = $seanceModel->getSeanceById($notification['seance_id']);
            $notification['seance'] = $seance;
        }
        
        if (!empty($notification['rendu_id'])) {
            require_once ROOT_PATH . '/models/Rendu.php';
            $renduModel = new Rendu();
            $rendu = $renduModel->getRenduById($notification['rendu_id']);
            $notification['rendu'] = $rendu;
        }
        
        // Charger la vue
        require_once ROOT_PATH . '/views/notifications/details.php';
    }
    
    /**
     * Marque une notification comme lue
     */
    public function markAsRead() {
        // Vérifier la méthode de requête
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = "Méthode non autorisée.";
            header('Location: ' . BASE_URL . '/notifications/index.php');
            exit;
        }
        
        // Récupérer l'ID de la notification
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        
        if ($id === 0) {
            $_SESSION['error'] = "ID de notification non spécifié.";
            header('Location: ' . BASE_URL . '/notifications/index.php');
            exit;
        }
        
        // Marquer la notification comme lue
        $success = $this->notificationModel->markAsRead($id, $_SESSION['user_id']);
        
        if ($success) {
            $_SESSION['success'] = "Notification marquée comme lue.";
        } else {
            $_SESSION['error'] = "Une erreur est survenue.";
        }
        
        // Rediriger vers la page précédente ou la liste
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : BASE_URL . '/notifications/index.php';
        header('Location: ' . $referer);
        exit;
    }
    
    /**
     * Marque toutes les notifications comme lues
     */
    public function markAllAsRead() {
        // Vérifier la méthode de requête
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = "Méthode non autorisée.";
            header('Location: ' . BASE_URL . '/notifications/index.php');
            exit;
        }
        
        // Marquer toutes les notifications comme lues
        $success = $this->notificationModel->markAllAsRead($_SESSION['user_id']);
        
        if ($success) {
            $_SESSION['success'] = "Toutes les notifications ont été marquées comme lues.";
        } else {
            $_SESSION['error'] = "Une erreur est survenue.";
        }
        
        // Rediriger vers la liste des notifications
        header('Location: ' . BASE_URL . '/notifications/index.php');
        exit;
    }
    
    /**
     * Supprime une notification
     */
    public function delete() {
        // Vérifier la méthode de requête
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = "Méthode non autorisée.";
            header('Location: ' . BASE_URL . '/notifications/index.php');
            exit;
        }
        
        // Récupérer l'ID de la notification
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        
        if ($id === 0) {
            $_SESSION['error'] = "ID de notification non spécifié.";
            header('Location: ' . BASE_URL . '/notifications/index.php');
            exit;
        }
        
        // Supprimer la notification
        $success = $this->notificationModel->deleteNotification($id, $_SESSION['user_id']);
        
        if ($success) {
            $_SESSION['success'] = "Notification supprimée.";
        } else {
            $_SESSION['error'] = "Une erreur est survenue.";
        }
        
        // Rediriger vers la liste des notifications
        header('Location: ' . BASE_URL . '/notifications/index.php');
        exit;
    }
    
    /**
     * Supprime toutes les notifications
     */
    public function deleteAll() {
        // Vérifier la méthode de requête
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error'] = "Méthode non autorisée.";
            header('Location: ' . BASE_URL . '/notifications/index.php');
            exit;
        }
        
        // Supprimer toutes les notifications
        $success = $this->notificationModel->deleteAllNotifications($_SESSION['user_id']);
        
        if ($success) {
            $_SESSION['success'] = "Toutes les notifications ont été supprimées.";
        } else {
            $_SESSION['error'] = "Une erreur est survenue.";
        }
        
        // Rediriger vers la liste des notifications
        header('Location: ' . BASE_URL . '/notifications/index.php');
        exit;
    }
    
    /**
     * Envoie des notifications de rappel pour les devoirs à rendre bientôt
     * Cette méthode est appelée par un cron job
     */
    public function sendReminderNotifications() {
        // Vérifier si l'appel est autorisé (par exemple avec un token)
        if (!isset($_GET['token']) || $_GET['token'] !== CRON_TOKEN) {
            exit('Accès non autorisé');
        }
        
        // Récupérer les devoirs à rendre dans les prochains jours
        require_once ROOT_PATH . '/models/Devoir.php';
        $devoirModel = new Devoir();
        
        // Récupérer les devoirs à rendre dans les 3 prochains jours
        $dateMin = date('Y-m-d H:i:s');
        $dateMax = date('Y-m-d H:i:s', strtotime('+3 days'));
        
        $devoirs = $devoirModel->getAllDevoirs([
            'date_debut_passee' => true,
            'date_debut' => $dateMin,
            'date_fin' => $dateMax,
            'est_visible' => 1,
            'statut' => STATUT_A_FAIRE
        ]);
        
        $success = true;
        $nbNotifications = 0;
        
        foreach ($devoirs as $devoir) {
            // Envoyer des notifications pour chaque devoir
            if ($this->notificationModel->sendDevoirNotifications($devoir['id'], 'rappel')) {
                $nbNotifications++;
            } else {
                $success = false;
            }
        }
        
        // Envoyer les récapitulatifs hebdomadaires le dimanche
        if (date('w') == 0) { // 0 = dimanche
            if ($this->notificationModel->sendWeeklyRecap()) {
                $nbNotifications++;
            } else {
                $success = false;
            }
        }
        
        // Retourner le résultat en JSON
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            'nb_notifications' => $nbNotifications,
            'date' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
}