<?php
namespace API\Services\Import;

use PDO;

/**
 * Moteur d'import en masse générique, piloté par ImportSchemas.
 *
 * Cible : CSV / TSV / texte collé (un export Pronote en CSV FR fonctionne via les
 * alias d'en-têtes). Pas de dépendance XLSX (absente du projet).
 *
 * Pipeline : parse() -> detectMapping() -> preview() -> import().
 * Tout est scopé à l'établissement courant (EstablishmentContext::id()).
 */
class BulkImporter
{
    private PDO $pdo;
    /** Cache de résolution de clés étrangères pour la durée d'un run. */
    private array $fkCache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /* ============================== PARSE ============================== */

    /**
     * Lit un chemin de fichier OU une chaîne collée. Auto-détecte le séparateur.
     * @return array{headers:string[],rows:array<int,string[]>,total:int}
     */
    public function parse(string $rawOrPath, string $delimiter = 'auto'): array
    {
        $content = (is_file($rawOrPath) && strlen($rawOrPath) < 4096) ? (string) file_get_contents($rawOrPath) : $rawOrPath;
        // Retirer un BOM UTF-8 éventuel.
        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
            $content = substr($content, 3);
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = array_values(array_filter(explode("\n", $content), fn($l) => trim($l) !== ''));
        if (empty($lines)) {
            return ['headers' => [], 'rows' => [], 'total' => 0];
        }

        if ($delimiter === 'auto') {
            $delimiter = $this->detectDelimiter($lines[0]);
        }

        $parseLine = fn(string $l) => array_map('trim', str_getcsv($l, $delimiter));
        $headers = $parseLine(array_shift($lines));
        $rows = [];
        foreach ($lines as $l) {
            $rows[] = $parseLine($l);
        }
        return ['headers' => $headers, 'rows' => $rows, 'total' => count($rows)];
    }

    private function detectDelimiter(string $headerLine): string
    {
        $candidates = [';' => substr_count($headerLine, ';'), "\t" => substr_count($headerLine, "\t"), ',' => substr_count($headerLine, ',')];
        arsort($candidates);
        $best = array_key_first($candidates);
        return $candidates[$best] > 0 ? $best : ';';
    }

    /* ============================== MAPPING ============================== */

    /** Normalise un en-tête : minuscule, sans accent, sans ponctuation. */
    public static function normalizeHeader(string $h): string
    {
        $h = mb_strtolower(trim($h), 'UTF-8');
        $map = ['à','â','ä','á','ã','ç','é','è','ê','ë','î','ï','í','ô','ö','ó','õ','ù','û','ü','ú','ÿ','ñ'];
        $rep = ['a','a','a','a','a','c','e','e','e','e','i','i','i','o','o','o','o','u','u','u','u','y','n'];
        $h = str_replace($map, $rep, $h);
        $h = preg_replace('/[^a-z0-9]+/', ' ', $h);
        return trim(preg_replace('/\s+/', ' ', $h));
    }

    /**
     * Associe chaque en-tête entrant à une colonne canonique via les alias du schéma.
     * @return array<string,?string> header => canonical|null
     */
    public function detectMapping(string $entity, array $headers): array
    {
        $schema = ImportSchemas::get($entity);
        if (!$schema) return [];
        // Index alias normalisé => canonique.
        $aliasIndex = [];
        foreach ($schema['columns'] as $canonical => $def) {
            $aliasIndex[self::normalizeHeader($canonical)] = $canonical;
            foreach ($def['aliases'] ?? [] as $a) {
                $aliasIndex[self::normalizeHeader($a)] = $canonical;
            }
        }
        $mapping = [];
        foreach ($headers as $h) {
            $mapping[$h] = $aliasIndex[self::normalizeHeader($h)] ?? null;
        }
        return $mapping;
    }

    /* ============================== PREVIEW ============================== */

    /**
     * Validation à blanc : applique mapping + transforms + résolution FK sans écrire.
     * @return array{valid:int,invalid:array,dedup:int,sample:array,total:int,mapped:array}
     */
    public function preview(string $entity, array $headers, array $rows, array $mapping, int $max = 20): array
    {
        $schema = ImportSchemas::get($entity);
        if (!$schema) return ['valid' => 0, 'invalid' => [], 'dedup' => 0, 'sample' => [], 'total' => 0, 'mapped' => []];

        $valid = 0; $invalid = []; $dedupCount = 0; $sample = [];
        $seen = [];
        foreach ($rows as $i => $rawRow) {
            [$data, $errors] = $this->buildRow($entity, $schema, $headers, $rawRow, $mapping);
            $line = $i + 2; // +1 pour l'en-tête, +1 pour l'indexation 1-based
            if ($errors) {
                $invalid[] = ['line' => $line, 'errors' => $errors];
                continue;
            }
            // Doublon dans le fichier (clé dedup).
            $key = $this->dedupKey($schema, $data);
            if ($key !== null && isset($seen[$key])) { $dedupCount++; continue; }
            if ($key !== null) $seen[$key] = true;
            // Doublon en base.
            if ($key !== null && $this->existsInDb($schema, $data)) { $dedupCount++; continue; }

            $valid++;
            if (count($sample) < $max) $sample[] = $data;
        }
        return ['valid' => $valid, 'invalid' => $invalid, 'dedup' => $dedupCount, 'sample' => $sample, 'total' => count($rows), 'mapped' => $mapping];
    }

    /* ============================== IMPORT ============================== */

    /**
     * Importe réellement. Scopé établissement. Doublons ignorés. Erreurs collectées.
     * @return array{nb_total:int,nb_importes:int,nb_doublons:int,nb_erreurs:int,erreurs:array,passwords:array}
     */
    public function import(string $entity, array $headers, array $rows, array $mapping, array $options = []): array
    {
        $schema = ImportSchemas::get($entity);
        $res = ['nb_total' => count($rows), 'nb_importes' => 0, 'nb_doublons' => 0, 'nb_erreurs' => 0, 'erreurs' => [], 'passwords' => []];
        if (!$schema) { $res['erreurs'][] = "Entité inconnue : {$entity}"; $res['nb_erreurs'] = count($rows); return $res; }

        $generatePasswords = $options['generate_passwords'] ?? true;
        $etabScoped = !empty($schema['scoped']);
        $etabId = null;
        if ($etabScoped) {
            try { $etabId = \API\Core\EstablishmentContext::id(); } catch (\Throwable $e) { $etabId = null; }
        }
        $seen = [];

        foreach ($rows as $i => $rawRow) {
            $line = $i + 2;
            [$data, $errors] = $this->buildRow($entity, $schema, $headers, $rawRow, $mapping);
            if ($errors) { $res['nb_erreurs']++; $res['erreurs'][] = "Ligne {$line} : " . implode(' ; ', $errors); continue; }

            $key = $this->dedupKey($schema, $data);
            if ($key !== null && isset($seen[$key])) { $res['nb_doublons']++; continue; }
            if ($key !== null && $this->existsInDb($schema, $data)) { $res['nb_doublons']++; if ($key !== null) $seen[$key] = true; continue; }
            if ($key !== null) $seen[$key] = true;

            // Champs générés (comptes utilisateurs).
            $plainPassword = null;
            foreach ($schema['generate'] ?? [] as $gen) {
                if ($gen === 'identifiant' && empty($data['identifiant'])) {
                    $data['identifiant'] = \API\Services\IdentifierGenerator::generate($this->pdo, $data['nom'] ?? '', $data['prenom'] ?? '', $etabId);
                }
                if ($gen === 'mot_de_passe') {
                    $plainPassword = $generatePasswords ? $this->generatePassword() : 'Fronote2025!';
                    $data['mot_de_passe'] = \API\Security\PasswordPolicy::hash($plainPassword);
                }
            }
            if ($etabScoped && $etabId !== null) {
                $data['etablissement_id'] = $etabId;
            }

            try {
                $this->insert($schema['table'], $data);
                $res['nb_importes']++;
                if ($plainPassword !== null && !empty($data['identifiant'])) {
                    $res['passwords'][] = [
                        'identifiant' => $data['identifiant'],
                        'nom'         => $data['nom'] ?? '',
                        'prenom'      => $data['prenom'] ?? '',
                        'type'        => $entity,
                        'password'    => $plainPassword,
                    ];
                }
            } catch (\Throwable $e) {
                $res['nb_erreurs']++;
                $res['erreurs'][] = "Ligne {$line} : " . $e->getMessage();
            }
        }
        return $res;
    }

    /* ============================== ROW BUILD ============================== */

    /**
     * Construit la ligne BD à partir du mapping. Applique transforms, défauts, FK.
     * @return array{0:array<string,mixed>,1:string[]} [data, errors]
     */
    private function buildRow(string $entity, array $schema, array $headers, array $rawRow, array $mapping): array
    {
        // canonical => valeur brute (depuis la 1ère colonne mappée non vide)
        $values = [];
        foreach ($headers as $idx => $h) {
            $canonical = $mapping[$h] ?? null;
            if ($canonical === null) continue;
            $val = $rawRow[$idx] ?? '';
            if ($val !== '' || !isset($values[$canonical])) {
                $values[$canonical] = $val;
            }
        }

        $data = [];
        $errors = [];
        foreach ($schema['columns'] as $canonical => $def) {
            $raw = $values[$canonical] ?? null;
            $required = !empty($def['required']);

            // Résolution FK (notes : élève/matière/prof par nom).
            if (!empty($def['fk'])) {
                if ($raw === null || trim((string) $raw) === '') {
                    if ($required) $errors[] = "« {$canonical} » manquant";
                    continue;
                }
                $id = $this->resolveRef($def['fk']['entity'], $def['fk']['by'], (string) $raw);
                if ($id === null) {
                    if ($required) $errors[] = "« {$raw} » introuvable ({$def['fk']['entity']})";
                    continue;
                }
                $data[$canonical] = $id;
                continue;
            }

            if ($raw === null || trim((string) $raw) === '') {
                if ($required) { $errors[] = "« {$canonical} » manquant"; continue; }
                if (array_key_exists('default', $def)) { $data[$canonical] = $def['default']; }
                continue;
            }

            $data[$canonical] = $this->transform($def['transform'] ?? null, (string) $raw);
        }

        return [$data, $errors];
    }

    private function transform(?string $type, string $val): string
    {
        $val = trim($val);
        switch ($type) {
            case 'date':   return $this->normalizeDate($val);
            case 'ouinon': return in_array(mb_strtolower($val), ['oui', 'yes', '1', 'true', 'o', 'x'], true) ? 'oui' : 'non';
            case 'lower':  return mb_strtolower($val);
            case 'int':    return (string) (int) preg_replace('/[^0-9-]/', '', $val);
            case 'float':  return (string) (float) str_replace(',', '.', preg_replace('/[^0-9,.\-]/', '', $val));
            case 'trim':
            default:       return $val;
        }
    }

    private function normalizeDate(string $val): string
    {
        $val = trim($val);
        if ($val === '') return '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $val, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
        if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})$#', $val, $m)) {
            $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $y = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
            return "{$y}-{$mo}-{$d}";
        }
        return ''; // illisible -> vide (le défaut de schéma s'appliquera si la colonne est NOT NULL)
    }

    /* ============================== FK RESOLUTION ============================== */

    /** Résout un référentiel (élève/matière/prof) vers son id, scopé établissement. */
    private function resolveRef(string $entity, string $by, string $value): ?int
    {
        $value = trim($value);
        if ($value === '') return null;
        $cacheKey = $entity . '|' . $by . '|' . mb_strtolower($value);
        if (array_key_exists($cacheKey, $this->fkCache)) return $this->fkCache[$cacheKey];

        $etab = null;
        try { $etab = \API\Core\EstablishmentContext::id(); } catch (\Throwable $e) {}
        $etabClause = $etab !== null ? ' AND etablissement_id = ?' : '';

        $id = null;
        if ($entity === 'eleve' || $entity === 'professeur') {
            $table = $entity === 'eleve' ? 'eleves' : 'professeurs';
            // "Nom Prénom" ou "Prénom Nom" (insensible à l'ordre/casse).
            $sql = "SELECT id FROM `{$table}` WHERE (LOWER(CONCAT(nom,' ',prenom)) = LOWER(?) OR LOWER(CONCAT(prenom,' ',nom)) = LOWER(?)){$etabClause} LIMIT 2";
            $params = [$value, $value];
            if ($etab !== null) $params[] = $etab;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $id = (count($ids) === 1) ? (int) $ids[0] : null;
        } elseif ($entity === 'matiere') {
            $sql = "SELECT id FROM matieres WHERE (LOWER(code) = LOWER(?) OR LOWER(nom) = LOWER(?)){$etabClause} LIMIT 2";
            $params = [$value, $value];
            if ($etab !== null) $params[] = $etab;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $id = (count($ids) === 1) ? (int) $ids[0] : null;
        }
        return $this->fkCache[$cacheKey] = $id;
    }

    /* ============================== DEDUP / INSERT ============================== */

    private function dedupKey(array $schema, array $data): ?string
    {
        if (empty($schema['dedup'])) return null;
        $parts = [];
        foreach ($schema['dedup'] as $col) {
            $v = $data[$col] ?? '';
            if (trim((string) $v) === '') return null; // clé incomplète -> pas de dedup
            $parts[] = mb_strtolower(trim((string) $v));
        }
        return implode('|', $parts);
    }

    private function existsInDb(array $schema, array $data): bool
    {
        if (empty($schema['dedup'])) return false;
        $where = [];
        $params = [];
        foreach ($schema['dedup'] as $col) {
            $where[] = "`{$col}` = ?";
            $params[] = $data[$col] ?? '';
        }
        if (!empty($schema['scoped'])) {
            try { $etab = \API\Core\EstablishmentContext::id(); $where[] = 'etablissement_id = ?'; $params[] = $etab; } catch (\Throwable $e) {}
        }
        $sql = "SELECT 1 FROM `{$schema['table']}` WHERE " . implode(' AND ', $where) . ' LIMIT 1';
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function insert(string $table, array $data): void
    {
        $cols = array_keys($data);
        $place = implode(', ', array_fill(0, count($cols), '?'));
        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
        $stmt = $this->pdo->prepare("INSERT INTO `{$table}` ({$colList}) VALUES ({$place})");
        $stmt->execute(array_values($data));
    }

    /* ============================== HELPERS ============================== */

    private function generatePassword(int $length = 12): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#';
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    /** CSV modèle (en-têtes canoniques) pour une entité. */
    public function template(string $entity): string
    {
        $schema = ImportSchemas::get($entity);
        if (!$schema) return '';
        $cols = [];
        foreach ($schema['columns'] as $canonical => $def) {
            // Pour les FK on met un en-tête lisible (le 1er alias).
            $cols[] = !empty($def['fk']) ? ($def['aliases'][0] ?? $canonical) : $canonical;
        }
        return "\xEF\xBB\xBF" . implode(';', $cols) . "\n";
    }
}
