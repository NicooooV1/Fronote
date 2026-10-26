# Fronote — Plateforme (console globale)

`platform.nicov1.fr` — CT129. Console de gestion GLOBALE de Fronote :

- **Établissements** : autoriser un établissement = émettre sa licence (sur le serveur de distribution
  CT122) ; la clé n'est affichée qu'une fois. Suivi : licence, instance (rapports quotidiens), IP autorisées,
  révocation, nouvelle clé, archivage.
- **Distribution** : parc, licences, IP autorisées, tentatives refusées — via l'API admin de CT122
  (CT122 garde la clé de signature et les paquets).
- **Démo publique** : disponibilité de demo.nicov1.fr.
- **Demandes escaladées** : seules les demandes que l'ÉQUIPE d'un établissement transmet arrivent ici
  (les tickets internes restent dans l'instance). Réponses relevées par l'instance.

## Accès
- Interface : Caddy la réserve au réseau local / VPN ET la place derrière Authelia (groupe `admins`).
  L'application ne croit les en-têtes `Remote-*` que s'ils viennent de Caddy (`trusted_proxy`).
- `/api/instance/*` : public (instances sur Internet), authentifié par jeton d'installation
  (vérifié auprès de dist, gardé 1 h) ou par clé d'API émise depuis la fiche établissement.

## Déploiement
`platform/bin/deploy.sh` (hyperviseur) — code depuis le dépôt CT102, config hors dépôt dans
`/etc/fronote-platform/config.php` (modèle : `config.example.php`). Retour : `--rollback`.
