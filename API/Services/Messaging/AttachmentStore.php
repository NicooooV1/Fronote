<?php
declare(strict_types=1);

namespace API\Services\Messaging;

use PDO;
use RuntimeException;

/**
 * Pièces jointes de la messagerie : stockées HORS du dossier web (storage/messagerie/AAAA/MM),
 * sous un nom aléatoire, type réel vérifié (finfo). Servies uniquement après contrôle de
 * participation (download.php). Les anciens fichiers (assets/uploads…) restent lisibles.
 */
final class AttachmentStore
{
    public const MAX_FILES = 5;
    public const MAX_BYTES = 10 * 1024 * 1024;
    /** extension => types MIME acceptés */
    private const ALLOWED = [
        'pdf' => ['application/pdf'], 'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'], 'webp' => ['image/webp'], 'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'doc' => ['application/msword'], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel'], 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint'], 'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'], 'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'], 'zip' => ['application/zip'],
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public static function root(): string { return BASE_PATH . '/storage/messagerie'; }

    /** Normalise $_FILES['x'] (multiple) en liste. */
    public static function normalize(array $f): array
    {
        if (!isset($f['name'])) { return []; }
        if (!is_array($f['name'])) { return [$f]; }
        $out = [];
        foreach ($f['name'] as $i => $n) {
            if ((string) $n === '') { continue; }
            $out[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'size' => $f['size'][$i], 'error' => $f['error'][$i]];
        }
        return $out;
    }

    public function storeAll(int $messageId, array $files): void
    {
        $files = self::normalize($files);
        if (count($files) > self::MAX_FILES) { throw new RuntimeException(self::MAX_FILES . ' pièces jointes maximum.'); }
        $ins = $this->pdo->prepare('INSERT INTO message_attachments (message_id, file_name, file_path, type_mime, taille, uploaded_at) VALUES (?,?,?,?,?,NOW())');
        foreach ($files as $f) {
            if ((int) $f['error'] !== UPLOAD_ERR_OK) { throw new RuntimeException('Échec du téléversement de « ' . $f['name'] . ' ».'); }
            if ((int) $f['size'] > self::MAX_BYTES) { throw new RuntimeException('« ' . $f['name'] . ' » dépasse 10 Mo.'); }
            $name = mb_substr(preg_replace('/[^\p{L}\p{N} ._()-]/u', '_', basename((string) $f['name'])) ?? 'fichier', 0, 180);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $f['tmp_name']) ?: 'application/octet-stream';
            if (!isset(self::ALLOWED[$ext]) || !in_array($mime, self::ALLOWED[$ext], true)) {
                throw new RuntimeException('Type de fichier non accepté : « ' . $name . ' ».');
            }
            $rel = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
            $abs = self::root() . '/' . $rel;
            if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0750, true)) { throw new RuntimeException('Stockage indisponible.'); }
            if (!move_uploaded_file((string) $f['tmp_name'], $abs)) { throw new RuntimeException('Stockage impossible.'); }
            @chmod($abs, 0640);
            $ins->execute([$messageId, $name, 'storage:' . $rel, $mime, (int) $f['size']]);
        }
    }

    /** Chemin absolu sûr d'une pièce jointe (nouveau format « storage: » ou historique). */
    public static function resolve(string $stored): ?string
    {
        if (str_starts_with($stored, 'storage:')) {
            $p = realpath(self::root() . '/' . substr($stored, 8));
            return ($p && str_starts_with($p, realpath(self::root()) . '/') && is_file($p)) ? $p : null;
        }
        // Historique : chemin relatif à uploads/ (format 4.x), à la racine, à assets/uploads, ou au préfixe mal concaténé des versions < 4.3
        $base = realpath(BASE_PATH);
        foreach ([BASE_PATH . '/uploads/' . ltrim($stored, '/'), BASE_PATH . '/' . ltrim($stored, '/'), BASE_PATH . '/assets/uploads/' . basename($stored), BASE_PATH . 'assets/uploads/' . basename($stored)] as $cand) {
            $p = realpath($cand);
            if ($p && is_file($p) && ($base && str_starts_with($p, dirname($base)))) { return $p; }
        }
        return null;
    }
}
