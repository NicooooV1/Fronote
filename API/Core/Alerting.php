<?php
declare(strict_types=1);

namespace API\Core;

/**
 * Alerting — notification proactive d'incidents (sécurité, sauvegarde, disponibilité).
 *
 * Journalise toujours en CRITICAL, et POSTe vers ALERT_WEBHOOK (Slack/Teams/webhook
 * générique) si configuré. Best-effort et non bloquant : ne lève jamais, n'ajoute pas
 * de latence perceptible (timeout court). Anti-flood : au plus une alerte identique
 * par fenêtre courte (clé sujet, via fichier de garde dans le temp).
 */
final class Alerting
{
    public static function notify(string $subject, string $body, int $throttleSeconds = 300): void
    {
        error_log('[fronote][ALERT][CRITICAL] ' . $subject . ' — ' . $body);

        $webhook = getenv('ALERT_WEBHOOK') ?: '';
        if ($webhook === '' || !filter_var($webhook, FILTER_VALIDATE_URL)) {
            return;
        }
        if (!self::shouldSend($subject, $throttleSeconds)) {
            return;
        }
        $host = gethostname() ?: 'fronote';
        $payload = json_encode(['text' => "[Fronote:{$host}] {$subject}\n{$body}"]);
        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($webhook);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT => 4,
                    CURLOPT_RETURNTRANSFER => true,
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
        } catch (\Throwable $e) {
            error_log('[fronote][ALERT] webhook failed: ' . $e->getMessage());
        }
    }

    /** Anti-flood best-effort par clé de sujet (garde-fichier dans le temp). */
    private static function shouldSend(string $subject, int $throttleSeconds): bool
    {
        try {
            $dir = (getenv('TEMP_PATH') ?: sys_get_temp_dir()) . '/fronote-alerts';
            if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
            $key = $dir . '/' . hash('sha256', $subject) . '.ts';
            $last = is_file($key) ? (int) @file_get_contents($key) : 0;
            $now = time();
            if ($now - $last < $throttleSeconds) {
                return false;
            }
            @file_put_contents($key, (string) $now);
        } catch (\Throwable $e) {
            // En cas de doute, on laisse passer l'alerte (fail-open sur la notification).
        }
        return true;
    }
}
