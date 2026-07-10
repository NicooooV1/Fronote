<?php
declare(strict_types=1);
/**
 * M23 – RGPD & Audit — Service
 * Enhanced: data export, anonymization, retention policies
 */
class AuditRgpdService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /* ───────── JOURNAL D'AUDIT ───────── */

    public function getAuditLogs(array $filtres = []): array
    {
        // Le journal d'audit ne doit JAMAIS provoquer une 500 (colonne/table
        // manquante, scope indéterminé…) : en cas d'échec on renvoie un résultat
        // vide et on trace l'erreur côté serveur.
        try {
            $sql = "SELECT * FROM audit_log WHERE 1=1";
            $params = [];
            if (!empty($filtres['user_id'])) { $sql .= ' AND user_id = ?'; $params[] = $filtres['user_id']; }
            if (!empty($filtres['action'])) { $sql .= ' AND action LIKE ?'; $params[] = '%' . $filtres['action'] . '%'; }
            if (!empty($filtres['date_debut'])) { $sql .= ' AND created_at >= ?'; $params[] = $filtres['date_debut']; }
            if (!empty($filtres['date_fin'])) { $sql .= ' AND created_at <= ?'; $params[] = $filtres['date_fin'] . ' 23:59:59'; }
            // Scoper au journal de l'établissement courant pour un admin (un super_admin
            // voit l'audit de tous les établissements). Cf. resoudreUtilisateur().
            [$etabClause, $etabParams] = $this->etablissementScope('audit_log');
            $sql .= $etabClause;
            $params = array_merge($params, $etabParams);
            $sql .= ' ORDER BY created_at DESC LIMIT 500';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[rgpd audit] getAuditLogs: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Construit le filtre d'établissement pour une table donnée.
     * - Un super_admin n'est PAS scopé (visibilité cross-établissement).
     * - Un admin (ou autre rôle) est scopé sur l'établissement courant.
     * - Si la colonne `etablissement_id` n'existe pas sur la table (schéma non
     *   migré), AUCUN filtre n'est appliqué : cela évite toute 500 « Unknown
     *   column » tant que la migration (Database/install.sql) n'est pas passée.
     * Retourne [clauseSQL, paramsBind] ; clause vide si pas de scope applicable.
     */
    private function etablissementScope(string $table, string $alias = ''): array
    {
        if (function_exists('isSuperAdmin') && isSuperAdmin()) {
            return ['', []];
        }
        if (!$this->columnExists($table, 'etablissement_id')) {
            return ['', []];
        }
        try {
            $eid = \API\Core\EstablishmentContext::id();
            $col = ($alias !== '' ? $alias . '.' : '') . 'etablissement_id';
            return [' AND ' . $col . ' = ?', [$eid]];
        } catch (\Throwable $e) {
            // FAIL-CLOSED : contexte établissement non résolu (ambigu/absent) pour un
            // non-super-admin → ne rien exposer plutôt que de retirer le cloisonnement.
            return [' AND 1 = 0', []];
        }
    }

    /** Cache des colonnes existantes par table (évite N requêtes information_schema). */
    private array $columnCache = [];

    /**
     * Indique si une colonne existe sur une table du schéma courant.
     * Résilient : en cas d'erreur d'introspection, renvoie false (= pas de scope,
     * jamais de 500).
     */
    private function columnExists(string $table, string $column): bool
    {
        if (!isset($this->columnCache[$table])) {
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
                );
                $stmt->execute([$table]);
                $this->columnCache[$table] = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            } catch (\Throwable $e) {
                $this->columnCache[$table] = [];
            }
        }
        return in_array($column, $this->columnCache[$table], true);
    }

    public function getAuditStats(): array
    {
        return $this->getAuditStatsProper();
    }

    public function getAuditStatsProper(): array
    {
        // Comme getAuditLogs(), ne jamais provoquer de 500 : sur erreur on rend
        // des compteurs à zéro et on trace côté serveur.
        try {
            $today = date('Y-m-d');
            $month = date('Y-m');

            // Mêmes règles de scope que getAuditLogs() : un admin ne voit que son
            // établissement, un super_admin voit tout.
            [$etabClause, $etabParams] = $this->etablissementScope('audit_log');

            $stmtToday = $this->pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE DATE(created_at) = ?" . $etabClause);
            $stmtToday->execute(array_merge([$today], $etabParams));
            $todayCount = (int)$stmtToday->fetchColumn();

            $stmtTotal = $this->pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE 1=1" . $etabClause);
            $stmtTotal->execute($etabParams);
            $totalCount = (int)$stmtTotal->fetchColumn();

            $stmtMonth = $this->pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE created_at LIKE ?" . $etabClause);
            $stmtMonth->execute(array_merge([$month . '%'], $etabParams));
            $monthCount = (int)$stmtMonth->fetchColumn();

            return [
                'total' => $totalCount,
                'today' => $todayCount,
                'month' => $monthCount,
            ];
        } catch (\Throwable $e) {
            error_log('[rgpd audit] getAuditStatsProper: ' . $e->getMessage());
            return ['total' => 0, 'today' => 0, 'month' => 0];
        }
    }

    /**
     * Export audit logs CSV
     */
    public function exportAuditCSV(array $filtres = []): array
    {
        $logs = $this->getAuditLogs($filtres);
        $rows = [];
        foreach ($logs as $l) {
            $rows[] = [
                'date' => $l['created_at'] ?? $l['date'] ?? '',
                'user' => ($l['user_type'] ?? '') . ' #' . ($l['user_id'] ?? ''),
                'action' => $l['action'] ?? '',
                'details' => $l['details'] ?? $l['description'] ?? '',
                'ip' => $l['ip_address'] ?? $l['ip'] ?? '',
            ];
        }
        return $rows;
    }

    /* ───────── CONSENTEMENTS ───────── */

    public function getConsentements(int $userId, string $userType): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM rgpd_consentements WHERE user_id = ? AND user_type = ? ORDER BY type_consentement");
        $stmt->execute([$userId, $userType]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $types = self::typesConsentement();
        $result = [];
        $existing = array_column($rows, null, 'type_consentement');
        foreach ($types as $key => $label) {
            $result[$key] = $existing[$key] ?? [
                'type_consentement' => $key,
                'consenti' => 0,
                'date_consentement' => null,
            ];
        }
        return $result;
    }

    /**
     * Point d'application des consentements. Un traitement conditionné (photo, géoloc,
     * données médicales) doit consulter ces méthodes AVANT d'agir.
     * consentementRefuse() = l'utilisateur a EXPLICITEMENT retiré son consentement
     * (enregistrement consenti=0). On honore les refus explicites sans bloquer par défaut
     * les comptes sans enregistrement (rétro-compat) — c'est le cas qui a une valeur juridique.
     */
    public function consentementRefuse(int $userId, string $userType, string $type): bool
    {
        try {
            $stmt = $this->pdo->prepare("SELECT 1 FROM rgpd_consentements WHERE user_id = ? AND user_type = ? AND type_consentement = ? AND consenti = 0 LIMIT 1");
            $stmt->execute([$userId, $userType, $type]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Vrai seulement si le consentement est ACCORDÉ (enregistrement consenti=1). */
    public function aConsentement(int $userId, string $userType, string $type): bool
    {
        try {
            $stmt = $this->pdo->prepare("SELECT consenti FROM rgpd_consentements WHERE user_id = ? AND user_type = ? AND type_consentement = ? LIMIT 1");
            $stmt->execute([$userId, $userType, $type]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function sauvegarderConsentement(int $userId, string $userType, string $type, bool $consenti, ?string $ip = null): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO rgpd_consentements (user_id, user_type, type_consentement, consenti, date_consentement, ip_address)
            VALUES (?, ?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE consenti = VALUES(consenti),
                date_consentement = IF(VALUES(consenti) = 1, NOW(), date_consentement),
                date_retrait = IF(VALUES(consenti) = 0, NOW(), NULL),
                ip_address = VALUES(ip_address)
        ");
        $stmt->execute([$userId, $userType, $type, $consenti ? 1 : 0, $ip]);
    }

    public function sauvegarderConsentements(int $userId, string $userType, array $consentements, ?string $ip = null): void
    {
        $types = self::typesConsentement();
        foreach ($types as $key => $label) {
            $consenti = isset($consentements[$key]);
            $this->sauvegarderConsentement($userId, $userType, $key, $consenti, $ip);
        }
    }

    /* ───────── DEMANDES RGPD ───────── */

    public function creerDemande(int $userId, string $userType, string $type, ?string $description): int
    {
        // Renseigner etablissement_id à la création pour cloisonner la demande
        // (quand la colonne existe). Sinon, INSERT sur les colonnes historiques.
        if ($this->columnExists('rgpd_demandes', 'etablissement_id') && ($eid = $this->currentEtablissementId()) !== null) {
            $stmt = $this->pdo->prepare("
                INSERT INTO rgpd_demandes (user_id, user_type, type_demande, description, etablissement_id)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $userType, $type, $description, $eid]);
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO rgpd_demandes (user_id, user_type, type_demande, description)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $userType, $type, $description]);
        }
        return (int)$this->pdo->lastInsertId();
    }

    public function getDemandes(array $filtres = []): array
    {
        $sql = "SELECT d.*, 
                COALESCE(
                    (SELECT CONCAT(prenom, ' ', nom) FROM administrateurs WHERE id = d.user_id AND d.user_type = 'administrateur'),
                    (SELECT CONCAT(prenom, ' ', nom) FROM professeurs WHERE id = d.user_id AND d.user_type = 'professeur'),
                    (SELECT CONCAT(prenom, ' ', nom) FROM eleves WHERE id = d.user_id AND d.user_type = 'eleve'),
                    (SELECT CONCAT(prenom, ' ', nom) FROM parents WHERE id = d.user_id AND d.user_type = 'parent'),
                    (SELECT CONCAT(prenom, ' ', nom) FROM vie_scolaire WHERE id = d.user_id AND d.user_type = 'vie_scolaire')
                ) AS demandeur_nom,
                COALESCE(
                    (SELECT identifiant FROM administrateurs WHERE id = d.user_id AND d.user_type = 'administrateur'),
                    (SELECT identifiant FROM professeurs WHERE id = d.user_id AND d.user_type = 'professeur'),
                    (SELECT identifiant FROM eleves WHERE id = d.user_id AND d.user_type = 'eleve'),
                    (SELECT identifiant FROM parents WHERE id = d.user_id AND d.user_type = 'parent'),
                    (SELECT identifiant FROM vie_scolaire WHERE id = d.user_id AND d.user_type = 'vie_scolaire')
                ) AS demandeur_identifiant
                FROM rgpd_demandes d WHERE 1=1";
        $params = [];
        if (!empty($filtres['statut'])) { $sql .= ' AND d.statut = ?'; $params[] = $filtres['statut']; }
        if (!empty($filtres['type_demande'])) { $sql .= ' AND d.type_demande = ?'; $params[] = $filtres['type_demande']; }
        // Cloisonnement : un admin ne voit que les demandes de son établissement
        // (un super_admin voit tout). Préfixe `d.` car la table est aliasée.
        [$etabClause, $etabParams] = $this->etablissementScope('rgpd_demandes', 'd');
        $sql .= $etabClause;
        $params = array_merge($params, $etabParams);
        $sql .= ' ORDER BY d.date_demande DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDemande(int $id): ?array
    {
        // Cloisonnement : empêche de lire la demande d'un autre établissement.
        [$etabClause, $etabParams] = $this->etablissementScope('rgpd_demandes');
        $stmt = $this->pdo->prepare("SELECT * FROM rgpd_demandes WHERE id = ?" . $etabClause);
        $stmt->execute(array_merge([$id], $etabParams));
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Résout un identifiant humain (login nom.prenom ou mail) vers l'id interne
     * d'un type d'utilisateur donné. Retourne null si introuvable / ambigu.
     * Mirroir du resolver d'auth (UserProvider: WHERE mail = ? OR identifiant = ?).
     */
    public function resoudreUtilisateur(string $identifiant, string $userType): ?int
    {
        $tableMap = [
            'eleve' => 'eleves', 'professeur' => 'professeurs',
            'parent' => 'parents', 'administrateur' => 'administrateurs',
            'vie_scolaire' => 'vie_scolaire',
        ];
        $table = $tableMap[$userType] ?? null;
        if (!$table) return null;
        $identifiant = trim($identifiant);
        if ($identifiant === '') return null;
        // Scoper à l'établissement courant : l'identifiant n'est unique que par
        // (identifiant, etablissement_id) ; sans ce filtre, un même login présent dans
        // deux établissements ferait échouer le contrôle d'ambiguïté (LIMIT 2).
        $params = [$identifiant, $identifiant];
        $etabClause = '';
        try { $eid = \API\Core\EstablishmentContext::id(); $etabClause = ' AND etablissement_id = ?'; $params[] = $eid; } catch (\Throwable $e) {}
        $stmt = $this->pdo->prepare("SELECT id FROM `{$table}` WHERE (identifiant = ? OR mail = ?){$etabClause} LIMIT 2");
        $stmt->execute($params);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) !== 1) return null; // introuvable ou ambigu
        return (int)$ids[0];
    }

    public function traiterDemande(int $id, string $statut, ?string $reponse, int $traiteParId): void
    {
        // Cloisonnement : un admin ne peut traiter qu'une demande de son
        // établissement (la clause AND etablissement_id = ? rend l'UPDATE inopérant
        // sur une demande d'un autre établissement).
        [$etabClause, $etabParams] = $this->etablissementScope('rgpd_demandes');
        $stmt = $this->pdo->prepare("UPDATE rgpd_demandes SET statut = ?, reponse = ?, traite_par = ?, date_traitement = NOW() WHERE id = ?" . $etabClause);
        $stmt->execute(array_merge([$statut, $reponse, $traiteParId, $id], $etabParams));
    }

    public function getMesDemandesUser(int $userId, string $userType): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM rgpd_demandes WHERE user_id = ? AND user_type = ? ORDER BY date_demande DESC");
        $stmt->execute([$userId, $userType]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ───────── EXPORT DONNÉES UTILISATEUR (Droit d'accès) ───────── */

    /**
     * Collecte TOUTES les données d'un utilisateur pour export (Art. 15 RGPD)
     */
    public function exporterDonneesUtilisateur(int $userId, string $userType): array
    {
        $pdo = $this->pdo;
        $data = ['meta' => [
            'export_date' => date('Y-m-d H:i:s'),
            'user_id' => $userId,
            'user_type' => $userType,
            'rgpd_article' => 'Article 15 - Droit d\'accès',
        ]];

        // 1. Profil utilisateur
        $tableMap = [
            'eleve' => 'eleves', 'professeur' => 'professeurs',
            'parent' => 'parents', 'administrateur' => 'administrateurs',
            'vie_scolaire' => 'vie_scolaire',
        ];
        $table = $tableMap[$userType] ?? null;
        if ($table) {
            $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
            $stmt->execute([$userId]);
            $profil = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($profil) {
                // Masquer les secrets d'authentification : hash mot de passe ET secret TOTP
                // (un export contenant le secret 2FA permettrait de générer des codes valides).
                unset(
                    $profil['mot_de_passe'], $profil['password'], $profil['password_hash'],
                    $profil['two_factor_secret'], $profil['two_factor_recovery_codes']
                );
                $data['profil'] = $profil;
            }
        }

        // 2. Consentements
        $data['consentements'] = $this->getConsentements($userId, $userType);

        // 3. Demandes RGPD
        $data['demandes_rgpd'] = $this->getMesDemandesUser($userId, $userType);

        // 4. Données spécifiques par type
        if ($userType === 'eleve') {
            // Notes
            $data['notes'] = $this->collecte('notes',
                "SELECT n.*, m.nom AS matiere FROM notes n LEFT JOIN matieres m ON n.id_matiere = m.id WHERE n.id_eleve = ? ORDER BY n.date_note DESC",
                [$userId]);

            // Absences
            $data['absences'] = $this->collecte('absences',
                "SELECT * FROM absences WHERE id_eleve = ? ORDER BY date_debut DESC",
                [$userId]);

            // Retards (la colonne de liaison est `id_eleve`, pas `eleve_id`).
            $data['retards'] = $this->collecte('retards',
                "SELECT * FROM retards WHERE id_eleve = ? ORDER BY date_retard DESC",
                [$userId]);

            // Incidents
            $data['incidents'] = $this->collecte('incidents',
                "SELECT * FROM incidents WHERE eleve_id = ? ORDER BY date_incident DESC",
                [$userId]);

            // Bulletins
            $data['bulletins'] = $this->collecte('bulletins',
                "SELECT * FROM bulletins WHERE eleve_id = ? ORDER BY id DESC",
                [$userId]);

            // Inscriptions : la table `inscriptions` n'a pas de colonne `eleve_id` ;
            // la liaison se fait sur l'identité (nom/prénom/date de naissance) de
            // l'élève, qui est le sujet de l'inscription.
            $data['inscriptions'] = $this->collecte('inscriptions',
                "SELECT i.* FROM inscriptions i
                 JOIN eleves e ON e.id = ?
                 WHERE i.nom_eleve = e.nom AND i.prenom_eleve = e.prenom AND i.date_naissance = e.date_naissance
                 ORDER BY i.id DESC",
                [$userId]);

            // Déchiffrement at-rest (Art. 9) : tous les champs médicaux libres sont chiffrés en
            // base. L'export du sujet lui-même doit les rendre en clair. Helper partagé pour les
            // 3 collections santé (sinon export = charabia chiffré).
            $enc = \API\Core\Encryption::available() ? new \API\Core\Encryption() : null;
            $decryptFields = function (?array $rows, array $fields) use ($enc): ?array {
                if (!$enc || empty($rows)) return $rows;
                foreach ($rows as &$row) {
                    foreach ($fields as $f) {
                        if (!empty($row[$f]) && $enc->isEncrypted($row[$f])) {
                            try { $row[$f] = $enc->decrypt($row[$f]); } catch (\Throwable $e) {}
                        }
                    }
                }
                unset($row);
                return $rows;
            };

            // Fiche santé (RGPD Art. 9) — déchiffrée pour l'export du sujet lui-même.
            $fiche = $this->collecte('fiche_sante',
                "SELECT allergies, traitements, antecedents, medecin_traitant, telephone_urgence,
                        contact_urgence, groupe_sanguin, pai, pai_details, observations, vaccinations,
                        pathologies, date_modification
                 FROM fiches_sante WHERE eleve_id = ?",
                [$userId]);
            $data['fiche_sante'] = $decryptFields($fiche,
                ['allergies', 'traitements', 'contact_urgence', 'pai', 'observations', 'pathologies', 'antecedents', 'pai_details']);

            // Passages infirmerie (Art.9)
            $passages = $this->collecte('passages_infirmerie',
                "SELECT date_passage, motif, symptomes, soins_prodigues, orientation
                 FROM passages_infirmerie WHERE eleve_id = ? ORDER BY date_passage DESC",
                [$userId]);
            $data['passages_infirmerie'] = $decryptFields($passages, ['symptomes', 'soins_prodigues']);

            // Traitements infirmerie (Art.9)
            $trait = $this->collecte('traitements_infirmerie',
                "SELECT * FROM infirmerie_traitements WHERE eleve_id = ? ORDER BY id DESC", [$userId]);
            $data['traitements_infirmerie'] = $decryptFields($trait, ['medicament', 'posologie']);

            // Diplômes obtenus
            $data['diplomes'] = $this->collecte('diplomes',
                "SELECT * FROM diplomes WHERE eleve_id = ? ORDER BY date_obtention DESC", [$userId]);

            // Accessibilité / handicap (Art.9) : aménagements, dossier MDPH, réunions ESS
            $data['accessibilite_amenagements'] = $this->collecte('accessibilite_amenagements',
                "SELECT * FROM accessibilite_amenagements WHERE eleve_id = ?", [$userId]);
            $data['accessibilite_mdph'] = $this->collecte('accessibilite_mdph',
                "SELECT * FROM accessibilite_mdph WHERE eleve_id = ?", [$userId]);
            $data['accessibilite_ess'] = $this->collecte('accessibilite_ess',
                "SELECT * FROM accessibilite_ess WHERE eleve_id = ?", [$userId]);

            // Garderie / périscolaire
            $data['garderie_inscriptions'] = $this->collecte('garderie_inscriptions',
                "SELECT * FROM garderie_inscriptions WHERE eleve_id = ? ORDER BY id DESC", [$userId]);
            // Cantine, bourses, stages (données personnelles de l'élève). collecte() tolère
            // les tables absentes du déploiement (retourne []).
            $data['cantine_reservations'] = $this->collecte('cantine_reservations',
                "SELECT * FROM cantine_reservations WHERE eleve_id = ? ORDER BY id DESC", [$userId]);
            $data['bourses_demandes'] = $this->collecte('bourses_demandes',
                "SELECT * FROM bourses_demandes WHERE eleve_id = ? ORDER BY id DESC", [$userId]);
            $data['stages'] = $this->collecte('stages',
                "SELECT * FROM stages WHERE eleve_id = ? ORDER BY id DESC", [$userId]);
        }

        if ($userType === 'parent') {
            // Factures (la colonne de tri est `created_at`, pas `date_creation`).
            $data['factures'] = $this->collecte('factures',
                "SELECT * FROM factures WHERE parent_id = ? ORDER BY created_at DESC",
                [$userId]);
        }

        // 5. Messages (tous types)
        $data['messages_envoyes'] = $this->collecte('messages_envoyes',
            "SELECT m.id, m.body, m.created_at, c.subject
             FROM messages m
             JOIN conversations c ON m.conversation_id = c.id
             WHERE m.sender_id = ? AND m.sender_type = ? AND m.is_deleted = 0
             ORDER BY m.created_at DESC",
            [$userId, $userType]);

        // 6. Sessions / connexions — on N'EXPORTE PAS la colonne `id` (= identifiant de
        // session PHP actif) : un export contenant des tokens de session vivants permettrait
        // un détournement de session. On ne fournit que les métadonnées (transparence RGPD).
        $data['sessions'] = $this->collecte('sessions',
            "SELECT user_id, user_type, ip_address, user_agent, is_active, created_at, last_activity, expires_at
             FROM session_security WHERE user_id = ? AND user_type = ? ORDER BY created_at DESC LIMIT 50",
            [$userId, $userType]);

        // 7. Audit logs relatifs
        $data['audit_trail'] = $this->collecte('audit_trail',
            "SELECT action, details, ip_address, created_at FROM audit_log WHERE user_id = ? AND user_type = ? ORDER BY created_at DESC LIMIT 200",
            [$userId, $userType]);

        return $data;
    }

    /**
     * Exécute une requête de collecte pour l'export Art. 15 et renvoie ses lignes.
     * En cas d'échec (table/colonne absente, etc.) : NE PAS échouer silencieusement
     * — on trace via error_log ET on renvoie un marqueur visible dans l'export
     * afin que l'amputation soit signalée à l'utilisateur / au DPO, au lieu d'un
     * simple tableau vide indiscernable d'une absence réelle de données.
     *
     * @return array Liste des lignes, ou [['_erreur' => 'collecte partielle', ...]] sur échec.
     */
    private function collecte(string $section, string $sql, array $params): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("[rgpd export] section '{$section}' : " . $e->getMessage());
            return [[
                '_erreur'  => 'collecte partielle',
                '_section' => $section,
                '_detail'  => 'Donnees non collectees suite a une erreur technique (voir journaux serveur).',
            ]];
        }
    }

    /* ───────── ANONYMISATION (Droit à l'oubli) ───────── */

    /**
     * Anonymise un utilisateur (Art. 17 RGPD)
     * Remplace les données personnelles par des valeurs anonymes
     * Conserve les données statistiques (notes, absences) sans identification
     */
    public function anonymiserUtilisateur(int $userId, string $userType, int $adminId): array
    {
        $pdo = $this->pdo;
        $anonymId = 'ANON_' . strtoupper(bin2hex(random_bytes(4)));
        $actions = [];

        $tableMap = [
            'eleve' => 'eleves', 'professeur' => 'professeurs',
            'parent' => 'parents', 'administrateur' => 'administrateurs',
            'vie_scolaire' => 'vie_scolaire',
        ];
        $table = $tableMap[$userType] ?? null;
        if (!$table) return ['error' => 'Type utilisateur inconnu'];

        try {
            $pdo->beginTransaction();

            // 0. Élève : anonymiser la table `inscriptions` (nom/prénom/date de
            // naissance + contacts parents en clair) AVANT d'écraser le profil —
            // la liaison se fait sur l'identité de l'élève, perdue après le UPDATE.
            if ($userType === 'eleve') {
                try {
                    $idn = $pdo->prepare("SELECT nom, prenom, date_naissance FROM eleves WHERE id = ?");
                    $idn->execute([$userId]);
                    if ($who = $idn->fetch(\PDO::FETCH_ASSOC)) {
                        $inscCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inscriptions'")->fetchAll(\PDO::FETCH_COLUMN);
                        $inscMap = [
                            'nom_eleve' => $anonymId, 'prenom_eleve' => 'Anonyme', 'adresse' => null,
                            'nom_parent1' => 'Anonyme', 'telephone_parent1' => null, 'email_parent1' => null,
                            'nom_parent2' => 'Anonyme', 'telephone_parent2' => null, 'email_parent2' => null,
                            'telephone' => null, 'email_contact' => null, 'observations' => null,
                        ];
                        $set = []; $vals = [];
                        foreach ($inscMap as $col => $val) {
                            if (!in_array($col, $inscCols, true)) continue;
                            if ($val === null) { $set[] = "`$col` = NULL"; }
                            else { $set[] = "`$col` = ?"; $vals[] = $val; }
                        }
                        if ($set) {
                            $scopeInsc = in_array('etablissement_id', $inscCols, true) ? ' AND etablissement_id = ?' : '';
                            $vals[] = $who['nom']; $vals[] = $who['prenom']; $vals[] = $who['date_naissance'];
                            if ($scopeInsc !== '') { $vals[] = $this->currentEtablissementId(); }
                            $upd = $pdo->prepare("UPDATE inscriptions SET " . implode(', ', $set)
                                . " WHERE nom_eleve = ? AND prenom_eleve = ? AND date_naissance = ?" . $scopeInsc);
                            $upd->execute($vals);
                            $actions[] = "Inscriptions anonymisées (" . $upd->rowCount() . ")";
                        }
                    }
                } catch (\Throwable $e) {
                    // Droit à l'oubli : l'échec est FATAL — on remonte pour annuler la
                    // transaction, plutôt que de committer et prétendre au succès.
                    error_log('[rgpd anonymisation] inscriptions user=' . $userId . ' : ' . $e->getMessage());
                    throw $e;
                }
            }

            // 1. Anonymiser le profil.
            // Les tables utilisateurs n'ont pas toutes les mêmes colonnes (ex.
            // administrateurs n'a pas `telephone`, vie_scolaire n'a pas `adresse`,
            // aucune n'a `photo`). On construit donc le SET dynamiquement à partir
            // des colonnes réellement présentes, sinon l'UPDATE échouait → rollback →
            // droit à l'oubli (Art. 17 RGPD) jamais appliqué.
            $stmtCols = $pdo->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $stmtCols->execute([$table]);
            $existingCols = $stmtCols->fetchAll(\PDO::FETCH_COLUMN);

            // Colonne => valeur (NULL pour effacer, chaîne pour pseudonymiser).
            $anonFields = [
                'nom'          => $anonymId,
                'prenom'       => 'Anonyme',
                'mail'         => $anonymId . '@anonymise.rgpd',
                'identifiant'  => $anonymId,
                'telephone'    => null,
                'adresse'      => null,
                'mot_de_passe' => '',
                'actif'        => 0,
                'photo'        => null,
            ];

            $setParts = [];
            $params = [];
            foreach ($anonFields as $col => $val) {
                if (!in_array($col, $existingCols, true)) {
                    continue; // colonne absente sur cette table → on saute
                }
                if ($val === null) {
                    $setParts[] = "`{$col}` = NULL";
                } else {
                    $setParts[] = "`{$col}` = ?";
                    $params[] = $val;
                }
            }
            if (empty($setParts)) {
                throw new \RuntimeException("Aucune colonne anonymisable trouvée pour {$table}");
            }
            $params[] = $userId;

            $stmt = $pdo->prepare("UPDATE `{$table}` SET " . implode(', ', $setParts) . " WHERE id = ?");
            $stmt->execute($params);
            $actions[] = "Profil anonymisé dans {$table}";

            // 2. Supprimer consentements
            $stmt = $pdo->prepare("DELETE FROM rgpd_consentements WHERE user_id = ? AND user_type = ?");
            $stmt->execute([$userId, $userType]);
            $actions[] = "Consentements supprimés";

            // Suivi fail-safe : tout échec d'effacement d'une donnée personnelle rend
            // l'anonymisation PARTIELLE → success=false (l'ancien code renvoyait toujours
            // true en laissant potentiellement des données en clair).
            $hadFailure = false;

            // 3. Supprimer messages
            try {
                $stmt = $pdo->prepare("UPDATE messages SET body = '[Message supprimé - RGPD]', is_deleted = 1 WHERE sender_id = ? AND sender_type = ?");
                $stmt->execute([$userId, $userType]);
                $actions[] = "Messages anonymisés";
            } catch (\Exception $e) {
                $hadFailure = true;
                error_log('[rgpd anonymisation] échec messages user=' . $userId . ' : ' . $e->getMessage());
                $actions[] = "ÉCHEC anonymisation messages : " . $e->getMessage();
            }

            // 3b. Effacement du DOSSIER MÉDICAL / MDPH + données périscolaires (Art.9 + Art.17)
            // pour un élève. Ordre FK-safe (enfants d'abord). Chaque échec est tracé ET rend
            // l'opération partielle (fail-safe). Les DELETE sur tables absentes sont tolérés
            // (catch par requête) sans marquer d'échec.
            if ($userType === 'eleve') {
                $eleveDeletes = [
                    "DELETE FROM infirmerie_prises WHERE traitement_id IN (SELECT id FROM infirmerie_traitements WHERE eleve_id = ?)",
                    "DELETE FROM infirmerie_traitements WHERE eleve_id = ?",
                    "DELETE FROM passages_infirmerie WHERE eleve_id = ?",
                    "DELETE FROM fiches_sante WHERE eleve_id = ?",
                    "DELETE FROM accessibilite_amenagements WHERE eleve_id = ?",
                    "DELETE FROM accessibilite_mdph WHERE eleve_id = ?",
                    "DELETE FROM accessibilite_ess WHERE eleve_id = ?",
                    // Périscolaire (données personnelles de mineurs) :
                    "DELETE FROM garderie_presences WHERE inscription_id IN (SELECT id FROM garderie_inscriptions WHERE eleve_id = ?)",
                    "DELETE FROM garderie_inscriptions WHERE eleve_id = ?",
                    "DELETE FROM cantine_reservations WHERE eleve_id = ?",
                    // Pièces jointes / justificatifs de l'élève :
                    "DELETE FROM justificatifs WHERE id_eleve = ?",
                ];
                $eleveOk = true;
                foreach ($eleveDeletes as $sql) {
                    try {
                        $pdo->prepare($sql)->execute([$userId]);
                    } catch (\Throwable $e) {
                        $msg = $e->getMessage();
                        // Table simplement absente du déploiement → tolérer sans échec.
                        if (stripos($msg, "doesn't exist") !== false || stripos($msg, 'Unknown column') !== false) {
                            continue;
                        }
                        $eleveOk = false; $hadFailure = true;
                        error_log('[rgpd anonymisation] échec effacement élève user=' . $userId . ' : ' . $msg);
                        $actions[] = "ÉCHEC effacement donnée élève : " . $msg;
                    }
                }
                $actions[] = $eleveOk ? "Dossier médical/MDPH + périscolaire + PJ supprimés (Art.9/Art.17)" : "Effacement élève PARTIEL — voir échecs";
            }

            // 4. Supprimer sessions
            try {
                $stmt = $pdo->prepare("DELETE FROM session_security WHERE user_id = ? AND user_type = ?");
                $stmt->execute([$userId, $userType]);
                $actions[] = "Sessions supprimées";
            } catch (\Exception $e) {
                $hadFailure = true;
                error_log('[rgpd anonymisation] échec sessions user=' . $userId . ' : ' . $e->getMessage());
                $actions[] = "ÉCHEC suppression sessions : " . $e->getMessage();
            }

            // 5. Log l'action
            $this->logAudit($adminId, 'administrateur', 'rgpd_anonymisation',
                "Anonymisation de {$userType} #{$userId} → {$anonymId}" . ($hadFailure ? ' [PARTIEL]' : ''));

            $pdo->commit();
            $actions[] = $hadFailure ? "Anonymisation PARTIELLE (échecs ci-dessus)" : "Anonymisation complète";
        } catch (\Exception $e) {
            $pdo->rollBack();
            return ['error' => $e->getMessage()];
        }

        return ['success' => !$hadFailure, 'partial' => $hadFailure, 'anonym_id' => $anonymId, 'actions' => $actions];
    }

    /* ───────── RÉTENTION DES DONNÉES ───────── */

    /**
     * Politiques de rétention par défaut (en jours)
     */
    public static function retentionDefaults(): array
    {
        return [
            'audit_log'             => ['label' => 'Logs d\'audit', 'duree' => 365, 'obligatoire' => true],
            'session_security'      => ['label' => 'Sessions de connexion', 'duree' => 90, 'obligatoire' => false],
            'messages'              => ['label' => 'Messages supprimés', 'duree' => 180, 'obligatoire' => false],
            'notifications_globales' => ['label' => 'Notifications lues', 'duree' => 90, 'obligatoire' => false],
            'rate_limits'           => ['label' => 'Logs rate limiting', 'duree' => 30, 'obligatoire' => false],
            // Rétention scolaire (RGPD Art.5§1e — limitation de conservation). OPT-IN
            // (actif=false par défaut) : l'établissement fixe sa durée légale et l'active
            // sciemment. Ne JAMAIS auto-purger des données scolaires par défaut.
            'notes'                 => ['label' => 'Notes (scolarité)', 'duree' => 1825, 'obligatoire' => false, 'optin' => true],
            'absences'              => ['label' => 'Absences (scolarité)', 'duree' => 1825, 'obligatoire' => false, 'optin' => true],
            'retards'               => ['label' => 'Retards (scolarité)', 'duree' => 1825, 'obligatoire' => false, 'optin' => true],
            'incidents'             => ['label' => 'Incidents disciplinaires', 'duree' => 1825, 'obligatoire' => false, 'optin' => true],
        ];
    }

    /**
     * Récupère les politiques de rétention (DB ou défaut)
     */
    public function getRetentionPolicies(): array
    {
        $defaults = self::retentionDefaults();
        try {
            $rows = $this->pdo->query("SELECT * FROM rgpd_retention_policies")->fetchAll(PDO::FETCH_ASSOC);
            $existing = array_column($rows, null, 'table_name');
            foreach ($defaults as $key => &$def) {
                if (isset($existing[$key])) {
                    $def['duree'] = (int)$existing[$key]['retention_days'];
                    $def['actif'] = (bool)$existing[$key]['actif'];
                    $def['derniere_purge'] = $existing[$key]['derniere_purge'];
                } else {
                    // Défaut : actif sauf pour les politiques OPT-IN (scolarité) qui exigent
                    // une activation explicite par l'établissement.
                    $def['actif'] = !($def['optin'] ?? false);
                    $def['derniere_purge'] = null;
                }
            }
        } catch (\Exception $e) {
            // Table doesn't exist yet, use defaults
            foreach ($defaults as &$def) {
                $def['actif'] = !($def['optin'] ?? false);
                $def['derniere_purge'] = null;
            }
        }
        return $defaults;
    }

    /**
     * Sauvegarde une politique de rétention
     */
    public function sauvegarderRetentionPolicy(string $tableName, int $dureeJours, bool $actif): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO rgpd_retention_policies (table_name, retention_days, actif)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE retention_days = VALUES(retention_days), actif = VALUES(actif)
        ");
        $stmt->execute([$tableName, $dureeJours, $actif ? 1 : 0]);
    }

    /**
     * Exécute la purge selon les politiques de rétention.
     *
     * @param int|null $etablissementId Quand fourni (appel admin depuis l'UI),
     *   la purge est CLOISONNÉE à cet établissement : chaque DELETE reçoit
     *   « AND etablissement_id = ? », et les tables globales sans cette colonne
     *   (rate_limits, session_security) sont IGNORÉES. Empêche un admin
     *   d'établissement de purger les données d'un autre établissement.
     *   Null = purge système globale (cron CLI de maintenance).
     */
    public function executerPurge(?int $etablissementId = null): array
    {
        $policies = $this->getRetentionPolicies();
        $results = [];
        // PLANCHER SERVEUR (minimum légal de conservation). Une table marquée
        // 'obligatoire' (ex. audit_log : 365 j) ne peut JAMAIS être purgée en-deçà
        // de la durée par défaut définie dans le code, même si une policy en base
        // configure une durée plus courte. On applique donc max(durée, plancher)
        // ci-dessous. Les tables non-obligatoires ne sont pas affectées.
        $planchers = [];
        foreach (self::retentionDefaults() as $tbl => $def) {
            if (!empty($def['obligatoire'])) {
                $planchers[$tbl] = (int)$def['duree'];
            }
        }
        // Colonne de date réelle par table (le schéma livré diffère des noms
        // « génériques » : rate_limits.attempted_at, notifications_globales.date_creation).
        $dateColumnMap = [
            'audit_log' => 'created_at',
            'session_security' => 'created_at',
            'messages' => 'created_at',
            'notifications_globales' => 'date_creation',
            'rate_limits' => 'attempted_at',
            // Scolarité (opt-in) :
            'notes' => 'date_note',
            'absences' => 'date_debut',
            'retards' => 'date_retard',
            'incidents' => 'date_incident',
        ];
        $conditionMap = [
            'messages' => 'is_deleted = 1 AND',
            'notifications_globales' => 'lu = 1 AND',
        ];

        foreach ($policies as $table => $policy) {
            if (!$policy['actif']) continue;
            $col = $dateColumnMap[$table] ?? 'created_at';
            $extra = $conditionMap[$table] ?? '';
            // Table obligatoire : ne jamais purger sous le plancher légal, même si la
            // policy DB configure une durée plus courte (max(durée, plancher)).
            $duree = (int)$policy['duree'];
            if (isset($planchers[$table]) && $duree < $planchers[$table]) {
                $duree = $planchers[$table];
            }
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$duree} days"));

            try {
                $params = [$cutoff];
                $scope  = '';
                // Cloisonnement établissement (appel UI) : n'agir que sur les lignes
                // de l'établissement courant. Table globale sans etablissement_id →
                // on l'ignore plutôt que de purger tous les tenants (fail-closed).
                if ($etablissementId !== null) {
                    if (!$this->columnExists($table, 'etablissement_id')) {
                        $results[$table] = ['skipped' => 'table globale sans etablissement_id (non purgée en mode cloisonné)'];
                        continue;
                    }
                    $scope    = ' AND etablissement_id = ?';
                    $params[] = $etablissementId;
                }
                $sql = "DELETE FROM {$table} WHERE {$extra} {$col} < ?{$scope}";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $count = $stmt->rowCount();
                $results[$table] = ['purged' => $count, 'cutoff' => $cutoff];

                // Update derniere_purge
                try {
                    $stmt = $this->pdo->prepare("UPDATE rgpd_retention_policies SET derniere_purge = NOW() WHERE table_name = ?");
                    $stmt->execute([$table]);
                } catch (\Exception $e) {
                    error_log('[AuditRgpdService] maj derniere_purge ' . $table . ' : ' . $e->getMessage());
                }
            } catch (\Exception $e) {
                $results[$table] = ['error' => $e->getMessage()];
            }
        }

        $this->logAudit(0, 'system', 'rgpd_purge', json_encode($results));
        return $results;
    }

    /* ───────── HELPER ───────── */

    private function logAudit(int $userId, string $userType, string $action, string $details): void
    {
        try {
            // Renseigner etablissement_id quand la colonne existe, pour que le
            // journal reste cloisonné par établissement à la relecture.
            if ($this->columnExists('audit_log', 'etablissement_id') && ($eid = $this->currentEtablissementId()) !== null) {
                $stmt = $this->pdo->prepare("INSERT INTO audit_log (user_id, user_type, action, details, ip_address, etablissement_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$userId, $userType, $action, $details, $_SERVER['REMOTE_ADDR'] ?? '', $eid]);
            } else {
                $stmt = $this->pdo->prepare("INSERT INTO audit_log (user_id, user_type, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$userId, $userType, $action, $details, $_SERVER['REMOTE_ADDR'] ?? '']);
            }
        } catch (\Exception $e) { error_log('[rgpd audit] ' . $e->getMessage()); }
    }

    /** Établissement courant ou null si indéterminé (jamais d'exception remontée). */
    private function currentEtablissementId(): ?int
    {
        try {
            return \API\Core\EstablishmentContext::id();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /* ───────── STATIC ───────── */

    public static function typesConsentement(): array
    {
        return [
            'sms' => 'Notifications SMS',
            'email_marketing' => 'Emails d\'information',
            'photo' => 'Droit à l\'image (photos)',
            'donnees_medicales' => 'Données médicales',
            'partage_notes' => 'Partage des notes inter-modules',
            'geolocalisation' => 'Géolocalisation (transports)',
        ];
    }

    public static function typesDemande(): array
    {
        return [
            'acces' => 'Accès aux données',
            'rectification' => 'Rectification',
            'suppression' => 'Suppression (droit à l\'oubli)',
            'portabilite' => 'Portabilité',
            'opposition' => 'Opposition au traitement',
        ];
    }

    public static function statutBadge(string $s): string
    {
        $map = ['en_attente' => 'warning', 'en_cours' => 'info', 'traitee' => 'success', 'refusee' => 'danger'];
        $labels = ['en_attente' => 'En attente', 'en_cours' => 'En cours', 'traitee' => 'Traitée', 'refusee' => 'Refusée'];
        return '<span class="badge badge-' . ($map[$s] ?? 'secondary') . '">' . ($labels[$s] ?? $s) . '</span>';
    }

    // ─── REGISTRE TRAITEMENTS (Art. 30) ───

    public function ajouterTraitement(array $d): int
    {
        $hasEtab = $this->columnExists('rgpd_registre_traitements', 'etablissement_id');
        $eid = $hasEtab ? $this->currentEtablissementId() : null;
        $cols = "nom_traitement, finalite, base_legale, categories_donnees, destinataires, duree_conservation, mesures_securite";
        $vals = ":n, :f, :b, :c, :d, :dur, :m";
        $params = [
            ':n' => $d['nom_traitement'], ':f' => $d['finalite'], ':b' => $d['base_legale'] ?? 'consentement',
            ':c' => $d['categories_donnees'] ?? '', ':d' => $d['destinataires'] ?? '',
            ':dur' => $d['duree_conservation'] ?? '', ':m' => $d['mesures_securite'] ?? '',
        ];
        if ($eid !== null) { $cols .= ", etablissement_id"; $vals .= ", :eid"; $params[':eid'] = $eid; }
        $stmt = $this->pdo->prepare("INSERT INTO rgpd_registre_traitements ({$cols}, created_at) VALUES ({$vals}, NOW())");
        $stmt->execute($params);
        return (int)$this->pdo->lastInsertId();
    }

    public function getRegistreTraitements(): array
    {
        // Cloisonnement par établissement (sauf super_admin).
        [$etabClause, $etabParams] = $this->etablissementScope('rgpd_registre_traitements');
        $sql = "SELECT * FROM rgpd_registre_traitements WHERE 1=1" . $etabClause . " ORDER BY nom_traitement";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($etabParams);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ─── ANALYSE D'IMPACT (AIPD / DPIA) ───

    public function creerAnalyseImpact(string $titre, string $description, int $creerPar): int
    {
        $hasEtab = $this->columnExists('rgpd_analyses_impact', 'etablissement_id');
        $eid = $hasEtab ? $this->currentEtablissementId() : null;
        if ($eid !== null) {
            $stmt = $this->pdo->prepare("INSERT INTO rgpd_analyses_impact (titre, description, cree_par, statut, etablissement_id, created_at) VALUES (:t, :d, :c, 'en_cours', :eid, NOW())");
            $stmt->execute([':t' => $titre, ':d' => $description, ':c' => $creerPar, ':eid' => $eid]);
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO rgpd_analyses_impact (titre, description, cree_par, statut, created_at) VALUES (:t, :d, :c, 'en_cours', NOW())");
            $stmt->execute([':t' => $titre, ':d' => $description, ':c' => $creerPar]);
        }
        return (int)$this->pdo->lastInsertId();
    }

    public function getAnalysesImpact(): array
    {
        // Cloisonnement par établissement (sauf super_admin).
        [$etabClause, $etabParams] = $this->etablissementScope('rgpd_analyses_impact');
        $sql = "SELECT * FROM rgpd_analyses_impact WHERE 1=1" . $etabClause . " ORDER BY created_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($etabParams);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ─── VIOLATIONS DE DONNÉES (Art. 33/34) ───

    public function signalerViolation(array $d): int
    {
        $hasEtab = $this->columnExists('rgpd_violations', 'etablissement_id');
        $eid = $hasEtab ? $this->currentEtablissementId() : null;
        $cols = "titre, description, date_detection, nature, donnees_concernees, nb_personnes, gravite, signale_par, statut";
        $vals = ":t, :desc, :dd, :n, :dc, :nb, :g, :sp, 'detectee'";
        $params = [
            ':t' => $d['titre'], ':desc' => $d['description'], ':dd' => $d['date_detection'] ?? date('Y-m-d'),
            ':n' => $d['nature'] ?? '', ':dc' => $d['donnees_concernees'] ?? '',
            ':nb' => $d['nb_personnes'] ?? 0, ':g' => $d['gravite'] ?? 'moyenne', ':sp' => $d['signale_par'],
        ];
        if ($eid !== null) { $cols .= ", etablissement_id"; $vals .= ", :eid"; $params[':eid'] = $eid; }
        $stmt = $this->pdo->prepare("INSERT INTO rgpd_violations ({$cols}, created_at) VALUES ({$vals}, NOW())");
        $stmt->execute($params);
        return (int)$this->pdo->lastInsertId();
    }

    public function getViolations(?string $statut = null): array
    {
        $sql = "SELECT * FROM rgpd_violations WHERE 1=1";
        $params = [];
        if ($statut) { $sql .= " AND statut = :s"; $params[':s'] = $statut; }
        // Cloisonnement par établissement (sauf super_admin).
        [$etabClause, $etabParams] = $this->etablissementScopeNamed('rgpd_violations');
        $sql .= $etabClause;
        $params = array_merge($params, $etabParams);
        $sql .= " ORDER BY date_detection DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function traiterViolation(int $id, string $statut, ?string $actions = null): void
    {
        // Cloisonnement : un admin ne peut traiter qu'une violation de son
        // établissement.
        [$etabClause, $etabParams] = $this->etablissementScopeNamed('rgpd_violations');
        $params = array_merge([':s' => $statut, ':a' => $actions, ':id' => $id], $etabParams);
        $this->pdo->prepare("UPDATE rgpd_violations SET statut = :s, actions_correctives = :a, date_resolution = NOW() WHERE id = :id" . $etabClause)
            ->execute($params);
    }

    /**
     * Variante de etablissementScope() à placeholders NOMMÉS, pour les requêtes
     * de ce bloc (violations) qui utilisent des paramètres `:nom`.
     * Retourne [clauseSQL, ['param' => valeur]].
     */
    private function etablissementScopeNamed(string $table): array
    {
        if (function_exists('isSuperAdmin') && isSuperAdmin()) {
            return ['', []];
        }
        if (!$this->columnExists($table, 'etablissement_id')) {
            return ['', []];
        }
        try {
            $eid = \API\Core\EstablishmentContext::id();
            return [' AND etablissement_id = :etab_scope', [':etab_scope' => $eid]];
        } catch (\Throwable $e) {
            return ['', []];
        }
    }

    // ─── DASHBOARD CONFORMITÉ ───

    public function getDashboardConformite(): array
    {
        // Cloisonnement par établissement (sauf super_admin) sur les compteurs.
        [$demClause, $demParams] = $this->etablissementScope('rgpd_demandes');
        $stmtD = $this->pdo->prepare("SELECT statut, COUNT(*) AS nb FROM rgpd_demandes WHERE 1=1" . $demClause . " GROUP BY statut");
        $stmtD->execute($demParams);
        $demandes = $stmtD->fetchAll(\PDO::FETCH_KEY_PAIR);

        $consentements = $this->pdo->query("SELECT COUNT(*) AS total, SUM(consenti) AS consented FROM rgpd_consentements")->fetch(\PDO::FETCH_ASSOC);

        [$vioClause, $vioParams] = $this->etablissementScope('rgpd_violations');
        $stmtV = $this->pdo->prepare("SELECT COUNT(*) FROM rgpd_violations WHERE statut != 'resolue'" . $vioClause);
        $stmtV->execute($vioParams);
        $violations = $stmtV->fetchColumn();

        return [
            'demandes' => $demandes,
            'demandes_en_attente' => (int)($demandes['en_attente'] ?? 0),
            'taux_consentement' => $consentements['total'] > 0 ? round((float) ($consentements['consented'] / $consentements['total'] * 100), 1) : 0,
            'violations_ouvertes' => (int)$violations,
            'derniere_purge' => $this->pdo->query("SELECT MAX(derniere_purge) FROM rgpd_retention_policies")->fetchColumn() ?: 'Jamais',
        ];
    }
}
