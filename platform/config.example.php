<?php
// Fronote PLATFORM — configuration (copier en /etc/fronote-platform/config.php, root:www-data 640).
return [
    'db' => ['dsn' => 'mysql:host=127.0.0.1;dbname=fronote_platform;charset=utf8mb4', 'user' => 'platform', 'pass' => 'CHANGEZ-MOI'],
    // Seul proxy autorisé à poser les en-têtes d'identité SSO (Remote-User / Remote-Groups).
    'trusted_proxy' => '10.3.20.116',
    // Groupes LDAP (Authelia) autorisés à utiliser la console.
    'operator_groups' => ['admins'],
    // Serveur de distribution (licences, parc) : URL + jeton admin (même jeton que le bot).
    'dist_url' => 'https://dist.nicov1.fr',
    'dist_admin_token' => 'CHANGEZ-MOI',
    // Démo publique (supervision).
    'demo_url' => 'https://demo.nicov1.fr',
    // Jeton du bouton « Réinitialiser la démo » = DEMO_RESET_TOKEN du .env de la démo (openssl rand -hex 32).
    'demo_reset_token' => '',
    // URL publique de la plateforme (affichée aux établissements).
    'public_url' => 'https://platform.nicov1.fr',
];
