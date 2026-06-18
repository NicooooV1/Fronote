<?php
declare(strict_types=1);

namespace API\Services\Scolaire;

use PDO;
use RuntimeException;

class ClasseService
{
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    private function etabId(): int { return \API\Core\EstablishmentContext::id(); }

    public function getAllWithStats(): array
    {
        $stmt = $this->pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM eleves WHERE classe = c.nom AND actif = 1 AND etablissement_id = c.etablissement_id) AS effectif, CONCAT(p.prenom, ' ', p.nom) AS pp_nom FROM classes c LEFT JOIN professeurs p ON c.professeur_principal_id = p.id WHERE c.etablissement_id = ? ORDER BY c.nom");
        $stmt->execute([$this->etabId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM classes WHERE id = ?'); $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC); return $row !== false ? $row : null;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO classes (nom, niveau, annee_scolaire, professeur_principal_id) VALUES (:nom, :niveau, :annee_scolaire, :professeur_principal_id)");
        $stmt->execute([':nom' => $data['nom'], ':niveau' => $data['niveau'], ':annee_scolaire' => $data['annee_scolaire'], ':professeur_principal_id' => $data['professeur_principal_id'] ?? null]);
        $id = (int) $this->pdo->lastInsertId();
        app('hooks')?->dispatch(new \API\Events\ClasseCreated($id, $data));
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        $fields = []; $params = [':id' => $id];
        foreach (['nom', 'niveau', 'annee_scolaire', 'professeur_principal_id', 'actif'] as $field) {
            if (array_key_exists($field, $data)) { $fields[] = "{$field} = :{$field}"; $params[":{$field}"] = $data[$field]; }
        }
        if (empty($fields)) return false;
        $stmt = $this->pdo->prepare('UPDATE classes SET ' . implode(', ', $fields) . ' WHERE id = :id');
        $stmt->execute($params);
        $updated = $stmt->rowCount() > 0;
        if ($updated) app('hooks')?->dispatch(new \API\Events\ClasseUpdated($id, $data));
        return $updated;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT nom FROM classes WHERE id = ?'); $stmt->execute([$id]);
        $nom = $stmt->fetchColumn();
        if ($nom === false) return false;
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM eleves WHERE classe = ?'); $stmt->execute([$nom]);
        $effectif = (int) $stmt->fetchColumn();
        if ($effectif > 0) throw new RuntimeException("Impossible de supprimer la classe #{$id} ({$nom}) : {$effectif} eleve(s) affecte(s).");
        $stmt = $this->pdo->prepare('DELETE FROM classes WHERE id = ?'); $stmt->execute([$id]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) app('hooks')?->dispatch(new \API\Events\ClasseDeleted($id));
        return $deleted;
    }

    public function assignStudents(int $classeId, string $className, array $studentIds): int
    {
        if (empty($studentIds)) return 0;
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $this->pdo->prepare("UPDATE eleves SET classe = ? WHERE id IN ({$placeholders})");
        $stmt->execute(array_merge([$className], array_map('intval', $studentIds)));
        return $stmt->rowCount();
    }

    public function getAffectationsMatrix(): array
    {
        $stmt = $this->pdo->prepare("SELECT pc.professeur_id, pc.classe_nom, CONCAT(p.prenom, ' ', p.nom) AS prof_nom FROM professeur_classes pc JOIN professeurs p ON pc.professeur_id = p.id WHERE p.etablissement_id = ?");
        $stmt->execute([$this->etabId()]);
        $matrix = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $profId = (int) $row['professeur_id'];
            if (!isset($matrix[$profId])) $matrix[$profId] = ['prof_nom' => $row['prof_nom'], 'classes' => []];
            $matrix[$profId]['classes'][] = $row['classe_nom'];
        }
        return $matrix;
    }

    public function toggleAffectation(int $profId, string $className): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM professeur_classes WHERE professeur_id = ? AND classe_nom = ?'); $stmt->execute([$profId, $className]);
        if ((int) $stmt->fetchColumn() > 0) {
            $this->pdo->prepare('DELETE FROM professeur_classes WHERE professeur_id = ? AND classe_nom = ?')->execute([$profId, $className]);
        } else {
            $this->pdo->prepare('INSERT INTO professeur_classes (professeur_id, classe_nom) VALUES (?, ?)')->execute([$profId, $className]);
        }
        return true;
    }

    public function saveFullMatrix(array $assignments): bool
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM professeur_classes');
            $stmt = $this->pdo->prepare('INSERT INTO professeur_classes (professeur_id, classe_nom) VALUES (:professeur_id, :classe_nom)');
            foreach ($assignments as $a) { $stmt->execute([':professeur_id' => $a['professeur_id'], ':classe_nom' => $a['classe_nom']]); }
            $this->pdo->commit(); return true;
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    public function getAll(): array
    {
        return $this->getAllWithStats();
    }
}
