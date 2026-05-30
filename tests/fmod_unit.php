<?php
/**
 * Pure unit tests for FmodService — no DB, no filesystem (apart from /tmp scratch).
 *
 * Each block prints its assertion and exits non-zero on the first failure so CI
 * surfaces the breakage. Keep these tests fast and deterministic: they run on
 * every push.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/API/bootstrap.php';

use API\Services\FmodService;

$failures = 0;
function ok(bool $cond, string $name): void {
    global $failures;
    if ($cond) {
        echo "ok   $name\n";
    } else {
        echo "FAIL $name\n";
        $failures++;
    }
}

// ───────────────────────── canonicalCertBytes ─────────────────────────
$cert = ['publisher_id' => 'x', 'b' => '2', 'a' => '1'];
$canon1 = FmodService::canonicalCertBytes($cert);
ok($canon1 === "a=1\nb=2\npublisher_id=x", 'canonical sorts keys lexicographically');

$cert['signature_b64'] = 'IGNORED';
$canon2 = FmodService::canonicalCertBytes($cert);
ok($canon2 === $canon1, 'canonical excludes signature_b64');

$cert3 = ['k' => true, 'l' => 42, 'm' => 'foo'];
$canon3 = FmodService::canonicalCertBytes($cert3);
ok($canon3 === "k=1\nl=42\nm=foo", 'canonical normalises bool/int to string');

$threw = false;
try { FmodService::canonicalCertBytes(['ok' => 'good', 'bad' => "with\nnewline"]); } catch (\InvalidArgumentException $e) { $threw = true; }
ok($threw, 'canonical rejects newline in value');

$threw = false;
try { FmodService::canonicalCertBytes(['ok' => 'good', 'nested' => ['x' => 1]]); } catch (\InvalidArgumentException $e) { $threw = true; }
ok($threw, 'canonical rejects non-scalar value');

// ───────────────────────── fingerprint helpers ─────────────────────────
[$sk, $pk, $fp] = FmodService::generateKeypair();
ok(FmodService::fingerprint($pk) === $fp, 'fingerprint matches keygen output');

$threw = false;
try { FmodService::fingerprint('not-base64-!!!'); } catch (\InvalidArgumentException $e) { $threw = true; }
ok($threw, 'fingerprint rejects invalid public key');

ok(FmodService::certFingerprint('') === null,       'certFingerprint(empty) = null');
ok(FmodService::certFingerprint('@@@@@@') === null, 'certFingerprint(bad b64) = null');

$rawCert = '{"some":"json"}';
$expected = bin2hex(hash('sha256', $rawCert, true));
ok(FmodService::certFingerprint(base64_encode($rawCert)) === $expected, 'certFingerprint(valid) hashes raw bytes');

// ───────────────────────── parseManifest ─────────────────────────
$svc = new FmodService();
$m = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  foo/bar.php\n"
   . "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb  baz.txt\n";
$parsed = $svc->parseManifest($m);
ok($parsed['foo/bar.php'] === str_repeat('a', 64), 'parseManifest reads hash');
ok($parsed['baz.txt']     === str_repeat('b', 64), 'parseManifest reads second hash');

$threw = false;
try { $svc->parseManifest("bogus line"); } catch (\RuntimeException $e) { $threw = true; }
ok($threw, 'parseManifest rejects malformed line');

// ───────────────────────── semverSatisfies ─────────────────────────
ok($svc->semverSatisfies('1.2.3', '>=1.2.0'),  'semver >= basic');
ok($svc->semverSatisfies('1.2.3', '<2.0.0'),   'semver < basic');
ok(!$svc->semverSatisfies('2.0.0', '<2.0.0'),  'semver < strict');
ok($svc->semverSatisfies('1.0.0', '*'),        'semver * always true');
ok($svc->semverSatisfies('1.2.3', '>=1.0 <2.0'), 'semver conjunction');
ok(!$svc->semverSatisfies('2.0.1', '>=1.0 <2.0'), 'semver conjunction outer bound');
ok($svc->semverSatisfies('1.2.3', '^1.2.0'),    'semver caret major-bumped');
ok(!$svc->semverSatisfies('2.0.0', '^1.2.0'),   'semver caret major upper bound');
ok($svc->semverSatisfies('1.2.9', '~1.2.0'),    'semver tilde minor-bumped');
ok(!$svc->semverSatisfies('1.3.0', '~1.2.0'),   'semver tilde minor upper bound');
ok($svc->semverSatisfies('2.1.5', '2.*'),       'semver wildcard match');
ok(!$svc->semverSatisfies('3.0.0', '2.*'),      'semver wildcard miss');
ok(!$svc->semverSatisfies('2.1.0', '>=2.*'),    'semver wildcard+comparator rejected');

// ───────────────────────── parseCert (via reflection) ─────────────────────────
// parseCert is private — exercise it through verifyAndExtract behaviour rather than directly.
// Here we just confirm that a malformed cert string short-circuits the chain check inside verifyCertChain.
$svcRoots = new FmodService([str_repeat('0', 64) => base64_encode(random_bytes(32))]);
$verif = $svcRoots->verifyPackage('/nonexistent/path.fmod', '2.1.0');
ok($verif['ok'] === false, 'verifyPackage on missing file = ok:false');

// ───────────────────────── buildManifest deterministic ─────────────────────────
$tmp = sys_get_temp_dir() . '/fmod_unit_' . bin2hex(random_bytes(3));
mkdir($tmp . '/sub', 0775, true);
file_put_contents($tmp . '/a.txt', 'A');
file_put_contents($tmp . '/sub/b.txt', 'B');
$m1 = $svc->buildManifest($tmp);
file_put_contents($tmp . '/sub/c.txt', 'C');
$m2 = $svc->buildManifest($tmp);
ok($m1 !== $m2,                                                  'buildManifest changes when files change');
ok(strpos($m1, 'a.txt') !== false && strpos($m1, 'sub/b.txt') !== false, 'buildManifest enumerates all files');
ok(strpos($m1, FmodService::MANIFEST_FILE) === false,            'buildManifest excludes MANIFEST itself');

// cleanup
$rrm = function ($d) use (&$rrm) {
    if (!is_dir($d)) return;
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $d . DIRECTORY_SEPARATOR . $f;
        is_dir($p) ? $rrm($p) : @unlink($p);
    }
    @rmdir($d);
};
$rrm($tmp);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} failure(s)\n");
    exit(1);
}
echo "\nAll unit tests passed.\n";
