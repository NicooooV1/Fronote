<?php
declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Garde-fous d'hygiène front (audit qualité HTML/CSS/JS).
 *
 * Verrouille deux invariants établis par la correction de l'audit :
 *
 *  1. AUCUNE dépendance CDN externe (Font Awesome, Socket.IO, Chart.js…). L'app est déployée en
 *     réseau local par l'établissement : toute ressource tierce chargée depuis internet casse
 *     (icônes, WebSocket, graphes) hors ligne et échappe à la CSP stricte. Les libs sont
 *     vendorisées sous assets/lib/ et servies same-origin. Cf. findings M7/M8/m19/m24/m40/m41/m47.
 *
 *  2. AUCUN gestionnaire inline `on*=` (onclick/onchange/…) dans les templates/pages du socle. La
 *     CSP retire 'unsafe-inline' de script-src : ces handlers ne s'exécutent pas. Ils sont convertis
 *     en attributs data-fr-* résolus par csp-actions.js. Cf. findings M1/m42.
 *
 * Contrairement au cliquet SQL, la cible ici est ZÉRO (invariant, pas migration progressive).
 */
final class FrontendHygieneTest extends TestCase
{
    /** Motifs d'hôtes CDN interdits dans le code servi. */
    private const CDN_PATTERNS = ['cdnjs.cloudflare', 'cdn.socket.io', 'cdn.jsdelivr', 'unpkg.com'];

    /** Dossiers de pages du socle où un handler on*= inline est un bug (CSP). Hors platform/tenant (îlots documentés). */
    private const PAGE_DIRS = ['templates', 'modules', 'admin', 'accueil', 'parametres'];

    /** Chemins exclus des scans (vendorisé, dépendances, docs, tests). */
    private const EXCLUDE = '#/(assets/lib|vendor|node_modules|\.git|tests|docs)/#';

    public function testNoExternalCdnReferences(): void
    {
        $root = \dirname(__DIR__, 2);
        $offenders = [];
        foreach ($this->files($root, ['php', 'js', 'html']) as $file) {
            $content = (string) file_get_contents($file);
            foreach (self::CDN_PATTERNS as $needle) {
                if (strpos($content, $needle) !== false) {
                    $offenders[] = str_replace($root . '/', '', $file) . ' → ' . $needle;
                    break;
                }
            }
        }
        $this->assertSame(
            [],
            $offenders,
            "Dépendance CDN externe détectée (à vendoriser sous assets/lib/) :\n" . implode("\n", $offenders)
        );
    }

    public function testNoInlineEventHandlersInPages(): void
    {
        $root = \dirname(__DIR__, 2);
        $re = '/\son(?:click|change|submit|input|load|error|keyup|keydown|mouseover|mouseout|focus|blur)\s*=/i';
        $offenders = [];
        foreach (self::PAGE_DIRS as $pd) {
            $dir = $root . '/' . $pd;
            if (!is_dir($dir)) {
                continue;
            }
            foreach ($this->files($dir, ['php']) as $file) {
                $content = (string) file_get_contents($file);
                if (preg_match($re, $content)) {
                    $offenders[] = str_replace($root . '/', '', $file);
                }
            }
        }
        $this->assertSame(
            [],
            $offenders,
            "Gestionnaire on*= inline (cassé par la CSP stricte) — convertir en data-fr-* :\n" . implode("\n", $offenders)
        );
    }

    /**
     * @param string[] $exts
     * @return iterable<string>
     */
    private function files(string $dir, array $exts): iterable
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            $path = str_replace('\\', '/', $f->getPathname());
            if (preg_match(self::EXCLUDE, $path)) {
                continue;
            }
            if (in_array(strtolower($f->getExtension()), $exts, true)) {
                yield $f->getPathname();
            }
        }
    }
}
