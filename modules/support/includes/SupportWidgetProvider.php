<?php

declare(strict_types=1);

namespace Support\Widgets;

use API\Contracts\AbstractWidgetProvider;

/**
 * Note scope : tickets_support n'a pas (encore) de colonne `etablissement_id`. Le scope
 * admin/vie_scolaire est donc limité aux utilisateurs ayant un compte dans cet
 * établissement (résolu via JOIN sur la table user_type correspondante). Cette
 * stratégie évite la fuite cross-tenant sans changer le schéma.
 */
class SupportWidgetProvider extends AbstractWidgetProvider
{
    public function getData(int $userId, string $userType, ?array $config = null): array
    {
        $pdo = $this->pdo();
        if (!$pdo) {
            return ['tickets' => [], 'count' => 0];
        }

        $limit = min(10, max(1, (int) ($config['limit'] ?? 5)));

        if (in_array($userType, ['administrateur', 'vie_scolaire'], true)) {
            $etabId = $this->etabId();
            if ($etabId === null) return ['tickets' => [], 'count' => 0];
            // Admin : tickets ouverts MAIS scopés aux utilisateurs de cet établissement.
            // On filtre par sous-requête UNION par type → table d'origine pour matcher l'etab.
            $stmt = $pdo->prepare(
                "SELECT id, sujet, categorie, priorite, statut, created_at
                 FROM tickets_support t
                 WHERE statut IN ('ouvert','en_cours')
                   AND (
                        (t.auteur_type = 'eleve'          AND t.auteur_id IN (SELECT id FROM eleves          WHERE etablissement_id = :eid))
                     OR (t.auteur_type = 'parent'         AND t.auteur_id IN (SELECT id FROM parents         WHERE etablissement_id = :eid))
                     OR (t.auteur_type = 'professeur'    AND t.auteur_id IN (SELECT id FROM professeurs     WHERE etablissement_id = :eid))
                     OR (t.auteur_type = 'vie_scolaire'  AND t.auteur_id IN (SELECT id FROM vie_scolaire    WHERE etablissement_id = :eid))
                     OR (t.auteur_type = 'administrateur' AND t.auteur_id IN (SELECT id FROM administrateurs WHERE etablissement_id = :eid))
                   )
                 ORDER BY FIELD(priorite, 'urgent', 'haute', 'normale', 'basse'), created_at DESC
                 LIMIT :lim"
            );
            $stmt->bindValue(':eid', $etabId, \PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
        } else {
            // Utilisateur : ses propres tickets
            $stmt = $pdo->prepare(
                "SELECT id, sujet, categorie, priorite, statut, created_at
                 FROM tickets_support
                 WHERE auteur_id = ? AND auteur_type = ?
                   AND statut IN ('ouvert','en_cours')
                 ORDER BY created_at DESC
                 LIMIT ?"
            );
            $stmt->execute([$userId, $userType, $limit]);
        }

        $tickets = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return ['tickets' => $tickets, 'count' => count($tickets)];
    }

    public function getRefreshInterval(): int
    {
        return 600;
    }
}
