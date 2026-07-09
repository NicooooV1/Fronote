<?php
declare(strict_types=1);

namespace Modules\Notes\Services;

use PDO;

class NoteService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    private function etabId(): int
    {
        return \API\Core\EstablishmentContext::id();
    }

    public function getFiltered(array $filters, int $page = 1, int $perPage = 50): array
    {
        $where  = ['n.etablissement_id = :etab'];
        $params = [':etab' => $this->etabId()];

        if (!empty($filters['classe']))       { $where[] = 'e.classe = :classe';                 $params[':classe']       = $filters['classe']; }
        if (!empty($filters['matiere_id']))   { $where[] = 'n.id_matiere = :matiere_id';          $params[':matiere_id']   = (int) $filters['matiere_id']; }
        if (!empty($filters['professeur_id'])){ $where[] = 'n.id_professeur = :professeur_id';    $params[':professeur_id']= (int) $filters['professeur_id']; }
        if (!empty($filters['trimestre']))    { $where[] = 'n.trimestre = :trimestre';             $params[':trimestre']    = (int) $filters['trimestre']; }
        if (!empty($filters['date_from']))    { $where[] = 'n.date_note >= :date_from';            $params[':date_from']    = $filters['date_from']; }
        if (!empty($filters['date_to']))      { $where[] = 'n.date_note <= :date_to';              $params[':date_to']      = $filters['date_to']; }
        if (!empty($filters['eleve_id']))     { $where[] = 'n.id_eleve = :eleve_id';              $params[':eleve_id']     = (int) $filters['eleve_id']; }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM notes n JOIN eleves e ON n.id_eleve = e.id JOIN matieres m ON n.id_matiere = m.id JOIN professeurs p ON n.id_professeur = p.id {$whereClause}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        $pages  = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare("SELECT n.*, e.nom AS eleve_nom, e.prenom AS eleve_prenom, m.nom AS matiere_nom, CONCAT(p.nom, ' ', p.prenom) AS professeur_nom FROM notes n JOIN eleves e ON n.id_eleve = e.id JOIN matieres m ON n.id_matiere = m.id JOIN professeurs p ON n.id_professeur = p.id {$whereClause} ORDER BY n.date_note DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) { $stmt->bindValue($key, $value); }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'pages' => $pages];
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT n.*, e.nom AS eleve_nom, e.prenom AS eleve_prenom, m.nom AS matiere_nom, CONCAT(p.nom, ' ', p.prenom) AS professeur_nom FROM notes n JOIN eleves e ON n.id_eleve = e.id JOIN matieres m ON n.id_matiere = m.id JOIN professeurs p ON n.id_professeur = p.id WHERE n.id = ? AND n.etablissement_id = ?");
        $stmt->execute([$id, $this->etabId()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function create(array $data): int
    {
        $etabId = null;
        try { $etabId = \API\Core\EstablishmentContext::id(); } catch (\Throwable $e) { /* défaut SQL si contexte ambigu */ }
        $stmt = $this->pdo->prepare("INSERT INTO notes (id_eleve, id_matiere, id_professeur, note, date_note, trimestre, note_sur, coefficient, type_evaluation, commentaire" . ($etabId !== null ? ", etablissement_id" : "") . ") VALUES (:id_eleve, :id_matiere, :id_professeur, :note, :date_note, :trimestre, :note_sur, :coefficient, :type_evaluation, :commentaire" . ($etabId !== null ? ", :etab" : "") . ")");
        $params = [':id_eleve' => (int) $data['id_eleve'], ':id_matiere' => (int) $data['id_matiere'], ':id_professeur' => (int) $data['id_professeur'], ':note' => $data['note'], ':date_note' => $data['date_note'], ':trimestre' => (int) $data['trimestre'], ':note_sur' => $data['note_sur'] ?? 20, ':coefficient' => $data['coefficient'] ?? 1, ':type_evaluation' => $data['type_evaluation'] ?? null, ':commentaire' => $data['commentaire'] ?? null];
        if ($etabId !== null) { $params[':etab'] = $etabId; }
        $stmt->execute($params);
        $id = (int) $this->pdo->lastInsertId();
        app('hooks')?->dispatch(new \Modules\Notes\Events\NoteCreated($id, $data));
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        $allowed = ['id_eleve', 'id_matiere', 'id_professeur', 'note', 'note_sur', 'coefficient', 'type_evaluation', 'date_note', 'commentaire', 'trimestre'];
        $sets = []; $params = [':id' => $id];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) { $sets[] = "{$field} = :{$field}"; $params[":{$field}"] = $data[$field]; }
        }
        if (!$sets) return false;
        $params[':etab'] = $this->etabId();
        $stmt = $this->pdo->prepare("UPDATE notes SET " . implode(', ', $sets) . ", date_modification = NOW() WHERE id = :id AND etablissement_id = :etab");
        $stmt->execute($params);
        $updated = $stmt->rowCount() > 0;
        if ($updated) app('hooks')?->dispatch(new \Modules\Notes\Events\NoteUpdated($id, $data));
        return $updated;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM notes WHERE id = ? AND etablissement_id = ?');
        $stmt->execute([$id, $this->etabId()]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) app('hooks')?->dispatch(new \Modules\Notes\Events\NoteDeleted($id));
        return $deleted;
    }

    public function getStatsByClasse(string $classe, int $trimestre): array
    {
        $stmt = $this->pdo->prepare("SELECT m.nom AS matiere, AVG(n.note * 20 / n.note_sur) AS moyenne, COUNT(*) AS nb_notes FROM notes n JOIN eleves e ON n.id_eleve = e.id JOIN matieres m ON n.id_matiere = m.id WHERE e.classe = ? AND n.trimestre = ? AND n.etablissement_id = ? GROUP BY m.id, m.nom");
        $stmt->execute([$classe, $trimestre, $this->etabId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStatsByEleve(int $eleveId): array
    {
        $stmt = $this->pdo->prepare("SELECT n.trimestre, m.nom AS matiere, AVG(n.note * 20 / n.note_sur) AS moyenne, COUNT(*) AS nb_notes FROM notes n JOIN matieres m ON n.id_matiere = m.id WHERE n.id_eleve = ? AND n.etablissement_id = ? GROUP BY n.trimestre, m.id, m.nom ORDER BY n.trimestre, m.nom");
        $stmt->execute([$eleveId, $this->etabId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMoyenneGenerale(int $trimestre): ?float
    {
        $stmt = $this->pdo->prepare("SELECT AVG(note * 20 / note_sur) AS moyenne FROM notes WHERE trimestre = ? AND etablissement_id = ?");
        $stmt->execute([$trimestre, $this->etabId()]);
        $result = $stmt->fetchColumn();
        return ($result !== null && $result !== false) ? (float) $result : null;
    }

    public function getElevesByClasse(string $classe): array
    {
        $stmt = $this->pdo->prepare("SELECT DISTINCT e.id, e.nom, e.prenom FROM eleves e WHERE e.classe = ? AND e.etablissement_id = ? ORDER BY e.nom, e.prenom");
        $stmt->execute([$classe, $this->etabId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
