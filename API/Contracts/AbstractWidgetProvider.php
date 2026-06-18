<?php

declare(strict_types=1);

namespace API\Contracts;

use PDO;
use API\Core\EstablishmentContext;

/**
 * Base par défaut pour les WidgetDataProvider.
 *
 * Fournit deux helpers que TOUS les widgets devraient utiliser :
 *  - `pdo()`              : connexion PDO résolue via le container (avec fallback).
 *  - `etabId()` / `etabIdOrEmpty()` : établissement courant.
 *
 * Le contrat WidgetDataProvider lui-même ne change pas (compat 12 providers existants),
 * mais hériter de cette classe garantit que les nouveaux widgets ne servent pas
 * de chiffres cross-tenant par défaut.
 *
 * Pattern recommandé :
 *
 *   class MyWidget extends AbstractWidgetProvider {
 *       public function getData(int $userId, string $userType, ?array $config = null): array {
 *           $etabId = $this->etabIdOrEmpty([...]); // retour par défaut si pas de contexte
 *           if (!$etabId) return $etabId; // ← chemin court
 *           $stmt = $this->pdo()->prepare('SELECT ... WHERE etablissement_id = ?');
 *           $stmt->execute([$etabId]);
 *           ...
 *       }
 *       public function getRefreshInterval(): int { return 300; }
 *   }
 */
abstract class AbstractWidgetProvider implements WidgetDataProvider
{
    protected function pdo(): ?PDO
    {
        try {
            $db = function_exists('app') ? app('db') : null;
            return $db?->getConnection();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Établissement courant ou null si le contexte n'est pas résolu.
     */
    protected function etabId(): ?int
    {
        try {
            return EstablishmentContext::id();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Helper en cascade : retourne l'etabId si présent, sinon le tableau "vide"
     * fourni — pratique pour court-circuiter `getData()` en une ligne.
     *
     * @return int|array Si int : l'etabId à utiliser. Si array : c'est la valeur de retour.
     */
    protected function etabIdOrEmpty(array $emptyShape)
    {
        $id = $this->etabId();
        return $id ?? $emptyShape;
    }
}
