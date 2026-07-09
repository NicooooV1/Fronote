<?php
declare(strict_types=1);
/**
 * InternatService — Service métier pour le module Internat (M19).
 */
class InternatService
{
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** Établissement courant ou null. */
    private function etabId(): ?int
    {
        try { return \API\Core\EstablishmentContext::id(); }
        catch (\Throwable $e) { return null; }
    }

    /* ==================== CHAMBRES ==================== */

    public function getChambres(): array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $stmt = $this->pdo->prepare(
            "SELECT ch.*, COUNT(af.id) AS nb_occupants
             FROM internat_chambres ch
             LEFT JOIN internat_affectations af ON ch.id = af.chambre_id AND af.statut = 'actif'
             WHERE ch.etablissement_id = ?
             GROUP BY ch.id ORDER BY ch.batiment, ch.etage, ch.numero"
        );
        $stmt->execute([$etabId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getChambre(int $id): ?array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return null;
        $stmt = $this->pdo->prepare("SELECT * FROM internat_chambres WHERE id = ? AND etablissement_id = ?");
        $stmt->execute([$id, $etabId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function creerChambre(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO internat_chambres (etablissement_id, numero, batiment, etage, capacite, type, equipements) VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([\API\Core\EstablishmentContext::id(), $data['numero'], $data['batiment'] ?? null, $data['etage'] ?? null,
            $data['capacite'] ?? 2, $data['type'] ?? 'double', $data['equipements'] ?? null]);
        return (int) $this->pdo->lastInsertId();
    }

    public function modifierChambre(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE internat_chambres SET numero = ?, batiment = ?, etage = ?, capacite = ?, type = ?, equipements = ? WHERE id = ? AND etablissement_id = ?"
        );
        return $stmt->execute([$data['numero'], $data['batiment'] ?? null, $data['etage'] ?? null,
            $data['capacite'] ?? 2, $data['type'] ?? 'double', $data['equipements'] ?? null, $id, \API\Core\EstablishmentContext::id()]);
    }

    /* ==================== AFFECTATIONS ==================== */

    public function getAffectations(?string $annee = null): array
    {
        $annee = $annee ?: $this->getAnneeScolaire();
        $stmt = $this->pdo->prepare(
            "SELECT af.*, e.nom, e.prenom, e.classe, ch.numero AS chambre_numero, ch.batiment
             FROM internat_affectations af
             JOIN eleves e ON af.eleve_id = e.id
             JOIN internat_chambres ch ON af.chambre_id = ch.id
             WHERE af.annee_scolaire = ? AND af.etablissement_id = ?
             ORDER BY ch.batiment, ch.numero, e.nom"
        );
        $stmt->execute([$annee, \API\Core\EstablishmentContext::id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAffectationsChambre(int $chambreId, ?string $annee = null): array
    {
        $annee = $annee ?: $this->getAnneeScolaire();
        $stmt = $this->pdo->prepare(
            "SELECT af.*, e.nom, e.prenom, e.classe
             FROM internat_affectations af
             JOIN eleves e ON af.eleve_id = e.id
             WHERE af.chambre_id = ? AND af.annee_scolaire = ? AND af.statut = 'actif' AND af.etablissement_id = ?
             ORDER BY e.nom"
        );
        $stmt->execute([$chambreId, $annee, \API\Core\EstablishmentContext::id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function affecterEleve(int $chambreId, int $eleveId, string $dateDebut): int
    {
        $annee = $this->getAnneeScolaire();
        $stmt = $this->pdo->prepare(
            "INSERT INTO internat_affectations (etablissement_id, chambre_id, eleve_id, annee_scolaire, date_debut)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE chambre_id = VALUES(chambre_id), date_debut = VALUES(date_debut), statut = 'actif'"
        );
        $stmt->execute([\API\Core\EstablishmentContext::id(), $chambreId, $eleveId, $annee, $dateDebut]);
        return (int) $this->pdo->lastInsertId();
    }

    public function libererPlace(int $affectationId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE internat_affectations SET statut = 'termine', date_fin = CURDATE() WHERE id = ? AND etablissement_id = ?"
        );
        return $stmt->execute([$affectationId, \API\Core\EstablishmentContext::id()]);
    }

    /* ==================== VIE INTERNAT (entrées/sorties) ==================== */

    public function enregistrerMouvement(int $eleveId, int $chambreId, string $type, ?string $motif = null, ?int $signalePar = null): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO internat_reglement (eleve_id, chambre_id, type, motif, signale_par) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$eleveId, $chambreId, $type, $motif, $signalePar]);
        return (int) $this->pdo->lastInsertId();
    }

    public function getMouvementsJour(string $date): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.*, e.nom, e.prenom, e.classe, ch.numero AS chambre_numero
             FROM internat_reglement r
             JOIN eleves e ON r.eleve_id = e.id
             JOIN internat_chambres ch ON r.chambre_id = ch.id
             WHERE DATE(r.date_heure) = ? AND e.etablissement_id = ?
             ORDER BY r.date_heure DESC"
        );
        $stmt->execute([$date, \API\Core\EstablishmentContext::id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ==================== INCIDENTS ==================== */

    public function signalerIncident(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO internat_incidents (chambre_id, eleve_id, type, description, gravite) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $data['chambre_id'] ?? null, $data['eleve_id'] ?? null,
            $data['type'] ?? 'autre', $data['description'], $data['gravite'] ?? 1,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function getIncidents(?string $dateDebut = null, ?string $dateFin = null): array
    {
        $sql = "SELECT i.*, e.nom, e.prenom, ch.numero AS chambre_numero
                FROM internat_incidents i
                LEFT JOIN eleves e ON i.eleve_id = e.id
                LEFT JOIN internat_chambres ch ON i.chambre_id = ch.id";
        $params = [];
        if ($dateDebut && $dateFin) {
            $sql .= " WHERE DATE(i.date_incident) BETWEEN ? AND ?";
            $params = [$dateDebut, $dateFin];
        }
        $sql .= " ORDER BY i.date_incident DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function traiterIncident(int $id, int $traitePar, string $suite): bool
    {
        // Cloisonnement multi-tenant : internat_incidents n'a pas d'etablissement_id,
        // on scope via ses parents (internat_chambres / eleves).
        $etabId = \API\Core\EstablishmentContext::id();
        $stmt = $this->pdo->prepare(
            "UPDATE internat_incidents i
             LEFT JOIN internat_chambres ch ON i.chambre_id = ch.id
             LEFT JOIN eleves e ON i.eleve_id = e.id
             SET i.traite = 1, i.traite_par = ?, i.suite_donnee = ?
             WHERE i.id = ? AND (ch.etablissement_id = ? OR e.etablissement_id = ?)"
        );
        $stmt->execute([$traitePar, $suite, $id, $etabId, $etabId]);
        return $stmt->rowCount() > 0;
    }

    /* ==================== STATS ==================== */

    public function getStats(): array
    {
        $stats = [];
        $etabId = (int) \API\Core\EstablishmentContext::id();
        $stats['total_chambres'] = (int) $this->pdo->query("SELECT COUNT(*) FROM internat_chambres WHERE etablissement_id = $etabId")->fetchColumn();
        $stats['capacite_totale'] = (int) $this->pdo->query("SELECT COALESCE(SUM(capacite), 0) FROM internat_chambres WHERE etablissement_id = $etabId")->fetchColumn();
        $annee = $this->getAnneeScolaire();
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM internat_affectations WHERE annee_scolaire = ? AND statut = 'actif' AND etablissement_id = ?");
        $stmt->execute([$annee, $etabId]);
        $stats['internes_actifs'] = (int) $stmt->fetchColumn();
        $stats['taux_occupation'] = $stats['capacite_totale'] > 0
            ? round($stats['internes_actifs'] / $stats['capacite_totale'] * 100, 1) : 0;
        $stats['incidents_non_traites'] = (int) $this->pdo->query("SELECT COUNT(*) FROM internat_incidents WHERE traite = 0")->fetchColumn();
        return $stats;
    }

    private function getAnneeScolaire(): string
    {
        $m = (int) date('n'); $y = (int) date('Y');
        return $m >= 9 ? "$y-" . ($y + 1) : ($y - 1) . "-$y";
    }

    /* ==================== EXPORT ==================== */

    public function getAffectationsForExport(?string $annee = null): array
    {
        $affectations = $this->getAffectations($annee);
        return array_map(fn($a) => [
            $a['nom'] . ' ' . $a['prenom'],
            $a['classe'] ?? '-',
            $a['chambre_numero'],
            $a['batiment'] ?? '-',
            $a['annee_scolaire'],
            $a['date_debut'] ?? '-',
            $a['date_fin'] ?? '-',
            ucfirst($a['statut']),
        ], $affectations);
    }

    public function getIncidentsForExport(?string $dateDebut = null, ?string $dateFin = null): array
    {
        $incidents = $this->getIncidents($dateDebut, $dateFin);
        return array_map(fn($i) => [
            $i['date_incident'] ?? '-',
            $i['chambre_numero'] ?? '-',
            isset($i['nom']) ? $i['nom'] . ' ' . ($i['prenom'] ?? '') : 'Non renseigné',
            ucfirst($i['type'] ?? '-'),
            $i['description'] ?? '-',
            $i['gravite'] ?? '-',
            $i['traite'] ? 'Oui' : 'Non',
            $i['suite_donnee'] ?? '-',
        ], $incidents);
    }

    // ─── INSPECTIONS CHAMBRES ───

    public function creerInspection(int $chambreId, int $inspecteurId, int $proprete, int $rangement, int $equipement, ?string $commentaire = null): int
    {
        $note = round(($proprete + $rangement + $equipement) / 3, 1);
        $stmt = $this->pdo->prepare("
            INSERT INTO internat_inspections (chambre_id, inspecteur_id, proprete, rangement, equipement, note_globale, commentaire, date_inspection)
            VALUES (:ch, :ins, :p, :r, :eq, :n, :c, NOW())
        ");
        $stmt->execute([':ch' => $chambreId, ':ins' => $inspecteurId, ':p' => $proprete, ':r' => $rangement, ':eq' => $equipement, ':n' => $note, ':c' => $commentaire]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getInspections(int $chambreId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare("SELECT ii.*, CONCAT(p.prenom, ' ', p.nom) AS inspecteur_nom FROM internat_inspections ii LEFT JOIN professeurs p ON ii.inspecteur_id = p.id WHERE ii.chambre_id = :ch ORDER BY ii.date_inspection DESC LIMIT :l");
        $stmt->bindValue(':ch', $chambreId, \PDO::PARAM_INT);
        $stmt->bindValue(':l', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ─── APPEL DU SOIR ───

    public function faireAppelSoir(string $date): array
    {
        $annee = $this->getAnneeScolaire();
        $stmt = $this->pdo->prepare("
            SELECT ia.id AS affectation_id, ia.eleve_id, CONCAT(e.prenom, ' ', e.nom) AS eleve_nom,
                   ic.numero AS chambre, ic.batiment,
                   (SELECT id FROM internat_appels_soir WHERE affectation_id = ia.id AND date_appel = :d) AS appel_id
            FROM internat_affectations ia
            JOIN eleves e ON ia.eleve_id = e.id
            JOIN internat_chambres ic ON ia.chambre_id = ic.id
            WHERE ia.annee_scolaire = :a AND ia.statut = 'actif' AND ia.etablissement_id = :etab
            ORDER BY ic.batiment, ic.numero, e.nom
        ");
        $stmt->execute([':d' => $date, ':a' => $annee, ':etab' => \API\Core\EstablishmentContext::id()]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function enregistrerAppelSoir(int $affectationId, string $date, bool $present, ?string $motifAbsence = null): void
    {
        $this->pdo->prepare("
            INSERT INTO internat_appels_soir (affectation_id, date_appel, present, motif_absence)
            VALUES (:a, :d, :p, :m)
            ON DUPLICATE KEY UPDATE present = VALUES(present), motif_absence = VALUES(motif_absence)
        ")->execute([':a' => $affectationId, ':d' => $date, ':p' => $present ? 1 : 0, ':m' => $motifAbsence]);
    }

    // ─── AUTORISATIONS SORTIE ───

    public function creerAutorisationSortie(int $eleveId, string $dateDebut, string $dateFin, string $motif, ?int $autoriseParId = null): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO internat_autorisations_sortie (eleve_id, date_debut, date_fin, motif, autorise_par, statut, created_at)
            VALUES (:e, :dd, :df, :m, :a, 'en_attente', NOW())
        ");
        $stmt->execute([':e' => $eleveId, ':dd' => $dateDebut, ':df' => $dateFin, ':m' => $motif, ':a' => $autoriseParId]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getAutorisationsSortie(?int $eleveId = null, ?string $statut = null): array
    {
        $sql = "SELECT ias.*, CONCAT(e.prenom, ' ', e.nom) AS eleve_nom FROM internat_autorisations_sortie ias JOIN eleves e ON ias.eleve_id = e.id WHERE e.etablissement_id = :etab";
        $params = [':etab' => \API\Core\EstablishmentContext::id()];
        if ($eleveId) { $sql .= " AND ias.eleve_id = :e"; $params[':e'] = $eleveId; }
        if ($statut) { $sql .= " AND ias.statut = :s"; $params[':s'] = $statut; }
        $sql .= " ORDER BY ias.date_debut DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function traiterAutorisationSortie(int $id, string $statut, int $traitePar): void
    {
        $this->pdo->prepare("UPDATE internat_autorisations_sortie SET statut = :s, autorise_par = :t WHERE id = :id AND eleve_id IN (SELECT id FROM eleves WHERE etablissement_id = :etab)")
            ->execute([':s' => $statut, ':t' => $traitePar, ':id' => $id, ':etab' => \API\Core\EstablishmentContext::id()]);
    }

    // ─── ACTIVITÉS WEEK-END ───

    public function creerActiviteWeekend(string $titre, string $date, ?string $description = null, ?int $responsableId = null, int $placesMax = 0): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO internat_activites_weekend (titre, date_activite, description, responsable_id, places_max, created_at)
            VALUES (:t, :d, :desc, :r, :p, NOW())
        ");
        $stmt->execute([':t' => $titre, ':d' => $date, ':desc' => $description, ':r' => $responsableId, ':p' => $placesMax]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getActivitesWeekend(?string $dateDebut = null, ?string $dateFin = null): array
    {
        $sql = "SELECT iaw.*,
                  (SELECT COUNT(*) FROM internat_activites_inscriptions iai WHERE iai.activite_id = iaw.id) AS nb_inscrits
                FROM internat_activites_weekend iaw WHERE 1=1";
        $params = [];
        if ($dateDebut) { $sql .= " AND iaw.date_activite >= :dd"; $params[':dd'] = $dateDebut; }
        if ($dateFin) { $sql .= " AND iaw.date_activite <= :df"; $params[':df'] = $dateFin; }
        $sql .= " ORDER BY iaw.date_activite";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function inscrireActiviteWeekend(int $activiteId, int $eleveId): void
    {
        $this->pdo->prepare("INSERT IGNORE INTO internat_activites_inscriptions (activite_id, eleve_id, inscrit_at) VALUES (:a, :e, NOW())")
            ->execute([':a' => $activiteId, ':e' => $eleveId]);
    }
}
