<?php
declare(strict_types=1);

namespace API\Services;

use PDO;

/**
 * WebPushService — Envoi de notifications push via Web Push Protocol (VAPID).
 *
 * Implémentation native sans dépendance externe.
 * Utilise les clés VAPID configurées dans .env :
 *   VAPID_PUBLIC_KEY=...
 *   VAPID_PRIVATE_KEY=...
 *   VAPID_SUBJECT=mailto:admin@example.com
 */
class WebPushService
{
    private PDO $pdo;
    private string $publicKey;
    private string $privateKey;
    private string $subject;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->publicKey = getenv('VAPID_PUBLIC_KEY') ?: '';
        $this->privateKey = getenv('VAPID_PRIVATE_KEY') ?: '';
        $this->subject = getenv('VAPID_SUBJECT') ?: 'mailto:admin@fronote.local';
    }

    /**
     * Vérifie si le service est configuré.
     */
    public function isConfigured(): bool
    {
        return !empty($this->publicKey) && !empty($this->privateKey);
    }

    /**
     * Retourne la clé publique VAPID (pour le client JS).
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    // ─── Gestion des souscriptions ──────────────────────────────────

    /**
     * Enregistre une souscription push pour un utilisateur.
     */
    /**
     * Un endpoint push est-il légitime ? Anti-SSRF : on refuse tout ce qui n'est pas un service push
     * connu en HTTPS (le serveur fera plus tard un curl vers cet endpoint). Bloque IP littérales,
     * loopback/privé/link-local et hôtes arbitraires.
     */
    private function isAllowedEndpoint(string $endpoint): bool
    {
        $p = parse_url($endpoint);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            return false;
        }
        $host = strtolower($p['host']);
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return false; // pas d'IP littérale (empêche 127.0.0.1, 169.254.169.254, 10.x…)
        }
        $suffixes = [
            'fcm.googleapis.com', 'android.googleapis.com',
            '.push.services.mozilla.com', 'push.services.mozilla.com',
            'web.push.apple.com', '.push.apple.com', '.notify.windows.com',
        ];
        foreach ($suffixes as $s) {
            if ($s[0] === '.') {
                if (str_ends_with($host, $s)) return true;
            } elseif ($host === $s || str_ends_with($host, '.' . $s)) {
                return true;
            }
        }
        return false;
    }

    public function subscribe(int $userId, string $userType, string $endpoint, string $p256dh, string $auth): bool
    {
        try {
            if (!$this->isAllowedEndpoint($endpoint)) {
                error_log('WebPushService::subscribe rejected non-push endpoint host');
                return false;
            }
            // Supprimer l'ancienne souscription pour le même endpoint
            $this->pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")->execute([$endpoint]);

            $stmt = $this->pdo->prepare(
                "INSERT INTO push_subscriptions (user_id, user_type, endpoint, p256dh, auth, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            return $stmt->execute([
                $userId,
                $userType,
                $endpoint,
                $p256dh,
                $auth,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log('WebPushService::subscribe: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprime une souscription push.
     */
    public function unsubscribe(string $endpoint, ?int $userId = null, ?string $userType = null): bool
    {
        try {
            // Anti-IDOR : quand une identité est fournie (appel depuis un endpoint utilisateur), la
            // suppression est bornée au propriétaire. Sans identité (nettoyage interne des souscriptions
            // mortes dans sendPush), on supprime par endpoint — action serveur légitime.
            if ($userId !== null && $userType !== null) {
                return $this->pdo->prepare(
                    "DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ? AND user_type = ?"
                )->execute([$endpoint, $userId, $userType]);
            }
            return $this->pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")->execute([$endpoint]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Retourne les souscriptions d'un utilisateur.
     */
    public function getSubscriptions(int $userId, string $userType): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM push_subscriptions WHERE user_id = ? AND user_type = ?"
        );
        $stmt->execute([$userId, $userType]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── Envoi de notifications push ────────────────────────────────

    /**
     * Envoie une notification push à un utilisateur (tous ses appareils).
     */
    public function sendToUser(int $userId, string $userType, array $payload): int
    {
        $subscriptions = $this->getSubscriptions($userId, $userType);
        $sent = 0;

        foreach ($subscriptions as $sub) {
            if ($this->sendPush($sub['endpoint'], $sub['p256dh'], $sub['auth'], $payload)) {
                $sent++;
            } else {
                // Souscription expirée/invalide — supprimer
                $this->unsubscribe($sub['endpoint']);
            }
        }

        return $sent;
    }

    /**
     * Envoie une notification push à plusieurs utilisateurs.
     */
    public function sendToUsers(array $targets, array $payload): int
    {
        $total = 0;
        foreach ($targets as $target) {
            $total += $this->sendToUser($target['user_id'], $target['user_type'], $payload);
        }
        return $total;
    }

    /**
     * Envoie une notification push brute à un endpoint.
     * Implémentation Web Push Protocol simplifiée.
     */
    private function sendPush(string $endpoint, string $p256dh, string $authKey, array $payload): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // Construction de la requête HTTP POST vers l'endpoint push
        $headers = [
            'Content-Type: application/json',
            'TTL: 86400',
        ];

        // Utiliser cURL pour l'envoi
        if (!function_exists('curl_init')) {
            error_log('WebPushService: cURL required for push notifications');
            return false;
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 201 Created = succès, 410 Gone = souscription expirée
        if ($httpCode === 410 || $httpCode === 404) {
            return false; // Souscription invalide
        }

        return $httpCode >= 200 && $httpCode < 300;
    }

    // ─��─ Nettoyage ──────────────────────────────────────────────────

    /**
     * Supprime les souscriptions anciennes (> 90 jours).
     */
    public function cleanup(): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM push_subscriptions WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        $stmt->execute();
        return $stmt->rowCount();
    }
}
