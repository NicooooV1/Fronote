<?php
/**
 * API pour les actions sur les notifications
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../controllers/notification.php';
require_once __DIR__ . '/../models/notification.php';
require_once __DIR__ . '/../core/auth.php';

// Désactiver l'affichage des erreurs pour éviter de corrompre le JSON
ini_set('display_errors', 0);
error_reporting(0);

// Vérifier l'authentification
$user = checkAuth();
if (!$user) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Point d'entrée SSE pour les notifications en temps réel
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'stream') {
    // Configuration des en-têtes SSE
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    // Empêcher le buffering
    ini_set('output_buffering', 'off');
    ini_set('implicit_flush', true);
    ob_implicit_flush(true);

    // Récupérer le dernier ID de notification connu
    $lastNotificationId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

    // Envoyer un ping initial
    echo "event: ping\n";
    echo "data: {\"time\": " . time() . "}\n\n";
    flush();

    // Boucle principale
    while (true) {
        // Vérifier si la connexion client est toujours active
        if (connection_aborted()) {
            break;
        }
        
        // Compter les notifications non lues
        $count = countUnreadNotifications($user['id'], $user['type']);
        
        // Récupérer la dernière notification si nécessaire
        $latestNotification = null;
        if ($count > 0) {
            $notifications = getUnreadNotifications($user['id'], $user['type'], 1);
            if (!empty($notifications)) {
                $latestNotification = $notifications[0];
                
                // Vérifier s'il s'agit d'une nouvelle notification
                if ($latestNotification['id'] > $lastNotificationId) {
                    $lastNotificationId = $latestNotification['id'];
                    
                    echo "event: notification\n";
                    echo "data: " . json_encode([
                        'count' => $count,
                        'latest_notification' => $latestNotification
                    ]) . "\n\n";
                    flush();
                }
            }
        }
        
        // Envoyer un ping pour maintenir la connexion
        echo "event: ping\n";
        echo "data: {\"time\": " . time() . "}\n\n";
        flush();
        
        // Pause pour éviter de surcharger le serveur
        sleep(3);
    }
    
    exit;
}

// Vérification des notifications
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'check') {
    header('Content-Type: application/json');
    
    try {
        $count = countUnreadNotifications($user['id'], $user['type']);
        
        // Récupérer la dernière notification si nécessaire
        $latestNotification = null;
        if ($count > 0) {
            $notifications = getUnreadNotifications($user['id'], $user['type'], 1);
            if (!empty($notifications)) {
                $latestNotification = $notifications[0];
            }
        }
        
        echo json_encode([
            'success' => true,
            'count' => $count,
            'latest_notification' => $latestNotification
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Vérification optimisée des notifications avec ETag
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'check_conditional') {
    header('Content-Type: application/json');
    
    try {
        // Compter les notifications non lues
        $count = countUnreadNotifications($user['id'], $user['type']);
        
        // Générer un ETag basé sur le nombre de notifications
        $etag = '"notifications_' . $user['id'] . '_' . $user['type'] . '_' . $count . '"';
        
        // Vérifier si le client a envoyé un ETag
        $clientEtag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH'] : '';
        
        // Si les ETags correspondent, renvoyer 304 Not Modified
        if ($clientEtag === $etag) {
            header('HTTP/1.1 304 Not Modified');
            exit;
        }
        
        // Obtenir la dernière notification si nécessaire
        $latestNotification = null;
        if ($count > 0) {
            $notifications = getUnreadNotifications($user['id'], $user['type'], 1);
            if (!empty($notifications)) {
                $latestNotification = $notifications[0];
            }
        }
        
        // Définir l'en-tête ETag
        header('ETag: ' . $etag);
        header('Cache-Control: private, must-revalidate');
        
        echo json_encode([
            'success' => true,
            'count' => $count,
            'latest_notification' => $latestNotification
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Marquer une notification comme lue
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id']) && isset($_GET['action']) && $_GET['action'] === 'mark_read') {
    header('Content-Type: application/json');
    
    $notificationId = (int)$_GET['id'];

    if (!$notificationId) {
        echo json_encode(['success' => false, 'error' => 'ID de notification invalide']);
        exit;
    }

    try {
        $result = handleMarkNotificationRead($notificationId, $user);
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Mise à jour des préférences de notification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_preferences') {
    header('Content-Type: application/json');
    
    if (!isset($_POST['preferences']) || !is_array($_POST['preferences'])) {
        echo json_encode(['success' => false, 'error' => 'Préférences invalides']);
        exit;
    }

    try {
        $result = handleUpdateNotificationPreferences($user['id'], $user['type'], $_POST['preferences']);
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Si on arrive ici, c'est que l'action demandée n'existe pas
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Action non supportée']);