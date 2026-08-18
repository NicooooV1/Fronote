<?php
declare(strict_types=1);

namespace Dist;

use RuntimeException;

/**
 * Construit les archives de distribution depuis le dépôt Fronote et les SIGNE (Ed25519).
 *
 *   - base.tar.gz     : le socle applicatif + UNIQUEMENT les « modules simples »
 *                       (les modules premium en sont exclus → téléchargés ensuite).
 *   - module-<x>.tar.gz : un module premium isolé (modules/<x>/).
 *
 * Exclut systématiquement : .git, vendor, node_modules, secrets, données runtime, et
 * le composant de distribution lui-même (dist/data, dist/config.php). vendor/ n'est PAS
 * inclus : le bootstrapper lance « composer install » côté client.
 */
final class PayloadBuilder
{
    private string $appRoot;
    private string $outDir;
    private Signer $signer;
    /** @var string[] */
    private array $simpleModules;

    /** @param string[] $simpleModules */
    public function __construct(string $appRoot, string $outDir, Signer $signer, array $simpleModules)
    {
        $this->appRoot = rtrim($appRoot, '/');
        $this->outDir = rtrim($outDir, '/');
        $this->signer = $signer;
        $this->simpleModules = $simpleModules;
        if (!is_dir($this->outDir) && !@mkdir($this->outDir, 0750, true) && !is_dir($this->outDir)) {
            throw new RuntimeException("Impossible de créer {$this->outDir}");
        }
    }

    /** @return string[] Tous les modules présents dans modules/. */
    public function allModules(): array
    {
        $out = [];
        foreach (glob($this->appRoot . '/modules/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (is_file($d . '/module.json')) {
                $out[] = basename($d);
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Modules « core » = ceux qui déclarent `"core": true` dans leur module.json.
     * Ce sont des briques SYSTÈME (onboarding, profil, support, notifications…) dont
     * l'application a besoin pour fonctionner dès la première connexion — ils DOIVENT
     * donc voyager dans le socle, quelle que soit la licence.
     *
     * ⚠️ Correctif critique 2026-08-18 : sans ce garde-fou, le module `onboarding`
     * (core) tombait dans « premium » (car absent de simple_modules), était exclu de
     * base.tar.gz, et toute installation neuve se terminait en 404 au premier login —
     * l'école ne pouvait jamais se configurer (test e2e réel CT129).
     *
     * @return string[]
     */
    public function coreModules(): array
    {
        $out = [];
        foreach ($this->allModules() as $m) {
            $mj = @file_get_contents($this->appRoot . '/modules/' . $m . '/module.json');
            if ($mj !== false) {
                $data = json_decode($mj, true);
                if (is_array($data) && !empty($data['core'])) {
                    $out[] = $m;
                }
            }
        }
        return $out;
    }

    /**
     * Modules livrés DANS le socle = union des modules simples (grille tarifaire) et des
     * modules core (indispensables au fonctionnement). Data-driven : ajouter un module
     * core à l'avenir le fait voyager automatiquement dans base.tar.gz.
     *
     * @return string[]
     */
    public function baseModules(): array
    {
        return array_values(array_unique(array_merge($this->simpleModules, $this->coreModules())));
    }

    /** @return string[] Modules premium = tous sauf ceux du socle (simples + core). */
    public function premiumModules(): array
    {
        return array_values(array_diff($this->allModules(), $this->baseModules()));
    }

    /**
     * Construit + signe le socle (base + modules simples). @return array{archive:string,sig:string,sha256:string,bytes:int}
     */
    public function buildBase(): array
    {
        $excludes = [
            '.git', '.github', 'vendor', 'node_modules', 'websocket/node_modules',
            'dist/data', 'dist/config.php', 'dist/discord-bot/node_modules', 'dist/discord-bot/.env',
            '.env', 'install.lock', 'install.conf', 'storage', 'uploads', 'temp', 'API/logs',
            '.phpunit.result.cache',
        ];
        // Exclure tous les modules premium du socle.
        foreach ($this->premiumModules() as $m) {
            $excludes[] = 'modules/' . $m;
        }
        $archive = $this->outDir . '/base.tar.gz';
        $this->tar($archive, ['.'], $excludes);
        return $this->finalize($archive);
    }

    /** Construit + signe un module premium isolé. @return array{archive:string,sig:string,sha256:string,bytes:int} */
    public function buildModule(string $module): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $module) || !is_dir($this->appRoot . '/modules/' . $module)) {
            throw new RuntimeException("Module inconnu : {$module}");
        }
        $archive = $this->outDir . '/module-' . $module . '.tar.gz';
        $this->tar($archive, ['modules/' . $module], []);
        return $this->finalize($archive);
    }

    /** Construit le socle + tous les modules premium. @return array<string,array<string,mixed>> */
    public function buildAll(): array
    {
        $report = ['base' => $this->buildBase()];
        foreach ($this->premiumModules() as $m) {
            $report['module:' . $m] = $this->buildModule($m);
        }
        return $report;
    }

    /**
     * @param string[] $paths
     * @param string[] $excludes
     */
    private function tar(string $archive, array $paths, array $excludes): void
    {
        $cmd = 'tar -czf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($this->appRoot);
        foreach ($excludes as $e) {
            $cmd .= ' --exclude=' . escapeshellarg($e);
        }
        foreach ($paths as $p) {
            $cmd .= ' ' . escapeshellarg($p);
        }
        $cmd .= ' 2>&1';
        exec($cmd, $out, $code);
        if ($code !== 0) {
            throw new RuntimeException("tar a échoué ({$code}) : " . implode("\n", $out));
        }
    }

    /** @return array{archive:string,sig:string,sha256:string,bytes:int} */
    private function finalize(string $archive): array
    {
        $sig = $this->signer->signFile($archive);
        file_put_contents($archive . '.sig', $sig);
        @chmod($archive, 0640);
        @chmod($archive . '.sig', 0640);
        return [
            'archive' => $archive,
            'sig'     => $archive . '.sig',
            'sha256'  => hash_file('sha256', $archive),
            'bytes'   => (int) filesize($archive),
        ];
    }
}
