<?php
/**
 * Point d'entrée du flux SSO OAuth2/OIDC.
 * Génère le state anti-CSRF en session puis redirige vers le provider
 * (OAuthGuard::getAuthorizationUrl). Inactif si OAUTH_* n'est pas configuré.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/API/bootstrap.php';

try {
	$guard = new \API\Auth\OAuthGuard(getPDO());
	if (!$guard->isConfigured()) {
		$_SESSION['error_message'] = 'SSO is not configured on this instance.';
		header('Location: index.php');
		exit;
	}
	header('Location: ' . $guard->getAuthorizationUrl());
	exit;
} catch (\Throwable $e) {
	error_log('oauth_start: ' . $e->getMessage());
	$_SESSION['error_message'] = 'SSO initialization failed.';
	header('Location: index.php');
	exit;
}
