<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use API\Core\ProductionReadinessChecker;

/**
 * Vérifie le garde-fou de configuration production (fail-fast au boot).
 *
 * La classe est pure : on lui injecte un tableau d'environnement et on observe la
 * liste de problèmes / la décision de blocage. Aucun I/O, aucune dépendance runtime.
 */
final class ProductionReadinessCheckerTest extends TestCase
{
    /** Environnement de production parfaitement sain (baseline : zéro problème). */
    private function goodEnv(): array
    {
        return [
            'APP_ENV'                   => 'production',
            'APP_DEBUG'                 => 'false',
            'APP_URL'                   => 'https://ecole.example.fr',
            'APP_KEY'                   => str_repeat('a', 64),
            'JWT_SECRET'                => str_repeat('b', 64),
            'WEBSOCKET_ENABLED'         => 'true',
            'WEBSOCKET_API_SECRET'      => str_repeat('c', 64),
            'WEBSOCKET_ALLOWED_ORIGINS' => 'https://ecole.example.fr',
            'SESSION_SECURE'            => 'true',
            'HEALTH_TOKEN'              => str_repeat('d', 32),
            'DB_PASS'                   => 'a-real-db-password',
        ];
    }

    public function testCleanProductionConfigHasNoIssues(): void
    {
        $checker = new ProductionReadinessChecker($this->goodEnv(), true);
        $this->assertSame([], $checker->check(), 'Une config production saine ne doit remonter aucun problème.');
        $this->assertFalse($checker->shouldBlockBoot());
    }

    public function testPlaceholderWebsocketSecretBlocksBoot(): void
    {
        $env = $this->goodEnv();
        $env['WEBSOCKET_API_SECRET'] = 'your_secure_secret_here';
        $checker = new ProductionReadinessChecker($env, true);

        $this->assertTrue($checker->shouldBlockBoot(), 'Un secret WS factice doit bloquer le boot en production.');
        $keys = array_column($checker->criticalIssues(), 'key');
        $this->assertContains('WEBSOCKET_API_SECRET', $keys);
    }

    public function testEmptyMasterKeysAreCritical(): void
    {
        $env = $this->goodEnv();
        $env['APP_KEY'] = '';
        $env['JWT_SECRET'] = '';
        // WS désactivé pour isoler la cause racine (sinon le WS ajoute aussi des criticals).
        $env['WEBSOCKET_ENABLED'] = 'false';
        $checker = new ProductionReadinessChecker($env, true);

        $keys = array_column($checker->criticalIssues(), 'key');
        $this->assertContains('APP_KEY', $keys, 'APP_KEY et JWT_SECRET tous deux vides = critique.');
        $this->assertTrue($checker->shouldBlockBoot());
    }

    public function testAppDebugInProductionIsCritical(): void
    {
        $env = $this->goodEnv();
        $env['APP_DEBUG'] = 'true';
        $checker = new ProductionReadinessChecker($env, true);

        $this->assertContains('APP_DEBUG', array_column($checker->criticalIssues(), 'key'));
    }

    public function testInsecureSessionCookieOnHttpsIsCritical(): void
    {
        $env = $this->goodEnv();
        $env['SESSION_SECURE'] = 'false';
        $checker = new ProductionReadinessChecker($env, /* isHttps */ true);

        $this->assertContains('SESSION_SECURE', array_column($checker->criticalIssues(), 'key'));
    }

    public function testInsecureSessionCookieOnHttpLanIsTolerated(): void
    {
        // Petite instance en HTTP sur réseau local : SESSION_SECURE=false ne doit PAS
        // bloquer (sinon le cookie ne serait jamais émis et l'auth casserait).
        $env = $this->goodEnv();
        $env['SESSION_SECURE'] = 'false';
        $env['APP_URL'] = 'http://192.168.1.10';
        $checker = new ProductionReadinessChecker($env, /* isHttps */ false);

        $sessionCriticals = array_filter($checker->criticalIssues(), fn($i) => $i['key'] === 'SESSION_SECURE');
        $this->assertSame([], $sessionCriticals, 'SESSION_SECURE=false en HTTP ne doit pas être critique.');
    }

    public function testHttpAppUrlIsWarningNotCritical(): void
    {
        $env = $this->goodEnv();
        $env['APP_URL'] = 'http://ecole.example.fr';
        // En HTTP, on évite aussi le critical SESSION_SECURE.
        $checker = new ProductionReadinessChecker($env, false);

        $this->assertContains('APP_URL', array_column($checker->warnings(), 'key'));
        $this->assertNotContains('APP_URL', array_column($checker->criticalIssues(), 'key'));
    }

    public function testShortSecretIsWeak(): void
    {
        $env = $this->goodEnv();
        $env['APP_KEY'] = 'short';
        $checker = new ProductionReadinessChecker($env, true);

        $this->assertContains('APP_KEY', array_column($checker->criticalIssues(), 'key'));
    }

    public function testNonProductionNeverBlocksBoot(): void
    {
        // En dev, même une config catastrophique ne doit jamais bloquer le démarrage.
        $env = $this->goodEnv();
        $env['APP_ENV']  = 'local';
        $env['APP_KEY']  = '';
        $env['JWT_SECRET'] = '';
        $env['APP_DEBUG'] = 'true';
        $checker = new ProductionReadinessChecker($env, true);

        $this->assertFalse($checker->isProduction());
        $this->assertFalse($checker->shouldBlockBoot(), 'Hors production, shouldBlockBoot() est toujours false.');
        // Les problèmes restent listés (utile pour journaliser), seul le blocage est inhibé.
        $this->assertNotEmpty($checker->criticalIssues());
    }
}
