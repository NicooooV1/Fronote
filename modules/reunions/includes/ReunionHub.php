<?php
declare(strict_types=1);

/**
 * Réunions (refonte 4.6) — tout ce qui n'est pas un cours.
 *
 * Une réunion a un TYPE (catalogue ci-dessous), des PARTICIPANTS (convocation, présence), un
 * ORDRE DU JOUR (points minutés), éventuellement une SÉANCE (démarrée, point en cours, close) et
 * un COMPTE RENDU publié aux participants. Les rencontres parents-professeurs ont en plus des
 * CRÉNEAUX par professeur que les familles réservent (reunion_creneaux / reunion_reservations).
 *
 * Confidentialité : les réunions confidentielles (discipline, commission éducative, équipe
 * éducative, entretien) ne sont visibles que de leurs participants, de la direction et de la CPE.
 */
final class ReunionHub
{
    /** clé => [libellé, icône, couleur, durée (min), confidentiel, description, ordre du jour proposé] */
    public const TYPES = [
        'parents_profs' => ['Rencontres parents-professeurs', 'fa-people-arrows', '#2563eb', 210, false,
            'Soirée de rencontres : chaque professeur ouvre des créneaux, les familles réservent.', []],
        'conseil_classe' => ['Conseil de classe', 'fa-users-rectangle', '#7c3aed', 75, false,
            'Bilan du trimestre de la classe, avis et appréciations.',
            [['Bilan général de la classe (professeur principal)', 10], ['Parole aux délégués des élèves', 5], ['Parole aux délégués des parents', 5], ['Examen des situations individuelles', 45], ['Synthèse et décisions', 10]]],
        'conseil_discipline' => ['Conseil de discipline', 'fa-gavel', '#b91c1c', 90, true,
            'Instance disciplinaire présidée par le chef d\'établissement.',
            [['Ouverture de la séance, rappel des droits de la défense', 5], ['Lecture du rapport et des faits reprochés', 15], ['Audition de l\'élève et de ses responsables', 20], ['Audition des témoins', 15], ['Délibération et vote', 25], ['Notification de la décision', 10]]],
        'commission_educative' => ['Commission éducative', 'fa-scale-balanced', '#c2410c', 60, true,
            'Recherche d\'une réponse éducative personnalisée, avant ou à la place d\'une sanction.',
            [['Présentation de la situation', 10], ['Échange avec l\'élève et sa famille', 20], ['Mesures et engagements', 20], ['Modalités de suivi', 10]]],
        'equipe_educative' => ['Équipe éducative / ESS', 'fa-hands-holding-child', '#0d9488', 60, true,
            'Situation d\'un élève à besoins particuliers : aménagements, PAP, PPS.',
            [['Point sur la scolarité et les difficultés', 15], ['Avis des professionnels (santé, AESH, psy-EN)', 15], ['Aménagements proposés', 20], ['Calendrier de suivi', 10]]],
        'conseil_pedagogique' => ['Conseil pédagogique', 'fa-chalkboard-user', '#0891b2', 90, false,
            'Coordination des enseignements, projets et évaluation.',
            [['Point d\'actualité', 10], ['Progressions et évaluations communes', 30], ['Projets pédagogiques', 30], ['Questions diverses', 20]]],
        'conseil_administration' => ['Conseil d\'administration', 'fa-landmark', '#475569', 120, false,
            'Instance de décision de l\'établissement (budget, règlement, voyages).',
            [['Approbation du procès-verbal précédent', 5], ['Budget et décisions modificatives', 40], ['Voyages et sorties', 25], ['Règlement intérieur', 30], ['Questions diverses', 20]]],
        'reunion_equipe' => ['Réunion d\'équipe', 'fa-people-group', '#4f46e5', 60, false,
            'Équipe pédagogique, de niveau ou de vie scolaire.',
            [['Tour de table', 15], ['Sujets du jour', 35], ['Prochaines étapes', 10]]],
        'entretien' => ['Entretien avec une famille', 'fa-comments', '#db2777', 30, true,
            'Rendez-vous individuel entre l\'établissement et une famille.',
            [['Motif de la rencontre', 10], ['Échange', 15], ['Suite donnée', 5]]],
        'autre' => ['Autre réunion', 'fa-calendar-check', '#64748b', 60, false, 'Réunion libre.', []],
    ];
    public const ROLES = [
        'organisateur' => 'Organisateur', 'president' => 'Président·e', 'secretaire' => 'Secrétaire de séance', 'membre' => 'Membre',
        'professeur' => 'Professeur', 'cpe' => 'CPE', 'eleve' => 'Élève concerné·e', 'famille' => 'Responsable légal',
        'delegue_eleve' => 'Délégué·e des élèves', 'delegue_parent' => 'Délégué·e des parents', 'invite' => 'Invité·e', 'temoin' => 'Témoin',
        'sante' => 'Santé scolaire', 'aesh' => 'AESH',
    ];
    private const USER_TABLES = ['administrateur' => 'administrateurs', 'professeur' => 'professeurs', 'vie_scolaire' => 'vie_scolaire', 'parent' => 'parents', 'eleve' => 'eleves'];

    private PDO $pdo;
    private int $etab;
    private array $names = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->etab = (int) \API\Core\EstablishmentContext::id();
    }

    public static function type(string $key): array
    {
        $t = self::TYPES[$key] ?? self::TYPES['autre'];
        return ['key' => isset(self::TYPES[$key]) ? $key : 'autre', 'label' => $t[0], 'icon' => $t[1], 'color' => $t[2], 'duree' => $t[3], 'confidentiel' => $t[4], 'description' => $t[5], 'points' => $t[6]];
    }

    // ── Utilisateur courant ──────────────────────────────────────────────────
    public static function me(): array
    {
        return ['id' => (int) getUserId(), 'type' => (string) getUserRole()];
    }

    public static function isDirection(): bool { return getUserRole() === 'administrateur' || getUserRole() === 'super_admin'; }
    public static function isVieScolaire(): bool { return getUserRole() === 'vie_scolaire'; }
    public static function canOrganise(): bool { return in_array(getUserRole(), ['administrateur', 'super_admin', 'vie_scolaire', 'professeur'], true); }

    /** Noms d'affichage (lot, mis en cache). */
    public function nameOf(string $type, int $id): string
    {
        $k = "$type:$id";
        if (!isset($this->names[$k])) {
            $t = self::USER_TABLES[$type] ?? null;
            $this->names[$k] = '—';
            if ($t) {
                $st = $this->pdo->prepare("SELECT CONCAT(prenom, ' ', nom) FROM `$t` WHERE id = ?");
                $st->execute([$id]);
                $this->names[$k] = (string) ($st->fetchColumn() ?: '—');
            }
        }
        return $this->names[$k];
    }

    private function preloadNames(array $rows): void
    {
        $by = [];
        foreach ($rows as $r) { $by[$r['user_type']][] = (int) $r['user_id']; }
        foreach ($by as $type => $ids) {
            $t = self::USER_TABLES[$type] ?? null;
            if (!$t || !$ids) { continue; }
            $ids = array_values(array_unique($ids));
            $st = $this->pdo->prepare("SELECT id, CONCAT(prenom, ' ', nom) FROM `$t` WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $n) { $this->names["$type:$id"] = (string) $n; }
        }
    }

    /** Classes d'un utilisateur (enseignées / de ses enfants / la sienne). */
    public function classesOf(array $me): array
    {
        switch ($me['type']) {
            case 'professeur':
                $st = $this->pdo->prepare('SELECT nom_classe FROM professeur_classes WHERE id_professeur = ?');
                $st->execute([$me['id']]);
                return $st->fetchAll(PDO::FETCH_COLUMN);
            case 'parent':
                $st = $this->pdo->prepare('SELECT DISTINCT e.classe FROM parent_eleve pe JOIN eleves e ON e.id = pe.id_eleve WHERE pe.id_parent = ?');
                $st->execute([$me['id']]);
                return $st->fetchAll(PDO::FETCH_COLUMN);
            case 'eleve':
                $st = $this->pdo->prepare('SELECT classe FROM eleves WHERE id = ?');
                $st->execute([$me['id']]);
                return array_filter([(string) $st->fetchColumn()]);
        }
        return [];
    }

    public function enfantsOf(int $parentId): array
    {
        $st = $this->pdo->prepare('SELECT e.id, e.prenom, e.nom, e.classe FROM parent_eleve pe JOIN eleves e ON e.id = pe.id_eleve WHERE pe.id_parent = ? ORDER BY e.prenom');
        $st->execute([$parentId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Lecture ──────────────────────────────────────────────────────────────
    /**
     * Réunions visibles par l'utilisateur, enrichies : type, mon rôle, ma convocation,
     * effectifs, créneaux (parents-professeurs).
     * Filtres : type, quand ('a_venir' | 'passees' | 'toutes'), mes (seulement celles où je suis attendu).
     */
    public function lister(array $f = []): array
    {
        $me = self::me();
        $where = ['r.etablissement_id = ?']; $args = [$this->etab];
        if (!empty($f['type']) && isset(self::TYPES[$f['type']])) { $where[] = 'r.type = ?'; $args[] = $f['type']; }
        $quand = $f['quand'] ?? 'a_venir';
        if ($quand === 'a_venir') { $where[] = "r.date_fin >= NOW() AND r.statut <> 'annulee'"; }
        elseif ($quand === 'passees') { $where[] = 'r.date_fin < NOW()'; }
        $sql = 'SELECT r.*, c.nom AS classe_nom, s.nom AS salle_nom, CONCAT(el.prenom, " ", el.nom) AS eleve_nom,
                   (SELECT COUNT(*) FROM reunion_participants p WHERE p.reunion_id = r.id) AS nb_participants,
                   (SELECT COUNT(*) FROM reunion_participants p WHERE p.reunion_id = r.id AND p.convocation = "confirmee") AS nb_confirmes,
                   (SELECT COUNT(*) FROM reunion_points pt WHERE pt.reunion_id = r.id) AS nb_points,
                   (SELECT COUNT(*) FROM reunion_creneaux rc WHERE rc.reunion_id = r.id) AS nb_creneaux,
                   (SELECT COUNT(*) FROM reunion_creneaux rc JOIN reunion_reservations rr ON rr.creneau_id = rc.id AND rr.statut <> "annulee" WHERE rc.reunion_id = r.id) AS nb_reserves,
                   mp.role AS mon_role, mp.convocation AS ma_convocation
                FROM reunions r
                LEFT JOIN classes c ON c.id = r.classe_id
                LEFT JOIN salles s ON s.id = r.salle_id
                LEFT JOIN eleves el ON el.id = r.eleve_id
                LEFT JOIN reunion_participants mp ON mp.reunion_id = r.id AND mp.user_type = ? AND mp.user_id = ?
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY r.date_debut ' . ($quand === 'passees' ? 'DESC' : 'ASC') . ' LIMIT ' . max(1, min(300, (int) ($f['limit'] ?? 150)));
        $st = $this->pdo->prepare($sql);
        $st->execute(array_merge([$me['type'], $me['id']], $args));
        $classes = $this->classesOf($me);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!$this->peutVoir($r, $me, $classes)) { continue; }
            if (!empty($f['mes']) && !$r['mon_role'] && !$this->concerne($r, $me, $classes)) { continue; }
            $r['t'] = self::type((string) $r['type']);
            $out[] = $r;
        }
        return $out;
    }

    /** L'utilisateur est-il « attendu » (participant, ou famille/prof concerné par une soirée) ? */
    public function concerne(array $r, array $me, array $classes): bool
    {
        if (!empty($r['mon_role'])) { return true; }
        if ($r['type'] === 'parents_profs') {
            if ($me['type'] === 'parent') { return $r['classe_nom'] === null || in_array($r['classe_nom'], $classes, true); }
            if ($me['type'] === 'professeur') {
                $st = $this->pdo->prepare('SELECT 1 FROM reunion_creneaux WHERE reunion_id = ? AND professeur_id = ? LIMIT 1');
                $st->execute([$r['id'], $me['id']]);
                return (bool) $st->fetchColumn();
            }
        }
        return (int) $r['organisateur_id'] === $me['id'] && $r['organisateur_type'] === $me['type'];
    }

    public function peutVoir(array $r, ?array $me = null, ?array $classes = null): bool
    {
        $me ??= self::me();
        if (self::isDirection() || self::isVieScolaire()) { return true; }
        if (!empty($r['mon_role']) || ((int) $r['organisateur_id'] === $me['id'] && $r['organisateur_type'] === $me['type'])) { return true; }
        if (!empty($r['confidentiel'])) { return false; }
        $classes ??= $this->classesOf($me);
        $classe = $r['classe_nom'] ?? null;
        return match ($r['type']) {
            'parents_profs' => in_array($me['type'], ['professeur', 'parent', 'eleve'], true) && ($classe === null || in_array($classe, $classes, true)),
            'conseil_classe' => $me['type'] === 'professeur' && in_array($classe, $classes, true),
            'conseil_pedagogique', 'conseil_administration', 'reunion_equipe' => $me['type'] === 'professeur',
            default => false,
        };
    }

    public function peutGerer(array $r): bool
    {
        $me = self::me();
        if (self::isDirection() || self::isVieScolaire()) { return true; }
        if ((int) $r['organisateur_id'] === $me['id'] && $r['organisateur_type'] === $me['type']) { return true; }
        return in_array($r['mon_role'] ?? '', ['president', 'secretaire', 'organisateur'], true);
    }

    public function get(int $id): ?array
    {
        $me = self::me();
        $st = $this->pdo->prepare('SELECT r.*, c.nom AS classe_nom, s.nom AS salle_nom, CONCAT(el.prenom, " ", el.nom) AS eleve_nom, el.classe AS eleve_classe,
                mp.id AS mon_participant_id, mp.role AS mon_role, mp.convocation AS ma_convocation
            FROM reunions r LEFT JOIN classes c ON c.id = r.classe_id LEFT JOIN salles s ON s.id = r.salle_id LEFT JOIN eleves el ON el.id = r.eleve_id
            LEFT JOIN reunion_participants mp ON mp.reunion_id = r.id AND mp.user_type = ? AND mp.user_id = ?
            WHERE r.id = ? AND r.etablissement_id = ?');
        $st->execute([$me['type'], $me['id'], $id, $this->etab]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) { return null; }
        $r['t'] = self::type((string) $r['type']);
        $r['organisateur_nom'] = $this->nameOf((string) $r['organisateur_type'], (int) $r['organisateur_id']);
        return $r;
    }

    public function participants(int $id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM reunion_participants WHERE reunion_id = ? ORDER BY FIELD(role, "president", "organisateur", "secretaire", "cpe", "professeur", "sante", "aesh", "delegue_parent", "delegue_eleve", "famille", "eleve", "temoin", "membre", "invite"), id');
        $st->execute([$id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $this->preloadNames($rows);
        foreach ($rows as &$p) { $p['nom'] = $this->nameOf((string) $p['user_type'], (int) $p['user_id']); }
        return $rows;
    }

    public function points(int $id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM reunion_points WHERE reunion_id = ? ORDER BY ordre, id');
        $st->execute([$id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Création ─────────────────────────────────────────────────────────────
    /** Participants proposés selon le type (l'organisateur peut ensuite en retirer / ajouter). */
    public function proposer(string $type, ?string $classe, ?int $eleveId): array
    {
        $p = [];
        $add = function (string $t, int $id, string $role) use (&$p) { if ($id > 0) { $p["$t:$id"] = ['user_type' => $t, 'user_id' => $id, 'role' => $role]; } };
        $direction = $this->pdo->prepare("SELECT id FROM administrateurs WHERE etablissement_id = ? AND actif = 1 AND identifiant <> 'admin' ORDER BY id LIMIT 1");
        $direction->execute([$this->etab]); $dirId = (int) $direction->fetchColumn();
        $cpe = $this->pdo->prepare("SELECT id FROM vie_scolaire WHERE etablissement_id = ? AND actif = 1 AND est_CPE = 'oui' ORDER BY id LIMIT 1");
        $cpe->execute([$this->etab]); $cpeId = (int) $cpe->fetchColumn();
        $profsDe = function (?string $c): array {
            if (!$c) { return []; }
            $st = $this->pdo->prepare('SELECT DISTINCT id_professeur FROM professeur_classes WHERE nom_classe = ?');
            $st->execute([$c]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        };
        $ppDe = function (?string $c): int {
            if (!$c) { return 0; }
            $st = $this->pdo->prepare('SELECT professeur_principal_id FROM classes WHERE nom = ? AND etablissement_id = ? LIMIT 1');
            $st->execute([$c, $this->etab]);
            return (int) $st->fetchColumn();
        };
        $eleveClasse = null; $familles = [];
        if ($eleveId) {
            $st = $this->pdo->prepare('SELECT classe FROM eleves WHERE id = ?'); $st->execute([$eleveId]); $eleveClasse = (string) $st->fetchColumn();
            $st = $this->pdo->prepare('SELECT id_parent FROM parent_eleve WHERE id_eleve = ?'); $st->execute([$eleveId]); $familles = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        switch ($type) {
            case 'conseil_classe':
                $add('administrateur', $dirId, 'president'); $add('professeur', $ppDe($classe), 'secretaire'); $add('vie_scolaire', $cpeId, 'cpe');
                foreach ($profsDe($classe) as $pid) { $add('professeur', $pid, 'professeur'); }
                break;
            case 'conseil_discipline':
                $add('administrateur', $dirId, 'president'); $add('vie_scolaire', $cpeId, 'cpe'); $add('professeur', $ppDe($eleveClasse), 'professeur');
                $add('eleve', (int) $eleveId, 'eleve'); foreach ($familles as $f) { $add('parent', $f, 'famille'); }
                break;
            case 'commission_educative':
                $add('administrateur', $dirId, 'president'); $add('vie_scolaire', $cpeId, 'cpe'); $add('professeur', $ppDe($eleveClasse), 'professeur');
                $add('eleve', (int) $eleveId, 'eleve'); foreach ($familles as $f) { $add('parent', $f, 'famille'); }
                break;
            case 'equipe_educative':
                $add('administrateur', $dirId, 'president'); $add('professeur', $ppDe($eleveClasse), 'professeur'); $add('vie_scolaire', $cpeId, 'cpe');
                $inf = $this->pdo->prepare("SELECT id FROM vie_scolaire WHERE etablissement_id = ? AND est_infirmerie = 'oui' LIMIT 1"); $inf->execute([$this->etab]); $add('vie_scolaire', (int) $inf->fetchColumn(), 'sante');
                foreach ($familles as $f) { $add('parent', $f, 'famille'); }
                break;
            case 'entretien':
                $me = self::me(); $add($me['type'], $me['id'], 'organisateur'); foreach ($familles as $f) { $add('parent', $f, 'famille'); }
                break;
            case 'conseil_pedagogique':
                $add('administrateur', $dirId, 'president');
                foreach ($this->pdo->query('SELECT DISTINCT professeur_principal_id FROM classes WHERE professeur_principal_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $pid) { $add('professeur', (int) $pid, 'membre'); }
                break;
            case 'conseil_administration':
                $add('administrateur', $dirId, 'president'); $add('vie_scolaire', $cpeId, 'membre');
                break;
            case 'reunion_equipe':
                $me = self::me(); $add($me['type'], $me['id'], 'organisateur'); foreach ($profsDe($classe) as $pid) { $add('professeur', $pid, 'membre'); }
                break;
        }
        return array_values($p);
    }

    public function creer(array $d, array $participants, array $points, array $creneaux = []): int
    {
        $me = self::me();
        $t = self::type((string) $d['type']);
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare('INSERT INTO reunions (etablissement_id, titre, description, type, date_debut, date_fin, lieu, salle_id, classe_id, eleve_id, organisateur_id, organisateur_type, statut, confidentiel, visio_url, periode_id, duree_creneau)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,"planifiee",?,?,?,?)');
            $st->execute([$this->etab, $d['titre'], $d['description'] ?? null, $t['key'], $d['date_debut'], $d['date_fin'], $d['lieu'] ?? null, $d['salle_id'] ?: null,
                $d['classe_id'] ?: null, $d['eleve_id'] ?: null, $me['id'], $me['type'], !empty($d['confidentiel']) ? 1 : 0, $d['visio_url'] ?: null, $d['periode_id'] ?? null, $d['duree_creneau'] ?? null]);
            $id = (int) $this->pdo->lastInsertId();
            $ip = $this->pdo->prepare('INSERT IGNORE INTO reunion_participants (reunion_id, user_id, user_type, role) VALUES (?,?,?,?)');
            $ip->execute([$id, $me['id'], $me['type'], 'organisateur']);
            foreach ($participants as $p) { $ip->execute([$id, (int) $p['user_id'], (string) $p['user_type'], (string) ($p['role'] ?? 'membre')]); }
            $pt = $this->pdo->prepare('INSERT INTO reunion_points (reunion_id, ordre, titre, duree_minutes) VALUES (?,?,?,?)');
            foreach (array_values($points) as $i => [$titre, $duree]) { if (trim((string) $titre) !== '') { $pt->execute([$id, $i + 1, trim((string) $titre), max(1, (int) $duree)]); } }
            if ($creneaux) {
                $c = $this->pdo->prepare('INSERT INTO reunion_creneaux (reunion_id, professeur_id, heure_debut, heure_fin, duree_minutes, salle) VALUES (?,?,?,?,?,?)');
                foreach ($creneaux as $cr) { $c->execute([$id, $cr['professeur_id'], $cr['debut'], $cr['fin'], $cr['duree'], $cr['salle'] ?? null]); }
            }
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }

    /** Créneaux d'une soirée parents-professeurs : pour chaque professeur, de $debut à $fin par pas de $duree. */
    public static function genererCreneaux(array $profs, string $debut, string $fin, int $duree, array $sallesParProf = []): array
    {
        $out = [];
        foreach ($profs as $pid) {
            for ($t = strtotime("2000-01-01 $debut"); $t + $duree * 60 <= strtotime("2000-01-01 $fin"); $t += $duree * 60) {
                $out[] = ['professeur_id' => (int) $pid, 'debut' => date('H:i:s', $t), 'fin' => date('H:i:s', $t + $duree * 60), 'duree' => $duree, 'salle' => $sallesParProf[$pid] ?? null];
            }
        }
        return $out;
    }

    // ── Convocations ─────────────────────────────────────────────────────────
    /** Envoie les convocations en attente (notification dans l'application). Retourne le nombre envoyé. */
    public function convoquer(int $id): int
    {
        $r = $this->get($id);
        if (!$r) { return 0; }
        $st = $this->pdo->prepare("SELECT * FROM reunion_participants WHERE reunion_id = ? AND convocation = 'a_envoyer' AND NOT (user_type = ? AND user_id = ?)");
        $st->execute([$id, $r['organisateur_type'], $r['organisateur_id']]);
        $n = 0;
        $quand = self::dateLongue(new DateTimeImmutable($r['date_debut']));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $this->notifier((int) $p['user_id'], (string) $p['user_type'], 'Convocation : ' . $r['t']['label'], $r['titre'] . ' — ' . $quand . ($r['salle_nom'] ? ', ' . $r['salle_nom'] : ''), $id, 'haute');
            $n++;
        }
        $this->pdo->prepare("UPDATE reunion_participants SET convocation = 'envoyee' WHERE reunion_id = ? AND convocation = 'a_envoyer'")->execute([$id]);
        return $n;
    }

    public function repondre(int $id, string $reponse): bool
    {
        if (!in_array($reponse, ['confirmee', 'excusee'], true)) { return false; }
        $me = self::me();
        $st = $this->pdo->prepare('UPDATE reunion_participants SET convocation = ? WHERE reunion_id = ? AND user_type = ? AND user_id = ?');
        $st->execute([$reponse, $id, $me['type'], $me['id']]);
        return $st->rowCount() > 0;
    }

    private function notifier(int $uid, string $utype, string $titre, string $contenu, int $reunionId, string $importance = 'normale'): void
    {
        try {
            $this->pdo->prepare('INSERT INTO notifications_globales (etablissement_id, user_id, user_type, type, titre, contenu, lien, importance, lu, source_type, source_id, date_creation)
                VALUES (?,?,?,"reunion",?,?,?,?,0,"reunion",?,NOW())')->execute([$this->etab, $uid, $utype, $titre, $contenu, '/modules/reunions/detail.php?id=' . $reunionId, $importance, $reunionId]);
        } catch (\Throwable $e) { error_log('[reunions] notification : ' . $e->getMessage()); }
    }

    // ── Séance ───────────────────────────────────────────────────────────────
    public function demarrer(int $id): void
    {
        $this->pdo->prepare("UPDATE reunions SET statut = 'en_cours', started_at = COALESCE(started_at, NOW()) WHERE id = ? AND etablissement_id = ?")->execute([$id, $this->etab]);
        $first = $this->pdo->prepare("SELECT id FROM reunion_points WHERE reunion_id = ? AND statut IN ('a_traiter','en_cours') ORDER BY ordre LIMIT 1");
        $first->execute([$id]);
        if ($pid = (int) $first->fetchColumn()) { $this->activerPoint($id, $pid); }
    }

    public function activerPoint(int $id, int $pointId): void
    {
        $this->pdo->prepare("UPDATE reunion_points SET statut = 'traite' WHERE reunion_id = ? AND statut = 'en_cours' AND id <> ?")->execute([$id, $pointId]);
        $this->pdo->prepare("UPDATE reunion_points SET statut = 'en_cours', debut_at = COALESCE(debut_at, NOW()) WHERE id = ? AND reunion_id = ?")->execute([$pointId, $id]);
        $this->pdo->prepare('UPDATE reunions SET point_courant = ? WHERE id = ?')->execute([$pointId, $id]);
    }

    /** Clôt le point courant et passe au suivant ; retourne l'id du nouveau point (0 = plus de point). */
    public function pointSuivant(int $id): int
    {
        $this->pdo->prepare("UPDATE reunion_points SET statut = 'traite' WHERE reunion_id = ? AND statut = 'en_cours'")->execute([$id]);
        $n = $this->pdo->prepare("SELECT id FROM reunion_points WHERE reunion_id = ? AND statut = 'a_traiter' ORDER BY ordre LIMIT 1");
        $n->execute([$id]);
        $next = (int) $n->fetchColumn();
        if ($next) { $this->activerPoint($id, $next); } else { $this->pdo->prepare('UPDATE reunions SET point_courant = NULL WHERE id = ?')->execute([$id]); }
        return $next;
    }

    public function majPoint(int $id, int $pointId, array $champs): void
    {
        $ok = array_intersect_key($champs, array_flip(['notes', 'decision', 'statut', 'titre', 'duree_minutes']));
        if (!$ok) { return; }
        if (isset($ok['statut']) && !in_array($ok['statut'], ['a_traiter', 'en_cours', 'traite', 'reporte'], true)) { unset($ok['statut']); }
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($ok)));
        $this->pdo->prepare("UPDATE reunion_points SET $set WHERE id = ? AND reunion_id = ?")->execute(array_merge(array_values($ok), [$pointId, $id]));
    }

    public function ajouterPoint(int $id, string $titre, int $duree): int
    {
        $o = $this->pdo->prepare('SELECT COALESCE(MAX(ordre), 0) + 1 FROM reunion_points WHERE reunion_id = ?');
        $o->execute([$id]);
        $this->pdo->prepare('INSERT INTO reunion_points (reunion_id, ordre, titre, duree_minutes) VALUES (?,?,?,?)')->execute([$id, (int) $o->fetchColumn(), $titre, max(1, $duree)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function presence(int $id, int $participantId, ?string $presence): void
    {
        if ($presence !== null && !in_array($presence, ['present', 'absent', 'excuse', 'retard'], true)) { return; }
        $this->pdo->prepare('UPDATE reunion_participants SET presence = ?, arrive_at = IF(? IN ("present","retard"), COALESCE(arrive_at, NOW()), NULL) WHERE id = ? AND reunion_id = ?')
            ->execute([$presence, $presence, $participantId, $id]);
    }

    public function clore(int $id, string $compteRendu, bool $publier): void
    {
        $this->pdo->prepare("UPDATE reunion_points SET statut = 'traite' WHERE reunion_id = ? AND statut = 'en_cours'")->execute([$id]);
        $this->pdo->prepare("UPDATE reunions SET statut = 'terminee', ended_at = COALESCE(ended_at, NOW()), point_courant = NULL, pv_contenu = ?, cr_publie = ? WHERE id = ? AND etablissement_id = ?")
            ->execute([$compteRendu, $publier ? 1 : 0, $id, $this->etab]);
        if ($publier) {
            $r = $this->get($id);
            foreach ($this->participants($id) as $p) {
                if ($p['user_type'] === $r['organisateur_type'] && (int) $p['user_id'] === (int) $r['organisateur_id']) { continue; }
                $this->notifier((int) $p['user_id'], (string) $p['user_type'], 'Compte rendu disponible', $r['titre'], $id);
            }
        }
    }

    public function enregistrerCompteRendu(int $id, string $cr, bool $publier): void
    {
        $this->pdo->prepare('UPDATE reunions SET pv_contenu = ?, cr_publie = ? WHERE id = ? AND etablissement_id = ?')->execute([$cr, $publier ? 1 : 0, $id, $this->etab]);
    }

    /** Brouillon de compte rendu construit à partir de la séance (présences, points, notes, décisions). */
    public function brouillonCompteRendu(int $id): string
    {
        $r = $this->get($id);
        $lignes = [mb_strtoupper($r['t']['label']) . ' — ' . $r['titre'], self::dateLongue(new DateTimeImmutable($r['date_debut'])) . ($r['salle_nom'] ? ' — ' . $r['salle_nom'] : ''), ''];
        $pres = []; $abs = [];
        foreach ($this->participants($id) as $p) {
            $lib = $p['nom'] . ' (' . (self::ROLES[$p['role']] ?? $p['role']) . ')';
            if (in_array($p['presence'], ['present', 'retard'], true)) { $pres[] = $lib; } elseif ($p['presence'] !== null || $p['convocation'] === 'excusee') { $abs[] = $lib; }
        }
        $lignes[] = 'Présents : ' . ($pres ? implode(', ', $pres) : '—');
        if ($abs) { $lignes[] = 'Absents ou excusés : ' . implode(', ', $abs); }
        $lignes[] = '';
        foreach ($this->points($id) as $i => $pt) {
            $lignes[] = ($i + 1) . '. ' . $pt['titre'] . ($pt['statut'] === 'reporte' ? ' (reporté)' : '');
            if (trim((string) $pt['notes']) !== '') { $lignes[] = '   ' . trim((string) $pt['notes']); }
            if (trim((string) $pt['decision']) !== '') { $lignes[] = '   Décision : ' . trim((string) $pt['decision']); }
        }
        return implode("\n", $lignes);
    }

    public function annuler(int $id): void
    {
        $r = $this->get($id);
        $this->pdo->prepare("UPDATE reunions SET statut = 'annulee' WHERE id = ? AND etablissement_id = ?")->execute([$id, $this->etab]);
        foreach ($this->participants($id) as $p) {
            if ($p['convocation'] !== 'a_envoyer') { $this->notifier((int) $p['user_id'], (string) $p['user_type'], 'Réunion annulée', $r['titre'], $id); }
        }
    }

    // ── Rencontres parents-professeurs ───────────────────────────────────────
    /** Tableau de réservation : professeurs (et créneaux) concernés par l'utilisateur. */
    public function tableau(int $id): array
    {
        $me = self::me();
        $r = $this->get($id);
        $st = $this->pdo->prepare('SELECT rc.*, rr.id AS resa_id, rr.parent_id, rr.eleve_id, rr.statut AS resa_statut, rr.commentaire,
                CONCAT(p.prenom, " ", p.nom) AS prof_nom, p.matiere AS prof_matiere, CONCAT(e.prenom, " ", e.nom) AS eleve_nom, e.classe AS eleve_classe
            FROM reunion_creneaux rc JOIN professeurs p ON p.id = rc.professeur_id
            LEFT JOIN reunion_reservations rr ON rr.creneau_id = rc.id AND rr.statut <> "annulee"
            LEFT JOIN eleves e ON e.id = rr.eleve_id
            WHERE rc.reunion_id = ? ORDER BY p.nom, rc.heure_debut');
        $st->execute([$id]);
        $profs = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $profs[$c['professeur_id']]['id'] = (int) $c['professeur_id'];
            $profs[$c['professeur_id']]['nom'] = $c['prof_nom'];
            $profs[$c['professeur_id']]['matiere'] = $c['prof_matiere'];
            $profs[$c['professeur_id']]['salle'] = $c['salle'];
            $profs[$c['professeur_id']]['creneaux'][] = $c;
        }
        $enfants = $me['type'] === 'parent' ? $this->enfantsOf($me['id']) : [];
        if ($me['type'] === 'parent') {
            // Seulement les professeurs de mes enfants, avec pour chacun l'enfant concerné
            $profsEnfants = [];
            foreach ($enfants as $e) {
                $q = $this->pdo->prepare('SELECT id_professeur FROM professeur_classes WHERE nom_classe = ?');
                $q->execute([$e['classe']]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $pid) { $profsEnfants[(int) $pid][] = $e; }
            }
            $profs = array_intersect_key($profs, $profsEnfants);
            foreach ($profs as $pid => &$p) { $p['enfants'] = $profsEnfants[$pid]; }
            unset($p);
        } elseif ($me['type'] === 'professeur') {
            $profs = isset($profs[$me['id']]) ? [$me['id'] => $profs[$me['id']]] + $profs : $profs;
        }
        return ['reunion' => $r, 'profs' => array_values($profs), 'enfants' => $enfants];
    }

    /** Réservation par une famille : contrôles d'appartenance, un créneau par professeur et par enfant, pas de chevauchement. */
    public function reserver(int $id, int $creneauId, int $eleveId, ?string $commentaire = null): array
    {
        $me = self::me();
        if ($me['type'] !== 'parent') { return [false, 'Seules les familles réservent un créneau.']; }
        $own = $this->pdo->prepare('SELECT 1 FROM parent_eleve WHERE id_parent = ? AND id_eleve = ?');
        $own->execute([$me['id'], $eleveId]);
        if (!$own->fetchColumn()) { return [false, 'Élève non rattaché à votre compte.']; }
        $c = $this->pdo->prepare('SELECT rc.*, r.date_debut, r.statut FROM reunion_creneaux rc JOIN reunions r ON r.id = rc.reunion_id WHERE rc.id = ? AND rc.reunion_id = ? AND r.etablissement_id = ?');
        $c->execute([$creneauId, $id, $this->etab]);
        $cr = $c->fetch(PDO::FETCH_ASSOC);
        if (!$cr || $cr['statut'] === 'annulee') { return [false, 'Créneau introuvable.']; }
        $t = $this->pdo->prepare('SELECT 1 FROM professeur_classes pc JOIN eleves e ON e.classe = pc.nom_classe WHERE pc.id_professeur = ? AND e.id = ?');
        $t->execute([$cr['professeur_id'], $eleveId]);
        if (!$t->fetchColumn()) { return [false, 'Ce professeur n\'enseigne pas à cet enfant.']; }
        $dup = $this->pdo->prepare('SELECT 1 FROM reunion_reservations rr JOIN reunion_creneaux rc ON rc.id = rr.creneau_id WHERE rc.reunion_id = ? AND rc.professeur_id = ? AND rr.eleve_id = ? AND rr.statut <> "annulee"');
        $dup->execute([$id, $cr['professeur_id'], $eleveId]);
        if ($dup->fetchColumn()) { return [false, 'Vous avez déjà un rendez-vous avec ce professeur pour cet enfant.']; }
        $ov = $this->pdo->prepare('SELECT 1 FROM reunion_reservations rr JOIN reunion_creneaux rc ON rc.id = rr.creneau_id WHERE rc.reunion_id = ? AND rr.parent_id = ? AND rr.statut <> "annulee" AND rc.heure_debut < ? AND rc.heure_fin > ?');
        $ov->execute([$id, $me['id'], $cr['heure_fin'], $cr['heure_debut']]);
        if ($ov->fetchColumn()) { return [false, 'Vous avez déjà un rendez-vous à cette heure-là.']; }
        try {
            $this->pdo->prepare('DELETE FROM reunion_reservations WHERE creneau_id = ? AND statut = "annulee"')->execute([$creneauId]);
            $this->pdo->prepare('INSERT INTO reunion_reservations (creneau_id, parent_id, eleve_id, statut, commentaire, date_reservation) VALUES (?,?,?,"confirmee",?,NOW())')
                ->execute([$creneauId, $me['id'], $eleveId, $commentaire ? mb_substr($commentaire, 0, 300) : null]);
        } catch (\PDOException $e) {
            return [false, 'Ce créneau vient d\'être réservé par une autre famille.'];
        }
        return [true, 'Rendez-vous réservé à ' . substr((string) $cr['heure_debut'], 0, 5) . '.'];
    }

    public function annulerReservation(int $id, int $resaId): bool
    {
        $me = self::me();
        $sql = 'UPDATE reunion_reservations rr JOIN reunion_creneaux rc ON rc.id = rr.creneau_id SET rr.statut = "annulee" WHERE rr.id = ? AND rc.reunion_id = ?';
        $args = [$resaId, $id];
        if ($me['type'] === 'parent') { $sql .= ' AND rr.parent_id = ?'; $args[] = $me['id']; }
        elseif ($me['type'] === 'professeur') { $sql .= ' AND rc.professeur_id = ?'; $args[] = $me['id']; }
        elseif (!self::isDirection() && !self::isVieScolaire()) { return false; }
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->rowCount() > 0;
    }

    // ── Aides d'affichage ────────────────────────────────────────────────────
    public static function dateLongue(DateTimeInterface $d, bool $heure = true): string
    {
        $j = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'][(int) $d->format('N')];
        $m = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][(int) $d->format('n')];
        return $j . ' ' . (int) $d->format('j') . ' ' . $m . ($heure ? ' à ' . $d->format('G') . ' h ' . $d->format('i') : '');
    }

    /** « dans 3 j », « dans 2 h 10 », « en cours », « il y a 5 j » */
    public static function relatif(DateTimeInterface $debut, DateTimeInterface $fin): string
    {
        $now = new DateTimeImmutable();
        if ($now >= $debut && $now <= $fin) { return 'en ce moment'; }
        $diff = $debut->getTimestamp() - $now->getTimestamp();
        $abs = abs($diff);
        $txt = $abs >= 86400 ? round($abs / 86400) . ' j' : ($abs >= 3600 ? intdiv($abs, 3600) . ' h ' . sprintf('%02d', intdiv($abs % 3600, 60)) : max(1, intdiv($abs, 60)) . ' min');
        return $diff > 0 ? 'dans ' . $txt : 'il y a ' . $txt;
    }
}
