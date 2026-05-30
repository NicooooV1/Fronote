<?php

declare(strict_types=1);

namespace Absences\Widgets;

use API\Contracts\WidgetDataProvider;

/**
 * Widget "Absences aujourd'hui" — toujours scopé par établissement courant.
 * Un widget de tableau de bord ne doit jamais agréger cross-tenant : un parent
 * ou un prof verrait sinon des chiffres mélangés.
 */
class AbsenceWidgetProvider implements WidgetDataProvider
{
    public function getData(int $userId, string $userType, ?array $config = null): array
    {
        $pdo = app('db')?->getConnection();
        if (!$pdo) {
            return ['absences' => 0, 'retards' => 0, 'unjustified' => 0];
        }

        try {
            $etabId = \API\Core\EstablishmentContext::id();
        } catch (\Throwable $e) {
            // Contexte établissement non résolu — on ne sert pas de chiffres globaux.
            return ['absences' => 0, 'retards' => 0, 'unjustified' => 0];
        }

        $absStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM absences a
             JOIN eleves e ON a.id_eleve = e.id
             WHERE e.etablissement_id = ?
               AND CURDATE() BETWEEN DATE(a.date_debut) AND DATE(a.date_fin)"
        );
        $absStmt->execute([$etabId]);
        $absences = (int) $absStmt->fetchColumn();

        $retStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM retards r
             JOIN eleves e ON r.id_eleve = e.id
             WHERE e.etablissement_id = ? AND DATE(r.date_retard) = CURDATE()"
        );
        $retStmt->execute([$etabId]);
        $retards = (int) $retStmt->fetchColumn();

        $unjStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM absences a
             JOIN eleves e ON a.id_eleve = e.id
             WHERE e.etablissement_id = ? AND a.justifie = 0"
        );
        $unjStmt->execute([$etabId]);
        $unjustified = (int) $unjStmt->fetchColumn();

        return [
            'absences'    => $absences,
            'retards'     => $retards,
            'unjustified' => $unjustified,
        ];
    }

    public function getRefreshInterval(): int
    {
        return 300;
    }
}
