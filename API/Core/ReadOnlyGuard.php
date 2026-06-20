<?php
declare(strict_types=1);

namespace API\Core;

/**
 * ReadOnlyGuard — applique « établissement archivé = lecture seule » à TOUS les modules
 * sans les modifier. Au bootstrap web, si l'utilisateur est en contexte établissement
 * (session legacy/tenant, pas plateforme) et que son établissement n'est pas inscriptible
 * (archived/suspended/deleted/purged), toute requête en écriture (POST/PUT/PATCH/DELETE)
 * est bloquée (403), sauf l'authentification (login/logout).
 *
 * Le monde PLATEFORME est exempté (il gère justement les établissements archivés), de même
 * que le SUPPORT (session plateforme). La logique de décision est pure et testée.
 */
final class ReadOnlyGuard
{
    /** Statuts d'établissement où l'écriture des données est interdite. */
    public const NON_WRITABLE = ['suspended', 'archived', 'deleted', 'purged'];

    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Sous-chaînes de chemin toujours autorisées (authentification). */
    private const ALLOW = ['login', 'logout'];

    /**
     * Décision pure : faut-il bloquer cette écriture ? Testable hors HTTP.
     */
    public static function decide(string $method, string $status, bool $hasEstablishmentSession, bool $isPlatform, string $path, array $allow = self::ALLOW): bool
    {
        if (!in_array(strtoupper($method), self::WRITE_METHODS, true)) { return false; }
        if ($isPlatform || !$hasEstablishmentSession) { return false; }
        foreach ($allow as $sub) {
            if ($sub !== '' && stripos($path, $sub) !== false) { return false; }
        }
        return in_array($status, self::NON_WRITABLE, true);
    }

    /** Point d'entrée bootstrap : sorties anticipées bon marché avant tout accès DB. */
    public static function enforce(): void
    {
        if (PHP_SAPI === 'cli') { return; }
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, self::WRITE_METHODS, true)) { return; }
        if (!empty($_SESSION['platform'])) { return; } // monde plateforme exempté
        $hasEstab = !empty($_SESSION['user']) || !empty($_SESSION['tenant']);
        if (!$hasEstab) { return; }

        $estabId = (int) ($_SESSION['etablissement_id'] ?? ($_SESSION['tenant']['establishment_id'] ?? 0));
        if ($estabId <= 0) { return; }

        $path = (string) ($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''));
        foreach (self::ALLOW as $sub) {
            if (stripos($path, $sub) !== false) { return; } // login/logout : jamais bloqués
        }

        if (!function_exists('getPDO')) { return; }
        try {
            $st = getPDO()->prepare("SELECT status FROM etablissements WHERE id = ? LIMIT 1");
            $st->execute([$estabId]);
            $status = $st->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[ReadOnlyGuard] ' . $e->getMessage());
            return; // fail-open : indéterminable → ne pas verrouiller
        }
        if ($status === false) { return; }
        if (!self::decide($method, (string) $status, true, false, $path)) { return; }

        self::block((string) $status);
    }

    private static function block(string $status): void
    {
        error_log('[ReadOnlyGuard] écriture bloquée (établissement ' . $status . ') : ' . ($_SERVER['REQUEST_URI'] ?? ''));
        http_response_code(403);
        $msg = $status === 'archived'
            ? 'Établissement archivé — accès en lecture seule.'
            : 'Établissement indisponible — modification interdite.';
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') || stripos($accept, 'application/json') !== false;
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $msg, 'read_only' => true], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>Lecture seule</title>'
               . '<div style="font-family:system-ui,sans-serif;max-width:520px;margin:80px auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px">'
               . '<h1 style="font-size:1.1rem;margin:0 0 8px">' . htmlspecialchars($msg) . '</h1>'
               . '<p style="color:#475569">Cette action de modification est désactivée pour cet établissement.</p>'
               . '<a href="javascript:history.back()">Retour</a></div>';
        }
        exit;
    }
}
