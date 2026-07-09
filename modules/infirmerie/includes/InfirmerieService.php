<?php
/**
 * M31 – Santé / Infirmerie — Service
 */
class InfirmerieService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Établissement courant ou null. */
    private function etabId(): ?int
    {
        try { return \API\Core\EstablishmentContext::id(); }
        catch (\Throwable $e) { return null; }
    }

    /* ───────── CHIFFREMENT AT-REST DES DONNÉES DE SANTÉ (RGPD Art. 9) ─────────
       Les champs libres médicaux sont chiffrés (AES-256-GCM via APP_KEY) en base.
       Rétro-compatible : la lecture déchiffre le format chiffré et laisse passer
       le clair hérité ; si aucune clé n'est configurée, on reste en clair. */
    private const HEALTH_FIELDS = ['allergies', 'traitements', 'contact_urgence', 'contacts_urgence', 'pai', 'observations', 'remarques'];
    private ?\API\Core\Encryption $enc = null;
    private bool $encResolved = false;

    private function enc(): ?\API\Core\Encryption
    {
        if (!$this->encResolved) {
            $this->encResolved = true;
            if (\API\Core\Encryption::available()) {
                try { $this->enc = new \API\Core\Encryption(); }
                catch (\Throwable $e) { $this->enc = null; error_log('[InfirmerieService] encryption unavailable: ' . $e->getMessage()); }
            }
        }
        return $this->enc;
    }

    private function encField(?string $v): ?string
    {
        if ($v === null || $v === '') return $v;
        $e = $this->enc();
        return $e ? $e->encryptIfPlain($v) : $v;
    }

    private function decField(?string $v): ?string
    {
        if ($v === null || $v === '') return $v;
        $e = $this->enc();
        if ($e && $e->isEncrypted($v)) {
            try { return $e->decrypt($v); } catch (\Throwable $ex) { return $v; }
        }
        return $v;
    }

    /** Déchiffre les champs santé connus d'une ligne (raw + alias). */
    private function decryptRow(?array $row): ?array
    {
        if ($row === null) return null;
        foreach (self::HEALTH_FIELDS as $f) {
            if (array_key_exists($f, $row)) $row[$f] = $this->decField($row[$f]);
        }
        return $row;
    }

    /* ───────── FICHES SANTÉ ───────── */

    public function getFiche(int $eleveId): ?array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return null;
        // Fiche scopée via JOIN sur eleves : un élève d'un autre établissement = introuvable.
        $stmt = $this->pdo->prepare(
            "SELECT fs.*, fs.contact_urgence AS contacts_urgence, fs.observations AS remarques
             FROM fiches_sante fs
             JOIN eleves e ON fs.eleve_id = e.id
             WHERE fs.eleve_id = ? AND e.etablissement_id = ?"
        );
        $stmt->execute([$eleveId, $etabId]);
        return $this->decryptRow($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    public function sauvegarderFiche(int $eleveId, array $data): void
    {
        // Chiffrement at-rest des champs libres médicaux (RGPD Art. 9).
        $allergies = $this->encField($data['allergies'] ?? null);
        $traitements = $this->encField($data['traitements'] ?? null);
        $contact = $this->encField($data['contacts_urgence'] ?? null);
        $pai = $this->encField($data['pai'] ?? null);
        $observations = $this->encField($data['remarques'] ?? null);
        $etabId = $this->etabId();
        $existing = $this->getFiche($eleveId);
        if ($existing) {
            $stmt = $this->pdo->prepare("
                UPDATE fiches_sante SET allergies=?, traitements=?, contact_urgence=?, pai=?, groupe_sanguin=?, observations=?
                WHERE eleve_id=? AND etablissement_id=?
            ");
            $stmt->execute([
                $allergies, $traitements, $contact,
                $pai, $data['groupe_sanguin'] ?? null, $observations,
                $eleveId, $etabId,
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO fiches_sante (eleve_id, etablissement_id, allergies, traitements, contact_urgence, pai, groupe_sanguin, observations)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $eleveId, $etabId, $allergies, $traitements, $contact,
                $pai, $data['groupe_sanguin'] ?? null, $observations,
            ]);
        }
    }

    public function getFiches(string $recherche = null): array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $sql = "
            SELECT fs.*, fs.contact_urgence AS contacts_urgence, fs.observations AS remarques,
                   e.prenom, e.nom AS eleve_nom, cl.nom AS classe_nom
            FROM fiches_sante fs
            JOIN eleves e ON fs.eleve_id = e.id
            LEFT JOIN classes cl ON e.classe = cl.nom
            WHERE e.etablissement_id = ?
        ";
        $params = [$etabId];
        if ($recherche) {
            $sql .= " AND (e.nom LIKE ? OR e.prenom LIKE ?)";
            $params[] = "%$recherche%"; $params[] = "%$recherche%";
        }
        $sql .= ' ORDER BY e.nom, e.prenom';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'decryptRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /* ───────── PASSAGES INFIRMERIE ───────── */

    public function creerPassage(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO passages_infirmerie (eleve_id, etablissement_id, date_passage, motif, symptomes, soins_prodigues, orientation, parent_prevenu, observations)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['eleve_id'], $this->etabId(), $data['date_passage'] ?? date('Y-m-d H:i:s'),
            $data['motif'], $data['symptomes'] ?? null, $data['soins'] ?? null,
            $data['orientation'] ?? 'retour_classe', $data['notifier_parents'] ?? 0,
            $data['remarques'] ?? null,
        ]);
        return $this->pdo->lastInsertId();
    }

    public function getPassage(int $id): ?array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return null;
        $stmt = $this->pdo->prepare("
            SELECT p.*, p.soins_prodigues AS soins, p.parent_prevenu AS notifier_parents, p.observations AS remarques,
                   e.prenom, e.nom AS eleve_nom, cl.nom AS classe_nom
            FROM passages_infirmerie p
            JOIN eleves e ON p.eleve_id = e.id
            LEFT JOIN classes cl ON e.classe = cl.nom
            WHERE p.id = ? AND p.etablissement_id = ?
        ");
        $stmt->execute([$id, $etabId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getPassages(array $filtres = []): array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $sql = "
            SELECT p.*, p.soins_prodigues AS soins, p.parent_prevenu AS notifier_parents, p.observations AS remarques,
                   e.prenom, e.nom AS eleve_nom, cl.nom AS classe_nom
            FROM passages_infirmerie p
            JOIN eleves e ON p.eleve_id = e.id
            LEFT JOIN classes cl ON e.classe = cl.nom
            WHERE p.etablissement_id = ?
        ";
        $params = [$etabId];
        if (!empty($filtres['eleve_id'])) {
            $sql .= ' AND p.eleve_id = ?';
            $params[] = $filtres['eleve_id'];
        }
        if (!empty($filtres['date_debut'])) {
            $sql .= ' AND p.date_passage >= ?';
            $params[] = $filtres['date_debut'];
        }
        if (!empty($filtres['date_fin'])) {
            $sql .= ' AND p.date_passage <= ?';
            $params[] = $filtres['date_fin'] . ' 23:59:59';
        }
        if (!empty($filtres['orientation'])) {
            $sql .= ' AND p.orientation = ?';
            $params[] = $filtres['orientation'];
        }
        $sql .= ' ORDER BY p.date_passage DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPassagesEleve(int $eleveId): array
    {
        return $this->getPassages(['eleve_id' => $eleveId]);
    }

    public function getEleves(string $recherche = null): array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $sql = "SELECT e.id, e.prenom, e.nom, cl.nom AS classe_nom FROM eleves e LEFT JOIN classes cl ON e.classe = cl.nom WHERE e.etablissement_id = ?";
        $params = [$etabId];
        if ($recherche) { $sql .= ' AND (e.nom LIKE ? OR e.prenom LIKE ?)'; $params[] = "%$recherche%"; $params[] = "%$recherche%"; }
        $sql .= ' ORDER BY e.nom, e.prenom';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getEnfantsParent(int $parentId): array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $stmt = $this->pdo->prepare("
            SELECT e.id, e.prenom, e.nom, cl.nom AS classe_nom
            FROM eleves e
            JOIN parent_eleve pe ON e.id = pe.id_eleve
            LEFT JOIN classes cl ON e.classe = cl.nom
            WHERE pe.id_parent = ? AND e.etablissement_id = ?
        ");
        $stmt->execute([$parentId, $etabId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStats(): array
    {
        $etabId = (int)\API\Core\EstablishmentContext::id();
        $today = date('Y-m-d');
        $month = date('Y-m') . '%';

        $stmtJour = $this->pdo->prepare("SELECT COUNT(*) FROM passages_infirmerie WHERE DATE(date_passage) = ? AND etablissement_id = ?");
        $stmtJour->execute([$today, $etabId]);

        $stmtMois = $this->pdo->prepare("SELECT COUNT(*) FROM passages_infirmerie WHERE date_passage LIKE ? AND etablissement_id = ?");
        $stmtMois->execute([$month, $etabId]);

        return [
            'fiches' => (int)$this->pdo->query("SELECT COUNT(*) FROM fiches_sante WHERE etablissement_id = " . $etabId)->fetchColumn(),
            'passages_jour' => (int)$stmtJour->fetchColumn(),
            'passages_mois' => (int)$stmtMois->fetchColumn(),
            'pai_actifs' => (int)$this->pdo->query("SELECT COUNT(*) FROM fiches_sante WHERE pai IS NOT NULL AND pai != '' AND etablissement_id = " . $etabId)->fetchColumn(),
        ];
    }

    public function getStatsPassages(): array
    {
        // Recalculate properly
        $etabId = (int)\API\Core\EstablishmentContext::id();
        $today = date('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM passages_infirmerie WHERE DATE(date_passage) = ? AND etablissement_id = ?");
        $stmt->execute([$today, $etabId]);
        $jour = $stmt->fetchColumn();

        $month = date('Y-m') . '%';
        $stmtMois = $this->pdo->prepare("SELECT COUNT(*) FROM passages_infirmerie WHERE date_passage LIKE ? AND etablissement_id = ?");
        $stmtMois->execute([$month, $etabId]);
        $mois = $stmtMois->fetchColumn();

        $envois = $this->pdo->query("SELECT COUNT(*) FROM passages_infirmerie WHERE orientation = 'domicile' AND etablissement_id = " . $etabId)->fetchColumn();
        $urgences = $this->pdo->query("SELECT COUNT(*) FROM passages_infirmerie WHERE orientation = 'urgences' AND etablissement_id = " . $etabId)->fetchColumn();

        return ['jour' => $jour, 'mois' => $mois, 'renvoyes' => $envois, 'urgences' => $urgences];
    }

    public static function orientations(): array
    {
        return [
            'retour_classe' => 'Retour en classe',
            'repos' => 'Repos à l\'infirmerie',
            'domicile' => 'Renvoyé à domicile',
            'urgences' => 'Urgences / SAMU',
            'autre' => 'Autre',
        ];
    }

    public static function orientationBadge(string $o): string
    {
        $map = [
            'retour_classe' => 'success',
            'repos' => 'info',
            'domicile' => 'warning',
            'urgences' => 'danger',
            'autre' => 'secondary',
        ];
        $labels = self::orientations();
        return '<span class="badge badge-' . ($map[$o] ?? 'secondary') . '">' . ($labels[$o] ?? $o) . '</span>';
    }

    public static function groupesSanguins(): array
    {
        return ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    }

    /* ───────── VACCINATIONS ───────── */

    /**
     * Get vaccination records for a student.
     */
    public function getVaccinations(int $eleveId): array
    {
        $fiche = $this->getFiche($eleveId);
        if (!$fiche) return [];
        return json_decode($fiche['vaccinations'] ?? '[]', true) ?: [];
    }

    /**
     * Save vaccination records for a student.
     */
    public function saveVaccinations(int $eleveId, array $vaccinations): void
    {
        $json = json_encode($vaccinations, JSON_UNESCAPED_UNICODE);
        $etabId = $this->etabId();
        $fiche = $this->getFiche($eleveId);
        if ($fiche) {
            $this->pdo->prepare("UPDATE fiches_sante SET vaccinations = ? WHERE eleve_id = ? AND etablissement_id = ?")
                       ->execute([$json, $eleveId, $etabId]);
        } else {
            $this->pdo->prepare("INSERT INTO fiches_sante (eleve_id, etablissement_id, vaccinations) VALUES (?, ?, ?)")
                       ->execute([$eleveId, $etabId, $json]);
        }
    }

    /**
     * Get students with missing or expired vaccinations.
     */
    public function getVaccinationsManquantes(): array
    {
        $obligatoires = self::vaccinsObligatoires();
        $fiches = $this->getFiches();
        $manquants = [];

        foreach ($fiches as $f) {
            $vaccins = json_decode($f['vaccinations'] ?? '[]', true) ?: [];
            $vaccinsNoms = array_column($vaccins, 'nom');
            $missing = [];
            foreach ($obligatoires as $v) {
                if (!in_array($v, $vaccinsNoms)) {
                    $missing[] = $v;
                }
            }
            if (!empty($missing)) {
                $manquants[] = [
                    'eleve_id' => $f['eleve_id'],
                    'eleve_nom' => ($f['eleve_nom'] ?? '') . ' ' . ($f['prenom'] ?? ''),
                    'classe' => $f['classe_nom'] ?? '',
                    'vaccins_manquants' => $missing,
                ];
            }
        }
        return $manquants;
    }

    /* ───────── EMERGENCY PROTOCOLS ───────── */

    /**
     * Get emergency protocols.
     */
    public function getProtocoles(): array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $stmt = $this->pdo->prepare("SELECT * FROM protocoles_urgence WHERE etablissement_id = ? ORDER BY nom");
        $stmt->execute([$etabId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get protocol by pathology keyword.
     */
    public function getProtocoleByPathologie(string $keyword): ?array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return null;
        $stmt = $this->pdo->prepare("SELECT * FROM protocoles_urgence WHERE (pathologie LIKE ? OR nom LIKE ?) AND etablissement_id = ? LIMIT 1");
        $like = '%' . $keyword . '%';
        $stmt->execute([$like, $like, $etabId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get top motifs for passages (for stats dashboard).
     */
    public function getTopMotifs(int $limit = 10): array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $stmt = $this->pdo->prepare("
            SELECT motif, COUNT(*) AS total
            FROM passages_infirmerie
            WHERE date_passage >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
              AND etablissement_id = ?
            GROUP BY motif ORDER BY total DESC LIMIT ?
        ");
        $stmt->execute([$etabId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get frequent visitors (students with many passages).
     */
    public function getVisiteursFrequents(int $limit = 10): array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $stmt = $this->pdo->prepare("
            SELECT e.id, CONCAT(e.prenom, ' ', e.nom) AS eleve_nom, cl.nom AS classe_nom,
                   COUNT(*) AS nb_passages
            FROM passages_infirmerie p
            JOIN eleves e ON p.eleve_id = e.id
            LEFT JOIN classes cl ON e.classe = cl.nom
            WHERE p.date_passage >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
              AND p.etablissement_id = ?
            GROUP BY e.id HAVING nb_passages >= 3
            ORDER BY nb_passages DESC LIMIT ?
        ");
        $stmt->execute([$etabId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function vaccinsObligatoires(): array
    {
        return ['DTP', 'Coqueluche', 'Haemophilus B', 'Hépatite B', 'Pneumocoque', 'Méningocoque C', 'ROR'];
    }

    /* ───────── STATISTIQUES MENSUELLES ───────── */

    /**
     * Stats passages par mois (12 derniers mois)
     */
    public function getStatsMensuelles(): array
    {
        $etabId = (int)\API\Core\EstablishmentContext::id();
        $result = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-{$i} months"));
            $label = date('M Y', strtotime($month . '-01'));
            $stmtM = $this->pdo->prepare("SELECT COUNT(*) FROM passages_infirmerie WHERE date_passage LIKE ? AND etablissement_id = ?");
            $stmtM->execute([$month . '%', $etabId]);
            $count = (int)$stmtM->fetchColumn();
            $result[] = ['mois' => $label, 'passages' => $count];
        }
        return $result;
    }

    /**
     * Répartition par orientation
     */
    public function getRepartitionOrientations(): array
    {
        $etabId = (int)\API\Core\EstablishmentContext::id();
        return $this->pdo->query("
            SELECT orientation, COUNT(*) AS total
            FROM passages_infirmerie
            WHERE date_passage >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
              AND etablissement_id = " . $etabId . "
            GROUP BY orientation ORDER BY total DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── SUIVI MÉDICAMENTS ───

    public function ajouterTraitement(int $eleveId, string $medicament, string $posologie, string $dateDebut, ?string $dateFin = null, bool $paiActif = false): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO infirmerie_traitements (eleve_id, etablissement_id, medicament, posologie, date_debut, date_fin, pai, created_at)
            VALUES (:e, :etab, :m, :p, :dd, :df, :pai, NOW())
        ");
        $stmt->execute([':e' => $eleveId, ':etab' => $this->etabId(), ':m' => $medicament, ':p' => $posologie, ':dd' => $dateDebut, ':df' => $dateFin, ':pai' => $paiActif ? 1 : 0]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getTraitements(int $eleveId, bool $actifsOnly = false): array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $sql = "SELECT * FROM infirmerie_traitements WHERE eleve_id = :e AND etablissement_id = :etab";
        if ($actifsOnly) { $sql .= " AND (date_fin IS NULL OR date_fin >= CURDATE())"; }
        $sql .= " ORDER BY date_debut DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':e' => $eleveId, ':etab' => $etabId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function enregistrerPriseTraitement(int $traitementId, string $date, ?string $commentaire = null): void
    {
        $this->pdo->prepare("
            INSERT INTO infirmerie_prises (traitement_id, date_prise, heure_prise, commentaire)
            VALUES (:t, :d, NOW(), :c)
        ")->execute([':t' => $traitementId, ':d' => $date, ':c' => $commentaire]);
    }

    // ─── DÉTECTION ÉPIDÉMIE ───

    public function detecterEpidemie(int $joursAnalyse = 7, int $seuilAlerte = 5): array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $stmt = $this->pdo->prepare("
            SELECT motif, COUNT(*) AS nb_cas, COUNT(DISTINCT eleve_id) AS nb_eleves,
                   MIN(date_passage) AS premier_cas, MAX(date_passage) AS dernier_cas
            FROM passages_infirmerie
            WHERE date_passage >= DATE_SUB(CURDATE(), INTERVAL :j DAY)
              AND motif IS NOT NULL AND motif != ''
              AND etablissement_id = :etab
            GROUP BY motif
            HAVING nb_cas >= :s
            ORDER BY nb_cas DESC
        ");
        $stmt->execute([':j' => $joursAnalyse, ':s' => $seuilAlerte, ':etab' => $etabId]);
        $alertes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($alertes as &$a) {
            $stmtClasses = $this->pdo->prepare("
                SELECT DISTINCT cl.nom AS classe
                FROM passages_infirmerie p
                JOIN eleves e ON p.eleve_id = e.id
                LEFT JOIN classes cl ON e.classe = cl.nom
                WHERE p.motif = :m AND p.date_passage >= DATE_SUB(CURDATE(), INTERVAL :j DAY)
                  AND p.etablissement_id = :etab
            ");
            $stmtClasses->execute([':m' => $a['motif'], ':j' => $joursAnalyse, ':etab' => $etabId]);
            $a['classes_touchees'] = $stmtClasses->fetchAll(\PDO::FETCH_COLUMN);
        }
        return $alertes;
    }

    // ─── AFFICHAGE PAI ───

    public function getElevesPai(?string $classe = null): array
    {
        $etabId = $this->etabId();
        if ($etabId === null) return [];
        $sql = "SELECT fs.eleve_id, CONCAT(e.prenom, ' ', e.nom) AS eleve_nom, cl.nom AS classe_nom,
                       fs.pai, fs.allergies, fs.pathologies
                FROM fiches_sante fs
                JOIN eleves e ON fs.eleve_id = e.id
                LEFT JOIN classes cl ON e.classe = cl.nom
                WHERE fs.pai IS NOT NULL AND fs.pai != '' AND e.etablissement_id = :etab";
        $params = [':etab' => $etabId];
        if ($classe) { $sql .= " AND cl.nom = :c"; $params[':c'] = $classe; }
        $sql .= " ORDER BY e.nom";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'decryptRow'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function getPaiResume(int $eleveId): ?array
    {
        $fiche = $this->getFiche($eleveId);
        if (!$fiche || empty($fiche['pai'])) return null;
        $traitements = $this->getTraitements($eleveId, true);
        $traitementsPai = array_filter($traitements, fn($t) => $t['pai']);
        return [
            'eleve_id' => $eleveId,
            'pai' => $fiche['pai'],
            'allergies' => $fiche['allergies'] ?? '',
            'pathologies' => $fiche['pathologies'] ?? '',
            'traitements_actifs' => array_values($traitementsPai),
            'protocoles_associes' => $fiche['pathologies'] ? $this->getProtocoleByPathologie($fiche['pathologies']) : null,
        ];
    }

    // ─── STATS MENSUELLES WIDGET ───

    public function getStatsMensuellesWidget(): array
    {
        $etabId = (int)\API\Core\EstablishmentContext::id();
        $moisCourant = date('Y-m');
        $moisPrec = date('Y-m', strtotime('-1 month'));

        $stmtCur = $this->pdo->prepare("SELECT COUNT(*) FROM passages_infirmerie WHERE date_passage LIKE ? AND etablissement_id = ?");
        $stmtCur->execute([$moisCourant . '%', $etabId]);
        $current = (int)$stmtCur->fetchColumn();

        $stmtPrev = $this->pdo->prepare("SELECT COUNT(*) FROM passages_infirmerie WHERE date_passage LIKE ? AND etablissement_id = ?");
        $stmtPrev->execute([$moisPrec . '%', $etabId]);
        $previous = (int)$stmtPrev->fetchColumn();

        $evolution = $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : 0;

        $topMotifs = $this->getTopMotifs(3);

        return [
            'passages_mois' => $current,
            'passages_mois_precedent' => $previous,
            'evolution_pct' => $evolution,
            'top_motifs' => $topMotifs,
            'pai_actifs' => (int)$this->pdo->query("SELECT COUNT(*) FROM fiches_sante WHERE pai IS NOT NULL AND pai != '' AND etablissement_id = " . $etabId)->fetchColumn(),
            'epidemies_detectees' => count($this->detecterEpidemie()),
        ];
    }
}
