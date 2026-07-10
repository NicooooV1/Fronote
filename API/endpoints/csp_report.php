<?php
declare(strict_types=1);
/**
 * Endpoint: réception des rapports de violation Content-Security-Policy.
 * POST /API/endpoints/csp_report.php
 *
 * Appelé par le NAVIGATEUR (directive `report-uri`) avec un corps
 * `application/csp-report` (legacy) ou `application/reports+json` (Reporting API).
 * Volontairement public et sans session : il précède/ignore toute authentification
 * (référencé par shared_header.php pour toutes les pages).
 *
 * Sécurité / robustesse :
 *  - aucune donnée sensible exposée ; réponse toujours 204 (pas de contenu) ;
 *  - taille de corps bornée (anti-abus) ;
 *  - journalisation throttlée (fenêtre glissante) pour éviter le flooding des logs ;
 *  - aucune écriture BDD, aucune dépendance lourde (endpoint volontairement autonome).
 */

// Toujours répondre 204 en sortie, quoi qu'il arrive (le navigateur ignore le corps).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit;
}

// Corps borné : au-delà on ignore le surplus (un rapport CSP légitime est petit).
const CSP_MAX_BYTES = 16384; // 16 Ko
$raw = file_get_contents('php://input', false, null, 0, CSP_MAX_BYTES);
if ($raw === false) {
    $raw = '';
}

// Throttle : au plus N entrées journalisées par fenêtre de T secondes (par process/hôte),
// via un compteur fichier atomique. En cas d'échec d'I/O on journalise quand même (fail-open borné).
const CSP_LOG_WINDOW  = 60;  // secondes
const CSP_LOG_MAX     = 20;  // rapports journalisés max par fenêtre
$shouldLog = true;
$counterFile = sys_get_temp_dir() . '/fronote_csp_report.count';
$fh = @fopen($counterFile, 'c+');
if ($fh !== false) {
    if (flock($fh, LOCK_EX)) {
        $data = stream_get_contents($fh);
        $windowStart = 0;
        $count = 0;
        if ($data !== false && $data !== '' && strpos($data, ':') !== false) {
            [$windowStart, $count] = array_map('intval', explode(':', $data, 2));
        }
        $now = time();
        if (($now - $windowStart) >= CSP_LOG_WINDOW) {
            $windowStart = $now;
            $count = 0;
        }
        if ($count >= CSP_LOG_MAX) {
            $shouldLog = false;
        } else {
            $count++;
        }
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, $windowStart . ':' . $count);
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

if ($shouldLog && $raw !== '') {
    // On extrait uniquement des champs non sensibles et bornés. Aucune donnée utilisateur.
    $decoded = json_decode($raw, true);
    $fields = [];
    if (is_array($decoded)) {
        // Format legacy : { "csp-report": { ... } }
        $r = $decoded['csp-report'] ?? null;
        // Format Reporting API : [ { "body": { ... }, "type": "csp-violation" }, ... ]
        if (!is_array($r) && isset($decoded[0]['body']) && is_array($decoded[0]['body'])) {
            $r = $decoded[0]['body'];
        }
        if (is_array($r)) {
            $keys = [
                'document-uri', 'documentURL',
                'violated-directive', 'effectiveDirective', 'effective-directive',
                'blocked-uri', 'blockedURL',
                'source-file', 'sourceFile',
                'line-number', 'lineNumber',
            ];
            foreach ($keys as $k) {
                if (isset($r[$k]) && (is_string($r[$k]) || is_int($r[$k]))) {
                    // Borne chaque valeur pour éviter tout log surdimensionné.
                    $fields[$k] = mb_substr((string) $r[$k], 0, 512);
                }
            }
        }
    }
    $summary = $fields !== []
        ? json_encode($fields, JSON_UNESCAPED_SLASHES)
        : mb_substr($raw, 0, 512); // fallback borné si JSON non reconnu
    error_log('[csp-report] ' . $summary);
}

http_response_code(204);
