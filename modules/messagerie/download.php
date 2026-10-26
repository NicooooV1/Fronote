<?php
declare(strict_types=1);
/**
 * Messagerie — téléchargement d'une pièce jointe (participants de la conversation uniquement).
 * ?id=<id pièce jointe>[&inline=1] — inline n'est honoré que pour les images et les PDF.
 */
require_once __DIR__ . '/../../API/bootstrap.php';
requireAuth();
enforceModuleAccess('messagerie');

use API\Services\Messaging\MessagingService;

$a = null;
try { $a = MessagingService::forCurrentUser()->attachment((int) ($_GET['id'] ?? 0)); } catch (\Throwable $e) {}
if (!$a) { http_response_code(404); exit('Pièce jointe introuvable.'); }

$safeInline = in_array($a['mime'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'], true);
$inline = !empty($_GET['inline']) && $safeInline;
$name = str_replace(['"', "\r", "\n"], '', $a['name']);
header('Content-Type: ' . ($safeInline ? $a['mime'] : 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($a['path']));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($a['name']));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, max-age=300');
readfile($a['path']);
