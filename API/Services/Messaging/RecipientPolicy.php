<?php
declare(strict_types=1);

namespace API\Services\Messaging;

use PDO;

/**
 * QUI PEUT ÉCRIRE À QUI — politique de destinataires de la messagerie.
 *
 * Avant 4.3, n'importe quel élève pouvait écrire à n'importe quel élève ou parent de
 * l'établissement. Désormais :
 *   - élève      → ses professeurs, la vie scolaire, la direction
 *   - parent     → les professeurs de ses enfants, la vie scolaire, la direction
 *   - professeur → les élèves de ses classes et leurs parents, tout le personnel
 *   - vie scolaire / direction → tout l'établissement (+ envois à une classe entière)
 * Répondre dans une conversation existante reste toujours possible (si elle accepte les réponses).
 */
final class RecipientPolicy
{
    private const STAFF = ['professeur', 'vie_scolaire', 'administrateur'];

    private PDO $pdo;
    private int $etab;
    private People $people;
    private int $uid;
    private string $utype;
    /** @var array<string,true>|null */
    private ?array $allowed = null;
    /** @var string[]|null noms de classes « miennes » */
    private ?array $classes = null;

    public function __construct(PDO $pdo, int $etab, People $people, int $userId, string $userType)
    {
        $this->pdo = $pdo; $this->etab = $etab; $this->people = $people; $this->uid = $userId; $this->utype = $userType;
    }

    public function isStaff(): bool { return in_array($this->utype, self::STAFF, true); }
    public function isManager(): bool { return in_array($this->utype, ['vie_scolaire', 'administrateur'], true); }

    /** Classes de l'utilisateur (professeur : enseignées ; élève : la sienne ; parent : celles des enfants ; gestionnaires : toutes). */
    public function classes(): array
    {
        if ($this->classes !== null) { return $this->classes; }
        $q = match ($this->utype) {
            'professeur' => ["SELECT DISTINCT nom_classe FROM professeur_classes WHERE id_professeur = ?
                              UNION SELECT DISTINCT c.nom FROM emploi_du_temps e JOIN classes c ON c.id = e.classe_id
                               WHERE e.professeur_id = ? AND e.etablissement_id = ?", [$this->uid, $this->uid, $this->etab]],
            'eleve' => ['SELECT classe FROM eleves WHERE id = ? AND etablissement_id = ?', [$this->uid, $this->etab]],
            'parent' => ['SELECT DISTINCT e.classe FROM parent_eleve pe JOIN eleves e ON e.id = pe.id_eleve
                           WHERE pe.id_parent = ? AND e.etablissement_id = ?', [$this->uid, $this->etab]],
            default => ['SELECT nom FROM classes WHERE etablissement_id = ? AND actif = 1 ORDER BY nom', [$this->etab]],
        };
        $st = $this->pdo->prepare($q[0]); $st->execute($q[1]);
        $c = array_values(array_unique(array_filter(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)))));
        sort($c, SORT_NATURAL);
        return $this->classes = $c;
    }

    private function in(array $vals): string { return implode(',', array_fill(0, max(1, count($vals)), '?')); }

    /**
     * Personnes joignables, par catégorie (pour le sélecteur). Filtre texte optionnel.
     * @return array<int,array{key:string,label:string,people:array}>
     */
    public function directory(string $q = '', int $limitPerGroup = 60): array
    {
        $groups = [];
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($q)) . '%';
        $nameCond = " AND CONCAT(prenom,' ',nom) LIKE ?";
        $run = function (string $type, string $where, array $p, string $order = 'nom, prenom') use ($like, $nameCond, $limitPerGroup): array {
            [$table] = People::TYPES[$type];
            $extra = match ($type) {
                'eleve' => ', classe AS detail', 'professeur' => ', matiere AS detail',
                'vie_scolaire' => ", IF(est_CPE='oui','CPE',IF(est_infirmerie='oui','Infirmerie','')) AS detail", default => ", '' AS detail",
            };
            $sql = "SELECT id, prenom, nom{$extra} FROM `{$table}` WHERE etablissement_id = ? AND actif = 1 {$where}{$nameCond}
                    ORDER BY {$order} LIMIT " . (int) $limitPerGroup;
            $st = $this->pdo->prepare($sql);
            $st->execute(array_merge([$this->etab], $p, [$like]));
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ((int) $r['id'] === $this->uid && $type === $this->utype) { continue; }
                $out[] = $this->people->row($type, $r);
            }
            return $out;
        };
        $add = function (string $key, string $label, array $people) use (&$groups) {
            if ($people) { $groups[] = ['key' => $key, 'label' => $label, 'people' => $people]; }
        };
        $cls = $this->classes();

        switch ($this->utype) {
            case 'eleve':
            case 'parent':
                $profIds = $this->teachersOf($cls);
                $add('profs', $this->utype === 'eleve' ? 'Mes professeurs' : 'Professeurs de mes enfants',
                    $profIds ? $run('professeur', ' AND id IN (' . $this->in($profIds) . ')', $profIds) : []);
                $add('vs', 'Vie scolaire', $run('vie_scolaire', '', []));
                $add('direction', 'Direction', $run('administrateur', '', []));
                break;
            case 'professeur':
                $add('eleves', 'Mes élèves', $cls ? $run('eleve', ' AND classe IN (' . $this->in($cls) . ')', $cls, 'classe, nom, prenom') : []);
                $add('parents', 'Parents de mes élèves', $cls ? $run('parent', ' AND id IN (SELECT pe.id_parent FROM parent_eleve pe JOIN eleves e ON e.id = pe.id_eleve WHERE e.classe IN (' . $this->in($cls) . '))', $cls) : []);
                $add('profs', 'Professeurs', $run('professeur', '', []));
                $add('vs', 'Vie scolaire', $run('vie_scolaire', '', []));
                $add('direction', 'Direction', $run('administrateur', '', []));
                break;
            default: // vie scolaire, direction
                $add('profs', 'Professeurs', $run('professeur', '', []));
                $add('vs', 'Vie scolaire', $run('vie_scolaire', '', []));
                $add('direction', 'Direction', $run('administrateur', '', []));
                $add('eleves', 'Élèves', $run('eleve', '', [], 'classe, nom, prenom'));
                $add('parents', 'Parents', $run('parent', '', []));
        }
        return $groups;
    }

    /** Groupes d'envoi (classe entière) proposés au personnel. @return array<int,array{key:string,label:string,count:int}> */
    public function broadcastGroups(): array
    {
        if (!$this->isStaff()) { return []; }
        $out = [];
        foreach ($this->classes() as $c) {
            $n = $this->pdo->prepare('SELECT COUNT(*) FROM eleves WHERE etablissement_id = ? AND classe = ? AND actif = 1');
            $n->execute([$this->etab, $c]);
            $ne = (int) $n->fetchColumn();
            if ($ne === 0) { continue; }
            $out[] = ['key' => 'classe:' . $c . ':eleves', 'label' => 'Classe ' . $c . ' — élèves', 'count' => $ne];
            $out[] = ['key' => 'classe:' . $c . ':parents', 'label' => 'Classe ' . $c . ' — parents', 'count' => count($this->parentsOfClass($c))];
        }
        if ($this->isManager()) {
            $n = (int) $this->pdo->query('SELECT COUNT(*) FROM professeurs WHERE actif = 1 AND etablissement_id = ' . (int) $this->etab)->fetchColumn();
            $out[] = ['key' => 'tous:professeurs', 'label' => 'Tous les professeurs', 'count' => $n];
        }
        return $out;
    }

    /** Développe un groupe d'envoi en destinataires (après contrôle). @return array<int,array{id:int,type:string}> */
    public function expandGroup(string $key): array
    {
        $allowedKeys = array_column($this->broadcastGroups(), 'key');
        if (!in_array($key, $allowedKeys, true)) { return []; }
        if ($key === 'tous:professeurs') {
            $st = $this->pdo->prepare('SELECT id FROM professeurs WHERE actif = 1 AND etablissement_id = ?'); $st->execute([$this->etab]);
            return array_map(fn($id) => ['id' => (int) $id, 'type' => 'professeur'], $st->fetchAll(PDO::FETCH_COLUMN));
        }
        if (!preg_match('/^classe:(.+):(eleves|parents)$/u', $key, $m)) { return []; }
        if ($m[2] === 'eleves') {
            $st = $this->pdo->prepare('SELECT id FROM eleves WHERE etablissement_id = ? AND classe = ? AND actif = 1'); $st->execute([$this->etab, $m[1]]);
            return array_map(fn($id) => ['id' => (int) $id, 'type' => 'eleve'], $st->fetchAll(PDO::FETCH_COLUMN));
        }
        return array_map(fn($id) => ['id' => (int) $id, 'type' => 'parent'], $this->parentsOfClass($m[1]));
    }

    private function parentsOfClass(string $classe): array
    {
        $st = $this->pdo->prepare('SELECT DISTINCT pe.id_parent FROM parent_eleve pe JOIN eleves e ON e.id = pe.id_eleve
                                    JOIN parents p ON p.id = pe.id_parent AND p.actif = 1
                                   WHERE e.classe = ? AND e.etablissement_id = ?');
        $st->execute([$classe, $this->etab]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param string[] $classes @return int[] */
    private function teachersOf(array $classes): array
    {
        if (!$classes) { return []; }
        $in = $this->in($classes);
        $st = $this->pdo->prepare("SELECT id_professeur FROM professeur_classes WHERE nom_classe IN ({$in})
                                   UNION SELECT e.professeur_id FROM emploi_du_temps e JOIN classes c ON c.id = e.classe_id
                                    WHERE c.nom IN ({$in}) AND e.etablissement_id = ?");
        $st->execute(array_merge($classes, $classes, [$this->etab]));
        return array_values(array_unique(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));
    }

    /** L'utilisateur peut-il ouvrir une conversation avec cette personne ? */
    public function canContact(int $id, string $type): bool
    {
        if (!People::valid($type) || ($id === $this->uid && $type === $this->utype)) { return false; }
        if ($this->isManager()) { return $this->people->get($id, $type) !== null; }
        if (in_array($type, ['vie_scolaire', 'administrateur'], true) || ($this->isStaff() && $type === 'professeur')) {
            return $this->people->get($id, $type) !== null;
        }
        $cls = $this->classes();
        if (!$cls) { return false; }
        $in = $this->in($cls);
        if ($type === 'professeur') { // élève / parent → enseignants de la classe
            return in_array($id, $this->teachersOf($cls), true);
        }
        if ($this->utype === 'professeur' && $type === 'eleve') {
            $st = $this->pdo->prepare("SELECT 1 FROM eleves WHERE id = ? AND etablissement_id = ? AND classe IN ({$in})");
            $st->execute(array_merge([$id, $this->etab], $cls)); return (bool) $st->fetchColumn();
        }
        if ($this->utype === 'professeur' && $type === 'parent') {
            $st = $this->pdo->prepare("SELECT 1 FROM parent_eleve pe JOIN eleves e ON e.id = pe.id_eleve
                                       WHERE pe.id_parent = ? AND e.etablissement_id = ? AND e.classe IN ({$in}) LIMIT 1");
            $st->execute(array_merge([$id, $this->etab], $cls)); return (bool) $st->fetchColumn();
        }
        return false; // élève ↔ élève, parent ↔ parent, élève ↔ parent : non
    }
}
