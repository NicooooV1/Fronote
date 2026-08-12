<?php
declare(strict_types=1);

/**
 * Configuration du serveur de distribution Fronote (composant ISOLÉ).
 *
 * Copiez ce fichier en « dist/config.php » (ignoré par git) et adaptez les valeurs.
 * Générez les secrets avec :  openssl rand -hex 32
 *
 * Ce composant est indépendant de l'application multi-tenant : sa base est un fichier
 * SQLite (dist/data/dist.sqlite), ses clés de signature vivent dans dist/data/.
 */
return [
    // Racine du dépôt Fronote (source des payloads : base + modules).
    'app_root'   => dirname(__DIR__),

    // Répertoire de données isolé (base SQLite, clés de signature, payloads construits).
    // DOIT être hors docroot idéalement, ou protégé par .htaccess (fait par défaut).
    'data_dir'   => __DIR__ . '/data',

    // Poivre serveur ajouté avant hachage des clés de licence (défense si la base fuit).
    // openssl rand -hex 32
    'key_pepper' => 'REMPLACEZ_PAR_UN_HEX_ALEATOIRE_32_OCTETS',

    // Jeton d'administration : requis par l'API d'unban/révocation (bot Discord, CLI distante).
    // openssl rand -hex 32
    'admin_token' => 'REMPLACEZ_PAR_UN_AUTRE_HEX_ALEATOIRE',

    // Modèle ALLOWLIST : seules les IP whitelistées (via le bot Discord → API d'admin,
    // ou la CLI dist/bin/allow.php) peuvent échanger une clé et télécharger. Toute autre
    // IP est refusée avec ce message. Pas de bannissement à gérer.
    'not_whitelisted_message' => "Accès refusé : votre IP n'est pas autorisée. Communiquez votre IP publique au support pour être ajouté.",

    // Honore X-Forwarded-For UNIQUEMENT derrière un reverse-proxy de confiance (sinon spoofable).
    'trust_forwarded' => false,

    // Ensemble FIXE des « modules simples » livrés avec la base à toute installation.
    // (Les autres modules sont « premium » : téléchargeables ensuite si la licence y donne droit.)
    'simple_modules' => [
        'notes', 'absences', 'devoirs', 'messagerie', 'agenda', 'emploi_du_temps',
        'cahierdetextes', 'bulletins', 'trombinoscope', 'notifications',
    ],

    // URL publique de CE serveur de distribution (utilisée dans les liens et le bootstrapper).
    // DOIT être en https:// en production (la clé de licence transite ici).
    'dist_base_url' => 'https://dist.exemple.fr',
];
