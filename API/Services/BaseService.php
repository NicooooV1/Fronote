<?php

declare(strict_types=1);

namespace API\Services;

use PDO;
use API\Core\EstablishmentContext;

/**
 * Classe abstraite mutualisant les helpers de tous les services métier.
 *
 * Bénéfices :
 *  - Un seul endroit où résoudre l'établissement courant — facilite les audits
 *    « cette requête est-elle scopée ? ».
 *  - Helpers `scopedSelect()` / `audit()` pour éviter la duplication de boilerplate.
 *  - Garantit qu'une instance sans contexte ne renvoie jamais de données globales
 *    par accident.
 *
 * Migration : un Service existant peut hériter de BaseService progressivement ;
 * la classe parente est volontairement minimaliste et sans effets de bord.
 */
abstract class BaseService
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * ID de l'établissement courant, ou null si le contexte n'est pas résolu.
     * Retourner null oblige le caller à choisir explicitement entre « rien servir »
     * et « erreur ».
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
     * Variante stricte : lève une exception si le contexte est absent.
     * À utiliser dans les endpoints où servir des données globales serait un bug
     * de sécurité (listings, agrégats, exports).
     */
    protected function etabIdOrFail(): int
    {
        $id = $this->etabId();
        if ($id === null) {
            throw new \RuntimeException('No establishment in context — refusing to serve global data.');
        }
        return $id;
    }

    /**
     * Prépare et exécute un SELECT en y injectant l'établissement courant.
     * La requête DOIT contenir le placeholder positionnel `?` pour l'etablissement_id ;
     * il est passé en TÊTE de $params pour éviter les oublis.
     *
     * Exemple :
     *   $rows = $this->scopedSelect(
     *       'SELECT * FROM annonces WHERE etablissement_id = ? AND publie = ?',
     *       [1]
     *   );
     */
    protected function scopedSelect(string $sql, array $params = []): array
    {
        $etabId = $this->etabIdOrFail();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$etabId], $params));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Audit pratique — délégué au service d'audit central.
     */
    protected function audit(string $action, string $model, ?int $id = null, ?array $old = null, ?array $new = null): void
    {
        try {
            $svc = app('audit');
            if ($svc) $svc->log($action, $model, $id, $old, $new);
        } catch (\Throwable $e) {
            // Audit ne doit jamais casser une requête métier.
        }
    }
}
