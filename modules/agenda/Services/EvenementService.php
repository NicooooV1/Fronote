<?php
declare(strict_types=1);

namespace Modules\Agenda\Services;

use PDO;

class EvenementService
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }
    private function etabId(): int { return \API\Core\EstablishmentContext::id(); }

    public function getFiltered(array $filters, int $page = 1, int $perPage = 30): array
    {
        $where = ['etablissement_id = :etab']; $params = [':etab' => $this->etabId()];
        if (!empty($filters['type']))   { $where[] = 'type_evenement = :type';   $params[':type']   = $filters['type']; }
        if (!empty($filters['status'])) { $where[] = 'statut = :status';          $params[':status'] = $filters['status']; }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM evenements {$w}"); $stmt->execute($params);
        $total = (int) $stmt->fetchColumn(); $pages = (int) ceil((float) ($total / $perPage)); $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare("SELECT * FROM evenements {$w} ORDER BY date_debut DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT); $stmt->bindValue(':offset', $offset, PDO::PARAM_INT); $stmt->execute();
        return ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'pages' => $pages];
    }

    public function getById(int $id): ?array { $stmt = $this->pdo->prepare('SELECT * FROM evenements WHERE id = ? AND etablissement_id = ?'); $stmt->execute([$id, $this->etabId()]); $row = $stmt->fetch(PDO::FETCH_ASSOC); return $row !== false ? $row : null; }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO evenements (titre, description, date_debut, date_fin, type_evenement, type_personnalise, statut, createur, visibilite, personnes_concernees, lieu, classes, matieres, etablissement_id, date_creation, date_modification) VALUES (:titre, :description, :date_debut, :date_fin, :type_evenement, :type_personnalise, :statut, :createur, :visibilite, :personnes_concernees, :lieu, :classes, :matieres, :etab, NOW(), NOW())");
        $stmt->execute([':etab' => $this->etabId(), ':titre' => $data['titre'], ':description' => $data['description'] ?? null, ':date_debut' => $data['date_debut'], ':date_fin' => $data['date_fin'], ':type_evenement' => $data['type_evenement'], ':type_personnalise' => $data['type_personnalise'] ?? null, ':statut' => $data['statut'] ?? 'actif', ':createur' => $data['createur'] ?? null, ':visibilite' => $data['visibilite'] ?? null, ':personnes_concernees' => $data['personnes_concernees'] ?? null, ':lieu' => $data['lieu'] ?? null, ':classes' => $data['classes'] ?? null, ':matieres' => $data['matieres'] ?? null]);
        $id = (int) $this->pdo->lastInsertId();
        app('hooks')?->dispatch(new \Modules\Agenda\Events\EvenementCreated($id, $data));
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare("UPDATE evenements SET titre = :titre, description = :description, date_debut = :date_debut, date_fin = :date_fin, type_evenement = :type_evenement, lieu = :lieu, date_modification = NOW() WHERE id = :id AND etablissement_id = :etab");
        $stmt->execute([':titre' => $data['titre'], ':description' => $data['description'] ?? null, ':date_debut' => $data['date_debut'], ':date_fin' => $data['date_fin'], ':type_evenement' => $data['type_evenement'], ':lieu' => $data['lieu'] ?? null, ':id' => $id, ':etab' => $this->etabId()]);
        $updated = $stmt->rowCount() > 0;
        if ($updated) app('hooks')?->dispatch(new \Modules\Agenda\Events\EvenementUpdated($id, $data));
        return $updated;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM evenements WHERE id = ? AND etablissement_id = ?'); $stmt->execute([$id, $this->etabId()]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) app('hooks')?->dispatch(new \Modules\Agenda\Events\EvenementDeleted($id));
        return $deleted;
    }

    public function toggleStatus(int $id): bool { $stmt = $this->pdo->prepare("UPDATE evenements SET statut = CASE WHEN statut = 'actif' THEN 'annule' ELSE 'actif' END, date_modification = NOW() WHERE id = ? AND etablissement_id = ?"); $stmt->execute([$id, $this->etabId()]); return $stmt->rowCount() > 0; }
    public function getDistinctTypes(): array { $stmt = $this->pdo->prepare('SELECT DISTINCT type_evenement FROM evenements WHERE etablissement_id = ? ORDER BY type_evenement'); $stmt->execute([$this->etabId()]); return $stmt->fetchAll(PDO::FETCH_COLUMN); }
}
