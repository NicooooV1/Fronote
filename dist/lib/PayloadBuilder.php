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

    /** @return string[] Modules premium = tous sauf les simples. */
    public function premiumModules(): array
    {
        return array_values(array_diff($this->allModules(), $this->simpleModules));
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
