<?php
/**
 * OAuth2 SSO Callback
 *
 * Ce fichier est appelé par le provider OAuth2 après l'authentification.
 * Il échange le code d'autorisation contre un token, résout l'utilisateur
 * local, et connecte via SessionGuard.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/API/bootstrap.php';

$code  = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

// Erreur du provider
if ($error) {
	$_SESSION['error_message'] = 'SSO authentication cancelled or failed: ' . htmlspecialchars($error);
	header('Location: index.php');
	exit;
}

if (empty($code) || empty($state)) {
	$_SESSION['error_message'] = 'Invalid OAuth callback parameters.';
	header('Location: index.php');
	exit;
}

try {
	$guard = new \API\Auth\OAuthGuard(getPDO());

	if (!$guard->isConfigured()) {
		$_SESSION['error_message'] = 'SSO is not configured. Contact your administrator.';
		header('Location: index.php');
		exit;
	}

	$result = $guard->handleCallback($code, $state);

	if ($result['user'] === null) {
		// Pas d'utilisateur local trouvé
		$_SESSION['error_message'] = $result['error'] ?? 'No local account found for this email.';
		header('Location: index.php');
		exit;
	}

	$user = $result['user'];

	// SÉCURITÉ : le SSO ne doit PAS outrepasser les statuts de compte. Un compte désactivé
	// (actif=0) ou verrouillé (locked_until dans le futur) est refusé, comme sur le login
	// classique (sinon un compte révoqué se reconnecte via OAuth).
	$isLocked = !empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time();
	if ((int) ($user['actif'] ?? 1) !== 1 || $isLocked) {
		try { app('audit')->logAuth('sso_login', $user['mail'] ?? '', false, ['reason' => 'account_disabled_or_locked']); } catch (\Throwable $e) {}
		$_SESSION['error_message'] = 'Ce compte est désactivé ou verrouillé.';
		header('Location: index.php');
		exit;
	}

	// Si le compte a activé la 2FA, le SSO ne doit PAS la court-circuiter : on bascule
	// vers l'étape de second facteur au lieu de créer directement la session.
	$uId   = (int) ($user['id'] ?? 0);
	$uType = $user['type'] ?? null;
	if ($uId && $uType) {
		try {
			$twoFactor = new \API\Services\TwoFactorService(getPDO());
			if ($twoFactor->isEnabled($uId, $uType)) {
				$_SESSION['pending_2fa'] = ['user_id' => $uId, 'user_type' => $uType, 'remember_me' => false];
				header('Location: verify_2fa.php');
				exit;
			}
		} catch (\Throwable $e) {
			error_log('[oauth] 2FA check failed: ' . $e->getMessage());
		}
	}

	// Connecter via SessionGuard (app('auth') = AuthManager ; loginUser() crée la session à partir du tableau utilisateur)
	$sessionGuard = app('auth');
	$sessionGuard->loginUser($user);

	// Audit log (OAuthGuard renvoie la colonne `mail`, pas `email`)
	try {
		app('audit')->logAuth('sso_login', $user['mail'] ?? ($user['email'] ?? ''), true, [
			'provider' => env('OAUTH_PROVIDER', 'unknown'),
			'is_new' => $result['is_new'],
		]);
	} catch (\Throwable $e) { /* non-critical */ }

	// Rediriger vers le dashboard
	header('Location: ../accueil/accueil.php');
	exit;

} catch (\RuntimeException $e) {
	$_SESSION['error_message'] = 'SSO error: ' . $e->getMessage();
	header('Location: index.php');
	exit;
} catch (\Throwable $e) {
	error_log('OAuth callback error: ' . $e->getMessage());
	$_SESSION['error_message'] = 'An unexpected error occurred during SSO authentication.';
	header('Location: index.php');
	exit;
}
