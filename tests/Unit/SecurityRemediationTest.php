<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

// Fonctions globales (csv_safe / js_json) — non autoloadées (pas de classe).
require_once __DIR__ . '/../../API/Core/helpers.php';

/**
 * Verrouille les correctifs de sécurité à LOGIQUE PURE issus de la remédiation de l'audit
 * (déterministes, sans base) → protection anti-régression :
 *   - csv_safe()   : neutralisation d'injection de formule CSV (findings #13).
 *   - js_json()    : échappement </script>/'/"/& en <script> (finding #26).
 *   - WebPushService::isAllowedEndpoint() : allowlist anti-SSRF des endpoints push (#25/#28).
 */
final class SecurityRemediationTest extends TestCase
{
    /** @dataProvider csvCases */
    public function testCsvSafeNeutralizesFormulaInjection(string $in, string $out): void
    {
        $this->assertSame($out, csv_safe($in));
    }

    public static function csvCases(): array
    {
        return [
            'equals' => ['=1+1', "'=1+1"],
            'plus'   => ['+SUM(A1)', "'+SUM(A1)"],
            'minus'  => ['-2+3', "'-2+3"],
            'at'     => ['@cmd', "'@cmd"],
            'tab'    => ["\tx", "'\tx"],
            'cr'     => ["\rx", "'\rx"],
            'benign' => ['Dupont', 'Dupont'],
            'empty'  => ['', ''],
        ];
    }

    public function testJsJsonEscapesScriptBreakers(): void
    {
        $out = js_json('</script><img src=x onerror=alert(1)>');
        $this->assertStringNotContainsString('<', $out, 'js_json doit échapper < (anti </script>)');
        $this->assertStringNotContainsString('>', $out);

        $amp = js_json('a & b');
        $this->assertStringNotContainsString(' & ', $amp, 'js_json doit échapper &');

        // Valeur inoffensive : reste un JSON valide décodable.
        $this->assertSame('bonjour', json_decode(js_json('bonjour'), true));
    }

    /** @dataProvider pushEndpoints */
    public function testWebPushSsrfAllowlist(string $endpoint, bool $allowed): void
    {
        $rc  = new ReflectionClass(\API\Services\WebPushService::class);
        $svc = $rc->newInstanceWithoutConstructor(); // isAllowedEndpoint n'utilise pas $this->pdo
        $m   = $rc->getMethod('isAllowedEndpoint');
        $m->setAccessible(true);

        $this->assertSame($allowed, $m->invoke($svc, $endpoint), "endpoint: {$endpoint}");
    }

    public static function pushEndpoints(): array
    {
        return [
            'fcm'         => ['https://fcm.googleapis.com/fcm/send/abc123', true],
            'apple'       => ['https://web.push.apple.com/QABCdef', true],
            'mozilla'     => ['https://updates.push.services.mozilla.com/wpush/v2/xyz', true],
            'loopback'    => ['https://127.0.0.1/x', false],
            'metadata'    => ['https://169.254.169.254/latest/meta-data/', false],
            'internal-ip' => ['https://10.0.0.5/x', false],
            'evil-host'   => ['https://evil.example.com/collect', false],
            'not-https'   => ['http://fcm.googleapis.com/x', false],
            'garbage'     => ['not-a-url', false],
            'file-scheme' => ['file:///etc/passwd', false],
        ];
    }
}
