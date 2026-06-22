<?php
/**
 * Vérification du code de réinitialisation — DÉSACTIVÉE.
 *
 * Le parcours self-service de réinitialisation est validé par un administrateur
 * (cf. login/reset_password.php → table demandes_reinitialisation → admin/users/passwords.php).
 * Aucun code/token à usage unique n'est émis vers l'utilisateur : ce point d'entrée
 * « saisir un code » n'a jamais de cible valide et constituait un chemin mort.
 *
 * Plutôt que d'exposer un écran de saisie de code sans émission de code (risque de
 * confusion / surface inutile), on neutralise la page : redirection vers le parcours
 * de demande de réinitialisation. Le reset admin existant n'est pas affecté.
 */
require_once __DIR__ . '/../API/core.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Nettoyer tout résidu de l'ancien chemin self-service.
unset($_SESSION['reset_code']);

header('Location: reset_password.php');
exit;
