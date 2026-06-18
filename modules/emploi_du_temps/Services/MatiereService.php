<?php
declare(strict_types=1);

namespace Modules\EmploiDuTemps\Services;

use PDO;
use RuntimeException;

class MatiereService
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }
    private function etabId(): int { return \API\Core\EstablishmentContext::id(); }

    public function getAll(): array
    {
        $etab = $this->etabId();
        return app('cache')->remember('matieres:all:' . $etab, 600, function () use ($etab) {
            $stmt = $this->pdo->prepare("SELECT m.*, (SELECT COUNT(*) FROM notes WHERE id_matiere = m.id AND etablissement_id = m.etablissement_id) AS note_count FROM matieres m WHERE m.etablissement_id = ? ORDER BY m.actif DESC, m.nom");
            $stmt->execute([$etab]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    public function getById(int $id): ?array { $stmt = $this->pdo->prepare('SELECT * FROM matieres WHERE id = ?'); $stmt->execute([$id]); $row = $stmt->fetch(PDO::FETCH_ASSOC); return $row !== false ? $row : null; }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO matieres (nom, code, coefficient, couleur) VALUES (:nom, :code, :coefficient, :couleur)");
        $stmt->execute([':nom' => $data['nom'], ':code' => strtoupper($data['code']), ':coefficient' => $data['coefficient'], ':couleur' => $data['couleur']]);
        $id = (int) $this->pdo->lastInsertId();
        app('cache')->forget('matieres:all:' . $this->etabId()); app('cache')->forget('dashboard:counts');
        app('hooks')?->dispatch(new \Modules\EmploiDuTemps\Events\MatiereCreated($id, $data));
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare("UPDATE matieres SET nom = :nom, code = :code, coefficient = :coefficient, couleur = :couleur, actif = :actif WHERE id = :id");
        $stmt->execute([':nom' => $data['nom'], ':code' => strtoupper($data['code']), ':coefficient' => $data['coefficient'], ':couleur' => $data['couleur'], ':actif' => $data['actif'], ':id' => $id]);
        $updated = $stmt->rowCount() > 0;
        if ($updated) { app('cache')->forget('matieres:all:' . $this->etabId()); app('cache')->forget('dashboard:counts'); app('hooks')?->dispatch(new \Modules\EmploiDuTemps\Events\MatiereUpdated($id, $data)); }
        return $updated;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM notes WHERE id_matiere = ?'); $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) throw new RuntimeException("Impossible de supprimer la matiere #{$id} : notes associées.");
        $stmt = $this->pdo->prepare('DELETE FROM matieres WHERE id = ?'); $stmt->execute([$id]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) { app('cache')->forget('matieres:all:' . $this->etabId()); app('cache')->forget('dashboard:counts'); app('hooks')?->dispatch(new \Modules\EmploiDuTemps\Events\MatiereDeleted($id)); }
        return $deleted;
    }

    public function toggleActive(int $id): bool { $stmt = $this->pdo->prepare('UPDATE matieres SET actif = NOT actif WHERE id = ?'); $stmt->execute([$id]); return $stmt->rowCount() > 0; }
}
