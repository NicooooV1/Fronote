<?php
declare(strict_types=1);

namespace Modules\EmploiDuTemps\Services;

use PDO;

class PeriodeService
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function getAll(): array { $etab = \API\Core\EstablishmentContext::id(); return app('cache')->remember('periodes:all:' . $etab, 1800, fn() => $this->pdo->query('SELECT * FROM periodes WHERE etablissement_id = ' . (int) $etab . ' ORDER BY numero')->fetchAll(PDO::FETCH_ASSOC)); }
    public function getById(int $id): ?array { $stmt = $this->pdo->prepare('SELECT * FROM periodes WHERE id = ? AND etablissement_id = ?'); $stmt->execute([$id, \API\Core\EstablishmentContext::id()]); $row = $stmt->fetch(PDO::FETCH_ASSOC); return $row !== false ? $row : null; }

    public function create(array $data): int
    {
        $etab = \API\Core\EstablishmentContext::id();
        $stmt = $this->pdo->prepare("INSERT INTO periodes (nom, numero, type, date_debut, date_fin, etablissement_id) VALUES (:nom, :numero, :type, :date_debut, :date_fin, :etab)");
        $stmt->execute([':nom' => $data['nom'], ':numero' => $data['numero'], ':type' => $data['type'], ':date_debut' => $data['date_debut'], ':date_fin' => $data['date_fin'], ':etab' => $etab]);
        $id = (int) $this->pdo->lastInsertId(); app('cache')->forget('periodes:all:' . $etab);
        app('hooks')?->dispatch(new \Modules\EmploiDuTemps\Events\PeriodeCreated($id, $data));
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        $etab = \API\Core\EstablishmentContext::id();
        $stmt = $this->pdo->prepare("UPDATE periodes SET nom = :nom, numero = :numero, type = :type, date_debut = :date_debut, date_fin = :date_fin WHERE id = :id AND etablissement_id = :etab");
        $stmt->execute([':nom' => $data['nom'], ':numero' => $data['numero'], ':type' => $data['type'], ':date_debut' => $data['date_debut'], ':date_fin' => $data['date_fin'], ':id' => $id, ':etab' => $etab]);
        $updated = $stmt->rowCount() > 0;
        if ($updated) { app('cache')->forget('periodes:all:' . $etab); app('hooks')?->dispatch(new \Modules\EmploiDuTemps\Events\PeriodeUpdated($id, $data)); }
        return $updated;
    }

    public function delete(int $id): bool
    {
        $etab = \API\Core\EstablishmentContext::id();
        $stmt = $this->pdo->prepare('DELETE FROM periodes WHERE id = ? AND etablissement_id = ?'); $stmt->execute([$id, $etab]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) { app('cache')->forget('periodes:all:' . $etab); app('hooks')?->dispatch(new \Modules\EmploiDuTemps\Events\PeriodeDeleted($id)); }
        return $deleted;
    }

    public function detectOverlaps(): array
    {
        $etab = (int) \API\Core\EstablishmentContext::id();
        $rows = $this->pdo->query("SELECT a.id AS a_id, a.nom AS a_nom, b.id AS b_id, b.nom AS b_nom FROM periodes a INNER JOIN periodes b ON a.id < b.id AND a.date_debut <= b.date_fin AND b.date_debut <= a.date_fin WHERE a.etablissement_id = {$etab} AND b.etablissement_id = {$etab} ORDER BY a.id, b.id")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => "{$r['a_nom']} / {$r['b_nom']}", $rows);
    }

    public function getCurrent(): ?array { $row = $this->pdo->query("SELECT * FROM periodes WHERE CURDATE() BETWEEN date_debut AND date_fin AND etablissement_id = " . (int) \API\Core\EstablishmentContext::id() . " LIMIT 1")->fetch(PDO::FETCH_ASSOC); return $row !== false ? $row : null; }

    /**
     * Génère des plages de périodes par défaut pour une année scolaire donnée.
     * Année démarrée en septembre, déduite automatiquement.
     *
     * @param string $anneeScolaire ex. "2025-2026" (tolère "2025/2026" ou "2025")
     * @param string $system        'trimestre' (3) ou 'semestre' (2)
     * @return array<int,array{nom:string,date_debut:string,date_fin:string}>
     *         Plages contiguës, prêtes pour configurePeriodes().
     */
    public static function defaultPeriodes(string $anneeScolaire, string $system = 'trimestre'): array
    {
        // Année de début = premier nombre à 4 chiffres trouvé ; sinon ancrage septembre.
        if (preg_match('/(\d{4})/', $anneeScolaire, $m)) {
            $y = (int) $m[1];
        } else {
            $now = (int) date('Y');
            $y = ((int) date('n') >= 9) ? $now : $now - 1;
        }
        $y2 = $y + 1; // année civile de janvier→juillet

        if ($system === 'semestre') {
            return [
                ['nom' => '1er semestre',  'date_debut' => "$y-09-01",  'date_fin' => "$y2-01-31"],
                ['nom' => '2ème semestre', 'date_debut' => "$y2-02-01", 'date_fin' => "$y2-07-05"],
            ];
        }

        // Trimestres par défaut.
        return [
            ['nom' => '1er trimestre',  'date_debut' => "$y-09-01",  'date_fin' => "$y-12-15"],
            ['nom' => '2ème trimestre', 'date_debut' => "$y-12-16",  'date_fin' => "$y2-03-15"],
            ['nom' => '3ème trimestre', 'date_debut' => "$y2-03-16", 'date_fin' => "$y2-07-05"],
        ];
    }
}
