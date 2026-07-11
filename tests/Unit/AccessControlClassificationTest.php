<?php
declare(strict_types=1);

namespace Tests\Unit;

use API\Core\AccessControl;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou du deny-by-default (thème « front controller »). Chaque dossier de haut niveau
 * contenant des points d'entrée .php DOIT être explicitement classé — public, enforced,
 * self_guarded (monde à auth propre) — OU reconnu comme non-web (bloqué par .htaccess / lib / CLI).
 * Si un NOUVEAU dossier apparaît sans classement, ce test échoue → force une décision consciente
 * (au lieu d'une exposition — ou d'un blocage — silencieuse par défaut).
 */
final class AccessControlClassificationTest extends TestCase
{
    /** Dossiers de 1er niveau qui NE sont PAS des points d'entrée web (bloqués .htaccess, statiques, lib, CLI). */
    private const NON_WEB = [
        'vendor', 'node_modules', '.git', '.github', 'logs', 'storage', 'temp', 'uploads',
        'database', 'tests', 'cron', 'config', 'docker', 'docs', 'ltr', 'websocket', 'scripts',
        'assets', 'lang',
        // API/ et templates/ contiennent surtout de la lib/des includes : pas de point d'entrée
        // PUBLIC, le deny-by-default les protège correctement (API/endpoints est classé enforced).
        'API', 'templates',
    ];

    public function testEveryTopLevelWebDirIsClassified(): void
    {
        $root = \dirname(__DIR__, 2);
        $class = AccessControl::classification();
        $prefixes = array_map(
            static fn(string $p): string => rtrim($p, '/'),
            array_merge($class['public'], $class['enforced'], $class['self_guarded'])
        );

        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (in_array($name, self::NON_WEB, true)) {
                continue;
            }
            // Points d'entrée .php DIRECTEMENT dans ce dossier ?
            if (empty(glob($dir . '/*.php'))) {
                continue;
            }
            $classified = false;
            foreach ($prefixes as $p) {
                // Le dossier est couvert s'il correspond à un préfixe classé (ex. "admin" ⊂ "admin/…").
                if ($name === $p || str_starts_with($p, $name . '/') || str_starts_with($name . '/', $p . '/')) {
                    $classified = true;
                    break;
                }
            }
            $this->assertTrue(
                $classified,
                "Dossier de haut niveau « {$name}/ » NON classé dans AccessControl. "
                . "Sous deny-by-default il est protégé par défaut : classez-le explicitement "
                . "(public/enforced/self_guarded) ou ajoutez-le à NON_WEB s'il n'est pas un point d'entrée web."
            );
        }
    }

    public function testClassifiedPrefixesPointToRealDirs(): void
    {
        // Pas de classement mort : chaque préfixe de dossier classé doit exister.
        $root = \dirname(__DIR__, 2);
        foreach (AccessControl::classification()['self_guarded'] as $p) {
            $d = rtrim($p, '/');
            $this->assertDirectoryExists($root . '/' . $d, "self_guarded classé mais dossier absent : {$p}");
        }
    }
}
