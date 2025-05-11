<?php
/**
 * API pour les actions sur les notifications
 */
// Désactiver l'affichage des erreurs pour éviter de corrompre le JSON/SSE
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../controllers/notification.php';
require_once __DIR__ . '/../models/notification.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rate_limiter.php';
require_once __DIR__ . '/../core/logger.php';
require_once __DIR__ . '/../core/utils.php';

// Vérifier l'authentification
$user = checkAuth();
if (!$user) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

// Limiter le taux de requêtes API
enforceRateLimit('api_notifications', 120, 60, true); // 120 requêtes/minute

// Vérifier le jeton CSRF pour toutes les requêtes POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Jeton CSRF invalide']);
        exit;
    }
}

// Point d'entrée SSE pour les notifications en temps réel
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'stream') {
    // Vérifier le jeton SSE
    $token = $_GET['token'] ?? '';
    if (!validateNotificationSSEToken($token, $user['id'], $user['type'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Jeton SSE invalide']);
        exit;
    }
    
    // Configuration des en-têtes SSE
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // Pour Nginx
    // Empêcher le buffering
    ini_set('output_buffering', 'off');
    ini_set('implicit_flush', true);
    ob_implicit_flush(true);
    if (ob_get_level() > 0) ob_end_flush();

    // Récupérer le dernier ID de notification connu
    $lastNotificationId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
    
    // Définir une durée maximale pour la connexion SSE (30 minutes)
    $maxExecutionTime = 1800; // 30 minutes
    $startTime = time();

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
        
        // Vérifier si la durée maximale est atteinte
        if (time() - $startTime > $maxExecutionTime) {
            echo "event: timeout\n";
            echo "data: {\"message\": \"La connexion a expiré. Veuillez vous reconnecter.\"}\n\n";
            flush();
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

/**
 * Génère un jeton SSE pour les notifications
 * @param int $userId
 * @param string $userType
 * @return string
 */
function generateNotificationSSEToken($userId, $userType) {
    $secret = 'Sk*7pM#d3F@vG9tZ!qL*6bR8'; // Changer en production
    $expiry = time() + 3600; // 1 heure
    $data = $userId . '|' . $userType . '|' . $expiry;
    $signature = hash_hmac('sha256', $data, $secret);
    
    return base64_encode($data . '|' . $signature);
}

/**
 * Valide un jeton SSE pour les notifications
 * @param string $token
 * @param int $userId
 * @param string $userType
 * @return bool
 */
function validateNotificationSSEToken($token, $userId, $userType) {
    $secret = 'Sk*7pM#d3F@vG9tZ!qL*6bR8'; // Même que ci-dessus
    
    try {
        $decoded = base64_decode($token);
        if ($decoded === false) {
            return false;
        }
        
        $parts = explode('|', $decoded);
        if (count($parts) !== 4) {
            return false;
        }
        
        list($tokenUserId, $tokenUserType, $expiry, $signature) = $parts;
        
        // Vérifier l'expiration
        if (time() > (int)$expiry) {
            return false;
        }
        
        // Vérifier les paramètres
        if ((int)$tokenUserId !== (int)$userId || 
            $tokenUserType !== $userType) {
            return false;
        }
        
        // Vérifier la signature
        $data = $tokenUserId . '|' . $tokenUserType . '|' . $expiry;
        $computedSignature = hash_hmac('sha256', $data, $secret);
        
        return hash_equals($computedSignature, $signature);
        
    } catch (Exception $e) {
        logException($e, ['action' => 'validate_notification_token']);
        return false;
    }
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
        logException($e, ['action' => 'check_notifications']);
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
        logException($e, ['action' => 'check_conditional']);
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
        logException($e, ['action' => 'mark_read', 'notification_id' => $notificationId]);
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
        logException($e, ['action' => 'update_preferences']);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Si on arrive ici, c'est que l'action demandée n'existe pas
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Action non supportée']);