<?php
declare(strict_types=1);
/**
 * Legacy Bridge - Pont de compatibilité avec l'ancien code
 * Les fonctions sont créées uniquement si elles n'existent pas déjà.
 */

if (defined('PRONOTE_LEGACY_BRIDGE_LOADED')) {
	return;
}
define('PRONOTE_LEGACY_BRIDGE_LOADED', true);

// Ensure bootstrap (app(), helpers, autoloader) is loaded first
if (!defined('PRONOTE_BOOTSTRAP_LOADED')) {
	require_once __DIR__ . '/../bootstrap.php';
}

// Charger la classe User legacy (utilisée par les pages admin)
require_once __DIR__ . '/User.php';

// ==================== CONSTANTES GLOBALES ====================

if (!defined('LOGIN_URL')) {
	define('LOGIN_URL', '../login/index.php');
}

// ==================== AUTHENTIFICATION ====================

if (!function_exists('isLoggedIn')) {
	function isLoggedIn() {
		return app('auth')->check();
	}
}

if (!function_exists('getCurrentUser')) {
	function getCurrentUser() {
		return app('auth')->user();
	}
}

if (!function_exists('checkAuth')) {
	/**
	 * Alias de getCurrentUser() pour compatibilité messagerie
	 */
	function checkAuth() {
		return getCurrentUser();
	}
}

if (!function_exists('getUserRole')) {
	function getUserRole() {
		$user = app('auth')->user();
		return $user['profil'] ?? $user['type'] ?? null;
	}
}

if (!function_exists('requireLogin')) {
	function requireLogin() {
		if (!app('auth')->check()) {
			redirect('login/index.php');
		}
		return app('auth')->user();
	}
}

if (!function_exists('requireAuth')) {
	function requireAuth() {
		return requireLogin();
	}
}

if (!function_exists('logout')) {
	function logout() {
		app('auth')->logout();
	}
}

// ==================== VÉRIFICATIONS DE RÔLES ====================

if (!function_exists('isAdmin')) {
	function isAdmin() {
		return getUserRole() === 'administrateur';
	}
}
if (!function_exists('isTeacher')) {
	function isTeacher() {
		return getUserRole() === 'professeur';
	}
}
if (!function_exists('isProfesseur')) {
	function isProfesseur() {
		return isTeacher();
	}
}
if (!function_exists('isStudent')) {
	function isStudent() {
		return getUserRole() === 'eleve';
	}
}
if (!function_exists('isEleve')) {
	function isEleve() {
		return isStudent();
	}
}
if (!function_exists('isParent')) {
	function isParent() {
		return getUserRole() === 'parent';
	}
}
if (!function_exists('isVieScolaire')) {
	function isVieScolaire() {
		return getUserRole() === 'vie_scolaire';
	}
}

// ==================== PERMISSIONS (centralisées via RBAC) ====================

if (!function_exists('hasPermission')) {
	/**
	 * Vérifie si l'utilisateur connecté a la permission pour une action donnée.
	 * Délègue au moteur UNIQUE can() (catalogue RoleCatalog + surcharges plateforme
	 * rbac_grants). Accepte les formats legacy "notes" et catalogue "notes.manage".
	 * @param string $action Clé de permission
	 * @return bool
	 */
	function hasPermission(string $action): bool {
		// Format legacy "notes" → permission "notes.manage" (gestion). Route via can()
		// unifié : catalogue/authz (rôles effectifs base + attribués, résolution .manage).
		// Les rôles attribués (cpe, infirmerie, professeur_principal…) satisfont canManageX().
		$perm = str_contains($action, '.') ? $action : ($action . '.manage');
		return can($perm);
	}
}

/**
 * Fonctions legacy de vérification de permissions par module.
 * @deprecated Préférer `hasPermission('module.action')` ou `can('domaine.action')`.
 *
 * Conservées pour compatibilité ascendante : elles délèguent toutes à hasPermission()
 * → can() (moteur unique, catalogue RoleCatalog + rbac_grants). Aucune dépendance à
 * l'ancien RBAC ni à une matrice DB : « ce qu'un rôle peut faire » est central et
 * éditable depuis la plateforme.
 *
 * Définies inline (pas d'eval, pas de cache fichier) pour éviter les problèmes de
 * permissions sur storage/cache/ entre l'utilisateur d'install et www-data.
 */
if (!function_exists('canManageNotes')) {
    function canManageNotes(): bool       { return hasPermission('notes'); }
    function canManageAbsences(): bool    { return hasPermission('absences'); }
    function canManageDevoirs(): bool     { return hasPermission('devoirs'); }
    function canManageEDT(): bool         { return hasPermission('edt'); }
    function canManageAppel(): bool       { return hasPermission('appel'); }
    function canManageDiscipline(): bool  { return hasPermission('discipline'); }
    function canSignalerIncident(): bool  { return hasPermission('signaler_incident'); }
    function canManageAnnonces(): bool    { return hasPermission('annonces'); }
    function canManageBulletins(): bool   { return hasPermission('bulletins'); }
    function canManageRendus(): bool      { return hasPermission('rendus'); }
    function canAccessVieScolaire(): bool { return hasPermission('vie_scolaire'); }
    function canManageDocuments(): bool   { return hasPermission('documents'); }
    function canManageCompetences(): bool { return hasPermission('competences'); }
    function canAccessReporting(): bool   { return hasPermission('reporting'); }
    function canManageReunions(): bool    { return hasPermission('reunions'); }
    function canManageInscriptions(): bool{ return hasPermission('inscriptions'); }
    function canManageOrientation(): bool { return hasPermission('orientation'); }
    function canManageSignalements(): bool{ return hasPermission('signalements'); }
    function canManageBibliotheque(): bool{ return hasPermission('bibliotheque'); }
    function canManageClubs(): bool       { return hasPermission('clubs'); }
    function canAccessInfirmerie(): bool  { return hasPermission('infirmerie'); }
    function canManageArchives(): bool    { return hasPermission('archives'); }
    function canManageSupport(): bool     { return hasPermission('support'); }
    function canManageExamens(): bool     { return hasPermission('examens'); }
    function canManageBesoins(): bool     { return hasPermission('besoins'); }
    function canManagePersonnel(): bool   { return hasPermission('personnel'); }
    function canManageSalles(): bool      { return hasPermission('salles'); }
    function canManagePeriscolaire(): bool{ return hasPermission('periscolaire'); }
    function canManageStages(): bool      { return hasPermission('stages'); }
    function canManageTransports(): bool  { return hasPermission('transports'); }
    function canManageFacturation(): bool { return hasPermission('facturation'); }
    function canManageRessources(): bool  { return hasPermission('ressources'); }
    function canManageDiplomes(): bool    { return hasPermission('diplomes'); }
}

if (!function_exists('isPersonnelVS')) {
    /** @deprecated Utiliser isVieScolaire() */
    function isPersonnelVS() { return getUserRole() === 'vie_scolaire'; }
}

if (!function_exists('parentOwnsEleve')) {
    /**
     * Helper anti-IDOR : vérifie que l'élève passé en argument fait partie des enfants
     * rattachés au parent (table `parent_eleve` OU `eleve_parents` selon le schéma).
     *
     * À appeler dans toute page de détail (`?id=`/`?eleve=`) où le rôle "parent" peut
     * arriver, avant d'afficher des données rattachées à un élève. Empêche l'IDOR du
     * type "parent qui change l'ID dans l'URL pour voir un autre enfant".
     *
     * @return bool true si le parent est bien rattaché à cet élève ; false sinon
     *              ou si l'utilisateur n'est pas un parent.
     */
    function parentOwnsEleve(int $parentId, int $eleveId): bool
    {
        try {
            $pdo = getPDO();
            // Deux conventions de schéma coexistent dans le code :
            //   - canonique (pronote.sql) : `parent_eleve` (id_parent, id_eleve)
            //   - héritée (BulletinService / portail_parents) : `eleve_parents` (parent_id, eleve_id)
            // On teste les deux avec les BONNES colonnes pour chacune.
            $variants = [
                ['table' => 'parent_eleve', 'pcol' => 'id_parent', 'ecol' => 'id_eleve'],
                ['table' => 'eleve_parents', 'pcol' => 'parent_id', 'ecol' => 'eleve_id'],
            ];
            foreach ($variants as $v) {
                try {
                    $stmt = $pdo->prepare(
                        "SELECT 1 FROM `{$v['table']}` WHERE `{$v['pcol']}` = ? AND `{$v['ecol']}` = ? LIMIT 1"
                    );
                    $stmt->execute([$parentId, $eleveId]);
                    if ($stmt->fetchColumn()) return true;
                } catch (\Throwable $e) { /* table absente : on essaie l'autre */ error_log('[Bridge.php] ' . $e->getMessage()); }
            }
            return false;
        } catch (\Throwable $e) {
            error_log('parentOwnsEleve: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('assertUserCanReadEleve')) {
    /**
     * Helper consolidé : retourne true si l'utilisateur courant peut consulter
     * les données rattachées à $eleveId.
     *
     *  - admin / vie_scolaire / professeur : OK (RBAC peut affiner ensuite).
     *  - eleve : OK si c'est lui-même.
     *  - parent : OK si l'élève est l'un de ses enfants (cf. parentOwnsEleve).
     *
     * À utiliser systématiquement avant de servir notes / bulletins / absences /
     * cahier de textes / etc. d'un élève précis identifié par l'URL.
     */
    function assertUserCanReadEleve(int $eleveId): bool
    {
        $user = getCurrentUser();
        if (!$user) return false;
        $role = getUserRole();
        // Borne établissement + périmètre par rôle : anti-fuite inter-établissement
        // ET anti-IDOR (un professeur ne lit QUE les élèves de ses classes, pas tous).
        $pdo = getPDO();
        try {
            $stE = $pdo->prepare("SELECT etablissement_id, classe FROM eleves WHERE id = ? LIMIT 1");
            $stE->execute([$eleveId]);
            $eRow = $stE->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) { return false; }
        if (!$eRow) return false;
        if ($role === 'super_admin') return true;
        $myEtab = (int) ($user['etablissement_id'] ?? 0);
        if ($myEtab <= 0) { try { $myEtab = (int) \API\Core\EstablishmentContext::id(); } catch (\Throwable $e) { error_log('[Bridge.php] ' . $e->getMessage()); } }
        if ($myEtab > 0 && (int) $eRow['etablissement_id'] !== $myEtab) return false;
        if (in_array($role, ['administrateur', 'vie_scolaire'], true)) return true;
        if ($role === 'professeur') {
            // Périmètre unifié (account_relationships ∪ professeur_classes, avec respect de
            // l'activation/expiration des relations) — cohérent avec Authorization::can(own_classes).
            $resolver = new \API\Security\ScopeResolver($pdo, [
                'id' => (int) $user['id'], 'type' => 'professeur', 'etablissement_id' => $myEtab,
            ]);
            return $resolver->teachesClass((string) ($eRow['classe'] ?? ''));
        }
        if ($role === 'eleve')  return (int) $user['id'] === $eleveId;
        if ($role === 'parent') return parentOwnsEleve((int) $user['id'], $eleveId);
        return false;
    }
}

// ==================== RBAC ====================

if (!function_exists('getEffectiveRoles')) {
	/**
	 * Rôles EFFECTIFS de l'utilisateur courant : rôle de base (type de compte) +
	 * rôles attribués actifs (table user_roles, scopés/temporisés), résolus par le
	 * moteur catalogue (Authorization). C'est la base de tout contrôle d'accès par
	 * rôle : un rôle attribué (cpe, infirmerie, professeur_principal…) compte autant
	 * que le type de compte. Repli sur le seul type de base si le moteur échoue.
	 *
	 * @return string[] clés de rôles effectifs
	 */
	function getEffectiveRoles(): array {
		try {
			$keys = authz()->roleKeys();
			if (!empty($keys)) return $keys;
		} catch (\Throwable $e) {
			error_log('[getEffectiveRoles] ' . $e->getMessage());
		}
		$base = getUserRole();
		return $base ? [$base] : [];
	}
}

if (!function_exists('hasCapability')) {
	/**
	 * Capacité (garde d'entrée SANS périmètre) : l'un des rôles effectifs de
	 * l'utilisateur octroie-t-il $permission ? Pour « peut ouvrir ce module / cette
	 * fonctionnalité » — où il n'y a pas de ressource cible. Voir Authorization::hasCapability.
	 */
	function hasCapability(string $permission): bool {
		try { return authz()->hasCapability($permission); }
		catch (\Throwable $e) { error_log('[hasCapability] ' . $e->getMessage()); return false; }
	}
}

if (!function_exists('requireCapability')) {
	/**
	 * Bloque l'accès si l'utilisateur n'a pas la capacité $permission (garde d'entrée
	 * module/fonctionnalité, gouvernée par les permissions du rôle → éditable plateforme).
	 * Remplace requireRole() codé en dur sur les pages d'entrée de module. Même UX de refus
	 * que requireRole (message + redirection accueil). super_admin passe toujours.
	 */
	function requireCapability(string $permission): void {
		if (hasCapability($permission)) return;
		$script  = $_SERVER['SCRIPT_NAME'] ?? '?';
		$current = implode(', ', getEffectiveRoles()) ?: '(non authentifié)';
		if (session_status() === PHP_SESSION_ACTIVE) {
			$_SESSION['error_message'] = "Accès refusé sur {$script} : capacité requise = [{$permission}], rôles actuels = [{$current}].";
		}
		error_log("[requireCapability] denied script={$script} perm={$permission} roles=[{$current}]");
		if (!headers_sent()) {
			header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/accueil/accueil.php');
			exit;
		}
		http_response_code(403);
		exit('Accès non autorisé.');
	}
}

if (!function_exists('enforceModuleAccess')) {
	/**
	 * Gate d'autorisation PAR MODULE (défense en profondeur, fail-closed).
	 *
	 * Si le module $moduleKey déclare une liste de rôles autorisés
	 * (modules_config.roles_autorises) et qu'aucun rôle effectif de l'utilisateur n'y
	 * figure, l'accès est refusé (redirection vers l'accueil, comme requireRole). Les
	 * modules sans restriction (roles_autorises NULL ou "*") restent accessibles à tout
	 * utilisateur authentifié ; super_admin a toujours accès. Garantit que la visibilité
	 * du menu == l'accès réel (corrige « lien affiché mais accès qui rebondit »), et
	 * bloque l'accès direct par URL aux modules réservés.
	 */
	function enforceModuleAccess(string $moduleKey): void {
		$moduleKey = trim($moduleKey);
		if ($moduleKey === '') return;
		$roles = [];
		try {
			$roles   = getEffectiveRoles();
			$allowed = app('modules')->isVisibleForRoles($moduleKey, $roles);
		} catch (\Throwable $e) {
			// Fail-closed : une erreur d'infrastructure (DB/cache) ne doit JAMAIS
			// accorder l'accès par défaut. On refuse ce module — l'utilisateur est
			// renvoyé vers l'accueil (page non gatée), pas verrouillé hors de l'app.
			error_log('[enforceModuleAccess] infra error, denying module=' . $moduleKey . ' : ' . $e->getMessage());
			$allowed = false;
		}
		if ($allowed) return;

		$script  = $_SERVER['SCRIPT_NAME'] ?? '?';
		$current = $roles ? implode(', ', $roles) : '(non authentifié)';
		if (session_status() === PHP_SESSION_ACTIVE) {
			$_SESSION['error_message'] = "Accès refusé au module « {$moduleKey} » (rôles : {$current}).";
		}
		error_log("[enforceModuleAccess] denied module={$moduleKey} script={$script} roles=[{$current}]");
		if (!headers_sent()) {
			$base = defined('BASE_URL') ? BASE_URL : '';
			header('Location: ' . $base . '/accueil/accueil.php');
			exit;
		}
		http_response_code(403);
		exit('Accès non autorisé.');
	}
}

if (!function_exists('can')) {
	/**
	 * L'utilisateur courant a-t-il $permission (dans le contexte $ctx) ?
	 *
	 * Source de vérité UNIQUE = catalogue/authz (rôles effectifs base + attribués,
	 * périmètre, résolution .manage, surcharges globales rbac_grants éditées plateforme,
	 * audit des permissions sensibles). Plus de repli RBAC legacy : le catalogue fait foi.
	 * Fail-closed : toute erreur du moteur ⇒ refus.
	 *
	 * $ctx (optionnel) : ['etablissement_id'=>, 'class_id'=>, 'subject_id'=>,
	 * 'student_id'=>, 'owner_id'=>, 'owner_type'=>] — périmètre de l'action.
	 */
	function can(string $permission, array $ctx = []): bool {
		try { return authz()->can($permission, $ctx); }
		catch (\Throwable $e) { error_log('[can] authz: ' . $e->getMessage()); return false; }
	}
}

if (!function_exists('authorize')) {
	/**
	 * Vérifie une permission — bloque (redirection) si refusée. Délègue à can() unifié.
	 */
	function authorize(string $permission, array $ctx = []): void {
		if (can($permission, $ctx)) return;
		$_SESSION['error_message'] = 'Accès refusé.';
		$base = defined('BASE_URL') ? BASE_URL : '';
		header('Location: ' . $base . '/accueil/accueil.php');
		exit;
	}
}

// ==================== UTILITAIRES UTILISATEUR ====================

if (!function_exists('getUserFullName')) {
	function getUserFullName() {
		$user = app('auth')->user();
		return $user ? trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')) : '';
	}
}

if (!function_exists('getUserInitials')) {
	function getUserInitials() {
		$user = app('auth')->user();
		if (!$user) return '??';
		$i1 = !empty($user['prenom']) ? strtoupper(mb_substr($user['prenom'], 0, 1)) : '';
		$i2 = !empty($user['nom']) ? strtoupper(mb_substr($user['nom'], 0, 1)) : '';
		return ($i1 . $i2) ?: '??';
	}
}

if (!function_exists('getUserId')) {
	function getUserId() {
		$user = app('auth')->user();
		return $user['id'] ?? null;
	}
}

// ==================== INTERNATIONALISATION (i18n) ====================

if (!function_exists('__')) {
	/**
	 * Traduit une clé avec interpolation de paramètres.
	 * @param string $key     Clé de traduction (ex: 'btn.save', 'modules/notes.title')
	 * @param array  $params  Paramètres (ex: ['name' => 'Jean'] → ':name' remplacé)
	 * @param string|null $locale Forcer une locale
	 * @return string Texte traduit ou la clé si non trouvée
	 */
	function __(string $key, array $params = [], ?string $locale = null): string {
		try {
			return app('translator')->get($key, $params, $locale);
		} catch (\Throwable $e) {
			return $key;
		}
	}
}

// ==================== CSRF (helpers supplémentaires, ex-core.php) ====================

if (!function_exists('csrf_verify')) {
    function csrf_verify(): void {
        app('csrf')->verifyOrFail();
    }
}

// ==================== CSRF ====================

if (!function_exists('csrf_token')) {
	function csrf_token() {
		return app('csrf')->getToken();
	}
}
if (!function_exists('csrf_field')) {
	function csrf_field() {
		return app('csrf')->field();
	}
}
if (!function_exists('generateCSRFToken')) {
	function generateCSRFToken() {
		return app('csrf')->generate();
	}
}
if (!function_exists('validateCSRFToken')) {
	function validateCSRFToken($token = null) {
		// Délègue au validateur canonique : POST csrf_token/_csrf_token, header X-CSRF-Token, body JSON.
		if ($token !== null) {
			return app('csrf')->validate($token);
		}
		return app('csrf')->validateFromRequest();
	}
}
if (!function_exists('csrfField')) {
	function csrfField() {
		return app('csrf')->field();
	}
}

// ==================== LOGGING ====================

if (!function_exists('logError')) {
	function logError($message, $context = []) {
		try { app('log')->error($message, (array)$context); } catch (\Throwable $e) { error_log("[ERROR] $message " . json_encode($context)); }
	}
}
if (!function_exists('logInfo')) {
	function logInfo($message, $context = []) {
		try { app('log')->info($message, (array)$context); } catch (\Throwable $e) { error_log("[INFO] $message " . json_encode($context)); }
	}
}
if (!function_exists('logSecurityEvent')) {
	function logSecurityEvent($event, $data = []) {
		// Toujours logger dans les logs système
		error_log("SECURITY EVENT: {$event} " . json_encode($data));
		
		try {
			logInfo("Security event: {$event}", (array)$data);
			
			// Tenter l'audit en base (critique)
			$audit = app('audit');
			if ($audit) { 
				$result = $audit->logSecurity($event, (array)$data);
				if (!$result) {
					// Échec critique : notifier
					error_log("CRITICAL: Audit security logging failed for event '{$event}'");
				}
			} else {
				error_log("WARNING: Audit service not available for security event '{$event}'");
			}
		} catch (\Throwable $e) {
			// Erreur critique : TOUJOURS logger
			error_log("CRITICAL: Audit security exception for event '{$event}': " . $e->getMessage());
			error_log("Stack trace: " . $e->getTraceAsString());
		}
	}
}

// ==================== REDIRECTION ====================

if (!function_exists('redirect')) {
	function redirect($path, $message = null, $type = 'info') {
		if ($message) {
			$_SESSION['flash'][$type] = $message;
		}
		if (strpos($path, 'http') === 0) {
			header("Location: {$path}");
			exit;
		}
		$baseUrl = defined('BASE_URL') ? BASE_URL : (env('APP_URL', '') ?: '');
		$url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
		header("Location: {$url}");
		exit;
	}
}

if (!function_exists('redirectTo')) {
	/**
	 * Redirige vers une URL avec message flash optionnel
	 * Alias simplifié de redirect()
	 */
	function redirectTo($url, $message = null) {
		if ($message) {
			$_SESSION['error_message'] = $message;
		}
		header("Location: {$url}");
		exit;
	}
}

if (!function_exists('setFlashMessage')) {
	/**
	 * Définit un message flash en session
	 */
	function setFlashMessage($type, $message) {
		$_SESSION[$type . '_message'] = $message;
	}
}

// ==================== GLOBAL PDO HELPER (remplacement de $GLOBALS) ====================

if (!function_exists('getPDO')) {
	/**
	 * Récupère la connexion PDO (remplace $GLOBALS['pdo'])
	 * @return PDO
	 */
	function getPDO(): PDO {
		return app('db')->getConnection();
	}
}

// ==================== FONCTIONS UTILITAIRES COMMUNES ====================

if (!function_exists('formatDate')) {
	/**
	 * Formate une date au format français
	 * @param string $date La date au format SQL
	 * @param string $format Le format de sortie (défaut: d/m/Y)
	 * @return string La date formatée
	 */
	function formatDate($date, $format = 'd/m/Y') {
		if (empty($date)) return '';
		$timestamp = strtotime($date);
		return $timestamp ? date($format, $timestamp) : '';
	}
}

if (!function_exists('formatDateTime')) {
	/**
	 * Formate une date et heure au format français
	 * @param string $datetime La date et heure au format SQL
	 * @return string La date et heure formatée
	 */
	function formatDateTime($datetime) {
		if (empty($datetime)) return '';
		$timestamp = strtotime($datetime);
		return $timestamp ? date('d/m/Y à H:i', $timestamp) : '';
	}
}

if (!function_exists('getTrimestre')) {
	/**
	 * Détermine le trimestre scolaire actuel
	 * @return string Trimestre actuel
	 */
	function getTrimestre() {
		$mois = date('n');
		if ($mois >= 9 && $mois <= 12) return "1er trimestre";
		if ($mois >= 1 && $mois <= 3) return "2ème trimestre";
		if ($mois >= 4 && $mois <= 6) return "3ème trimestre";
		return "Période estivale";
	}
}

if (!function_exists('changePassword')) {
	function changePassword($userId, $newPassword, ?string $userType = null) {
		try {
			$userService = app()->make('API\Services\UserService');
			return $userService->changePassword($userId, $newPassword, $userType);
		} catch (\Exception $e) {
			error_log("Password change error: " . $e->getMessage());
			return ['success' => false, 'message' => 'Erreur lors du changement de mot de passe'];
		}
	}
}

if (!function_exists('getEtablissementData')) {
	function getEtablissementData() {
		try {
			$etablissementService = app()->make('API\Services\EtablissementService');
			return $etablissementService->getData();
		} catch (\Exception $e) {
			error_log("Etablissement data error: " . $e->getMessage());
			return ['info' => null, 'classes' => [], 'matieres' => [], 'periodes' => []];
		}
	}
}

if (!function_exists('isSuperAdmin')) {
	/**
	 * Check if the current user is a super-admin.
	 */
	function isSuperAdmin(): bool {
		return \API\Services\SuperAdminService::isSuperAdmin();
	}
}

// ──────────── RBAC/ABAC : autorisation par permission + périmètre ────────────
if (!function_exists('authz')) {
	/**
	 * Moteur d'autorisation courant (Authorization). Synchronise une seule fois par
	 * requête l'utilisateur courant dans le moteur : le singleton peut avoir été
	 * résolu avant l'ouverture de session (utilisateur null) ; sans cette synchro les
	 * rôles attribués ne seraient jamais évalués. roles() reste mis en cache ensuite
	 * (une seule lecture de user_roles par requête).
	 */
	function authz() {
		$a = app('authz');
		static $synced = false;
		if (!$synced) {
			$synced = true;
			try { $a->setUser(app('auth')->user()); } catch (\Throwable $e) { error_log('[Bridge.php] ' . $e->getMessage()); }
		}
		return $a;
	}
}
if (!function_exists('findUserByCredentials')) {
	function findUserByCredentials($username, $email, $phone, $userType) {
		try {
			$userService = app()->make('API\Services\UserService');
			return $userService->findByCredentials($username, $email, $phone, $userType);
		} catch (\Exception $e) {
			error_log("Find user error: " . $e->getMessage());
			return null;
		}
	}
}

if (!function_exists('createResetRequest')) {
	function createResetRequest($userId, $userType) {
		try {
			$userService = app()->make('API\Services\UserService');
			return $userService->createResetRequest($userId, $userType);
		} catch (\Exception $e) {
			error_log("Reset request error: " . $e->getMessage());
			return false;
		}
	}
}

// ──────────── Helpers d'autorisation des mondes plateforme/établissement ────────────
// Délèguent à API\Security\WorldContext.
if (!function_exists('platformCan')) {
	function platformCan(string $permission): bool { return \API\Security\WorldContext::platformCan($permission); }
}
if (!function_exists('platformAuthorize')) {
	function platformAuthorize(string $permission): void { \API\Security\WorldContext::platformAuthorize($permission); }
}
if (!function_exists('tenantCan')) {
	function tenantCan(string $permission): bool { return \API\Security\WorldContext::tenantCan($permission); }
}
if (!function_exists('tenantAuthorize')) {
	function tenantAuthorize(string $permission): void { \API\Security\WorldContext::tenantAuthorize($permission); }
}
if (!function_exists('tenantGate')) {
	/**
	 * Garde des pages back-office établissement — MODÈLE D'AUTORISATION UNIFIÉ.
	 *
	 * Un rôle = son jeu de permissions (catalogue RoleCatalog + surcharges GLOBALES
	 * éditées côté PLATEFORME via rbac_grants). L'accès est décidé par la PERMISSION,
	 * résolue par le moteur UNIQUE Authorization::can() — plus de « monde tenant »
	 * (tables d'appartenance) ni de repli par nom de rôle. Régler « qui a le droit »
	 * = éditer les permissions du rôle depuis la plateforme, rien d'autre.
	 *
	 * Les clés d'appel historiques `tenant.*` sont traduites vers la famille unique de
	 * permissions back-office `admin.*` du catalogue (une seule famille = éditeur clair).
	 * super_admin passe toujours ('*'), administrateur détient la famille admin.* ;
	 * aucun autre rôle ne l'a par défaut ⇒ comportement identique à l'ancien repli.
	 *
	 * @param string $permission  clé de permission (tenant.* héritée ou clé catalogue)
	 */
	function tenantGate(string $permission): void {
		static $MAP = [
			'tenant.users.view'           => 'admin.users',
			'tenant.users.manage'         => 'admin.users',
			'tenant.classes.view'         => 'admin.classes',
			'tenant.classes.manage'       => 'admin.classes',
			'tenant.roles.view'           => 'roles.view',
			'tenant.roles.manage'         => 'roles.manage',
			'tenant.modules.manage'       => 'admin.modules',
			'tenant.etablissement.manage' => 'admin.etablissement',
			'tenant.imports.manage'       => 'admin.users.import',
			'tenant.exports.manage'       => 'export.export',
			'tenant.audit.view'           => 'audit.view',
			'tenant.dashboard.view'       => 'admin.access',
		];
		if (isset($MAP[$permission])) {
			$perm = $MAP[$permission];
		} elseif (strncmp($permission, 'tenant.', 7) === 0) {
			// clé tenant.* non cartographiée : défaut conservateur (admin uniquement).
			error_log("[tenantGate] clé tenant.* non mappée: {$permission} → admin.access");
			$perm = 'admin.access';
		} else {
			$perm = $permission; // déjà une clé catalogue
		}
		authorize($perm);
	}
}

// ==================== FIN DU BRIDGE ====================
