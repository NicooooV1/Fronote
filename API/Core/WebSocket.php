<?php
declare(strict_types=1);
/**
 * Gestion des notifications WebSocket
 * Permet au back-end PHP d'émettre des événements vers le serveur WS
 */

namespace API\Core;

class WebSocket {
    private static $wsUrl;
    private static $apiSecret;
    private static $enabled = true;
    
    /**
     * Initialiser la configuration WebSocket
     */
    public static function init() {
        self::$wsUrl = getenv('WEBSOCKET_URL') ?: 'http://localhost:3000';
        self::$apiSecret = getenv('WEBSOCKET_API_SECRET') ?: '';
        self::$enabled = getenv('WEBSOCKET_ENABLED') !== 'false';
    }
    
    /**
     * Envoyer une notification HTTP au serveur WS
     */
    private static function sendNotification($endpoint, $data) {
        if (!self::$enabled) {
            return ['success' => false, 'message' => 'WebSocket disabled'];
        }
        
        if (!self::$apiSecret) {
            error_log('WebSocket: API_SECRET not configured');
            return ['success' => false, 'message' => 'API_SECRET missing'];
        }
        
        $ch = curl_init(self::$wsUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . self::$apiSecret,
            ],
            CURLOPT_TIMEOUT => 2, // Timeout court pour ne pas bloquer
            CURLOPT_CONNECTTIMEOUT => 1
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("WebSocket notification error: $error");
            return ['success' => false, 'message' => $error];
        }
        
        if ($httpCode !== 200) {
            error_log("WebSocket notification failed: HTTP $httpCode");
            return ['success' => false, 'message' => "HTTP $httpCode"];
        }
        
        return ['success' => true, 'response' => json_decode($response, true)];
    }
    
    /**
     * Generic domain-agnostic dispatcher.
     * Each module builds its own channel name and payload in its own listener.
     *
     * @param string $channel  WS endpoint path, e.g. '/notify/grade'
     * @param array  $payload  Arbitrary JSON-encodable payload
     */
    public static function dispatch(string $channel, array $payload): array
    {
        return self::sendNotification($channel, $payload);
    }

    /** @deprecated Use dispatch('/notify/message', [...]) from the messagerie module listener */
    public static function notifyNewMessage($convId, $messageData) {
        return self::dispatch('/notify/message', ['convId' => $convId, 'message' => $messageData]);
    }

    /** @deprecated Use dispatch('/notify/notification', [...]) from the notifications module listener */
    public static function notifyUser($userId, $data) {
        return self::dispatch('/notify/notification', ['userId' => $userId, 'data' => $data]);
    }

    /** @deprecated Use dispatch('/notify/grade', [...]) from the notes module listener */
    public static function notifyNewGrade($eleveId, $gradeData) {
        return self::dispatch('/notify/grade', ['eleveId' => $eleveId, 'gradeData' => $gradeData]);
    }

    /** @deprecated Use dispatch('/notify/absence', [...]) from the absences module listener */
    public static function notifyNewAbsence($eleveId, $absenceData) {
        return self::dispatch('/notify/absence', ['eleveId' => $eleveId, 'absenceData' => $absenceData]);
    }

    /** @deprecated Use dispatch('/notify/event', [...]) from the agenda module listener */
    public static function notifyNewEvent($targetType, $targetId, $eventData) {
        return self::dispatch('/notify/event', ['targetType' => $targetType, 'targetId' => $targetId, 'eventData' => $eventData]);
    }
    
    /**
     * Générer un token JWT pour l'authentification WebSocket
     */
    public static function generateToken($userId, $userType): ?string
    {
        // Ne pas émettre de jeton temps réel si le WebSocket est désactivé.
        $wsEnabled = getenv('WEBSOCKET_ENABLED');
        if ($wsEnabled === 'false' || $wsEnabled === '0') {
            return null;
        }

        $jwtSecret = getenv('JWT_SECRET') ?: getenv('WEBSOCKET_API_SECRET');

        if (!$jwtSecret) {
            error_log('WebSocket: JWT_SECRET not configured');
            return null;
        }

        // TTL court (30 min par défaut, était 24 h) : borne la durée pendant laquelle un jeton reste
        // valable APRÈS révocation de session. Le client rafraîchit via ws_token_refresh (rate-limité),
        // et ce refresh passe par bootstrap → une session révoquée (session_security is_active=0) ne
        // peut plus obtenir de nouveau jeton. Réglable via WS_TOKEN_TTL.
        $ttl = (int) (getenv('WS_TOKEN_TTL') ?: 1800);
        if ($ttl < 300) { $ttl = 300; }

        $payload = [
            'iss'      => 'fronote',
            'sub'      => (int) $userId,
            'userId'   => (int) $userId,
            'userType' => $userType,
            'iat'      => time(),
            'exp'      => time() + $ttl,
        ];

        // Cloisonnement multi-établissement : porter l'établissement dans le jeton
        // pour que le serveur temps réel puisse namespacer les rooms par tenant
        // (empêche un join cross-établissement). Best-effort : absent hors contexte.
        try {
            $payload['etablissement_id'] = \API\Core\EstablishmentContext::id();
        } catch (\Throwable $e) {
            // Pas de contexte établissement résolu : jeton sans scope tenant.
        }

        if (class_exists(\Firebase\JWT\JWT::class)) {
            return \Firebase\JWT\JWT::encode($payload, $jwtSecret, 'HS256');
        }

        // Fallback manuel (si vendor/ absent — dépréciable après composer install)
        $header = rtrim(strtr(base64_encode('{"typ":"JWT","alg":"HS256"}'), '+/', '-_'), '=');
        $body   = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $sig    = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$body", $jwtSecret, true)), '+/', '-_'), '=');
        return "$header.$body.$sig";
    }
}

// Initialiser au chargement
WebSocket::init();
