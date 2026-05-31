<?php
declare(strict_types=1);

namespace Modules\EmploiDuTemps\Services;

use PDO;

class PeriodeService
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function getAll(): array { return app('cache')->remember('periodes:all', 1800, fn() => $this->pdo->query('SELECT * FROM periodes ORDER BY numero')->fetchAll(PDO::FETCH_ASSOC)); }
    public function getById(int $id): ?array { $stmt = $this->pdo->prepare('SELECT * FROM periodes WHERE id = ?'); $stmt->execute([$id]); $row = $stmt->fetch(PDO::FETCH_ASSOC); return $row !== false ? $row : null; }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO periodes (nom, numero, type, date_debut, date_fin) VALUES (:nom, :numero, :type, :date_debut, :date_fin)");
        $stmt->execute([':nom' => $data['nom'], ':numero' => $data['numero'], ':type' => $data['type'], ':date_debut' => $data['date_debut'], ':date_fin' => $data['date_fin']]);
        $id = (int) $this->pdo->lastInsertId(); app('cache')->forget('periodes:all');
        app('hooks')?->dispatch(new \Modules\EmploiDuTemps\Events\PeriodeCreated($id, $data));
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare("UPDATE periodes SET nom = :nom, numero = :numero, type = :type, date_debut = :date_debut, date_fin = :date_fin WHERE id = :id");
        $stmt->execute([':nom' => $data['nom'], ':numero' => $data['numero'], ':type' => $data['type'], ':date_debut' => $data['date_debut'], ':date_fin' => $data['date_fin'], ':id' => $id]);
        $updated = $stmt->rowCount() > 0;
        if ($updated) { app('cache')->forget('periodes:all'); app('hooks')?->dispatch(new \Modules\EmploiDuTemps\Events\PeriodeUpdated($id, $data)); }
        return $updated;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM periodes WHERE id = ?'); $stmt->execute([$id]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) { app('cache')->forget('periodes:all'); app('hooks')?->dispatch(new \Modules\EmploiDuTemps\Events\PeriodeDeleted($id)); }
        return $deleted;
    }

    public function detectOverlaps(): array
    {
        $rows = $this->pdo->query("SELECT a.id AS a_id, a.nom AS a_nom, b.id AS b_id, b.nom AS b_nom FROM periodes a INNER JOIN periodes b ON a.id < b.id AND a.date_debut <= b.date_fin AND b.date_debut <= a.date_fin ORDER BY a.id, b.id")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => "{$r['a_nom']} / {$r['b_nom']}", $rows);
    }

    public function getCurrent(): ?array { $row = $this->pdo->query("SELECT * FROM periodes WHERE CURDATE() BETWEEN date_debut AND date_fin LIMIT 1")->fetch(PDO::FETCH_ASSOC); return $row !== false ? $row : null; }
}
