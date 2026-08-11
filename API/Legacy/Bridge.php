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

if (!function_exists('login')) {
	function login($profil, $identifiant, $password) {
		// Map legacy fields to current credentials keys
		return app('auth')->attempt([
			'type' => $profil,
			'login' => $identifiant,
			'password' => $password
		]);
	}
}

if (!function_exists('loginUser')) {
	/**
	 * Crée la session pour un utilisateur déjà validé (après 2FA ou login unifié).
	 * @param array $user Tableau utilisateur avec au moins 'id' et 'type'
	 */
	function loginUser(array $user): void {
		app('auth')->loginUser($user);
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
	 * Délègue au système RBAC centralisé (API\Security\RBAC).
	 * Accepte les formats legacy "notes" et RBAC "notes.manage".
	 * @param string $action Clé de permission
	 * @return bool
	 */
	function hasPermission(string $action): bool {
		// Format legacy "notes" → permission "notes.manage" (gestion). Route via can()
		// unifié : catalogue/authz (rôles effectifs base + attribués, résolution .manage)
		// d'abord, repli RBAC legacy ensuite. Zéro régression, et les rôles attribués
		// (cpe, infirmerie, professeur_principal…) satisfont désormais canManageX().
		$perm = str_contains($action, '.') ? $action : ($action . '.manage');
		return can($perm);
	}
}

/**
 * Fonctions legacy de vérification de permissions par module.
 * @deprecated DEPUIS 3.0 — utiliser :
 *   - `app('rbac')->can($userId, $userType, 'module', 'action')` pour une vérif fine,
 *   - ou `hasPermission('module.action')` côté code applicatif.
 *
 * Conservées pour compatibilité ascendante : elles doublent la matrice RBAC dynamique
 * (table `module_permissions`). Risque connu : un admin qui décoche `can_edit` dans la
 * matrice peut être contredit par une de ces fonctions si la logique du module n'utilise
 * que `canManageX()` au lieu de la matrice. Toute nouvelle écriture DOIT passer par
 * `app('rbac')->can()` avec module + action explicites.
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

if (!function_exists('requireRole')) {
	/**
	 * Bloque l'accès si AUCUN rôle effectif (base + attribués) n'est dans la liste.
	 * super_admin a toujours accès (périmètre global).
	 * @param string ...$roles Rôles autorisés
	 */
	function requireRole(string ...$roles) {
		$effective = getEffectiveRoles();
		// super_admin = accès global, ne se voit jamais refuser par un requireRole.
		if (in_array('super_admin', $effective, true)) return;
		if (array_intersect($roles, $effective)) return;

		// Message explicite avec rôle requis + rôles actuels — sinon l'admin se
		// retrouve avec une redirection muette et aucune trace utile.
		$wanted  = implode(', ', $roles);
		$current = $effective ? implode(', ', $effective) : '(non authentifié)';
		$script  = $_SERVER['SCRIPT_NAME'] ?? '?';
		$_SESSION['error_message'] = "Accès refusé sur {$script} : rôle requis = [{$wanted}], rôles actuels = [{$current}].";
		error_log("[requireRole] denied script={$script} roles=[{$current}] expected=[{$wanted}]");
		$base = defined('BASE_URL') ? BASE_URL : '';
		header('Location: ' . $base . '/accueil/accueil.php');
		exit;
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
	 * Source de vérité = catalogue/authz (rôles effectifs base + attribués, périmètre,
	 * résolution .manage, audit des permissions sensibles). Repli sur l'ancien RBAC
	 * pour les clés hors catalogue (admin.*, *.manage seedées par module.json, matrice
	 * module_permissions éditable) → zéro régression pendant la convergence.
	 *
	 * $ctx (optionnel) : ['etablissement_id'=>, 'class_id'=>, 'subject_id'=>,
	 * 'student_id'=>, 'owner_id'=>, 'owner_type'=>] — périmètre de l'action.
	 */
	function can(string $permission, array $ctx = []): bool {
		try { if (authz()->can($permission, $ctx)) return true; }
		catch (\Throwable $e) { error_log('[can] authz: ' . $e->getMessage()); }
		// Anti-IDOR : si un CONTEXTE de périmètre a été fourni, le moteur scopé a évalué le
		// scope et son refus fait autorité. On NE retombe PAS sur le RBAC legacy (aveugle au
		// périmètre), qui re-accorderait une permission refusée pour cause de scope. Le repli
		// legacy ne vaut que pour les vérifs SANS périmètre (clés hors catalogue : admin.*,
		// matrice module_permissions…) → zéro régression.
		if (!empty($ctx)) {
			return false;
		}
		try { return app('rbac')->can($permission); }
		catch (\Throwable $e) { return false; }
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

if (!function_exists('canOn')) {
	/**
	 * « L'utilisateur peut-il $permission SUR cette ressource ? » — forme à privilégier
	 * dans les modules pour bloquer l'IDOR : le périmètre est déduit du type+id de la
	 * ressource. Ex: canOn('notes.view', 'student', $eleveId), canOn('cdt.edit', 'class', $id).
	 * Types reconnus : student/eleve, class/classe, establishment/etablissement,
	 * subject/matiere, self/owner. $extra ajoute des clés de contexte (ex. owner_type).
	 *
	 * Contrairement à can(), pas de repli RBAC aveugle au périmètre : l'enforcement de
	 * scope est précisément le but ici (les permissions visées sont au catalogue).
	 */
	function canOn(string $permission, string $resourceType, int $resourceId, array $extra = []): bool {
		try { return authz()->canOn($permission, $resourceType, $resourceId, $extra); }
		catch (\Throwable $e) { error_log('[canOn] ' . $e->getMessage()); return false; }
	}
}

if (!function_exists('authorizeOn')) {
	/**
	 * Vérifie une permission SUR une ressource — bloque (redirection) si refusée.
	 * Fail-closed : toute erreur d'évaluation ⇒ refus (canOn() renvoie false).
	 */
	function authorizeOn(string $permission, string $resourceType, int $resourceId, array $extra = []): void {
		if (canOn($permission, $resourceType, $resourceId, $extra)) return;
		$_SESSION['error_message'] = 'Accès refusé.';
		$base = defined('BASE_URL') ? BASE_URL : '';
		if (!headers_sent()) header('Location: ' . $base . '/accueil/accueil.php');
		exit;
	}
}

if (!function_exists('canModule')) {
	/**
	 * Vérifie une permission CRUD sur un module.
	 * Ex: canModule('messagerie', 'send'), canModule('notes', 'create')
	 */
	function canModule(string $moduleKey, string $action = 'view'): bool {
		try {
			return app('rbac')->canModule($moduleKey, $action);
		} catch (\Throwable $e) {
			return false;
		}
	}
}

if (!function_exists('requireAdmin')) {
	/**
	 * Bloque l'accès au back-office si non-admin ou technicien
	 */
	function requireAdmin(): void {
		$role = getUserRole();
		if ($role === 'technicien') {
			// Technicien has limited admin access, verify it's still valid
			if (!isTechnicienValid()) {
				$_SESSION['error_message'] = 'Accès technicien expiré.';
				header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/login/index.php');
				exit;
			}
			return;
		}
		requireRole('administrateur');
	}
}

if (!function_exists('isTechnicienValid')) {
	/**
	 * Vérifie si l'accès technicien est encore valide (actif + non expiré)
	 */
	function isTechnicienValid(): bool {
		if (getUserRole() !== 'technicien') return false;
		try {
			$pdo = getPDO();
			$stmt = $pdo->prepare("SELECT id FROM technicien_access WHERE id = ? AND actif = 1 AND date_expiration > NOW() AND revoked_at IS NULL LIMIT 1");
			$stmt->execute([getUserId()]);
			return (bool)$stmt->fetchColumn();
		} catch (\Throwable $e) {
			return false;
		}
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

if (!function_exists('_n')) {
	/**
	 * Pluralisation. Le fichier de traduction utilise des variantes séparées par |
	 * Ex: "Aucun élément|:count élément|:count éléments"
	 * @param string $key    Clé de traduction
	 * @param int    $count  Nombre pour la pluralisation
	 * @param array  $params Paramètres supplémentaires
	 * @param string|null $locale Locale forcée
	 * @return string
	 */
	function _n(string $key, int $count, array $params = [], ?string $locale = null): string {
		try {
			return app('translator')->choice($key, $count, $params, $locale);
		} catch (\Throwable $e) {
			return $key;
		}
	}
}

if (!function_exists('currentLocale')) {
	/**
	 * Retourne la locale active
	 */
	function currentLocale(): string {
		try {
			return app('translator')->locale();
		} catch (\Throwable $e) {
			return 'fr';
		}
	}
}

// ==================== CSRF (helpers supplémentaires, ex-core.php) ====================

if (!function_exists('csrf_meta')) {
    function csrf_meta() {
        return app('csrf')->meta();
    }
}

if (!function_exists('csrf_validate')) {
    function csrf_validate(?string $token = null): bool {
        if ($token !== null) {
            return app('csrf')->validate($token);
        }
        return app('csrf')->validateFromRequest();
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify(): void {
        app('csrf')->verifyOrFail();
    }
}

if (!function_exists('isAjaxRequest')) {
    function isAjaxRequest(): bool {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
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

// ==================== DATABASE ====================

if (!function_exists('executeQuery')) {
	function executeQuery($sql, $params = [], $fetchMode = \PDO::FETCH_ASSOC) {
		$pdo = app('db')->getConnection();
		$stmt = $pdo->prepare($sql);
		$stmt->execute($params);

		$head = strtoupper(strtok(ltrim($sql), " \t\n\r"));
		if (in_array($head, ['SELECT','SHOW','DESCRIBE','EXPLAIN'], true)) {
			return $stmt->fetchAll($fetchMode);
		}
		if ($head === 'INSERT') {
			return (int) $pdo->lastInsertId();
		}
		return $stmt->rowCount();
	}
}

if (!function_exists('tableExists')) {
	function tableExists($tableName) {
		try {
			$pdo = app('db')->getConnection();
			$stmt = $pdo->prepare("SHOW TABLES LIKE ?");
			$stmt->execute([$tableName]);
			return $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			return false;
		}
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

// ==================== RATE LIMITING ====================

if (!function_exists('checkRateLimit')) {
	function checkRateLimit($key, $maxAttempts = 5, $decaySeconds = 60) {
		// Our RateLimiter exposes tooManyAttempts/hit/clear. Decay is session-based.
		$limiter = app('rate_limiter');
		if ($limiter->tooManyAttempts($key)) {
			return false;
		}
		$limiter->hit($key);
		return true;
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

// ==================== SESSION CLEANUP ====================

if (!function_exists('cleanExpiredSessions')) {
	function cleanExpiredSessions() {
		try {
			$pdo = app('db')->getConnection();
			$lifetime = (int)(config('security.session_lifetime', 7200));
			$sql = "DELETE FROM session_security WHERE last_activity < DATE_SUB(NOW(), INTERVAL ? SECOND)";
			$stmt = $pdo->prepare($sql);
			$stmt->execute([$lifetime]);
		} catch (\Throwable $e) {
			// Silent fail
			error_log('[Bridge.php] ' . $e->getMessage());
		}
	}
}

// ==================== VALIDATION HELPERS ====================

if (!function_exists('sanitizeInput')) {
	function sanitizeInput($input, $type = 'string') {
		if ($input === null) return null;
		switch ($type) {
			case 'email': return filter_var(trim($input), FILTER_SANITIZE_EMAIL);
			case 'int': return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
			case 'float': return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
			case 'url': return filter_var(trim($input), FILTER_SANITIZE_URL);
			default: return htmlspecialchars(trim((string)$input), ENT_QUOTES, 'UTF-8');
		}
	}
}

if (!function_exists('validateEmail')) {
	function validateEmail($email) {
		return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
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

// ==================== ADMIN ====================

if (!function_exists('isAdminManagementAllowed')) {
	/**
	 * Vérifie si la gestion des comptes administrateurs est autorisée
	 * @return bool
	 */
	function isAdminManagementAllowed() {
		// Par défaut, seul un administrateur connecté peut gérer les admins
		return isLoggedIn() && getUserRole() === 'administrateur';
	}
}

if (!function_exists('validateStrongPassword')) {
	/**
	 * Valide la robustesse d'un mot de passe
	 * @param string $password Le mot de passe à valider
	 * @return array ['valid' => bool, 'errors' => string[]]
	 */
	function validateStrongPassword($password) {
		$errors = [];
		if (strlen($password) < 8) {
			$errors[] = "Le mot de passe doit contenir au moins 8 caractères";
		}
		if (!preg_match('/[A-Z]/', $password)) {
			$errors[] = "Le mot de passe doit contenir au moins une lettre majuscule";
		}
		if (!preg_match('/[a-z]/', $password)) {
			$errors[] = "Le mot de passe doit contenir au moins une lettre minuscule";
		}
		if (!preg_match('/[0-9]/', $password)) {
			$errors[] = "Le mot de passe doit contenir au moins un chiffre";
		}
		if (!preg_match('/[^A-Za-z0-9]/', $password)) {
			$errors[] = "Le mot de passe doit contenir au moins un caractère spécial";
		}
		return ['valid' => empty($errors), 'errors' => $errors];
	}
}

// ==================== AUTH HELPERS (déplacés depuis core.php) ====================

if (!function_exists('authenticateUser')) {
	function authenticateUser($username, $password, $userType, $rememberMe = false) {
		try {
			$auth = app('auth');
			$credentials = [
				'email' => $username,
				'password' => $password,
				'type' => $userType
			];
			if ($auth->attempt($credentials)) {
				$user = $auth->user();
				return ['success' => true, 'user' => $user, 'message' => 'Connexion réussie'];
			}
			return ['success' => false, 'message' => 'Identifiant ou mot de passe incorrect'];
		} catch (\Exception $e) {
			error_log("Authentication error: " . $e->getMessage());
			return ['success' => false, 'message' => 'Erreur lors de l\'authentification'];
		}
	}
}

if (!function_exists('logoutUser')) {
	function logoutUser() {
		app('auth')->logout();
		redirect('login/index.php');
	}
}

if (!function_exists('createUser')) {
	function createUser($profil, $userData) {
		try {
			$userService = app()->make('API\Services\UserService');
			return $userService->create($profil, $userData);
		} catch (\Exception $e) {
			error_log("User creation error: " . $e->getMessage());
			return ['success' => false, 'message' => 'Erreur lors de la création de l\'utilisateur'];
		}
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

if (!function_exists('getEstablishmentId')) {
	/**
	 * Returns the current establishment ID from the context.
	 */
	function getEstablishmentId(): int {
		return \API\Core\EstablishmentContext::id();
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
if (!function_exists('authorizeOr403')) {
	/** Autorise ou coupe (403 JSON / redirection). */
	function authorizeOr403(string $permission, array $ctx = []): void {
		try { authz()->authorize($permission, $ctx); }
		catch (\Throwable $e) { error_log('[authorize] ' . $e->getMessage()); }
	}
}
if (!function_exists('hasRole')) {
	/** L'utilisateur possède-t-il ce rôle effectif (base + attribués) ? */
	function hasRole(string $roleKey): bool {
		try { return authz()->hasRole($roleKey); }
		catch (\Throwable $e) { return false; }
	}
}
if (!function_exists('isTechnicien')) {
	function isTechnicien(): bool { return getUserRole() === 'technicien'; }
}
if (!function_exists('isCpe')) {
	function isCpe(): bool { return hasRole('cpe'); }
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

if (!function_exists('getErrorMessage')) {
	function getErrorMessage() {
		return $_SESSION['error_message'] ?? 'Une erreur est survenue';
	}
}

if (!function_exists('getDatabaseConnection')) {
	function getDatabaseConnection() {
		return app('db')->getConnection();
	}
}

if (!function_exists('validate')) {
	function validate($data, $rules) {
		$validator = app('validator');
		return $validator->validate($data, $rules);
	}
}

// ──────────── REFONTE 3-MONDES : helpers d'autorisation par monde ────────────
// Remplacent l'ancien requireRole(). Délèguent à API\Security\WorldContext.
if (!function_exists('platformCan')) {
	function platformCan(string $permission): bool { return \API\Security\WorldContext::platformCan($permission); }
}
if (!function_exists('platformAuthorize')) {
	function platformAuthorize(string $permission): void { \API\Security\WorldContext::platformAuthorize($permission); }
}
if (!function_exists('tenantCan')) {
	function tenantCan(string $permission): bool { return \API\Security\WorldContext::tenantCan($permission); }
}
if (!function_exists('tenantCanOn')) {
	function tenantCanOn(string $permission, string $resourceType, int $resourceId): bool {
		return \API\Security\WorldContext::tenantCanOn($permission, $resourceType, $resourceId);
	}
}
if (!function_exists('tenantAuthorize')) {
	function tenantAuthorize(string $permission): void { \API\Security\WorldContext::tenantAuthorize($permission); }
}
if (!function_exists('tenantAuthorizeOn')) {
	function tenantAuthorizeOn(string $permission, string $resourceType, int $resourceId): void {
		\API\Security\WorldContext::tenantAuthorizeOn($permission, $resourceType, $resourceId);
	}
}
if (!function_exists('supportCan')) {
	function supportCan(int $establishmentId, string $level, ?string $type = null, ?int $id = null, bool $sensitive = false): bool {
		return \API\Security\WorldContext::supportCan($establishmentId, $level, $type, $id, $sensitive);
	}
}
if (!function_exists('supportAuthorize')) {
	function supportAuthorize(int $establishmentId, string $level, ?string $type = null, ?int $id = null, bool $sensitive = false): void {
		\API\Security\WorldContext::supportAuthorize($establishmentId, $level, $type, $id, $sensitive);
	}
}
if (!function_exists('currentWorld')) {
	function currentWorld(): ?string { return \API\Security\WorldContext::currentWorld(); }
}
if (!function_exists('tenantGate')) {
	/**
	 * Garde de bascule : la PERMISSION établissement fait autorité. Si l'utilisateur
	 * la détient (via son appartenance — connexion /e/{slug} OU repli legacy), accès accordé.
	 * Sinon, repli sur les rôles legacy le temps de la transition (zéro régression pour les
	 * comptes non encore migrés). Sans repli, refus strict (tenantAuthorize → 403/redirection).
	 *
	 * Remplace progressivement requireRole('administrateur', ...) sur les pages établissement.
	 */
	function tenantGate(string $permission, array $legacyFallbackRoles = []): void {
		if (tenantCan($permission)) { return; }
		if (!empty($legacyFallbackRoles)) { requireRole(...$legacyFallbackRoles); return; }
		tenantAuthorize($permission);
	}
}

// ==================== FIN DU BRIDGE ====================
