<?php

declare(strict_types=1);

namespace PortailParents\Widgets;

use API\Contracts\WidgetDataProvider;

/**
 * Widget : résumé des enfants rattachés au parent (classe + moyenne générale).
 */
class PortailParentsWidgetProvider implements WidgetDataProvider
{
    public function getData(int $userId, string $userType, ?array $config = null): array
    {
        if ($userType !== 'parent') {
            return ['type' => 'list', 'items' => []];
        }
        $pdo = app('db')?->getConnection();
        if (!$pdo) {
            return ['type' => 'list', 'items' => []];
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT e.id, e.nom, e.prenom, e.classe,
                        (SELECT ROUND(AVG(note),2) FROM notes WHERE id_eleve = e.id) AS moyenne
                 FROM eleves e
                 JOIN eleve_parents ep ON e.id = ep.eleve_id
                 WHERE ep.parent_id = ? AND e.actif = 1
                 ORDER BY e.prenom"
            );
            $stmt->execute([$userId]);
            $items = [];
            foreach ($stmt as $e) {
                $moy = $e['moyenne'] !== null ? ($e['moyenne'] . '/20') : 'pas de note';
                $items[] = [
                    'titre'       => trim(($e['prenom'] ?? '') . ' ' . ($e['nom'] ?? '')) . ' · ' . ($e['classe'] ?? ''),
                    'description' => 'Moyenne générale : ' . $moy,
                ];
            }
            return ['type' => 'list', 'items' => $items, 'link' => '../modules/portail_parents/portail.php', 'link_label' => 'Ouvrir le portail'];
        } catch (\Throwable $e) {
            return ['type' => 'list', 'items' => []];
        }
    }

    public function getRefreshInterval(): int
    {
        return 600;
    }
}
