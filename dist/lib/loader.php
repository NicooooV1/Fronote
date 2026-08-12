<?php
declare(strict_types=1);

/**
 * Chargeur commun du composant de distribution (autonome : n'utilise PAS l'autoload
 * composer de l'application, pour rester isolé). Partagé par les CLIs et le serveur HTTP.
 */

require_once __DIR__ . '/DistStore.php';
require_once __DIR__ . '/Signer.php';
require_once __DIR__ . '/PayloadBuilder.php';

/** Charge dist/config.php. Lève RuntimeException si absent. @return array<string,mixed> */
function dist_config(): array
{
    $cfgFile = dirname(__DIR__) . '/config.php';
    if (!is_file($cfgFile)) {
        throw new \RuntimeException('dist/config.php absent — copiez dist/config.example.php et renseignez-le.');
    }
    /** @var array<string,mixed> $cfg */
    $cfg = require $cfgFile;
    foreach (['key_pepper', 'admin_token'] as $req) {
        if (empty($cfg[$req]) || str_starts_with((string) $cfg[$req], 'REMPLACEZ')) {
            throw new \RuntimeException("dist/config.php : « {$req} » doit être renseigné (openssl rand -hex 32).");
        }
    }
    return $cfg;
}

/** @param array<string,mixed> $cfg */
function dist_store(array $cfg): \Dist\DistStore
{
    return new \Dist\DistStore(rtrim((string) $cfg['data_dir'], '/') . '/dist.sqlite', $cfg);
}

/** @param array<string,mixed> $cfg */
function dist_signer(array $cfg): \Dist\Signer
{
    $dir = rtrim((string) $cfg['data_dir'], '/');
    return new \Dist\Signer($dir . '/signing.key', $dir . '/signing.pub');
}
