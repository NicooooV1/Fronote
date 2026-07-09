<?php

declare(strict_types=1);

namespace EmploiDuTemps\Widgets;

use API\Contracts\AbstractWidgetProvider;

/**
 * @global-scope Widget scopé implicitement par user_id (le user appartient à un
 * seul établissement, donc ses entités le sont aussi).
 */
class EdtWidgetProvider extends AbstractWidgetProvider
{
    private const JOURS = ['dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi'];

    public function getData(int $userId, string $userType, ?array $config = null): array
    {
        $pdo = app('db')?->getConnection();
        if (!$pdo) {
            return ['cours' => [], 'jour' => ''];
        }

        $jourNum = (int) date('w'); // 0=dim ... 6=sam
        $jourFr = self::JOURS[$jourNum] ?? 'lundi';
        $today = date('Y-m-d');
        $etab = \API\Core\EstablishmentContext::id();

        if ($userType === 'eleve') {
            $stmt = $pdo->prepare(
                "SELECT edt.heure_debut, edt.heure_fin, m.nom AS matiere,
                        CONCAT(p.prenom, ' ', p.nom) AS professeur,
                        s.nom AS salle, edt.type_cours,
                        em.type_modification
                 FROM emploi_du_temps edt
                 JOIN matieres m ON m.id = edt.matiere_id
                 JOIN professeurs p ON p.id = edt.professeur_id
                 LEFT JOIN salles s ON s.id = edt.salle_id
                 LEFT JOIN edt_modifications em ON em.edt_id = edt.id AND em.date_cours = ?
                 WHERE edt.classe_id = (SELECT c.id FROM classes c JOIN eleves e ON e.classe = c.nom WHERE e.id = ? AND c.actif = 1 AND c.etablissement_id = ? LIMIT 1)
                   AND edt.jour = ? AND edt.actif = 1 AND edt.etablissement_id = ?
                 ORDER BY edt.heure_debut"
            );
            $stmt->execute([$today, $userId, $etab, $jourFr, $etab]);
        } elseif ($userType === 'professeur') {
            $stmt = $pdo->prepare(
                "SELECT edt.heure_debut, edt.heure_fin, m.nom AS matiere,
                        c.nom AS classe, s.nom AS salle, edt.type_cours,
                        em.type_modification
                 FROM emploi_du_temps edt
                 JOIN matieres m ON m.id = edt.matiere_id
                 JOIN classes c ON c.id = edt.classe_id
                 LEFT JOIN salles s ON s.id = edt.salle_id
                 LEFT JOIN edt_modifications em ON em.edt_id = edt.id AND em.date_cours = ?
                 WHERE edt.professeur_id = ? AND edt.jour = ? AND edt.actif = 1 AND edt.etablissement_id = ?
                 ORDER BY edt.heure_debut"
            );
            $stmt->execute([$today, $userId, $jourFr, $etab]);
        } elseif ($userType === 'parent') {
            $childId = $_SESSION['selected_child_id'] ?? null;
            if (!$childId) {
                return ['cours' => [], 'jour' => $jourFr];
            }
            $stmt = $pdo->prepare(
                "SELECT edt.heure_debut, edt.heure_fin, m.nom AS matiere,
                        CONCAT(p.prenom, ' ', p.nom) AS professeur,
                        s.nom AS salle, edt.type_cours,
                        em.type_modification
                 FROM emploi_du_temps edt
                 JOIN matieres m ON m.id = edt.matiere_id
                 JOIN professeurs p ON p.id = edt.professeur_id
                 LEFT JOIN salles s ON s.id = edt.salle_id
                 LEFT JOIN edt_modifications em ON em.edt_id = edt.id AND em.date_cours = ?
                 WHERE edt.classe_id = (SELECT c.id FROM classes c JOIN eleves e ON e.classe = c.nom WHERE e.id = ? AND c.actif = 1 AND c.etablissement_id = ? LIMIT 1)
                   AND edt.jour = ? AND edt.actif = 1 AND edt.etablissement_id = ?
                 ORDER BY edt.heure_debut"
            );
            $stmt->execute([$today, $childId, $etab, $jourFr, $etab]);
        } else {
            return ['cours' => [], 'jour' => $jourFr];
        }

        $cours = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Filter out cancelled courses
        $cours = array_filter($cours, fn($c) => ($c['type_modification'] ?? '') !== 'annulation');
        $cours = array_values($cours);

        return ['cours' => $cours, 'jour' => ucfirst($jourFr)];
    }

    public function getRefreshInterval(): int
    {
        return 900;
    }
}
