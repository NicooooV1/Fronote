<?php
declare(strict_types=1);

namespace API\Services;

/**
 * UpdateService — mise à jour Fronote en UN bouton, via le dépôt Git.
 *
 * Flux (synchrone, simple) :
 *   git fetch  →  git reset --hard origin/<branche>  →  sync schéma SQL
 *   (déclaratif, sans migrations)  →  re-sync des module.json  →  vide le cache.
 *
 * Aucune migration, aucun zip, aucun téléchargement de release : le dépôt Git
 * EST la source. Les changements de schéma sont appliqués de façon idempotente
 * par SchemaSyncService (création de tables/colonnes manquantes) — donc pas
 * besoin de réinitialiser la base après un commit.
 *
 * Config .env :
 *   GITHUB_BRANCH=main      (branche à suivre)
 *   GIT_BINARY=git          (chemin de git si absent du PATH d'Apache, ex. Windows)
 */
class UpdateService
{
    private string $basePath;
    private string $branch;
    private string $currentVersion;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->branch = getenv('GITHUB_BRANCH') ?: 'main';
        $this->currentVersion = $this->readVersion();
    }

    public function getCurrentVersion(): string
    {
        return $this->currentVersion;
    }

    public function getBranch(): string
    {
        return $this->branch;
    }

    public function isGitAvailable(): bool
    {
        $this->git('--version', $code);
        return $code === 0;
    }

    /**
     * Y a-t-il des commits en attente sur origin/<branche> ?
     * @return array|null  null si à jour / git indisponible
     */
    public function checkForUpdate(): ?array
    {
        if (!$this->isGitAvailable()) {
            return null;
        }
        $this->git('fetch --quiet origin ' . escapeshellarg($this->branch), $code);
        $local  = trim($this->git('rev-parse HEAD', $c1));
        $remote = trim($this->git('rev-parse ' . escapeshellarg('origin/' . $this->branch), $c2));
        if ($c1 !== 0 || $c2 !== 0 || $local === '' || $remote === '' || $local === $remote) {
            return null;
        }
        $behind = (int) trim($this->git('rev-list --count ' . escapeshellarg('HEAD..origin/' . $this->branch), $c3));
        $logRaw = $this->git('log --oneline --no-decorate -15 ' . escapeshellarg('HEAD..origin/' . $this->branch), $c4);
        $commits = array_values(array_filter(array_map('trim', explode("\n", $logRaw))));

        return [
            'available'       => true,
            'current_version' => $this->currentVersion,
            'branch'          => $this->branch,
            'behind'          => $behind,
            'commits'         => $commits,
        ];
    }

    /**
     * Applique la mise à jour : pull du code + réconciliation du schéma SQL.
     * @return array{success:bool,steps:string[],schema?:array,old_version?:string,new_version?:string,error?:string}
     */
    public function applyUpdate(): array
    {
        $steps = [];

        if (!$this->isGitAvailable()) {
            return ['success' => false, 'error' => "git est introuvable. Installez-le ou renseignez GIT_BINARY dans le .env.", 'steps' => $steps];
        }

        $old = $this->currentVersion;

        // 0) Sauvegarde du .env (par sécurité, même si .gitignore le protège normalement).
        $envFile = $this->basePath . '/.env';
        $envBackup = null;
        if (is_file($envFile)) {
            $envBackup = @file_get_contents($envFile);
        }

        // 0b) Filet de récupération : capturer l'état AVANT toute opération destructive
        //     (HEAD git + sauvegarde de la base) et passer le site en maintenance.
        $oldHead = trim($this->git('rev-parse HEAD', $cHead));
        if ($cHead !== 0 || $oldHead === '') { $oldHead = null; }
        $maintActive = false;
        try { app('maintenance')->activate('Mise à jour en cours…'); $maintActive = true; $steps[] = 'Mode maintenance activé'; }
        catch (\Throwable $e) { $steps[] = 'Mode maintenance indisponible : ' . $e->getMessage(); }
        $dbBackup = null;
        try { $dbBackup = app('backup')->createDatabaseBackup(); $steps[] = 'Sauvegarde base : ' . basename((string) $dbBackup); }
        catch (\Throwable $e) { $steps[] = 'Sauvegarde base indisponible : ' . $e->getMessage(); }

        // Restaure base + code au commit précédent (rollback).
        $rollback = function (string $reason) use (&$steps, $oldHead, $dbBackup): void {
            $steps[] = '⚠️ Rollback : ' . $reason;
            if ($dbBackup) {
                try { app('backup')->restoreDatabase($dbBackup); $steps[] = 'Base restaurée depuis la sauvegarde'; }
                catch (\Throwable $e) { $steps[] = 'Restauration base ÉCHOUÉE : ' . $e->getMessage(); }
            }
            if ($oldHead) {
                $this->git('reset --hard ' . escapeshellarg($oldHead), $rc);
                $steps[] = $rc === 0 ? 'Code remis à ' . substr($oldHead, 0, 8) : 'Rollback git ÉCHOUÉ';
            }
        };
        $endMaintenance = function () use (&$steps, &$maintActive): void {
            if ($maintActive) { try { app('maintenance')->deactivate(); $steps[] = 'Maintenance désactivée'; } catch (\Throwable $e) {} $maintActive = false; }
        };

        // 1) git fetch
        $out = $this->git('fetch origin ' . escapeshellarg($this->branch), $c1);
        $steps[] = 'git fetch' . ($out !== '' ? ' : ' . $out : '');
        if ($c1 !== 0) {
            $endMaintenance();
            return ['success' => false, 'error' => 'git fetch a échoué : ' . $out, 'steps' => $steps];
        }

        // 2) git reset --hard origin/<branche>  (le serveur reflète exactement le dépôt)
        $out = $this->git('reset --hard ' . escapeshellarg('origin/' . $this->branch), $c2);
        $steps[] = 'git reset --hard origin/' . $this->branch . ($out !== '' ? ' : ' . $out : '');
        if ($c2 !== 0) {
            $rollback('git reset a échoué');
            $endMaintenance();
            return ['success' => false, 'rolled_back' => true, 'error' => 'git reset a échoué : ' . $out, 'steps' => $steps];
        }

        // 2b) Restaurer le .env s'il a disparu (cas où il serait suivi par erreur).
        if ($envBackup !== null && !is_file($envFile)) {
            @file_put_contents($envFile, $envBackup);
            $steps[] = '.env restauré';
        }

        // 3) Réconciliation du schéma SQL (déclaratif, idempotent ; vraies migrations via MigrationRunner).
        $schema = ['created' => [], 'altered' => [], 'errors' => [], 'checked' => 0];
        try {
            $sync = new SchemaSyncService(getPDO(), $this->basePath);
            $schema = $sync->sync();
            $steps[] = sprintf(
                'Schéma SQL : %d table(s) vérifiée(s), %d créée(s), %d modifiée(s)%s',
                $schema['checked'], count($schema['created']), count($schema['altered']),
                $schema['errors'] ? ', ' . count($schema['errors']) . ' erreur(s)' : ''
            );
        } catch (\Throwable $e) {
            $steps[] = 'Schéma SQL : échec — ' . $e->getMessage();
            $schema['errors'][] = $e->getMessage();
        }

        // 3a) Migrations versionnées (transformations que SchemaSyncService ne sait pas faire).
        try {
            $mig = (new MigrationRunner(getPDO(), $this->basePath))->migrate();
            if (!empty($mig['applied'])) $steps[] = 'Migrations appliquées : ' . implode(', ', $mig['applied']);
            if (!empty($mig['errors'])) { $schema['errors'][] = 'migration: ' . implode(' ; ', $mig['errors']); }
        } catch (\Throwable $e) {
            $steps[] = 'Migrations : échec — ' . $e->getMessage();
            $schema['errors'][] = 'migration: ' . $e->getMessage();
        }

        // 3b) Schéma OU migration en erreur → on annule TOUT (base + code restaurés).
        if (!empty($schema['errors'])) {
            $rollback('réconciliation du schéma/migrations en erreur');
            $endMaintenance();
            $this->currentVersion = $this->readVersion();
            return [
                'success'     => false,
                'rolled_back' => true,
                'steps'       => $steps,
                'schema'      => $schema,
                'old_version' => $old,
                'new_version' => $this->currentVersion,
                'error'       => 'Mise à jour annulée et restaurée : ' . implode(' ; ', $schema['errors']),
            ];
        }

        // 4) Re-synchroniser les manifestes des modules (permissions, widgets, routes…).
        try {
            $r = app('module_sdk')->syncAll();
            $steps[] = 'Modules synchronisés : ' . ($r['synced'] ?? 0);
        } catch (\Throwable $e) {
            $steps[] = 'Sync modules : échec — ' . $e->getMessage();
        }

        // 4b) Synchroniser le catalogue de rôles RBAC (rbac_roles + grants rôle→permission).
        try {
            $rs = (new \API\Security\RoleSync(getPDO()))->sync();
            $steps[] = sprintf('Rôles RBAC : %d rôle(s), %d permission(s) synchronisé(es)', $rs['roles'], $rs['grants']);
        } catch (\Throwable $e) {
            $steps[] = 'Sync rôles RBAC : échec — ' . $e->getMessage();
        }

        // 5) Vider le cache applicatif.
        try { app('cache')->flush(); $steps[] = 'Cache vidé'; } catch (\Throwable $e) {}

        // 6) Sortie de maintenance + relire la version.
        $endMaintenance();
        $this->currentVersion = $this->readVersion();

        return [
            'success'     => true,
            'steps'       => $steps,
            'schema'      => $schema,
            'old_version' => $old,
            'new_version' => $this->currentVersion,
        ];
    }

    // ─── Helpers privés ─────────────────────────────────────────────

    private function readVersion(): string
    {
        $f = $this->basePath . '/version.json';
        $data = is_file($f) ? json_decode((string) file_get_contents($f), true) : [];
        return $data['version'] ?? '0.0.0';
    }

    private function gitBin(): string
    {
        $bin = getenv('GIT_BINARY');
        return ($bin && trim($bin) !== '') ? trim($bin) : 'git';
    }

    /** Exécute `git -C <basePath> <args>` et renvoie la sortie (stdout+stderr). */
    private function git(string $args, ?int &$code = 0): string
    {
        $cmd = $this->gitBin() . ' -C ' . escapeshellarg($this->basePath) . ' ' . $args . ' 2>&1';
        $output = [];
        $code = 0;
        @exec($cmd, $output, $code);
        return trim(implode("\n", $output));
    }
}
