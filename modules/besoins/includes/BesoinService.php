<?php
/**
 * M37 – Besoins particuliers — Service
 */
class BesoinService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Établissement courant ou null si pas de contexte (refuse listing global). */
    private function etabId(): ?int
    {
        try { return \API\Core\EstablishmentContext::id(); }
        catch (\Throwable $e) { return null; }
    }

    /* ───── PLANS ───── */

    public function getPlans(array $filters = []): array
    {
        // Scope obligatoire : ces plans contiennent des données de santé/handicap de mineurs.
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $sql = "SELECT pa.*, CONCAT(e.prenom, ' ', e.nom) AS eleve_nom, cl.nom AS classe_nom,
                       CONCAT(r.prenom, ' ', r.nom) AS responsable_nom
                FROM plans_accompagnement pa
                JOIN eleves e ON pa.eleve_id = e.id
                LEFT JOIN classes cl ON e.classe = cl.nom
                LEFT JOIN professeurs r ON pa.responsable_id = r.id
                WHERE pa.etablissement_id = ?";
        $params = [$etabId];
        if (!empty($filters['type'])) { $sql .= ' AND pa.type_plan = ?'; $params[] = $filters['type']; }
        if (!empty($filters['statut'])) { $sql .= ' AND pa.statut = ?'; $params[] = $filters['statut']; }
        if (!empty($filters['eleve_id'])) { $sql .= ' AND pa.eleve_id = ?'; $params[] = $filters['eleve_id']; }
        if (!empty($filters['responsable_id'])) { $sql .= ' AND pa.responsable_id = ?'; $params[] = $filters['responsable_id']; }
        $sql .= ' ORDER BY pa.created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPlan(int $id): ?array
    {
        // Scope obligatoire : empêche la lecture cross-établissement d'un plan de mineur.
        $etabId = $this->etabId();
        if ($etabId === null) return null;
        $stmt = $this->pdo->prepare("
            SELECT pa.*, CONCAT(e.prenom, ' ', e.nom) AS eleve_nom, cl.nom AS classe_nom,
                   CONCAT(r.prenom, ' ', r.nom) AS responsable_nom
            FROM plans_accompagnement pa
            JOIN eleves e ON pa.eleve_id = e.id
            LEFT JOIN classes cl ON e.classe = cl.nom
            LEFT JOIN professeurs r ON pa.responsable_id = r.id
            WHERE pa.id = ? AND pa.etablissement_id = ?
        ");
        $stmt->execute([$id, $etabId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function creerPlan(array $d): int
    {
        $etabId = $this->etabId();
        if ($etabId === null) throw new \RuntimeException('Établissement non déterminé.');
        // intitule est NOT NULL : valeur fournie sinon dérivée du type de plan.
        $intitule = !empty($d['intitule']) ? $d['intitule'] : ($d['type'] . ' - plan d\'accompagnement');
        $stmt = $this->pdo->prepare("INSERT INTO plans_accompagnement (etablissement_id, eleve_id, type_plan, intitule, amenagements, responsable_id, date_debut, date_fin, statut, document_path) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$etabId, $d['eleve_id'], $d['type'], $intitule, $d['amenagements'] ?? null, $d['responsable_id'] ?: null, $d['date_debut'], $d['date_fin'] ?: null, 'actif', $d['document_path'] ?? null]);
        return $this->pdo->lastInsertId();
    }

    public function modifierPlan(int $id, array $d): void
    {
        $etabId = $this->etabId();
        if ($etabId === null) return;
        $stmt = $this->pdo->prepare("UPDATE plans_accompagnement SET type_plan=?, amenagements=?, responsable_id=?, date_debut=?, date_fin=?, statut=?, document_path=? WHERE id=? AND etablissement_id=?");
        $stmt->execute([$d['type'], $d['amenagements'], $d['responsable_id'] ?: null, $d['date_debut'], $d['date_fin'], $d['statut'], $d['document_path'] ?? null, $id, $etabId]);
    }

    public function getPlansEleve(int $eleveId): array
    {
        return $this->getPlans(['eleve_id' => $eleveId]);
    }

    public function getPlansParent(int $parentId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT pa.*, CONCAT(e.prenom, ' ', e.nom) AS eleve_nom, cl.nom AS classe_nom,
                   CONCAT(r.prenom, ' ', r.nom) AS responsable_nom
            FROM plans_accompagnement pa
            JOIN eleves e ON pa.eleve_id = e.id
            LEFT JOIN classes cl ON e.classe = cl.nom
            LEFT JOIN professeurs r ON pa.responsable_id = r.id
            JOIN parent_eleve pe ON pe.id_eleve = e.id
            WHERE pe.id_parent = ?
            ORDER BY pa.created_at DESC
        ");
        $stmt->execute([$parentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ───── SUIVIS ───── */

    public function getSuivis(int $planId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT ps.*,
                   COALESCE(
                       (SELECT CONCAT(prenom, ' ', nom) FROM professeurs WHERE id = ps.auteur_id),
                       (SELECT CONCAT(prenom, ' ', nom) FROM administrateurs WHERE id = ps.auteur_id),
                       CONCAT('User #', ps.auteur_id)
                   ) AS auteur_nom
            FROM plan_suivis ps
            WHERE ps.plan_id = ?
            ORDER BY ps.created_at DESC
        ");
        $stmt->execute([$planId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function ajouterSuivi(int $planId, int $auteurId, string $observations, string $progres): void
    {
        // auteur_type et date_suivi sont NOT NULL : on dérive le type du rôle courant.
        $role = function_exists('getUserRole') ? getUserRole() : null;
        $auteurType = in_array($role, ['professeur', 'vie_scolaire', 'administrateur'], true) ? $role : 'administrateur';
        $stmt = $this->pdo->prepare("INSERT INTO plan_suivis (plan_id, auteur_id, auteur_type, date_suivi, observations, progres) VALUES (?,?,?,CURDATE(),?,?)");
        $stmt->execute([$planId, $auteurId, $auteurType, $observations, $progres]);
    }

    /* ───── EVALUATIONS PERIODIQUES ───── */

    /**
     * Add a periodic evaluation to a plan.
     */
    public function ajouterEvaluation(int $planId, array $data): void
    {
        $plan = $this->getPlan($planId);
        if (!$plan) return;

        $evaluations = !empty($plan['evaluations']) ? json_decode($plan['evaluations'], true) : [];
        $evaluations[] = [
            'date' => $data['date'] ?? date('Y-m-d'),
            'evaluateur_id' => $data['evaluateur_id'],
            'objectifs_atteints' => $data['objectifs_atteints'] ?? [],
            'commentaire' => $data['commentaire'] ?? '',
            'progres_global' => $data['progres_global'] ?? 'satisfaisant',
            'prochaine_evaluation' => $data['prochaine_evaluation'] ?? null,
        ];

        $this->pdo->prepare("UPDATE plans_accompagnement SET evaluations = ? WHERE id = ?")
                   ->execute([json_encode($evaluations, JSON_UNESCAPED_UNICODE), $planId]);
    }

    /**
     * Get evaluations for a plan.
     */
    public function getEvaluations(int $planId): array
    {
        $plan = $this->getPlan($planId);
        if (!$plan || empty($plan['evaluations'])) return [];
        return json_decode($plan['evaluations'], true) ?: [];
    }

    /**
     * Get plans needing periodic evaluation (last evaluation > 3 months ago or none).
     */
    public function getPlansAEvaluer(): array
    {
        $plans = $this->getPlans(['statut' => 'actif']);
        $result = [];
        $threshold = strtotime('-3 months');

        foreach ($plans as $p) {
            $evals = !empty($p['evaluations']) ? json_decode($p['evaluations'], true) : [];
            $lastEvalDate = null;
            if (!empty($evals)) {
                $lastEval = end($evals);
                $lastEvalDate = strtotime($lastEval['date'] ?? '');
            }

            if ($lastEvalDate === null || $lastEvalDate < $threshold) {
                $p['derniere_evaluation'] = $lastEvalDate ? date('Y-m-d', $lastEvalDate) : null;
                $p['nb_evaluations'] = count($evals);
                $result[] = $p;
            }
        }
        return $result;
    }

    /* ───── HELPERS ───── */

    public function getEleves(): array
    {
        $s = $this->pdo->prepare("SELECT e.id, e.prenom, e.nom, cl.nom AS classe_nom FROM eleves e LEFT JOIN classes cl ON e.classe = cl.nom WHERE e.etablissement_id = ? ORDER BY e.nom");
        $s->execute([\API\Core\EstablishmentContext::id()]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProfesseurs(): array
    {
        $s = $this->pdo->prepare("SELECT id, prenom, nom FROM professeurs WHERE etablissement_id = ? ORDER BY nom");
        $s->execute([\API\Core\EstablishmentContext::id()]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStats(): array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return ['par_type' => [], 'total_actifs' => 0];
        $stmt = $this->pdo->prepare("SELECT type_plan, COUNT(*) AS c FROM plans_accompagnement WHERE statut='actif' AND etablissement_id = ? GROUP BY type_plan");
        $stmt->execute([$etabId]);
        $stats = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $stats[$r['type_plan']] = $r['c']; }
        $totalStmt = $this->pdo->prepare("SELECT COUNT(*) FROM plans_accompagnement WHERE statut='actif' AND etablissement_id = ?");
        $totalStmt->execute([$etabId]);
        $total = $totalStmt->fetchColumn();
        return ['par_type' => $stats, 'total_actifs' => $total];
    }

    public static function typesPlan(): array
    {
        return ['PAP' => 'Plan d\'Accompagnement Personnalisé', 'PPS' => 'Projet Personnalisé de Scolarisation', 'PPRE' => 'Programme Personnalisé de Réussite Éducative', 'PAI' => 'Projet d\'Accueil Individualisé'];
    }

    public static function statutsPlan(): array
    {
        return ['brouillon' => 'Brouillon', 'actif' => 'Actif', 'suspendu' => 'Suspendu', 'termine' => 'Terminé', 'archive' => 'Archivé', 'expire' => 'Expiré'];
    }

    public static function niveauxProgres(): array
    {
        return ['insuffisant' => 'Insuffisant', 'en_difficulte' => 'En difficulté', 'satisfaisant' => 'Satisfaisant', 'tres_bien' => 'Très bien'];
    }

    public static function badgeType(string $type): string
    {
        $m = ['PAP' => 'info', 'PPS' => 'warning', 'PPRE' => 'success', 'PAI' => 'danger'];
        return '<span class="badge badge-' . ($m[$type] ?? 'secondary') . '">' . $type . '</span>';
    }

    public static function badgeProgres(string $p): string
    {
        $m = ['insuffisant' => 'danger', 'en_difficulte' => 'warning', 'satisfaisant' => 'info', 'tres_bien' => 'success'];
        return '<span class="badge badge-' . ($m[$p] ?? 'secondary') . '">' . ucfirst(str_replace('_', ' ', $p)) . '</span>';
    }

    // ─── NOTES MULTI-INTERVENANTS ───

    /**
     * Ajoute une observation d'un intervenant sur un plan d'accompagnement.
     */
    public function ajouterObservation(int $planId, int $auteurId, string $auteurType, string $texte): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO besoins_observations (plan_id, auteur_id, auteur_type, texte, date_observation) VALUES (:pid, :aid, :at, :t, NOW())");
        $stmt->execute([':pid' => $planId, ':aid' => $auteurId, ':at' => $auteurType, ':t' => $texte]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getObservations(int $planId): array
    {
        $stmt = $this->pdo->prepare("SELECT o.*, CASE WHEN o.auteur_type = 'professeur' THEN (SELECT CONCAT(pr.prenom,' ',pr.nom) FROM professeurs pr WHERE pr.id = o.auteur_id) ELSE CONCAT(o.auteur_type,'#',o.auteur_id) END AS auteur_nom FROM besoins_observations o WHERE o.plan_id = :pid ORDER BY o.date_observation DESC");
        $stmt->execute([':pid' => $planId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── VISUALISATION PROGRESSION ───

    /**
     * Données de progression d'un plan au fil du temps.
     */
    public function getProgressionChart(int $planId): array
    {
        $stmt = $this->pdo->prepare("SELECT date_evaluation, progres FROM plan_evaluations WHERE plan_id = :pid ORDER BY date_evaluation ASC");
        $stmt->execute([':pid' => $planId]);
        $evals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $niveaux = ['insuffisant' => 1, 'en_difficulte' => 2, 'satisfaisant' => 3, 'tres_bien' => 4];
        $dates = [];
        $values = [];
        foreach ($evals as $e) {
            $dates[] = $e['date_evaluation'];
            $values[] = $niveaux[$e['progres']] ?? 0;
        }

        return ['dates' => $dates, 'values' => $values, 'labels' => self::niveauxProgres()];
    }

    // ─── TEMPLATES PLANS ───

    /**
     * Récupère les modèles de plan disponibles.
     */
    public function getModelesPlan(string $typePlan = ''): array
    {
        $sql = "SELECT * FROM besoins_modeles";
        $params = [];
        if ($typePlan) { $sql .= " WHERE type_plan = :tp"; $params[':tp'] = $typePlan; }
        $sql .= " ORDER BY type_plan, titre";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Crée un plan à partir d'un modèle.
     */
    public function creerDepuisModele(int $modeleId, int $eleveId, int $createurId): int
    {
        $modele = $this->pdo->prepare("SELECT * FROM besoins_modeles WHERE id = :id");
        $modele->execute([':id' => $modeleId]);
        $m = $modele->fetch(PDO::FETCH_ASSOC);
        if (!$m) throw new \RuntimeException('Modèle introuvable');

        $etabId = $this->etabId();
        if ($etabId === null) throw new \RuntimeException('Établissement non déterminé.');
        // intitule et date_debut sont NOT NULL : on dérive depuis le modèle.
        $intitule = !empty($m['titre']) ? $m['titre'] : ($m['type_plan'] . ' - plan d\'accompagnement');
        $stmt = $this->pdo->prepare("INSERT INTO plans_accompagnement (etablissement_id, eleve_id, type_plan, intitule, description, amenagements, objectifs, createur_id, date_debut, statut) VALUES (:etab, :eid, :tp, :int, :d, :a, :o, :cid, CURDATE(), 'actif')");
        $stmt->execute([':etab' => $etabId, ':eid' => $eleveId, ':tp' => $m['type_plan'], ':int' => $intitule, ':d' => $m['description'], ':a' => $m['amenagements_defaut'], ':o' => $m['objectifs_defaut'] ?? '', ':cid' => $createurId]);
        return (int)$this->pdo->lastInsertId();
    }

    // ─── ALERTES EXPIRATION ───

    /**
     * Retourne les plans qui expirent prochainement (pour cron).
     */
    public function alertExpiringSoon(int $daysBefore = 30): array
    {
        $stmt = $this->pdo->prepare("SELECT pa.*, CONCAT(e.prenom,' ',e.nom) AS eleve_nom, e.classe FROM plans_accompagnement pa JOIN eleves e ON pa.eleve_id = e.id WHERE pa.statut = 'actif' AND pa.date_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :d DAY) ORDER BY pa.date_fin ASC");
        $stmt->execute([':d' => $daysBefore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
