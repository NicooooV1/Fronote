<?php

declare(strict_types=1);

namespace Intelligence\Widgets;

use API\Contracts\WidgetDataProvider;

/**
 * Widget : distribution RAG des scores de risque de l'établissement courant.
 * Rendu en barres par le dashboard (renderChartWidget).
 */
class IntelligenceWidgetProvider implements WidgetDataProvider
{
    public function getData(int $userId, string $userType, ?array $config = null): array
    {
        $pdo = app('db')?->getConnection();
        if (!$pdo) {
            return ['items' => []];
        }
        try {
            $etab = \API\Core\EstablishmentContext::id();
            $stmt = $pdo->prepare(
                "SELECT niveau_alerte, COUNT(*) AS nb
                 FROM intelligence_scores
                 WHERE etablissement_id = ?
                 GROUP BY niveau_alerte"
            );
            $stmt->execute([$etab]);
            $map = ['rouge' => 0, 'orange' => 0, 'jaune' => 0, 'vert' => 0];
            foreach ($stmt as $r) {
                $map[$r['niveau_alerte']] = (int) $r['nb'];
            }
            return ['items' => [
                ['label' => 'Rouge',  'value' => $map['rouge']],
                ['label' => 'Orange', 'value' => $map['orange']],
                ['label' => 'Jaune',  'value' => $map['jaune']],
                ['label' => 'Vert',   'value' => $map['vert']],
            ]];
        } catch (\Throwable $e) {
            return ['items' => []];
        }
    }

    public function getRefreshInterval(): int
    {
        return 3600;
    }
}
