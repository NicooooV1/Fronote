<?php

declare(strict_types=1);

namespace Reunions\Widgets;

use API\Contracts\AbstractWidgetProvider;

/**
 * @global-scope Widget scopé implicitement par user_id (le user appartient à un
 * seul établissement, donc ses entités le sont aussi).
 */
class ReunionWidgetProvider extends AbstractWidgetProvider
{
    public function getData(int $userId, string $userType, ?array $config = null): array
    {
        $pdo = app('db')?->getConnection();
        if (!$pdo) {
            return ['reunions' => [], 'count' => 0];
        }

        $limit = min(10, max(1, (int) ($config['limit'] ?? 5)));
        // Réunions où l'utilisateur est attendu (participant convoqué ou rendez-vous réservé), à venir
        $stmt = $pdo->prepare(
            "SELECT r.id, r.titre, r.type, r.date_debut, COALESCE(s.nom, r.lieu) AS lieu, MIN(rc.heure_debut) AS heure_debut
             FROM reunions r
             LEFT JOIN salles s ON s.id = r.salle_id
             LEFT JOIN reunion_participants p ON p.reunion_id = r.id AND p.user_id = ? AND p.user_type = ?
             LEFT JOIN reunion_creneaux rc ON rc.reunion_id = r.id
             LEFT JOIN reunion_reservations rr ON rr.creneau_id = rc.id AND rr.statut <> 'annulee' AND rr.parent_id = ?
             WHERE r.date_fin >= NOW() AND r.statut <> 'annulee'
               AND (p.id IS NOT NULL OR rr.id IS NOT NULL OR (rc.professeur_id = ? AND ? = 'professeur'))
             GROUP BY r.id
             ORDER BY r.date_debut ASC
             LIMIT ?"
        );
        $stmt->execute([$userId, $userType, $userType === 'parent' ? $userId : 0, $userId, $userType, $limit]);
        $reunions = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return ['reunions' => $reunions, 'count' => count($reunions)];
    }

    public function getRefreshInterval(): int
    {
        return 1800;
    }
}
