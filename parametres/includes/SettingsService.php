<?php
declare(strict_types=1);
/**
 * M17 – Paramètres utilisateur — Service
 */

class SettingsService {
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /**
     * Récupère les paramètres d'un utilisateur
     */
    public function getSettings(int $userId, string $userType): array {
        $stmt = $this->pdo->prepare("SELECT * FROM user_settings WHERE user_id = ? AND user_type = ?");
        $stmt->execute([$userId, $userType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return self::defaults($userId, $userType);
        }
        return $row;
    }

    /**
     * Sauvegarder les paramètres
     */
    public function save(int $userId, string $userType, array $data): bool {
        $theme = $this->validTheme($data['theme'] ?? 'light');
        $stmt = $this->pdo->prepare("
            INSERT INTO user_settings (user_id, user_type, theme, langue, notifications_email, notifications_web,
                                       taille_police, sidebar_collapsed, bio, date_modification)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                theme = VALUES(theme),
                langue = VALUES(langue),
                notifications_email = VALUES(notifications_email),
                notifications_web = VALUES(notifications_web),
                taille_police = VALUES(taille_police),
                sidebar_collapsed = VALUES(sidebar_collapsed),
                bio = VALUES(bio),
                date_modification = NOW()
        ");
        $ok = $stmt->execute([
            $userId,
            $userType,
            $theme,
            $data['langue'] ?? 'fr',
            isset($data['notifications_email']) ? 1 : 0,
            isset($data['notifications_web']) ? 1 : 0,
            $data['taille_police'] ?? 'normal',
            isset($data['sidebar_collapsed']) ? 1 : 0,
            $data['bio'] ?? '',
        ]);
        $this->cacheTheme($theme);
        return $ok;
    }

    /** Thème valide (liste blanche) ou repli 'light'. */
    private function validTheme(string $theme): string {
        return in_array($theme, array_keys(self::themes()), true) ? $theme : 'light';
    }

    /** Rafraîchit le cache thème SSR (sinon l'ancien thème est servi jusqu'au TTL). */
    private function cacheTheme(string $theme): void {
        try { (new \API\Core\ClientCache())->set('user_theme', $theme, 3600); }
        catch (\Throwable $e) { error_log('[SettingsService] cacheTheme: ' . $e->getMessage()); }
    }

    /**
     * Upload avatar
     */
    public function uploadAvatar(int $userId, string $userType, array $file): string {
        // Vérifier l'erreur d'upload avant tout traitement.
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Échec du téléversement du fichier.');
        }
        // S'assurer que le fichier provient bien d'un upload HTTP (anti-spoof tmp_name).
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new Exception('Fichier invalide.');
        }

        // Securite : écrire dans le dossier d'uploads RACINE (périmètre protégé), pour que le
        // chemin stocké en base ('uploads/avatars/...') corresponde réellement au fichier écrit.
        $uploadDir = dirname(__DIR__, 2) . '/uploads/avatars/';
        // 0755 et non 0777 : pas d'écriture « monde ».
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new Exception('Impossible de créer le dossier de destination.');
        }
        // Durcissement : lecture directe des images autorisée (affichage <img>), exécution PHP interdite.
        if (!is_file($uploadDir . '.htaccess')) {
            file_put_contents($uploadDir . '.htaccess',
                "Options -ExecCGI -Indexes\n"
                . "<FilesMatch \"(?i)\\.(jpe?g|png|gif|webp)$\">\n  Require all granted\n</FilesMatch>\n"
                . "<FilesMatch \"(?i)\\.(php|phtml|php3|php4|php5|php7|phps|phar|cgi|pl|py|sh|bash|htaccess)$\">\n"
                . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
                . "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n</FilesMatch>\n"
                . "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n"
                . "<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n"
                . "<IfModule mod_php8.c>\n  php_flag engine off\n</IfModule>\n");
        }

        if ($file['size'] > 2 * 1024 * 1024) {
            throw new Exception('La photo ne doit pas dépasser 2 Mo.');
        }

        // Validation par TYPE MIME réel (pas seulement l'extension) : on déduit
        // l'extension du contenu de l'image, ce qui bloque les fichiers polyglottes
        // (ex. .php renommé .jpg) que la simple vérification d'extension laissait passer.
        $allowedMime = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false || !isset($imageInfo['mime']) || !isset($allowedMime[$imageInfo['mime']])) {
            throw new Exception('Format non autorisé. Utilisez une vraie image JPG, PNG, GIF ou WebP.');
        }
        $ext = $allowedMime[$imageInfo['mime']];

        $filename = 'avatar_' . $userType . '_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        // Échec dur si le déplacement échoue : on n'enregistre jamais en base un
        // chemin pointant vers un fichier inexistant.
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            throw new Exception('Impossible d\'enregistrer le fichier téléversé.');
        }
        @chmod($uploadDir . $filename, 0644);

        $chemin = 'uploads/avatars/' . $filename;
        $stmt = $this->pdo->prepare("
            INSERT INTO user_settings (user_id, user_type, avatar_chemin, date_modification)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE avatar_chemin = VALUES(avatar_chemin), date_modification = NOW()
        ");
        $stmt->execute([$userId, $userType, $chemin]);
        return $chemin;
    }

    /**
     * Modifier le mot de passe
     */
    public function changerMotDePasse(int $userId, string $userType, string $ancien, string $nouveau): bool {
        // Password policy enforcement (≥ 12 caractères pour aligner avec
        // l'installateur et fermer le chemin faible « 8 caractères »).
        if (strlen($nouveau) < 12 || !preg_match('/[A-Z]/', $nouveau) || !preg_match('/[a-z]/', $nouveau)
            || !preg_match('/[0-9]/', $nouveau) || !preg_match('/[^A-Za-z0-9]/', $nouveau)) {
            return false;
        }

        $tables = [
            'administrateur' => 'administrateurs',
            'professeur' => 'professeurs',
            'eleve' => 'eleves',
            'parent' => 'parents',
            'vie_scolaire' => 'vie_scolaire',
        ];
        $table = $tables[$userType] ?? null;
        if (!$table) return false;

        $stmt = $this->pdo->prepare("SELECT mot_de_passe FROM {$table} WHERE id = ?");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($ancien, $hash)) {
            return false;
        }

        $newHash = \API\Security\PasswordPolicy::hash($nouveau);
        $stmt = $this->pdo->prepare("UPDATE {$table} SET mot_de_passe = ?, password_changed_at = NOW() WHERE id = ?");
        $ok = $stmt->execute([$newHash, $userId]);
        if ($ok) {
            // Synchronise le miroir accounts.password_hash (basculement complet).
            try { (new \API\Services\AccountService($this->pdo))->syncPassword($userType, (int) $userId, $newHash); }
            catch (\Throwable $e) { error_log('[changerMotDePasse] sync accounts: ' . $e->getMessage()); }
        }
        return $ok;
    }

    /**
     * Récupère les infos du profil depuis la table rôle
     */
    public function getProfile(int $userId, string $userType): ?array {
        $tables = [
            'administrateur' => 'administrateurs',
            'professeur' => 'professeurs',
            'eleve' => 'eleves',
            'parent' => 'parents',
            'vie_scolaire' => 'vie_scolaire',
        ];
        $table = $tables[$userType] ?? null;
        if (!$table) return null;

        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Valeurs par défaut
     */
    public static function defaults(int $userId, string $userType): array {
        return [
            'user_id' => $userId,
            'user_type' => $userType,
            'theme' => 'light',
            'langue' => 'fr',
            'notifications_email' => 1,
            'notifications_web' => 1,
            'taille_police' => 'normal',
            'sidebar_collapsed' => 0,
            'avatar_chemin' => null,
            'bio' => '',
        ];
    }

    /* ───── PRIVACY ───── */

    /**
     * Get privacy level for a user (public/private).
     */
    public function getPrivacyLevel(int $userId, string $userType): string
    {
        $stmt = $this->pdo->prepare("SELECT privacy_level FROM user_settings WHERE user_id = ? AND user_type = ?");
        $stmt->execute([$userId, $userType]);
        return $stmt->fetchColumn() ?: 'public';
    }

    /**
     * Set privacy level.
     */
    public function setPrivacyLevel(int $userId, string $userType, string $level): void
    {
        $this->pdo->prepare("
            INSERT INTO user_settings (user_id, user_type, privacy_level, date_modification)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE privacy_level = VALUES(privacy_level), date_modification = NOW()
        ")->execute([$userId, $userType, $level]);
    }

    // ─── Accueil config ──────────────────────────────────────────────

    /**
     * Récupère la configuration du tableau de bord de l'utilisateur.
     * @return array|null — List of widget keys, or null (=use defaults)
     */
    public function getAccueilConfig(int $userId, string $userType): ?array {
        try {
            $stmt = $this->pdo->prepare("SELECT accueil_config FROM user_settings WHERE user_id = ? AND user_type = ?");
            $stmt->execute([$userId, $userType]);
            $json = $stmt->fetchColumn();
            if ($json) {
                $decoded = json_decode($json, true);
                return is_array($decoded) ? $decoded : null;
            }
        } catch (\PDOException $e) { /* accueil_config column may not exist yet */ }
        return null;
    }

    /**
     * Sauvegarde la configuration du tableau de bord.
     * @param array $widgets — List of widget keys e.g. ['evenements','devoirs']
     */
    public function saveAccueilConfig(int $userId, string $userType, array $widgets): bool {
        $json = json_encode(array_values($widgets), JSON_UNESCAPED_UNICODE);
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO user_settings (user_id, user_type, accueil_config, date_modification)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE accueil_config = VALUES(accueil_config), date_modification = NOW()
            ");
            return $stmt->execute([$userId, $userType, $json]);
        } catch (\PDOException $e) {
            error_log("SettingsService::saveAccueilConfig error: " . $e->getMessage());
            return false;
        }
    }

    // ─── Static enums ────────────────────────────────────────────────

    public static function themes(): array {
        return ['light' => 'Clair', 'dark' => 'Sombre', 'liquid' => 'Liquide', 'auto' => 'Automatique'];
    }

    public static function fontSizes(): array {
        return ['small' => 'Petit', 'normal' => 'Normal', 'large' => 'Grand', 'xlarge' => 'Très grand'];
    }

    // ─── RACCOURCIS CLAVIER ───

    public function getKeybindings(int $userId, string $userType): array
    {
        $stmt = $this->pdo->prepare("SELECT keybindings FROM user_settings WHERE user_id = :u AND user_type = :t");
        $stmt->execute([':u' => $userId, ':t' => $userType]);
        $json = $stmt->fetchColumn();
        return $json ? (json_decode($json, true) ?: []) : self::defaultKeybindings();
    }

    public function saveKeybindings(int $userId, string $userType, array $bindings): void
    {
        $json = json_encode($bindings, JSON_UNESCAPED_UNICODE);
        $this->pdo->prepare("
            INSERT INTO user_settings (user_id, user_type, keybindings, date_modification) VALUES (:u, :t, :k, NOW())
            ON DUPLICATE KEY UPDATE keybindings = VALUES(keybindings), date_modification = NOW()
        ")->execute([':u' => $userId, ':t' => $userType, ':k' => $json]);
    }

    public static function defaultKeybindings(): array
    {
        return [
            'go_accueil' => 'Alt+H', 'go_notes' => 'Alt+N', 'go_agenda' => 'Alt+A',
            'go_messages' => 'Alt+M', 'search' => 'Ctrl+K', 'toggle_sidebar' => 'Ctrl+B',
        ];
    }

    // ─── SESSIONS ACTIVES ───

    // La table session_security utilise `is_active` (tinyint) et une PK `id`
    // varchar(128) (l'identifiant de session), pas une colonne `expired` ni un id
    // entier — d'où la signature string $sessionId.
    public function getSessionsActives(int $userId, string $userType): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM session_security WHERE user_id = :u AND user_type = :t AND is_active = 1 ORDER BY last_activity DESC");
        $stmt->execute([':u' => $userId, ':t' => $userType]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function revoquerSession(string $sessionId, int $userId, string $userType): bool
    {
        $stmt = $this->pdo->prepare("UPDATE session_security SET is_active = 0 WHERE id = :s AND user_id = :u AND user_type = :t");
        return $stmt->execute([':s' => $sessionId, ':u' => $userId, ':t' => $userType]);
    }

    public function revoquerToutesSessions(int $userId, string $userType, ?string $exceptId = null): int
    {
        $sql = "UPDATE session_security SET is_active = 0 WHERE user_id = :u AND user_type = :t AND is_active = 1";
        $params = [':u' => $userId, ':t' => $userType];
        if ($exceptId !== null && $exceptId !== '') { $sql .= " AND id != :e"; $params[':e'] = $exceptId; }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    // ─── EXPORT SETTINGS ───

    public function exportSettings(int $userId, string $userType): array
    {
        $settings = $this->getSettings($userId, $userType);
        $accueil = $this->getAccueilConfig($userId, $userType);
        return [
            'export_date' => date('Y-m-d H:i:s'),
            'settings' => $settings,
            'accueil_config' => $accueil,
        ];
    }

    public function importSettings(int $userId, string $userType, array $data): bool
    {
        if (!empty($data['settings'])) {
            $this->save($userId, $userType, $data['settings']);
        }
        if (!empty($data['accueil_config'])) {
            $this->saveAccueilConfig($userId, $userType, $data['accueil_config']);
        }
        return true;
    }

    /**
     * Réinitialise les préférences de l'utilisateur aux valeurs par défaut
     * (supprime sa ligne user_settings ; les getters retombent sur defaults()).
     */
    public function reinitialiser(int $userId, string $userType): void
    {
        $this->pdo->prepare("DELETE FROM user_settings WHERE user_id = :u AND user_type = :t")
            ->execute([':u' => $userId, ':t' => $userType]);
    }
}
