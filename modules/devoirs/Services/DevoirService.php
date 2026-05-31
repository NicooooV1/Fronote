<?php
declare(strict_types=1);

namespace Modules\Devoirs\Services;

use PDO;

class DevoirService
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }
    private function etabId(): int { try { return \API\Core\EstablishmentContext::id(); } catch (\Throwable $e) { return 1; } }

    public function getFiltered(array $filters, int $page = 1, int $perPage = 30): array
    {
        $where = ['d.etablissement_id = :etab']; $params = [':etab' => $this->etabId()];
        if (!empty($filters['classe']))    { $where[] = 'd.classe = :classe';              $params[':classe']      = $filters['classe']; }
        if (!empty($filters['matiere']))   { $where[] = 'd.nom_matiere = :matiere';        $params[':matiere']     = $filters['matiere']; }
        if (!empty($filters['professeur'])){ $where[] = 'd.nom_professeur = :professeur';  $params[':professeur']  = $filters['professeur']; }
        if (!empty($filters['date_from'])) { $where[] = 'd.date_rendu >= :date_from';      $params[':date_from']   = $filters['date_from']; }
        if (!empty($filters['date_to']))   { $where[] = 'd.date_rendu <= :date_to';        $params[':date_to']     = $filters['date_to']; }
        $w = 'WHERE ' . implode(' AND ', $where);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM devoirs d {$w}"); $stmt->execute($params);
        $total = (int) $stmt->fetchColumn(); $pages = $total > 0 ? (int) ceil($total / $perPage) : 1; $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare("SELECT d.* FROM devoirs d {$w} ORDER BY d.date_rendu DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT); $stmt->bindValue(':offset', $offset, PDO::PARAM_INT); $stmt->execute();
        return ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'pages' => $pages];
    }

    public function getById(int $id): ?array { $stmt = $this->pdo->prepare('SELECT * FROM devoirs WHERE id = ?'); $stmt->execute([$id]); $row = $stmt->fetch(PDO::FETCH_ASSOC); return $row !== false ? $row : null; }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO devoirs (titre, description, classe, nom_matiere, nom_professeur, date_ajout, date_rendu) VALUES (:titre, :description, :classe, :nom_matiere, :nom_professeur, :date_ajout, :date_rendu)");
        $stmt->execute([':titre' => $data['titre'], ':description' => $data['description'] ?? null, ':classe' => $data['classe'], ':nom_matiere' => $data['nom_matiere'], ':nom_professeur' => $data['nom_professeur'], ':date_ajout' => $data['date_ajout'] ?? date('Y-m-d'), ':date_rendu' => $data['date_rendu']]);
        $id = (int) $this->pdo->lastInsertId();
        app('hooks')?->dispatch(new \Modules\Devoirs\Events\DevoirCreated($id, $data));
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        $allowed = ['titre', 'description', 'classe', 'nom_matiere', 'nom_professeur', 'date_ajout', 'date_rendu'];
        $sets = []; $params = [':id' => $id];
        foreach ($allowed as $f) { if (array_key_exists($f, $data)) { $sets[] = "{$f} = :{$f}"; $params[":{$f}"] = $data[$f]; } }
        if (!$sets) return false;
        $stmt = $this->pdo->prepare("UPDATE devoirs SET " . implode(', ', $sets) . " WHERE id = :id"); $stmt->execute($params);
        $updated = $stmt->rowCount() > 0;
        if ($updated) app('hooks')?->dispatch(new \Modules\Devoirs\Events\DevoirUpdated($id, $data));
        return $updated;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM devoirs WHERE id = ?'); $stmt->execute([$id]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) app('hooks')?->dispatch(new \Modules\Devoirs\Events\DevoirDeleted($id));
        return $deleted;
    }

    public function getUpcomingCount(): int { $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM devoirs WHERE date_rendu >= CURDATE() AND etablissement_id = ?'); $stmt->execute([$this->etabId()]); return (int) $stmt->fetchColumn(); }
    public function getOverdueCount(): int { $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM devoirs WHERE date_rendu < CURDATE() AND etablissement_id = ?'); $stmt->execute([$this->etabId()]); return (int) $stmt->fetchColumn(); }
}
