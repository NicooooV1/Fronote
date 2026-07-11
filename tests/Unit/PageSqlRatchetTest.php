<?php
declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Cliquet anti-dette « SQL brut dans les pages » (thème couche de données).
 *
 * La revue de design a relevé que le scoping multi-tenant dépend de la vigilance de chaque
 * appelant : du SQL brut (`->prepare()`/`->query()`/`getPDO()->`) directement dans des fichiers
 * de PAGE (points d'entrée) est la porte ouverte aux fuites cross-établissement. La cible est de
 * faire passer ce SQL par une couche data (Repository / requête scopée EstablishmentContext).
 *
 * Ce test NE migre pas les pages : il pose un PLAFOND (baseline) qui ne peut que DÉCROÎTRE.
 * Toute NOUVELLE page avec du SQL brut fait échouer la CI ; chaque page migrée permet d'abaisser
 * le plafond. Même logique qu'une baseline PHPStan.
 */
final class PageSqlRatchetTest extends TestCase
{
    /** Plafond courant. À n'ABAISSER que (jamais relever) au fil des migrations vers la couche data. */
    private const BASELINE = 84;

    private const PAGE_DIRS = ['modules', 'admin', 'accueil', 'parametres', 'rgpd', 'tenant', 'platform', 'director', 'impersonation'];

    /** Sous-dossiers de couche data / non-page → exclus (le SQL y est légitime). */
    private const DATA_LAYER = '#/(includes|Services|models|Providers|Database|Events|Jobs|Widgets|Http|Domain|Repositories|Support|core|config|controllers|lang|assets|views)/#';

    public function testNoNewRawSqlInPageEntrypoints(): void
    {
        $root = \dirname(__DIR__, 2);
        $count = 0;
        foreach (self::PAGE_DIRS as $pd) {
            $dir = $root . '/' . $pd;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php') {
                    continue;
                }
                $path = str_replace('\\', '/', $f->getPathname());
                if (preg_match(self::DATA_LAYER, $path)) {
                    continue;
                }
                $src = (string) file_get_contents($path);
                if (preg_match('/->(prepare|query)\(|getPDO\(\)->/', $src)) {
                    $count++;
                }
            }
        }

        $this->assertLessThanOrEqual(
            self::BASELINE,
            $count,
            "SQL brut dans une PAGE au-delà du plafond ({$count} > " . self::BASELINE . "). "
            . "Le SQL d'un point d'entrée doit passer par un Repository / une requête scopée "
            . "EstablishmentContext, pas directement dans la page (cloisonnement tenant). "
            . "Ce plafond ne doit que décroître."
        );

        // Info : si le compte a DÉCRU sous la baseline, pense à abaisser self::BASELINE.
        $this->assertGreaterThan(0, self::BASELINE);
    }
}
