<?php
declare(strict_types=1);

namespace API\Services\Messaging;

use PDO;

/**
 * Annuaire minimal des personnes de l'établissement (5 types de comptes historiques),
 * avec mise en cache par requête. Fournit nom affiché, rôle lisible et précision
 * (classe d'un élève, matière d'un professeur, enfants d'un parent).
 */
final class People
{
    public const TYPES = [
        'eleve'          => ['eleves', 'Élève'],
        'parent'         => ['parents', 'Parent'],
        'professeur'     => ['professeurs', 'Professeur'],
        'vie_scolaire'   => ['vie_scolaire', 'Vie scolaire'],
        'administrateur' => ['administrateurs', 'Direction'],
    ];

    private PDO $pdo;
    private int $etab;
    /** @var array<string,array> */
    private array $cache = [];

    public function __construct(PDO $pdo, int $etab)
    {
        $this->pdo = $pdo;
        $this->etab = $etab;
    }

    public static function valid(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /** @return array{id:int,type:string,name:string,first:string,last:string,role:string,detail:string,initials:string}|null */
    public function get(int $id, string $type): ?array
    {
        $k = $type . ':' . $id;
        if (array_key_exists($k, $this->cache)) { return $this->cache[$k]; }
        if (!self::valid($type)) { return $this->cache[$k] = null; }
        $this->load([[$id, $type]]);
        return $this->cache[$k] ?? null;
    }

    /** Charge en lot (une requête par type). @param array<int,array{0:int,1:string}> $refs */
    public function load(array $refs): void
    {
        $byType = [];
        foreach ($refs as [$id, $type]) {
            if (self::valid($type) && !array_key_exists($type . ':' . (int) $id, $this->cache)) { $byType[$type][(int) $id] = true; }
        }
        foreach ($byType as $type => $ids) {
            $ids = array_keys($ids);
            [$table] = self::TYPES[$type];
            $extra = match ($type) {
                'eleve' => ', classe AS detail',
                'professeur' => ', matiere AS detail',
                'vie_scolaire' => ", IF(est_CPE='oui','CPE',IF(est_infirmerie='oui','Infirmerie','Vie scolaire')) AS detail",
                default => ", '' AS detail",
            };
            $st = $this->pdo->prepare("SELECT id, prenom, nom{$extra} FROM `{$table}` WHERE etablissement_id = ? AND id IN ("
                . implode(',', array_fill(0, count($ids), '?')) . ')');
            $st->execute(array_merge([$this->etab], $ids));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $this->cache[$type . ':' . (int) $r['id']] = $this->row($type, $r);
            }
            foreach ($ids as $id) { $this->cache[$type . ':' . $id] ??= null; }
        }
    }

    public function row(string $type, array $r): array
    {
        $first = trim((string) ($r['prenom'] ?? '')); $last = trim((string) ($r['nom'] ?? ''));
        $detail = trim((string) ($r['detail'] ?? ''));
        if ($type === 'eleve' && $detail !== '') { $detail = 'Élève · ' . $detail; }
        elseif ($type === 'professeur') { $detail = 'Professeur' . ($detail !== '' ? ' · ' . $detail : ''); }
        elseif ($type === 'parent') { $detail = 'Parent'; }
        elseif ($type === 'administrateur') { $detail = 'Direction'; }
        elseif ($detail === '') { $detail = self::TYPES[$type][1]; }
        $ini = mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
        return [
            'id' => (int) $r['id'], 'type' => $type, 'name' => trim($first . ' ' . $last), 'first' => $first, 'last' => $last,
            'role' => self::TYPES[$type][1], 'detail' => $detail, 'initials' => $ini !== '' ? $ini : '?',
        ];
    }
}
