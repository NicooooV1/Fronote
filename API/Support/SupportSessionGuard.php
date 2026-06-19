<?php
declare(strict_types=1);

namespace API\Support;

use PDO;

/**
 * SupportSessionGuard — contrôle d'entrée d'un membre Support dans un établissement.
 * Vérifie : session active et non expirée pour (compte support, établissement),
 * niveau d'accès suffisant, ressource dans le périmètre approuvé, restrictions
 * respectées. Toute vérification accordée est journalisée (audit append-only).
 */
final class SupportSessionGuard
{
    private PDO $pdo;
    private SupportSessionService $sessions;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->sessions = new SupportSessionService($pdo);
    }

    /** Session active courante du Support sur cet établissement (ou null). */
    public function session(int $platformAccountId, int $establishmentId, ?string $now = null): ?array
    {
        return $this->sessions->activeFor($platformAccountId, $establishmentId, $now);
    }

    /**
     * Le Support peut-il agir au niveau $requiredLevel (et, si fourni, sur la ressource) ?
     * fail-closed : pas de session / niveau insuffisant / hors périmètre ⇒ false.
     */
    public function can(int $platformAccountId, int $establishmentId, string $requiredLevel, ?string $resourceType = null, ?int $resourceId = null, bool $sensitive = false, ?string $now = null): bool
    {
        $session = $this->sessions->activeFor($platformAccountId, $establishmentId, $now);
        if ($session === null) {
            return false; // aucune session active : pas d'accès
        }
        if (!$this->sessions->meetsLevel($session, $requiredLevel)) {
            return false; // niveau d'accès insuffisant
        }
        if ($resourceType !== null && $resourceId !== null) {
            if (!$this->sessions->isInScope($session, $resourceType, $resourceId, $sensitive)) {
                return false; // hors périmètre approuvé / donnée sensible masquée
            }
        }
        // Accès accordé → trace d'intervention.
        $this->sessions->audit((int) $session['id'], 'access_granted', [
            'permission' => $requiredLevel, 'target_type' => $resourceType, 'target_id' => $resourceId, 'sensitive' => $sensitive,
        ]);
        return true;
    }

    public function authorize(int $platformAccountId, int $establishmentId, string $requiredLevel, ?string $resourceType = null, ?int $resourceId = null, bool $sensitive = false): void
    {
        if (!$this->can($platformAccountId, $establishmentId, $requiredLevel, $resourceType, $resourceId, $sensitive)) {
            http_response_code(403);
            if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(['error' => true, 'code' => 403, 'message' => 'Accès support refusé (session/niveau/périmètre).'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
