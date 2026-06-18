<?php
/**
 * API endpoint: gestion des modules favoris (épinglés) de l'utilisateur.
 * POST /API/endpoints/favorites.php
 * Body (JSON): { action: "list"|"toggle"|"reorder", module_key?, order?[], csrf_token }
 *
 * CSRF : comparaison non destructive (favoris basculés plusieurs fois par session).
 */
require_once __DIR__ . '/../bootstrap.php';
requireAuth();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$token = $input['csrf_token'] ?? '';
if (!app('csrf')->validate($token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}
// Emit a replacement token so the client can rotate its copy for the next request
app('csrf')->emitNextToken();

$modules  = app('modules');
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$userType = $_SESSION['user_type'] ?? '';
$role     = getUserRole() ?? 'eleve';
$action   = $input['action'] ?? '';

function favorites_payload($modules, int $userId, string $userType, string $role): array
{
    $favs = $modules->getFavorites($userId, $userType, $role);
    return array_map(static function ($m) {
        return [
            'module_key' => $m['module_key'],
            'label'      => $m['label'] ?? $m['module_key'],
            'icon'       => $m['icon'] ?? 'fas fa-circle',
            'route'      => $m['route'] ?? '',
            'type'       => $m['type'] ?? 'module',
        ];
    }, $favs);
}

switch ($action) {
    case 'list':
        echo json_encode(['favorites' => favorites_payload($modules, $userId, $userType, $role)]);
        break;

    case 'toggle':
        $key = trim((string) ($input['module_key'] ?? ''));
        if ($key === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing module_key']);
            break;
        }
        $isFav = $modules->toggleFavorite($userId, $userType, $key);
        echo json_encode([
            'success'   => true,
            'favorite'  => $isFav,
            'favorites' => favorites_payload($modules, $userId, $userType, $role),
        ]);
        break;

    case 'add_page':
        $url   = trim((string) ($input['url'] ?? ''));
        $label = trim((string) ($input['label'] ?? ''));
        $icon  = trim((string) ($input['icon'] ?? 'fas fa-bookmark'));
        if ($url === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing url']);
            break;
        }
        // N'accepter que des chemins internes relatifs : rejeter toute URL absolue
        // (contenant '://'), protocole-relative ('//host'), ou pseudo-protocole
        // dangereux (javascript:/data:) qui permettrait une redirection externe ou
        // une injection au clic sur le favori.
        $urlLower = strtolower($url);
        if (strpos($url, '://') !== false
            || strpos($url, '//') === 0
            || strpos($urlLower, 'javascript:') === 0
            || strpos($urlLower, 'data:') === 0
            || strpos($urlLower, 'vbscript:') === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'URL invalide : seuls les chemins internes relatifs sont autorisés']);
            break;
        }
        $ok = $modules->addPageFavorite($userId, $userType, $url, $label, $icon);
        echo json_encode([
            'success'   => $ok,
            'favorites' => favorites_payload($modules, $userId, $userType, $role),
        ]);
        break;

    case 'remove':
        $key = trim((string) ($input['key'] ?? $input['module_key'] ?? ''));
        if ($key === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing key']);
            break;
        }
        $modules->removeFavorite($userId, $userType, $key);
        echo json_encode([
            'success'   => true,
            'favorites' => favorites_payload($modules, $userId, $userType, $role),
        ]);
        break;

    case 'reorder':
        $order = $input['order'] ?? [];
        if (!is_array($order)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid order']);
            break;
        }
        $ok = $modules->reorderFavorites($userId, $userType, $order);
        echo json_encode(['success' => $ok]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
