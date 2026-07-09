<?php
/**
 * WebSocket Room Authorization Endpoint (server-to-server).
 * POST JSON { token, type: 'conversation'|'class', id }
 *
 * Le serveur temps réel (Node) appelle ce point AVANT d'autoriser un socket à
 * rejoindre une room, pour vérifier l'APPARTENANCE réelle (anti-IDOR : un socket
 * authentifié ne doit pas pouvoir écouter la conversation/classe d'autrui, ni
 * d'un autre établissement). Renvoie { allow: bool }.
 *
 * L'appel est authentifié par le secret partagé WEBSOCKET_API_SECRET (en-tête
 * X-WS-Secret, comparé en temps constant). Le token utilisateur est un JWT WS.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$deny = function (string $reason = 'denied', int $code = 200): void {
    http_response_code($code);
    echo json_encode(['allow' => false, 'reason' => $reason]);
    exit;
};

// 1) Authentifier l'APPELANT (serveur Node) par secret partagé, temps constant.
$wsSecret = getenv('WEBSOCKET_API_SECRET') ?: '';
$provided = $_SERVER['HTTP_X_WS_SECRET'] ?? '';
if ($wsSecret === '' || !is_string($provided) || !hash_equals($wsSecret, $provided)) {
    $deny('unauthorized_caller', 401);
}

$body  = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$token = (string) ($body['token'] ?? '');
$type  = (string) ($body['type'] ?? '');
$id    = (int) ($body['id'] ?? 0);
if ($token === '' || $id <= 0 || !in_array($type, ['conversation', 'class'], true)) {
    $deny('bad_request', 400);
}

// 2) Vérifier le JWT utilisateur (même secret que WebSocket::generateToken).
$jwtSecret = getenv('JWT_SECRET') ?: getenv('WEBSOCKET_API_SECRET') ?: '';
try {
    if (!class_exists(\Firebase\JWT\JWT::class)) {
        $deny('jwt_unavailable', 500);
    }
    $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($jwtSecret, 'HS256'));
} catch (\Throwable $e) {
    $deny('invalid_token');
}

$userId   = (int) ($decoded->userId ?? $decoded->sub ?? 0);
$userType = (string) ($decoded->userType ?? $decoded->role ?? '');
$etab     = (int) ($decoded->etablissement_id ?? 0);
if ($userId <= 0 || $userType === '') {
    $deny('invalid_claims');
}

$pdo = getPDO();

// 3) Autorisation par type de room, TOUJOURS cloisonnée par établissement.
try {
    if ($type === 'conversation') {
        // Le demandeur doit être participant non supprimé, et la conversation de son établissement.
        $sql = "SELECT 1 FROM conversation_participants cp
                JOIN conversations c ON c.id = cp.conversation_id
                WHERE cp.conversation_id = ? AND cp.user_id = ? AND cp.user_type = ?
                  AND (cp.is_deleted = 0 OR cp.is_deleted IS NULL)";
        $params = [$id, $userId, $userType];
        // Scoper par établissement si la colonne existe.
        $hasEtab = (bool) $pdo->query("SHOW COLUMNS FROM conversations LIKE 'etablissement_id'")->fetch();
        if ($hasEtab && $etab > 0) { $sql .= " AND c.etablissement_id = ?"; $params[] = $etab; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['allow' => (bool) $stmt->fetchColumn()]);
        exit;
    }

    // type === 'class' : $id = id de classe. Le personnel de l'établissement y a accès ;
    // le professeur seulement s'il l'enseigne ; l'élève si c'est sa classe ; le parent
    // si l'un de ses enfants y est.
    $cls = $pdo->prepare("SELECT nom FROM classes WHERE id = ?" . ($etab > 0 ? " AND etablissement_id = ?" : ""));
    $cls->execute($etab > 0 ? [$id, $etab] : [$id]);
    $classe = $cls->fetchColumn();
    if ($classe === false) { $deny('class_not_found_or_other_tenant'); }

    $allow = false;
    if (in_array($userType, ['administrateur', 'vie_scolaire'], true)) {
        $allow = true;
    } elseif ($userType === 'professeur') {
        $q = $pdo->prepare("SELECT 1 FROM professeur_classes WHERE id_professeur = ? AND nom_classe = ?");
        $q->execute([$userId, $classe]);
        $allow = (bool) $q->fetchColumn();
    } elseif ($userType === 'eleve') {
        $q = $pdo->prepare("SELECT 1 FROM eleves WHERE id = ? AND classe = ?" . ($etab > 0 ? " AND etablissement_id = ?" : ""));
        $q->execute($etab > 0 ? [$userId, $classe, $etab] : [$userId, $classe]);
        $allow = (bool) $q->fetchColumn();
    } elseif ($userType === 'parent') {
        $q = $pdo->prepare("SELECT 1 FROM parent_eleve pe JOIN eleves e ON pe.id_eleve = e.id
                            WHERE pe.id_parent = ? AND e.classe = ?");
        $q->execute([$userId, $classe]);
        $allow = (bool) $q->fetchColumn();
    }
    echo json_encode(['allow' => $allow]);
} catch (\Throwable $e) {
    error_log('[ws_authorize] ' . $e->getMessage());
    $deny('server_error', 500);
}
