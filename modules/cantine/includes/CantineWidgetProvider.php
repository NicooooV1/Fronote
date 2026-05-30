<?php

declare(strict_types=1);

namespace Cantine\Widgets;

use API\Contracts\AbstractWidgetProvider;

class CantineWidgetProvider extends AbstractWidgetProvider
{
    public function getData(int $userId, string $userType, ?array $config = null): array
    {
        $pdo = $this->pdo();
        if (!$pdo) return ['menu' => null, 'date' => null];

        $etabId = $this->etabId();
        if ($etabId === null) return ['menu' => null, 'date' => null];

        // Menu du jour ou prochain jour ouvré — scopé à l'établissement courant.
        $stmt = $pdo->prepare(
            "SELECT date_menu, entree, plat_principal, accompagnement, dessert, remarques
             FROM menus_cantine
             WHERE etablissement_id = ? AND date_menu >= CURDATE()
             ORDER BY date_menu ASC
             LIMIT 1"
        );
        $stmt->execute([$etabId]);
        $menu = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'menu' => $menu,
            'date' => $menu['date_menu'] ?? null,
        ];
    }

    public function getRefreshInterval(): int
    {
        return 3600;
    }
}
