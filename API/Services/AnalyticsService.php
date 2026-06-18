<?php
declare(strict_types=1);

namespace API\Services;

use PDO;

/**
 * AnalyticsService — Agrégation de données statistiques pour le tableau de bord admin.
 *
 * Fournit les KPIs globaux, par classe, par matière, par période.
 * Toutes les requêtes utilisent des agrégations SQL optimisées et sont scopées
 * par établissement courant (EstablishmentContext).
 *
 * Schéma de référence (pronote.sql + modules) :
 *   - absences : id_eleve, date_debut, date_fin, justifie, etablissement_id
 *   - notes    : id_eleve, id_matiere, id_professeur, note, note_sur, trimestre, etablissement_id
 *   - eleves   : classe (varchar) — lien vers classes.nom (PAS de classe_id)
 *   - notes.trimestre (int 1..3) — il n'existe PAS de notes.periode_id
 */
class AnalyticsService
{
    private PDO $pdo;

    /** Tables interrogeables par countTable() — whitelist anti-injection (CODE-01). */
    private const COUNTABLE_TABLES = [
        'eleves', 'professeurs', 'parents', 'vie_scolaire', 'administrateurs',
        'classes', 'matieres', 'absences', 'retards', 'justificatifs', 'notes',
        'evenements', 'annonces', 'bulletins', 'devoirs', 'incidents', 'messages',
    ];

    /** Tables portant la colonne etablissement_id (scope multi-établissement). */
    private const SCOPED_TABLES = [
        'eleves', 'professeurs', 'parents', 'vie_scolaire', 'administrateurs',
        'classes', 'matieres', 'absences', 'retards', 'justificatifs', 'notes',
        'evenements', 'annonces', 'bulletins', 'devoirs', 'incidents',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ─── KPIs globaux ───────────────────────────────────────────────

    /**
     * Statistiques globales de l'établissement.
     */
    public function getGlobalStats(): array
    {
        return [
            'students' => $this->countTable('eleves', 'actif = 1'),
            'teachers' => $this->countTable('professeurs', 'actif = 1'),
            'parents' => $this->countTable('parents', 'actif = 1'),
            'classes' => $this->countTable('classes'),
            'subjects' => $this->countTable('matieres'),
            'absences_today' => $this->countTable('absences', "DATE(date_debut) = CURDATE()"),
            'absences_month' => $this->countTable('absences', "date_debut >= DATE_FORMAT(NOW(), '%Y-%m-01')"),
            'incidents_open' => $this->countTable('incidents', "statut IN ('signale','en_traitement')"),
            'messages_today' => $this->countTable('messages', "created_at >= CURDATE()"),
        ];
    }

    /**
     * Taux d'absence global et par classe.
     *
     * @param int|null $periodeId Identifiant de période (table periodes) pour borner les dates.
     */
    public function getAbsenceStats(?int $periodeId = null): array
    {
        $etab = $this->etabId();
        $totalStudents = $this->countTable('eleves', 'actif = 1');

        if ($periodeId) {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM absences
                 WHERE date_debut BETWEEN (SELECT date_debut FROM periodes WHERE id = :pid)
                                      AND (SELECT date_fin   FROM periodes WHERE id = :pid)
                   AND etablissement_id = :etab"
            );
            $stmt->execute([':pid' => $periodeId, ':etab' => $etab]);
            $totalAbsences = (int) $stmt->fetchColumn();
        } else {
            $totalAbsences = $this->countTable('absences', "date_debut >= DATE_FORMAT(NOW(), '%Y-%m-01')");
        }

        // Par classe — eleves.classe (varchar) se lie à classes.nom.
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.nom AS classe, COUNT(a.id) AS total_absences,
                    COUNT(DISTINCT a.id_eleve) AS eleves_absents
             FROM classes c
             LEFT JOIN eleves e ON e.classe = c.nom AND e.actif = 1 AND e.etablissement_id = c.etablissement_id
             LEFT JOIN absences a ON a.id_eleve = e.id AND a.date_debut >= DATE_FORMAT(NOW(), '%Y-%m-01')
             WHERE c.etablissement_id = :etab
             GROUP BY c.id, c.nom
             ORDER BY total_absences DESC"
        );
        $stmt->execute([':etab' => $etab]);

        return [
            'total_students' => $totalStudents,
            'total_absences' => $totalAbsences,
            'rate' => $totalStudents > 0 ? round($totalAbsences / max($totalStudents, 1) * 100, 1) : 0,
            'by_class' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /**
     * Moyennes par matière.
     *
     * @param int|null $trimestre Numéro de trimestre (1..3) pour filtrer ; null = tous.
     */
    public function getGradeStats(?int $trimestre = null): array
    {
        $params = [':etab' => $this->etabId()];
        $trimestreFilter = '';
        if ($trimestre) {
            $trimestreFilter = 'AND n.trimestre = :trim';
            $params[':trim'] = $trimestre;
        }

        $stmt = $this->pdo->prepare(
            "SELECT m.id, m.nom AS matiere,
                    ROUND(AVG(n.note / n.note_sur * 20), 2) AS moyenne,
                    MIN(n.note / n.note_sur * 20) AS note_min,
                    MAX(n.note / n.note_sur * 20) AS note_max,
                    COUNT(n.id) AS total_notes,
                    COUNT(DISTINCT n.id_eleve) AS eleves_notes
             FROM matieres m
             LEFT JOIN notes n ON n.id_matiere = m.id AND n.etablissement_id = :etab {$trimestreFilter}
             WHERE m.etablissement_id = :etab
             GROUP BY m.id, m.nom
             HAVING total_notes > 0
             ORDER BY moyenne DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Moyennes par classe.
     *
     * @param int|null $trimestre Numéro de trimestre (1..3) pour filtrer ; null = tous.
     */
    public function getGradeStatsByClass(?int $trimestre = null): array
    {
        $params = [':etab' => $this->etabId()];
        $trimestreFilter = '';
        if ($trimestre) {
            $trimestreFilter = 'AND n.trimestre = :trim';
            $params[':trim'] = $trimestre;
        }

        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.nom AS classe,
                    ROUND(AVG(n.note / n.note_sur * 20), 2) AS moyenne,
                    COUNT(DISTINCT n.id_eleve) AS eleves,
                    COUNT(n.id) AS total_notes
             FROM classes c
             JOIN eleves e ON e.classe = c.nom AND e.actif = 1 AND e.etablissement_id = c.etablissement_id
             JOIN notes n ON n.id_eleve = e.id AND n.etablissement_id = c.etablissement_id {$trimestreFilter}
             WHERE c.etablissement_id = :etab
             GROUP BY c.id, c.nom
             ORDER BY moyenne DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Évolution des moyennes par trimestre.
     *
     * Note : la table notes n'a pas de FK vers periodes ; l'évolution est
     * agrégée sur la colonne notes.trimestre (1..3).
     */
    public function getGradeEvolution(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT n.trimestre AS periode_num,
                    CONCAT('Trimestre ', n.trimestre) AS periode,
                    ROUND(AVG(n.note / n.note_sur * 20), 2) AS moyenne_generale,
                    COUNT(n.id) AS total_notes
             FROM notes n
             WHERE n.etablissement_id = :etab
             GROUP BY n.trimestre
             ORDER BY n.trimestre"
        );
        $stmt->execute([':etab' => $this->etabId()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Statistiques de connexion (taux d'utilisation).
     */
    public function getUsageStats(int $days = 30): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT DATE(created_at) AS jour, COUNT(*) AS connexions
                 FROM audit_log
                 WHERE action = 'auth.login' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY DATE(created_at)
                 ORDER BY jour"
            );
            $stmt->execute([$days]);
            $daily = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Par rôle
            $stmt = $this->pdo->prepare(
                "SELECT user_type AS role, COUNT(*) AS total
                 FROM audit_log
                 WHERE action = 'auth.login' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY user_type
                 ORDER BY total DESC"
            );
            $stmt->execute([$days]);
            $byRole = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return ['daily' => $daily, 'by_role' => $byRole];
        } catch (\Throwable $e) {
            error_log('AnalyticsService::getUsageStats: ' . $e->getMessage());
            return ['daily' => [], 'by_role' => []];
        }
    }

    /**
     * Statistiques enseignant : résultats par matière.
     */
    public function getTeacherStats(int $professeurId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT m.nom AS matiere,
                    ROUND(AVG(n.note / n.note_sur * 20), 2) AS moyenne,
                    COUNT(n.id) AS total_notes,
                    COUNT(DISTINCT n.id_eleve) AS eleves
             FROM notes n
             JOIN matieres m ON m.id = n.id_matiere
             WHERE n.id_professeur = ? AND n.etablissement_id = ?
             GROUP BY m.id, m.nom"
        );
        $stmt->execute([$professeurId, $this->etabId()]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── Helper ─────────────────────────────────────────────────────

    /**
     * Compte les lignes d'une table avec un prédicat littéral, scopé par établissement.
     *
     * SÉCURITÉ : $table est validée contre une whitelist (COUNTABLE_TABLES) et $where
     * est un littéral défini par l'appelant (jamais une entrée utilisateur). Le scope
     * etablissement_id est ajouté via paramètre lié pour les tables concernées.
     */
    private function countTable(string $table, string $where = '1=1'): int
    {
        if (!in_array($table, self::COUNTABLE_TABLES, true)) {
            error_log("AnalyticsService::countTable: table non autorisée « {$table} »");
            return 0;
        }

        $params = [];
        if (in_array($table, self::SCOPED_TABLES, true)) {
            $where .= ' AND etablissement_id = ?';
            $params[] = $this->etabId();
        }
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$where}");
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log("AnalyticsService::countTable({$table}): " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Établissement courant (scope multi-établissement).
     *
     * Ne RETOMBE PLUS silencieusement sur l'établissement 1 : si le contexte ne peut
     * être résolu (multi-établissement sans scope de session), EstablishmentContext::id()
     * lève une RuntimeException — fail-closed pour éviter toute fuite inter-établissement.
     */
    private function etabId(): int
    {
        return \API\Core\EstablishmentContext::id();
    }
}
