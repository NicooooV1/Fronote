<?php
declare(strict_types=1);

namespace API\Support;

use PDO;

/**
 * SupportImpersonation — « agir en tant que » un utilisateur d'établissement, par un
 * compte Support disposant d'une session d'accès ACTIVE de niveau >= impersonation.
 *
 * Invariants de sécurité :
 *  - exige une session support active, de niveau impersonation, et le compte cible DANS
 *    le périmètre approuvé (fail-closed) ;
 *  - préserve $_SESSION['platform'] (identité réelle du Support) — l'impersonation pose
 *    EN PLUS une session legacy sur l'identité cible + un marqueur $_SESSION['impersonation'] ;
 *  - enforce() (au bootstrap) coupe immédiatement l'impersonation dès que la session
 *    support n'est plus active (révocation Direction, expiration, arrêt de sécurité) ;
 *  - chaque entrée/sortie est journalisée dans le journal d'intervention append-only.
 */
final class SupportImpersonation
{
    /** Validation pure (testable) : ce compte support peut-il impersonifier ce compte cible ? */
    public static function canImpersonate(SupportSessionService $svc, int $platformAccountId, int $establishmentId, int $tenantAccountId, ?string $now = null): array
    {
        $session = $svc->activeFor($platformAccountId, $establishmentId, $now);
        if (!$session) { return ['ok' => false, 'reason' => "Aucune session support active pour cet établissement."]; }
        if (!$svc->meetsLevel($session, 'impersonation')) { return ['ok' => false, 'reason' => "Niveau d'accès insuffisant (impersonation requis)."]; }
        if (!$svc->isInScope($session, 'account', $tenantAccountId)) { return ['ok' => false, 'reason' => "Compte cible hors du périmètre approuvé."]; }
        return ['ok' => true, 'session' => $session];
    }

    /** Démarre l'impersonation (runtime : manipule $_SESSION + app('auth')). */
    public static function start(PDO $pdo, int $platformAccountId, int $establishmentId, int $tenantAccountId): array
    {
        $svc = new SupportSessionService($pdo);
        $chk = self::canImpersonate($svc, $platformAccountId, $establishmentId, $tenantAccountId);
        if (!$chk['ok']) { return $chk; }

        $acc = self::tenantAccount($pdo, $tenantAccountId);
        if (!$acc) { return ['ok' => false, 'reason' => 'Compte cible introuvable.']; }
        $m = $pdo->prepare("SELECT 1 FROM tenant_memberships WHERE establishment_id = ? AND tenant_account_id = ? AND status = 'active' LIMIT 1");
        $m->execute([$establishmentId, $tenantAccountId]);
        if (!$m->fetchColumn()) { return ['ok' => false, 'reason' => "Le compte n'appartient pas à cet établissement."]; }
        if (empty($acc['legacy_type']) || empty($acc['legacy_id'])) {
            return ['ok' => false, 'reason' => "Compte sans identité applicative — impersonation impossible."];
        }
        $legacy = app('auth.provider')->retrieveById((int) $acc['legacy_id'], (string) $acc['legacy_type']);
        if (!$legacy) { return ['ok' => false, 'reason' => "Identité applicative introuvable ou inactive."]; }

        // Pose la session legacy sur la cible SANS toucher $_SESSION['platform'] (identité réelle conservée).
        app('auth')->loginUser($legacy);
        $_SESSION['etablissement_id'] = $establishmentId;
        try { \API\Core\EstablishmentContext::set($establishmentId); } catch (\Throwable $e) {}

        $name = trim(((string) ($acc['first_name'] ?? '')) . ' ' . ((string) ($acc['last_name'] ?? '')))
            ?: trim(((string) ($legacy['prenom'] ?? '')) . ' ' . ((string) ($legacy['nom'] ?? ''))) ?: ('compte #' . $tenantAccountId);
        $_SESSION['impersonation'] = [
            'support_session_id'       => (int) $chk['session']['id'],
            'platform_account_id'      => $platformAccountId,
            'establishment_id'         => $establishmentId,
            'ticket_id'                => (int) ($chk['session']['ticket_id'] ?? 0),
            'target_tenant_account_id' => $tenantAccountId,
            'target_legacy_type'       => (string) $acc['legacy_type'],
            'target_legacy_id'         => (int) $acc['legacy_id'],
            'target_name'              => $name,
            'expires_at'               => (string) ($chk['session']['expires_at'] ?? ''),
            'started_at'               => date('Y-m-d H:i:s'),
        ];
        $svc->audit((int) $chk['session']['id'], 'impersonation_start', [
            'target_type' => 'tenant_account', 'target_id' => $tenantAccountId, 'permission' => 'impersonation',
        ]);
        return ['ok' => true, 'session' => $chk['session']];
    }

    /** Termine l'impersonation (manuelle ou auto), journalise, conserve $_SESSION['platform']. */
    public static function stop(PDO $pdo, bool $auto = false): void
    {
        if (empty($_SESSION['impersonation'])) { return; }
        $imp = $_SESSION['impersonation'];
        try {
            (new SupportSessionService($pdo))->audit((int) ($imp['support_session_id'] ?? 0), $auto ? 'impersonation_auto_ended' : 'impersonation_end', [
                'target_type' => 'tenant_account', 'target_id' => (int) ($imp['target_tenant_account_id'] ?? 0),
            ]);
        } catch (\Throwable $e) { error_log('[SupportImpersonation] stop audit: ' . $e->getMessage()); }
        self::clear();
    }

    /** Efface la session legacy impersonifiée + le marqueur (PRÉSERVE $_SESSION['platform']). */
    public static function clear(): void
    {
        unset($_SESSION['user_id'], $_SESSION['user_type'], $_SESSION['user'], $_SESSION['etablissement_id'], $_SESSION['logged_in'], $_SESSION['impersonation']);
    }

    /**
     * Garde au bootstrap : si une impersonation est en cours mais que la session support
     * n'est plus active (révoquée/expirée/arrêtée), coupe immédiatement l'impersonation.
     */
    public static function enforce(): void
    {
        if (PHP_SAPI === 'cli') { return; }
        if (empty($_SESSION['impersonation'])) { return; }
        if (!function_exists('getPDO')) { return; }

        $imp = $_SESSION['impersonation'];
        $sid = (int) ($imp['support_session_id'] ?? 0);
        $svc = new SupportSessionService(getPDO());
        $s = $sid > 0 ? $svc->get($sid) : null;
        $active = $s && $s['status'] === 'active' && strtotime((string) $s['expires_at']) >= time();
        if ($active) { return; }

        self::stop(getPDO(), true);
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (stripos($uri, '/platform/') === false) { // évite toute boucle sur le portail plateforme
            $base = defined('BASE_URL') ? BASE_URL : '';
            header("Location: {$base}/platform/support/tickets.php?impersonation_ended=1");
            exit;
        }
    }

    private static function tenantAccount(PDO $pdo, int $id): ?array
    {
        try {
            $st = $pdo->prepare("SELECT * FROM tenant_accounts WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) { return null; }
    }
}
