<?php
declare(strict_types=1);
/**
 * Émet une nouvelle clé de licence (48 caractères, usage unique par défaut) et l'affiche
 * UNE SEULE FOIS. Seul le hachage est stocké — la clé n'est pas récupérable ensuite.
 *
 * Usage :
 *   php dist/bin/issue-key.php [--tier=base] [--modules=stages,internat] [--max=1]
 *                             [--expires=2027-01-01] [--note="Collège X"]
 *   --modules : modules PREMIUM autorisés (en plus des modules simples, toujours inclus).
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI uniquement.\n"); exit(2); }
require __DIR__ . '/../lib/loader.php';

$opt = static function (string $name, ?string $def = null) use ($argv): ?string {
    foreach ($argv as $a) {
        if (str_starts_with($a, "--{$name}=")) { return substr($a, strlen($name) + 3); }
    }
    return $def;
};

try {
    $cfg = dist_config();
    $store = dist_store($cfg);

    $modules = array_values(array_filter(array_map('trim', explode(',', (string) $opt('modules', '')))));
    $res = $store->issueLicense([
        'tier'            => $opt('tier', 'base'),
        'modules'         => $modules,
        'max_activations' => (int) $opt('max', '1'),
        'expires_at'      => $opt('expires'),
        'note'            => $opt('note'),
    ]);

    echo "════════════════════════════════════════════════════════════════\n";
    echo "  CLÉ DE LICENCE (à transmettre au client — NON récupérable) :\n";
    echo "════════════════════════════════════════════════════════════════\n";
    echo "  " . $res['key'] . "\n";
    echo "────────────────────────────────────────────────────────────────\n";
    echo "  id=#{$res['id']}  préfixe={$res['prefix']}  tier=" . $opt('tier', 'base')
        . "  premium=[" . implode(',', $modules) . "]"
        . "  max_activations=" . (int) $opt('max', '1')
        . ($opt('expires') ? "  expire=" . $opt('expires') : '') . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[erreur] " . $e->getMessage() . "\n");
    exit(1);
}
