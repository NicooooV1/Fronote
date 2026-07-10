<?php
declare(strict_types=1);
/**
 * GarderieService — Service métier pour le module Garderie (M20).
 */
class GarderieService
{
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** Établissement courant ou null. */
    private function etabId(): ?int
    {
        try { return \API\Core\EstablishmentContext::id(); }
        catch (\Throwable $e) { return null; }
    }

    /* ==================== CRÉNEAUX ==================== */

    public function getCreneaux(bool $actifsOnly = true): array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $sql = "SELECT * FROM garderie_creneaux WHERE etablissement_id = ?"
             . ($actifsOnly ? " AND actif = 1" : "")
             . " ORDER BY type, heure_debut";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$etabId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCreneau(int $id): ?array
    {
        // Cloisonnement établissement (anti-IDOR cross-tenant).
        $stmt = $this->pdo->prepare("SELECT * FROM garderie_creneaux WHERE id = ? AND etablissement_id = ?");
        $stmt->execute([$id, \API\Core\EstablishmentContext::id()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function creerCreneau(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO garderie_creneaux (etablissement_id, nom, type, heure_debut, heure_fin, places_max, tarif) VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([\API\Core\EstablishmentContext::id(), $data['nom'], $data['type'], $data['heure_debut'], $data['heure_fin'],
            $data['places_max'] ?? null, $data['tarif'] ?? null]);
        return (int) $this->pdo->lastInsertId();
    }

    /* ==================== INSCRIPTIONS ==================== */

    public function inscrire(int $creneauId, int $eleveId, string $jour, string $dateDebut, ?string $par = null): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO garderie_inscriptions (etablissement_id, creneau_id, eleve_id, jour, date_debut, inscrit_par)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE statut = 'actif', date_debut = VALUES(date_debut)"
        );
        $stmt->execute([\API\Core\EstablishmentContext::id(), $creneauId, $eleveId, $jour, $dateDebut, $par]);
        return (int) $this->pdo->lastInsertId();
    }

    public function desinscrire(int $inscriptionId): bool
    {
        // Cloisonnement multi-tenant : n'annuler qu'une inscription de l'établissement courant.
        $stmt = $this->pdo->prepare("UPDATE garderie_inscriptions SET statut = 'annule' WHERE id = ? AND etablissement_id = ?");
        $stmt->execute([$inscriptionId, $this->etabId()]);
        return $stmt->rowCount() > 0;
    }

    public function getInscriptions(?int $creneauId = null): array
    {
        // Cloisonnement multi-tenant : uniquement les inscriptions de l'établissement courant.
        $sql = "SELECT gi.*, e.nom, e.prenom, e.classe, gc.nom AS creneau_nom, gc.type AS creneau_type
                FROM garderie_inscriptions gi
                JOIN eleves e ON gi.eleve_id = e.id
                JOIN garderie_creneaux gc ON gi.creneau_id = gc.id
                WHERE gi.statut = 'actif' AND gi.etablissement_id = ?";
        $params = [$this->etabId()];
        if ($creneauId) { $sql .= " AND gi.creneau_id = ?"; $params[] = $creneauId; }
        $sql .= " ORDER BY gc.type, gi.jour, e.nom";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getInscriptionsEleve(int $eleveId): array
    {
        // Cloisonnement multi-tenant : défense en profondeur contre un eleve_id falsifié.
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $stmt = $this->pdo->prepare(
            "SELECT gi.*, gc.nom AS creneau_nom, gc.type AS creneau_type, gc.heure_debut, gc.heure_fin
             FROM garderie_inscriptions gi
             JOIN garderie_creneaux gc ON gi.creneau_id = gc.id
             WHERE gi.eleve_id = ? AND gi.etablissement_id = ? AND gi.statut = 'actif'
             ORDER BY FIELD(gi.jour, 'lundi','mardi','mercredi','jeudi','vendredi'), gc.heure_debut"
        );
        $stmt->execute([$eleveId, $etabId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ==================== PRÉSENCES ==================== */

    /**
     * Cloisonnement multi-tenant : vrai seulement si l'inscription appartient à
     * l'établissement courant. Utilisé pour garder les écritures de pointage
     * (l'inscription_id provient du client → jamais de confiance implicite).
     */
    private function inscriptionInEtab(int $inscriptionId): bool
    {
        $etabId = $this->etabId();
        if ($etabId === null) return false;
        $stmt = $this->pdo->prepare("SELECT 1 FROM garderie_inscriptions WHERE id = ? AND etablissement_id = ? LIMIT 1");
        $stmt->execute([$inscriptionId, $etabId]);
        return (bool) $stmt->fetchColumn();
    }

    public function pointerPresence(int $inscriptionId, string $date, bool $present = true, ?string $remarques = null): bool
    {
        // garderie_presences n'a pas de colonne etablissement_id : on garde par l'inscription.
        if (!$this->inscriptionInEtab($inscriptionId)) return false;
        $stmt = $this->pdo->prepare(
            "INSERT INTO garderie_presences (inscription_id, date_presence, present, remarques, heure_arrivee)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE present = VALUES(present), remarques = VALUES(remarques)"
        );
        return $stmt->execute([$inscriptionId, $date, $present ? 1 : 0, $remarques]);
    }

    public function getPresencesJour(string $date, ?int $creneauId = null): array
    {
        // Cloisonnement multi-tenant : ne jamais exposer les présences (PII de mineurs)
        // d'un autre établissement.
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $sql = "SELECT gp.*, gi.eleve_id, gi.jour, gi.creneau_id, e.nom, e.prenom, e.classe,
                       gc.nom AS creneau_nom, gc.type AS creneau_type
                FROM garderie_inscriptions gi
                JOIN eleves e ON gi.eleve_id = e.id
                JOIN garderie_creneaux gc ON gi.creneau_id = gc.id
                LEFT JOIN garderie_presences gp ON gi.id = gp.inscription_id AND gp.date_presence = ?
                WHERE gi.statut = 'actif' AND gi.etablissement_id = ? AND gi.jour = LOWER(DATE_FORMAT(?, '%W'))";
        $params = [$date, $etabId, $date];
        if ($creneauId) { $sql .= " AND gi.creneau_id = ?"; $params[] = $creneauId; }
        $sql .= " ORDER BY gc.type, e.nom";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ==================== POINTAGE ARRIVÉE/DÉPART ==================== */

    /**
     * Record arrival time for a student.
     */
    public function pointerArrivee(int $inscriptionId, string $date): void
    {
        // Cloisonnement multi-tenant : ne pointer qu'une inscription de l'établissement courant.
        if (!$this->inscriptionInEtab($inscriptionId)) return;
        $this->pdo->prepare("
            UPDATE garderie_inscriptions SET pointage_arrivee = NOW() WHERE id = ? AND etablissement_id = ?
        ")->execute([$inscriptionId, $this->etabId()]);

        $this->pointerPresence($inscriptionId, $date, true);
    }

    /**
     * Record departure time for a student.
     */
    public function pointerDepart(int $inscriptionId): void
    {
        // Cloisonnement multi-tenant : ne pointer qu'une inscription de l'établissement courant.
        if (!$this->inscriptionInEtab($inscriptionId)) return;
        $this->pdo->prepare("
            UPDATE garderie_inscriptions SET pointage_depart = NOW() WHERE id = ? AND etablissement_id = ?
        ")->execute([$inscriptionId, $this->etabId()]);
    }

    /**
     * Calculate billable hours for a student in a given month.
     */
    public function calculerHeuresMois(int $eleveId, string $mois): float
    {
        $etabId = $this->etabId();
        if ($etabId === null) return 0.0;
        $stmt = $this->pdo->prepare("
            SELECT SUM(TIMESTAMPDIFF(MINUTE, gi.pointage_arrivee, COALESCE(gi.pointage_depart, gi.pointage_arrivee))) / 60 AS heures
            FROM garderie_inscriptions gi
            WHERE gi.eleve_id = ? AND gi.etablissement_id = ? AND DATE_FORMAT(gi.pointage_arrivee, '%Y-%m') = ?
              AND gi.pointage_arrivee IS NOT NULL
        ");
        $stmt->execute([$eleveId, $etabId, $mois]);
        return round((float)$stmt->fetchColumn(), 2);
    }

    /* ==================== STATS ==================== */

    public function getStats(): array
    {
        // Cloisonnement multi-tenant : compteurs bornés à l'établissement courant.
        $etabId = $this->etabId();
        if ($etabId === null) return ['total_creneaux' => 0, 'total_inscrits' => 0, 'nb_eleves' => 0];
        $q = function (string $sql) use ($etabId): int {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$etabId]);
            return (int) $stmt->fetchColumn();
        };
        return [
            'total_creneaux' => $q("SELECT COUNT(*) FROM garderie_creneaux WHERE actif = 1 AND etablissement_id = ?"),
            'total_inscrits' => $q("SELECT COUNT(*) FROM garderie_inscriptions WHERE statut = 'actif' AND etablissement_id = ?"),
            'nb_eleves'      => $q("SELECT COUNT(DISTINCT eleve_id) FROM garderie_inscriptions WHERE statut = 'actif' AND etablissement_id = ?"),
        ];
    }

    /* ==================== EXPORT ==================== */

    public function getInscriptionsForExport(?int $creneauId = null): array
    {
        $inscriptions = $this->getInscriptions($creneauId);
        return array_map(fn($i) => [
            $i['nom'] . ' ' . $i['prenom'],
            $i['classe'] ?? '-',
            $i['creneau_nom'],
            ucfirst($i['creneau_type']),
            ucfirst($i['jour']),
            $i['date_debut'] ?? '-',
            ucfirst($i['statut']),
        ], $inscriptions);
    }

    public function getPresencesForExport(string $date, ?int $creneauId = null): array
    {
        $presences = $this->getPresencesJour($date, $creneauId);
        return array_map(fn($p) => [
            $p['nom'] . ' ' . $p['prenom'],
            $p['classe'] ?? '-',
            $p['creneau_nom'],
            $date,
            isset($p['present']) ? ($p['present'] ? 'Présent' : 'Absent') : 'Non pointé',
            $p['heure_arrivee'] ?? '-',
            $p['remarques'] ?? '',
        ], $presences);
    }

    // ─── PRÉSENTS EN TEMPS RÉEL ───

    public function getPresentsActuellement(?int $creneauId = null): array
    {
        // Cloisonnement multi-tenant : présents en direct de l'établissement courant uniquement.
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $sql = "SELECT gi.id, gi.eleve_id, CONCAT(e.prenom, ' ', e.nom) AS eleve_nom, e.classe,
                       gc.nom AS creneau_nom, gi.pointage_arrivee, gi.pointage_depart
                FROM garderie_inscriptions gi
                JOIN eleves e ON gi.eleve_id = e.id
                JOIN garderie_creneaux gc ON gi.creneau_id = gc.id
                WHERE gi.statut = 'actif'
                  AND gi.etablissement_id = :etab
                  AND DATE(gi.pointage_arrivee) = CURDATE()
                  AND gi.pointage_depart IS NULL";
        $params = [':etab' => $etabId];
        if ($creneauId) { $sql .= " AND gi.creneau_id = :c"; $params[':c'] = $creneauId; }
        $sql .= " ORDER BY gi.pointage_arrivee DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getNbPresentsActuellement(): int
    {
        return count($this->getPresentsActuellement());
    }

    // ─── PLANNING ACTIVITÉS ───

    public function creerActivite(array $d): int
    {
        // Cloisonnement multi-tenant : le créneau ciblé doit appartenir à l'établissement courant.
        if ($this->getCreneau((int) $d['creneau_id']) === null) return 0;
        $stmt = $this->pdo->prepare("
            INSERT INTO garderie_activites (creneau_id, titre, description, date_activite, animateur)
            VALUES (:c, :t, :d, :da, :a)
        ");
        $stmt->execute([':c' => $d['creneau_id'], ':t' => $d['titre'], ':d' => $d['description'] ?? null,
                        ':da' => $d['date_activite'], ':a' => $d['animateur'] ?? null]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getActivites(?int $creneauId = null, ?string $dateDebut = null, ?string $dateFin = null): array
    {
        // Cloisonnement multi-tenant : les activités sont rattachées à un créneau de l'établissement courant.
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $sql = "SELECT ga.*, gc.nom AS creneau_nom FROM garderie_activites ga JOIN garderie_creneaux gc ON ga.creneau_id = gc.id AND gc.etablissement_id = :etab WHERE 1=1";
        $params = [':etab' => $etabId];
        if ($creneauId) { $sql .= " AND ga.creneau_id = :c"; $params[':c'] = $creneauId; }
        if ($dateDebut) { $sql .= " AND ga.date_activite >= :dd"; $params[':dd'] = $dateDebut; }
        if ($dateFin) { $sql .= " AND ga.date_activite <= :df"; $params[':df'] = $dateFin; }
        $sql .= " ORDER BY ga.date_activite DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ─── NOTIFICATION ARRIVÉE PARENT ───

    public function notifierArriveeParent(int $eleveId): void
    {
        $stmt = $this->pdo->prepare("SELECT pe.id_parent AS parent_id FROM parent_eleve pe WHERE pe.id_eleve = :e");
        $stmt->execute([':e' => $eleveId]);
        $parentIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $eleve = $this->pdo->prepare("SELECT prenom, nom FROM eleves WHERE id = ?");
        $eleve->execute([$eleveId]);
        $el = $eleve->fetch(\PDO::FETCH_ASSOC);
        $nom = $el ? $el['prenom'] . ' ' . $el['nom'] : 'Votre enfant';

        try {
            require_once __DIR__ . '/../../notifications/includes/NotificationService.php';
            $notif = new \NotificationService($this->pdo);
            foreach ($parentIds as $pid) {
                $notif->creer((int)$pid, 'parent', 'garderie', 'Départ garderie',
                    "{$nom} a quitté la garderie à " . date('H:i') . '.',
                    '/garderie/', 'normale');
            }
        } catch (\Exception $e) { error_log('[GarderieService.php] ' . $e->getMessage()); }
    }

    // ─── BILAN MENSUEL ───

    public function getBilanMensuel(int $eleveId, string $mois): array
    {
        $etabId = $this->etabId();
        if ($etabId === null) {
            return ['mois' => $mois, 'presences' => [], 'total_jours_present' => 0, 'total_jours_absent' => 0, 'heures_totales' => 0.0];
        }
        $stmt = $this->pdo->prepare("
            SELECT gp.date_presence, gp.present, gp.remarques, gc.nom AS creneau_nom, gc.type
            FROM garderie_presences gp
            JOIN garderie_inscriptions gi ON gp.inscription_id = gi.id
            JOIN garderie_creneaux gc ON gi.creneau_id = gc.id
            WHERE gi.eleve_id = :e AND gi.etablissement_id = :etab AND DATE_FORMAT(gp.date_presence, '%Y-%m') = :m
            ORDER BY gp.date_presence
        ");
        $stmt->execute([':e' => $eleveId, ':etab' => $etabId, ':m' => $mois]);
        $presences = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $heures = $this->calculerHeuresMois($eleveId, $mois);
        $nbPresent = count(array_filter($presences, fn($p) => $p['present']));
        $nbAbsent = count(array_filter($presences, fn($p) => !$p['present']));

        return [
            'mois' => $mois,
            'presences' => $presences,
            'total_jours_present' => $nbPresent,
            'total_jours_absent' => $nbAbsent,
            'heures_totales' => $heures,
        ];
    }
}
