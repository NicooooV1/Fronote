<?php
declare(strict_types=1);

namespace API\Services;

use PDO;

/**
 * ThemeService — Gestion des thèmes CSS installables.
 *
 * Gère le CRUD des thèmes, leur activation par établissement ou par utilisateur,
 * et la validation des fichiers CSS uploadés.
 */
class ThemeService
{
    private PDO $pdo;
    private string $basePath;

    /** Thèmes intégrés non supprimables */
    private const BUILTIN_THEMES = ['classic', 'glass'];

    /** Extensions autorisées pour les previews */
    private const PREVIEW_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /** Taille max CSS : 500 Ko */
    private const MAX_CSS_SIZE = 512000;

    /** Tokens éditables depuis le designer (nom => valeur par défaut light). */
    public const EDITABLE_TOKENS = [
        '--primary-color'    => '#0f4c81',
        '--primary-light'    => '#2d7dd2',
        '--primary-dark'     => '#0a3962',
        '--background-color' => '#f5f6fa',
        '--text-color'       => '#333333',
        '--success-color'    => '#34c759',
        '--warning-color'    => '#ff9500',
        '--error-color'      => '#ff3b30',
    ];

    public function __construct(PDO $pdo, string $basePath)
    {
        $this->pdo = $pdo;
        $this->basePath = rtrim($basePath, '/\\');
    }

    // ─── CRUD ───────────────────────────────────────────────────────

    /**
     * Liste tous les thèmes (built-in + installés).
     */
    public function getAll(): array
    {
        $themes = [];

        // Built-in themes
        $themes[] = [
            'key' => 'classic',
            'name' => 'Classic',
            'description' => 'Thème par défaut, propre et professionnel.',
            'author' => 'Fronote',
            'version' => '2.0.0',
            'css_file' => 'assets/css/theme-classic.css',
            'preview_image' => null,
            'actif' => 1,
            'is_builtin' => true,
            'installed_at' => null,
        ];
        $themes[] = [
            'key' => 'glass',
            'name' => 'Glass',
            'description' => 'Thème glassmorphism avec effets de transparence.',
            'author' => 'Fronote',
            'version' => '2.0.0',
            'css_file' => 'assets/css/theme-glass.css',
            'preview_image' => null,
            'actif' => 1,
            'is_builtin' => true,
            'installed_at' => null,
        ];

        // DB themes
        try {
            $rows = $this->pdo->query("SELECT * FROM themes ORDER BY installed_at DESC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $row['is_builtin'] = false;
                $themes[] = $row;
            }
        } catch (\Throwable $e) {
            // Table might not exist yet
        }

        return $themes;
    }

    /**
     * Retourne un thème par sa clé.
     */
    public function get(string $key): ?array
    {
        foreach ($this->getAll() as $theme) {
            if ($theme['key'] === $key) {
                return $theme;
            }
        }
        return null;
    }

    /**
     * Retourne le thème actif par défaut pour l'établissement.
     */
    public function getDefault(): string
    {
        // Chaîne de priorité (cahier §29) : défaut établissement → défaut plateforme → défaut Fronote.
        try {
            $stmt = $this->pdo->query("SELECT valeur FROM etablissement_info WHERE cle = 'theme_default' LIMIT 1");
            $val = $stmt->fetchColumn();
            if ($val !== false && $val !== null && $val !== '') {
                return (string) $val;                       // 1) défaut de l'établissement
            }
        } catch (\Throwable $e) { /* table absente → tiers suivants */ }
        // 2) défaut plateforme (configurable via .env DEFAULT_THEME) : classic/glass/light/dark/liquid/auto.
        $platform = function_exists('env') ? trim((string) (env('DEFAULT_THEME', '') ?? '')) : '';
        return $platform !== '' ? $platform : 'classic';     // 3) défaut Fronote
    }

    /**
     * Définit le thème par défaut de l'établissement.
     */
    public function setDefault(string $key): bool
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO etablissement_info (cle, valeur) VALUES ('theme_default', ?)
                 ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)"
            )->execute([$key]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ─── Upload de thème custom ─────────────────────────────────────

    /**
     * Installe un thème depuis un upload de fichier CSS.
     */
    public function uploadTheme(array $file, string $key, string $name, string $description = '', ?array $previewFile = null): array
    {
        // Validation clé
        if (!preg_match('/^[a-z0-9_-]{2,30}$/', $key)) {
            return ['success' => false, 'error' => 'Clé de thème invalide (a-z, 0-9, -, _, 2-30 chars).'];
        }

        if (in_array($key, self::BUILTIN_THEMES, true)) {
            return ['success' => false, 'error' => 'Impossible d\'écraser un thème intégré.'];
        }

        // Validation fichier CSS
        if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Erreur d\'upload du fichier CSS.'];
        }

        if ($file['size'] > self::MAX_CSS_SIZE) {
            return ['success' => false, 'error' => 'Fichier CSS trop volumineux (max 500 Ko).'];
        }

        $cssContent = file_get_contents($file['tmp_name']);

        // Validation sécurité CSS (pas de JS inline)
        if ($this->containsDangerousCSS($cssContent)) {
            return ['success' => false, 'error' => 'Le fichier CSS contient du code potentiellement dangereux.'];
        }

        // Écrire le fichier CSS
        $cssPath = 'assets/css/theme-' . $key . '.css';
        file_put_contents($this->basePath . '/' . $cssPath, $cssContent);

        // Preview optionnelle
        $previewPath = null;
        if ($previewFile && !empty($previewFile['tmp_name']) && $previewFile['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($previewFile['name'], PATHINFO_EXTENSION));
            // Valider le TYPE MIME RÉEL (fileinfo), pas seulement l'extension :
            // empêche l'upload d'un fichier exécutable renommé en .png.
            $realMime = (new \finfo(FILEINFO_MIME_TYPE))->file($previewFile['tmp_name']) ?: '';
            $allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];
            if (in_array($ext, self::PREVIEW_EXTENSIONS, true)
                && in_array($realMime, $allowedMimes, true)
                && $previewFile['size'] <= 2097152) {
                $previewPath = 'assets/css/theme-' . $key . '-preview.' . $ext;
                move_uploaded_file($previewFile['tmp_name'], $this->basePath . '/' . $previewPath);
            }
        }

        // Upsert en base
        $this->pdo->prepare(
            "INSERT INTO themes (`key`, name, description, author, version, css_file, preview_image, actif, installed_at)
             VALUES (?, ?, ?, 'Custom', '1.0.0', ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
                css_file = VALUES(css_file), preview_image = COALESCE(VALUES(preview_image), preview_image)"
        )->execute([$key, $name, $description, $cssPath, $previewPath]);

        return ['success' => true, 'message' => "Thème '{$name}' installé."];
    }

    /**
     * Supprime un thème custom.
     */
    public function delete(string $key): array
    {
        if (in_array($key, self::BUILTIN_THEMES, true)) {
            return ['success' => false, 'error' => 'Impossible de supprimer un thème intégré.'];
        }

        // Supprimer les fichiers
        $cssFile = $this->basePath . '/assets/css/theme-' . $key . '.css';
        if (file_exists($cssFile)) {
            @unlink($cssFile);
        }
        foreach (self::PREVIEW_EXTENSIONS as $ext) {
            $preview = $this->basePath . '/assets/css/theme-' . $key . '-preview.' . $ext;
            if (file_exists($preview)) {
                @unlink($preview);
            }
        }

        // Réinitialiser les utilisateurs qui utilisaient ce thème
        $this->pdo->prepare("UPDATE user_settings SET theme = 'classic' WHERE theme = ?")->execute([$key]);

        // Supprimer de la base
        $this->pdo->prepare("DELETE FROM themes WHERE `key` = ?")->execute([$key]);

        return ['success' => true, 'message' => 'Thème supprimé.'];
    }

    // ─── Éditeur de tokens CSS ──────────────────────────────────────

    /**
     * Retourne les variables CSS custom de tokens.css parsées.
     */
    public function getTokens(): array
    {
        $tokensFile = $this->basePath . '/assets/css/tokens.css';
        if (!file_exists($tokensFile)) {
            return [];
        }

        $content = file_get_contents($tokensFile);
        $tokens = [];
        if (preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $tokens[] = ['name' => '--' . $m[1], 'value' => trim($m[2])];
            }
        }
        return $tokens;
    }

    /**
     * Sauvegarde un override de tokens pour un thème.
     */
    public function saveTokenOverrides(string $themeKey, array $overrides): bool
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO theme_token_overrides (theme_key, overrides) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE overrides = VALUES(overrides)"
            )->execute([$themeKey, json_encode($overrides)]);
            // Invalider le cache du CSS d'override pour que la modif prenne effet.
            try { app('client_cache')->forget('theme_overrides_' . $themeKey); } catch (\Throwable $e) { /* non bloquant */ }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Retourne les overrides de tokens enregistrés pour un thème (name => value).
     */
    public function getTokenOverrides(string $themeKey): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT overrides FROM theme_token_overrides WHERE theme_key = ? LIMIT 1");
            $stmt->execute([$themeKey]);
            $json = $stmt->fetchColumn();
            if (!$json) return [];
            $data = json_decode((string) $json, true);
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Chemin CSS relatif VALIDÉ d'un thème installé, ou null.
     * Empêche toute inclusion de chemin arbitraire depuis la base.
     */
    public function cssFileFor(string $key): ?string
    {
        if ($key === 'classic') return 'assets/css/theme-classic.css';
        if ($key === 'glass')   return 'assets/css/theme-glass.css';

        $theme = $this->get($key);
        $css = $theme['css_file'] ?? null;
        if (!$css || !preg_match('#^assets/css/theme-[a-z0-9_-]+\.css$#', $css)) {
            return null;
        }
        return is_file($this->basePath . '/' . $css) ? $css : null;
    }

    /**
     * Construit le bloc CSS :root des overlays de tokens d'un thème (assaini).
     * Retourne '' si aucun override valide.
     */
    public function renderOverrideCss(string $themeKey): string
    {
        $decls = [];
        foreach ($this->getTokenOverrides($themeKey) as $name => $value) {
            if (!is_string($name) || !preg_match('/^--[a-z0-9-]+$/i', $name)) continue;
            $value = trim((string) $value);
            if ($value === '' || preg_match('/[{}<>;]/', $value)) continue;
            if ($this->containsDangerousCSS($value)) continue;
            $decls[] = $name . ': ' . $value;
        }
        return empty($decls) ? '' : ':root{' . implode(';', $decls) . '}';
    }

    // ─── Accessibilité : contraste WCAG ─────────────────────────────

    /**
     * Ratio de contraste WCAG entre deux couleurs hex (#rrggbb), ou null si non calculable.
     */
    public function contrastRatio(string $hex1, string $hex2): ?float
    {
        $l1 = $this->relativeLuminance($hex1);
        $l2 = $this->relativeLuminance($hex2);
        if ($l1 === null || $l2 === null) return null;
        [$hi, $lo] = $l1 >= $l2 ? [$l1, $l2] : [$l2, $l1];
        return round(($hi + 0.05) / ($lo + 0.05), 2);
    }

    /**
     * Rapport de contraste sur les paires clés à partir des tokens effectifs (name => hex).
     * @return array Liste de ['label','ratio','min','pass'].
     */
    public function contrastReport(array $tokens): array
    {
        $get = fn(string $k) => $tokens[$k] ?? (self::EDITABLE_TOKENS[$k] ?? null);
        $pairs = [
            ['Texte sur fond',         $get('--text-color'),    $get('--background-color'), 4.5],
            ['Texte blanc sur primaire', '#ffffff',             $get('--primary-color'),    4.5],
            ['Alerte (erreur) sur fond', $get('--error-color'), $get('--background-color'), 3.0],
        ];
        $report = [];
        foreach ($pairs as [$label, $fg, $bg, $min]) {
            if (!$fg || !$bg) continue;
            $ratio = $this->contrastRatio((string)$fg, (string)$bg);
            if ($ratio === null) continue;
            $report[] = ['label' => $label, 'ratio' => $ratio, 'min' => $min, 'pass' => $ratio >= $min];
        }
        return $report;
    }

    private function relativeLuminance(string $hex): ?float
    {
        $rgb = $this->hexToRgb($hex);
        if ($rgb === null) return null;
        $lin = array_map(static function ($c) {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);
        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    }

    private function hexToRgb(string $hex): ?array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) return null;
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * Vérifie si un contenu CSS contient des patterns dangereux.
     */
    private function containsDangerousCSS(string $css): bool
    {
        $dangerous = [
            'javascript:',
            'expression(',
            'eval(',
            '<script',
            'url(data:text/html',
            'behavior:',
            '-moz-binding:',
        ];
        $lower = strtolower($css);
        foreach ($dangerous as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }
        return false;
    }
}
