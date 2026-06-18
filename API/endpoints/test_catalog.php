<?php
/**
 * Test catalog endpoint — distribution des modules test_only Fronote.
 * GET /API/endpoints/test_catalog.php
 *
 * Retourne le catalogue des modules de test disponibles.
 * Nécessite ALLOW_TEST_MODULES=true sur l'instance.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
requireAuth();

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (getenv('ALLOW_TEST_MODULES') !== 'true') {
    http_response_code(403);
    echo json_encode(['error' => 'Test modules not enabled on this instance. Set ALLOW_TEST_MODULES=true in .env.']);
    exit;
}

$registryUrl = getenv('MARKETPLACE_TEST_REGISTRY_URL') ?: '';

if ($registryUrl) {
    // Fetch from remote test registry
    $ctx = stream_context_create([
        'http' => ['timeout' => 10, 'user_agent' => 'Fronote/' . (defined('FRONOTE_VERSION') ? FRONOTE_VERSION : '2.0.0')],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $json = @file_get_contents($registryUrl, false, $ctx);
    if ($json !== false) {
        echo $json;
        exit;
    }
}

// Fallback: enumerate locally signed modules in config/marketplace/test-catalog/
$catalogDir = BASE_PATH . '/config/marketplace/test-catalog';
$items = [];
if (is_dir($catalogDir)) {
    foreach (glob($catalogDir . '/*.json') ?: [] as $f) {
        $item = json_decode((string) file_get_contents($f), true);
        if (is_array($item) && !empty($item['key'])) {
            $items[] = $item;
        }
    }
}

// Always include the local hello_world if it exists
$hwManifest = BASE_PATH . '/modules/hello_world/module.json';
if (is_file($hwManifest)) {
    $hw = json_decode((string) file_get_contents($hwManifest), true);
    if (is_array($hw)) {
        $hw['test_only'] = true;
        $hw['channel']   = 'test';
        $items[] = $hw;
    }
}

// Deduplicate by key
$seen  = [];
$items = array_values(array_filter($items, function ($i) use (&$seen) {
    $k = $i['key'] ?? '';
    if (isset($seen[$k])) return false;
    $seen[$k] = true;
    return true;
}));

echo json_encode([
    'catalog_version' => '1',
    'channel'         => 'test',
    'generated_at'    => gmdate('Y-m-d\TH:i:s\Z'),
    'items'           => $items,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
