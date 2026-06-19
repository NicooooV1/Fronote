<?php
declare(strict_types=1);

namespace API\Services;

use PDO;

/**
 * SchemaSyncService — réconciliation déclarative du schéma (SANS migrations).
 *
 * Principe : les fichiers `pronote.sql` (cœur) et `modules/<m>/Database/install.sql`
 * sont la DÉFINITION DÉSIRÉE du schéma. À chaque mise à jour, on rend la base
 * conforme à ces définitions, de façon NON destructive et idempotente :
 *   - table absente            → CREATE TABLE (le statement complet du .sql)
 *   - table présente           → ADD COLUMN pour chaque colonne manquante
 *
 * Pas de fichier de migration, pas de table de suivi, pas de version. On ne
 * supprime jamais de colonne/table et on ne modifie pas un type existant
 * (changements destructifs hors périmètre — on reste « ajout seulement »).
 */
class SchemaSyncService
{
    private PDO $pdo;
    private string $basePath;

    public function __construct(PDO $pdo, string $basePath)
    {
        $this->pdo = $pdo;
        $this->basePath = rtrim($basePath, '/\\');
    }

    /**
     * Applique la réconciliation. Retourne un rapport.
     * @return array{created:string[],altered:array<string,string[]>,errors:string[],checked:int}
     */
    public function sync(): array
    {
        $report = ['created' => [], 'altered' => [], 'errors' => [], 'checked' => 0];

        $tables = $this->collectDesiredTables();
        $report['checked'] = count($tables);

        // FK off : les CREATE peuvent référencer des tables pas encore créées
        // (ordre d'activation arbitraire entre modules).
        try { $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0'); } catch (\Throwable $e) { error_log('[SchemaSyncService.php] ' . $e->getMessage()); }

        foreach ($tables as $name => $def) {
            try {
                if (!$this->tableExists($name)) {
                    $this->pdo->exec($def['create']);
                    $report['created'][] = $name;
                    continue;
                }
                $live = $this->liveColumns($name);
                $added = [];
                foreach ($def['columns'] as $col => $columnDef) {
                    if (!isset($live[strtolower($col)])) {
                        $this->pdo->exec("ALTER TABLE `{$name}` ADD COLUMN {$columnDef}");
                        $added[] = $col;
                    }
                }
                if ($added) {
                    $report['altered'][$name] = $added;
                }
            } catch (\Throwable $e) {
                $report['errors'][] = "{$name} : " . $e->getMessage();
            }
        }

        try { $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (\Throwable $e) { error_log('[SchemaSyncService.php] ' . $e->getMessage()); }

        return $report;
    }

    /**
     * Agrège les définitions CREATE TABLE désirées depuis tous les .sql canoniques.
     * En cas de doublon (table dans pronote.sql ET dans un install.sql), les colonnes
     * sont fusionnées (union) ; le 1er CREATE rencontré sert de statement de création.
     * @return array<string,array{create:string,columns:array<string,string>}>
     */
    private function collectDesiredTables(): array
    {
        $sources = [];
        $core = $this->basePath . '/pronote.sql';
        if (is_file($core)) $sources[] = $core;
        foreach (glob($this->basePath . '/modules/*/Database/install.sql') ?: [] as $f) {
            $sources[] = $f;
        }

        $tables = [];
        foreach ($sources as $file) {
            $sql = (string) @file_get_contents($file);
            if ($sql === '') continue;
            foreach ($this->parseCreateTables($sql) as $name => $parsed) {
                if (!isset($tables[$name])) {
                    $tables[$name] = $parsed;
                } else {
                    // Fusion des colonnes (union) — garde le 1er CREATE.
                    foreach ($parsed['columns'] as $col => $def) {
                        if (!isset($tables[$name]['columns'][$col])) {
                            $tables[$name]['columns'][$col] = $def;
                        }
                    }
                }
            }
        }
        return $tables;
    }

    /**
     * Extrait les blocs CREATE TABLE d'un script SQL.
     * @return array<string,array{create:string,columns:array<string,string>}>
     */
    private function parseCreateTables(string $sql): array
    {
        $out = [];
        // Localise chaque "CREATE TABLE [IF NOT EXISTS] `name` (" puis capture le
        // corps parenthésé (profondeur équilibrée) + le suffixe jusqu'au ';'.
        $len = strlen($sql);
        $offset = 0;
        $re = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([a-zA-Z0-9_]+)`\s*\(/i';
        while (preg_match($re, $sql, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $name = $m[1][0];
            $parenStart = $m[0][1] + strlen($m[0][0]) - 1; // position du '('
            $depth = 0;
            $i = $parenStart;
            for (; $i < $len; $i++) {
                $ch = $sql[$i];
                if ($ch === '(') $depth++;
                elseif ($ch === ')') { $depth--; if ($depth === 0) break; }
            }
            if ($depth !== 0) { $offset = $parenStart + 1; continue; }
            $bodyStart = $parenStart + 1;
            $body = substr($sql, $bodyStart, $i - $bodyStart);
            // Suffixe (ENGINE=…) jusqu'au ';'
            $semi = strpos($sql, ';', $i);
            $suffix = $semi !== false ? substr($sql, $i, $semi - $i) : ')';
            $createStmt = "CREATE TABLE IF NOT EXISTS `{$name}` ({$body}{$suffix};";

            $out[$name] = [
                'create'  => $createStmt,
                'columns' => $this->parseColumns($body),
            ];
            $offset = ($semi !== false ? $semi : $i) + 1;
        }
        return $out;
    }

    /**
     * Découpe le corps d'un CREATE TABLE et retourne les définitions de COLONNES
     * (ignore PRIMARY/UNIQUE/KEY/INDEX/CONSTRAINT/FOREIGN/FULLTEXT).
     * @return array<string,string> nomColonne => définition complète (`col` type …)
     */
    private function parseColumns(string $body): array
    {
        $items = $this->splitTopLevel($body);
        $cols = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') continue;
            // Une colonne commence par un identifiant entre backticks suivi d'un type.
            if (preg_match('/^`([a-zA-Z0-9_]+)`\s+\S/', $item, $m)) {
                $cols[$m[1]] = $item;
            }
            // sinon : clé/contrainte → ignorée (le CREATE complet la gère à la création).
        }
        return $cols;
    }

    /** Découpe sur les virgules de PROFONDEUR 0 (hors parenthèses et chaînes). */
    private function splitTopLevel(string $body): array
    {
        $items = [];
        $depth = 0;
        $buf = '';
        $len = strlen($body);
        $inStr = false;
        $strCh = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($inStr) {
                $buf .= $ch;
                if ($ch === $strCh && ($i === 0 || $body[$i - 1] !== '\\')) $inStr = false;
                continue;
            }
            if ($ch === "'" || $ch === '"') { $inStr = true; $strCh = $ch; $buf .= $ch; continue; }
            if ($ch === '(') { $depth++; $buf .= $ch; continue; }
            if ($ch === ')') { $depth--; $buf .= $ch; continue; }
            if ($ch === ',' && $depth === 0) { $items[] = $buf; $buf = ''; continue; }
            $buf .= $ch;
        }
        if (trim($buf) !== '') $items[] = $buf;
        return $items;
    }

    private function tableExists(string $name): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
        );
        $stmt->execute([$name]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return array<string,true> colonnes vivantes (clé minuscule). */
    private function liveColumns(string $name): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$name]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $out[strtolower((string) $c)] = true;
        }
        return $out;
    }
}
