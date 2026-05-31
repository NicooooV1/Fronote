<?php
declare(strict_types=1);

namespace Modules\Absences\Services;

use PDO;

class AbsenceService
{
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    private function etabId(): int
    {
        try { return \API\Core\EstablishmentContext::id(); } catch (\Throwable $e) { return 1; }
    }

    public function getAbsences(array $filters, int $page = 1, int $perPage = 30): array
    {
        $where = ['a.etablissement_id = :etab']; $params = [':etab' => $this->etabId()];
        if (!empty($filters['classe']))           { $where[] = 'e.classe = :classe';         $params[':classe']    = $filters['classe']; }
        if (!empty($filters['eleve_id']))         { $where[] = 'a.id_eleve = :eleve_id';     $params[':eleve_id']  = (int) $filters['eleve_id']; }
        if (isset($filters['justifie']) && $filters['justifie'] !== '') { $where[] = 'a.justifie = :justifie'; $params[':justifie'] = (int) $filters['justifie']; }
        if (!empty($filters['date_from']))        { $where[] = 'a.date_debut >= :date_from'; $params[':date_from'] = $filters['date_from']; }
        if (!empty($filters['date_to']))          { $where[] = 'a.date_fin <= :date_to';     $params[':date_to']   = $filters['date_to']; }
        $w = 'WHERE ' . implode(' AND ', $where);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM absences a JOIN eleves e ON a.id_eleve = e.id {$w}");
        $stmt->execute($params); $total = (int) $stmt->fetchColumn(); $pages = (int) ceil($total / $perPage); $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare("SELECT a.*, e.nom AS eleve_nom, e.prenom AS eleve_prenom, e.classe FROM absences a JOIN eleves e ON a.id_eleve = e.id {$w} ORDER BY a.date_debut DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT); $stmt->bindValue(':offset', $offset, PDO::PARAM_INT); $stmt->execute();
        return ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'pages' => $pages];
    }

    public function getRetards(array $filters, int $page = 1, int $perPage = 30): array
    {
        $where = ['r.etablissement_id = :etab']; $params = [':etab' => $this->etabId()];
        if (!empty($filters['classe']))    { $where[] = 'e.classe = :classe';          $params[':classe']    = $filters['classe']; }
        if (!empty($filters['eleve_id'])) { $where[] = 'r.id_eleve = :eleve_id';      $params[':eleve_id']  = (int) $filters['eleve_id']; }
        if (!empty($filters['date_from'])){ $where[] = 'r.date_retard >= :date_from'; $params[':date_from'] = $filters['date_from']; }
        if (!empty($filters['date_to']))  { $where[] = 'r.date_retard <= :date_to';   $params[':date_to']   = $filters['date_to']; }
        $w = 'WHERE ' . implode(' AND ', $where);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM retards r JOIN eleves e ON r.id_eleve = e.id {$w}"); $stmt->execute($params);
        $total = (int) $stmt->fetchColumn(); $pages = (int) ceil($total / $perPage); $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare("SELECT r.*, e.nom AS eleve_nom, e.prenom AS eleve_prenom, e.classe FROM retards r JOIN eleves e ON r.id_eleve = e.id {$w} ORDER BY r.date_retard DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT); $stmt->bindValue(':offset', $offset, PDO::PARAM_INT); $stmt->execute();
        return ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'pages' => $pages];
    }

    public function createAbsence(array $data): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO absences (id_eleve, date_debut, date_fin, type_absence, motif, justifie, commentaire, signale_par, date_signalement) VALUES (:id_eleve, :date_debut, :date_fin, :type_absence, :motif, :justifie, :commentaire, :signale_par, NOW())");
        $stmt->execute([':id_eleve' => $data['id_eleve'], ':date_debut' => $data['date_debut'], ':date_fin' => $data['date_fin'], ':type_absence' => $data['type_absence'], ':motif' => $data['motif'] ?? null, ':justifie' => $data['justifie'] ?? 0, ':commentaire' => $data['commentaire'] ?? null, ':signale_par' => $data['signale_par']]);
        $id = (int) $this->pdo->lastInsertId();
        app('hooks')?->dispatch(new \Modules\Absences\Events\AbsenceCreated($id, $data));
        return $id;
    }

    public function createRetard(array $data): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO retards (id_eleve, date_retard, duree_minutes, motif, justifie, commentaire, signale_par, date_signalement) VALUES (:id_eleve, :date_retard, :duree_minutes, :motif, :justifie, :commentaire, :signale_par, NOW())");
        $stmt->execute([':id_eleve' => $data['id_eleve'], ':date_retard' => $data['date_retard'], ':duree_minutes' => $data['duree_minutes'], ':motif' => $data['motif'] ?? null, ':justifie' => $data['justifie'] ?? 0, ':commentaire' => $data['commentaire'] ?? null, ':signale_par' => $data['signale_par']]);
        $id = (int) $this->pdo->lastInsertId();
        app('hooks')?->dispatch(new \Modules\Absences\Events\RetardCreated($id, $data));
        return $id;
    }

    public function toggleJustificationAbsence(int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE absences SET justifie = NOT justifie, date_modification = NOW() WHERE id = ?');
        $stmt->execute([$id]); return $stmt->rowCount() > 0;
    }

    public function toggleJustificationRetard(int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE retards SET justifie = NOT justifie, date_modification = NOW() WHERE id = ?');
        $stmt->execute([$id]); return $stmt->rowCount() > 0;
    }

    public function deleteAbsence(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM absences WHERE id = ?'); $stmt->execute([$id]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) app('hooks')?->dispatch(new \Modules\Absences\Events\AbsenceDeleted($id));
        return $deleted;
    }

    public function deleteRetard(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM retards WHERE id = ?'); $stmt->execute([$id]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) app('hooks')?->dispatch(new \Modules\Absences\Events\RetardDeleted($id));
        return $deleted;
    }

    public function getStatsToday(): array
    {
        $etab = $this->etabId();
        $s1 = $this->pdo->prepare('SELECT COUNT(*) FROM absences WHERE CURDATE() BETWEEN DATE(date_debut) AND DATE(date_fin) AND etablissement_id = ?'); $s1->execute([$etab]);
        $s2 = $this->pdo->prepare('SELECT COUNT(*) FROM retards WHERE DATE(date_retard) = CURDATE() AND etablissement_id = ?'); $s2->execute([$etab]);
        return ['absences' => (int) $s1->fetchColumn(), 'retards' => (int) $s2->fetchColumn()];
    }

    public function getUnjustifiedCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM absences WHERE justifie = 0 AND etablissement_id = ?');
        $stmt->execute([$this->etabId()]); return (int) $stmt->fetchColumn();
    }

    public function approveJustificatif(int $id, int $adminId, string $comment = ''): bool
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("UPDATE justificatifs SET traite = 1, approuve = 1, commentaire_admin = :comment, date_traitement = NOW(), traite_par = :admin_id WHERE id = :id");
            $stmt->execute([':comment' => $comment, ':admin_id' => $adminId, ':id' => $id]);
            $updated = $stmt->rowCount() > 0;
            $stmt = $this->pdo->prepare('SELECT id_absence FROM justificatifs WHERE id = ?'); $stmt->execute([$id]);
            $idAbsence = $stmt->fetchColumn();
            if ($idAbsence) { $this->pdo->prepare('UPDATE absences SET justifie = 1, date_modification = NOW() WHERE id = ?')->execute([$idAbsence]); }
            $this->pdo->commit();
            if ($updated) app('hooks')?->dispatch(new \Modules\Absences\Events\JustificatifApproved($id, $adminId, $comment));
            return $updated;
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    public function rejectJustificatif(int $id, int $adminId, string $comment = ''): bool
    {
        $stmt = $this->pdo->prepare("UPDATE justificatifs SET traite = 1, approuve = 0, commentaire_admin = :comment, date_traitement = NOW(), traite_par = :admin_id WHERE id = :id");
        $stmt->execute([':comment' => $comment, ':admin_id' => $adminId, ':id' => $id]);
        $updated = $stmt->rowCount() > 0;
        if ($updated) app('hooks')?->dispatch(new \Modules\Absences\Events\JustificatifRejected($id, $adminId, $comment));
        return $updated;
    }

    public function getPendingJustificatifs(string $status = 'pending', int $page = 1, int $perPage = 30): array
    {
        $etab = $this->etabId();
        $wc = match($status) { 'approved' => 'WHERE j.traite = 1 AND j.approuve = 1', 'rejected' => 'WHERE j.traite = 1 AND j.approuve = 0', default => 'WHERE j.traite = 0' };
        $wc .= ' AND j.etablissement_id = :etab';
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM justificatifs j JOIN eleves e ON j.id_eleve = e.id {$wc}"); $stmt->execute([':etab' => $etab]);
        $total = (int) $stmt->fetchColumn(); $pages = (int) ceil($total / $perPage); $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare("SELECT j.*, e.nom AS eleve_nom, e.prenom AS eleve_prenom, e.classe FROM justificatifs j JOIN eleves e ON j.id_eleve = e.id {$wc} ORDER BY j.date_soumission DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':etab', $etab, PDO::PARAM_INT); $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT); $stmt->bindValue(':offset', $offset, PDO::PARAM_INT); $stmt->execute();
        return ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'pages' => $pages];
    }
}
