<?php

declare(strict_types=1);

namespace Evaluations\Widgets;

use API\Contracts\AbstractWidgetProvider;

/**
 * Widget : évaluations en ligne à venir (élève/parent par classe, prof par auteur).
 */
/**
 * @global-scope Widget scopé implicitement par user_id (le user appartient à un
 * seul établissement, donc ses entités le sont aussi).
 */
class EvaluationWidgetProvider extends AbstractWidgetProvider
{
    public function getData(int $userId, string $userType, ?array $config = null): array
    {
        $pdo = app('db')?->getConnection();
        if (!$pdo) {
            return ['type' => 'list', 'items' => []];
        }
        $limit = min(20, max(1, (int) ($config['limit'] ?? 5)));
        $items = [];

        try {
            if ($userType === 'professeur') {
                $stmt = $pdo->prepare(
                    "SELECT titre, classe, date_ouverture
                     FROM evaluations_en_ligne
                     WHERE professeur_id = ? AND statut <> 'brouillon' AND date_fermeture >= NOW()
                     ORDER BY date_ouverture ASC LIMIT ?"
                );
                $stmt->execute([$userId, $limit]);
                foreach ($stmt as $r) {
                    $items[] = ['titre' => $r['titre'], 'description' => trim(($r['classe'] ?? '') . ' · ' . ($r['date_ouverture'] ?? ''), ' ·')];
                }
            } elseif (in_array($userType, ['eleve', 'parent'], true)) {
                $cibleId = $userType === 'parent' ? ($_SESSION['selected_child_id'] ?? null) : $userId;
                if ($cibleId) {
                    $stmt = $pdo->prepare(
                        "SELECT el.titre, el.date_ouverture
                         FROM evaluations_en_ligne el
                         WHERE el.classe = (SELECT classe FROM eleves WHERE id = ? LIMIT 1)
                           AND el.statut <> 'brouillon' AND el.date_fermeture >= NOW()
                         ORDER BY el.date_ouverture ASC LIMIT ?"
                    );
                    $stmt->execute([$cibleId, $limit]);
                    foreach ($stmt as $r) {
                        $items[] = ['titre' => $r['titre'], 'description' => $r['date_ouverture'] ?? ''];
                    }
                }
            }
        } catch (\Throwable $e) {
            return ['type' => 'list', 'items' => []];
        }

        return ['type' => 'list', 'items' => $items, 'link' => '../modules/evaluations/evaluations.php', 'link_label' => 'Voir tout'];
    }

    public function getRefreshInterval(): int
    {
        return 300;
    }
}
