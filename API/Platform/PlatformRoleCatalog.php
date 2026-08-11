<?php
declare(strict_types=1);

namespace API\Platform;

/**
 * PlatformRoleCatalog — source de vérité (en code) des RÔLES et PERMISSIONS du
 * monde PLATEFORME (équipe interne Fronote). Strictement séparé du monde
 * établissement : ce catalogue ne contient AUCUN rôle/permission `tenant.*`, et
 * réciproquement le catalogue établissement ne contient aucun rôle `platform_*`.
 *
 * Synchronisé en base (platform_roles) par PlatformRoleSync ; l'autorisation
 * (PlatformAuthorization) retombe sur ce catalogue si la base est indisponible.
 */
final class PlatformRoleCatalog
{
    /** role_key => [label, sensitive] */
    private const ROLES = [
        'super_admin'          => ['label' => 'Super administrateur',          'sensitive' => true],
        'platform_admin'       => ['label' => 'Administrateur plateforme',     'sensitive' => false],
        'platform_support'     => ['label' => 'Support Fronote',               'sensitive' => false],
        'platform_security'    => ['label' => 'Sécurité plateforme',           'sensitive' => true],
        'platform_dpo'         => ['label' => 'Délégué à la protection des données (DPO)', 'sensitive' => true],
        'platform_maintenance' => ['label' => 'Maintenance plateforme',        'sensitive' => false],
        'platform_auditor'     => ['label' => 'Auditeur plateforme',           'sensitive' => false],
    ];

    /** permission_key => [label, sensitive?] (catalogue §XIII.1) */
    private const PERMISSIONS = [
        'platform.dashboard.view'              => ['label' => 'Voir le tableau de bord plateforme'],
        'platform.monitoring.view'             => ['label' => 'Voir le monitoring global'],
        'platform.updates.manage'              => ['label' => 'Gérer les mises à jour'],
        'platform.establishments.view'         => ['label' => 'Voir les établissements'],
        'platform.establishments.suspend'      => ['label' => 'Suspendre un établissement'],
        'platform.establishments.archive'      => ['label' => 'Archiver un établissement'],
        'platform.establishments.delete'       => ['label' => 'Supprimer (logique) un établissement'],
        'platform.establishments.purge'        => ['label' => 'Purger un établissement', 'sensitive' => true],
        'platform.director_invites.create'     => ['label' => 'Créer une invitation Directeur'],
        'platform.director_invites.revoke'     => ['label' => 'Révoquer une invitation Directeur'],
        'platform.director_invites.resend'     => ['label' => 'Renvoyer une invitation Directeur'],
        'platform.support.ticket.view'         => ['label' => 'Voir les tickets de support'],
        'platform.support.ticket.assign'       => ['label' => 'Assigner un ticket'],
        'platform.support.ticket.reply'        => ['label' => 'Répondre à un ticket'],
        'platform.support.access_request.create' => ['label' => "Créer une demande d'accès support"],
        'platform.support.access_request.cancel' => ['label' => "Annuler une demande d'accès support"],
        'platform.support.session.start'       => ['label' => 'Démarrer une session support'],
        'platform.support.session.end'         => ['label' => 'Terminer une session support'],
        'platform.support.audit.create'        => ['label' => "Journaliser une action d'intervention"],
        'platform.support.report.create'       => ['label' => "Produire un rapport d'intervention"],
        'platform.security.view'               => ['label' => 'Voir la sécurité', 'sensitive' => true],
        'platform.security.manage'             => ['label' => 'Gérer la sécurité', 'sensitive' => true],
        'platform.dpo.view'                    => ['label' => 'Vue DPO', 'sensitive' => true],
        'platform.audit.view'                  => ['label' => "Voir l'audit global"],
        'platform.audit.export'                => ['label' => "Exporter l'audit global"],
        'platform.maintenance.manage'          => ['label' => 'Gérer la maintenance'],
        'platform.system.view'                 => ['label' => 'Voir le système'],
        'platform.system.update'               => ['label' => 'Mettre à jour le système', 'sensitive' => true],
        'platform.backups.view'                => ['label' => 'Voir les sauvegardes'],
        'platform.backups.restore'             => ['label' => 'Restaurer une sauvegarde', 'sensitive' => true],
        'platform.rbac.manage'                 => ['label' => 'Gouverner la matrice rôles→permissions (établissements)', 'sensitive' => true],
    ];

    /** role_key => liste de permissions (wildcards '*' et 'domaine.*' autorisés). */
    private const GRANTS = [
        'super_admin'          => ['*'],
        'platform_admin'       => [
            'platform.dashboard.view', 'platform.monitoring.view', 'platform.updates.manage',
            'platform.establishments.view', 'platform.establishments.suspend', 'platform.establishments.archive',
            'platform.director_invites.*', 'platform.support.*',
            'platform.audit.view', 'platform.system.view', 'platform.backups.view', 'platform.maintenance.manage',
            'platform.rbac.manage',
        ],
        'platform_support'     => [
            'platform.dashboard.view', 'platform.establishments.view',
            'platform.support.ticket.view', 'platform.support.ticket.assign', 'platform.support.ticket.reply',
            'platform.support.access_request.create', 'platform.support.access_request.cancel',
            'platform.support.session.start', 'platform.support.session.end',
            'platform.support.audit.create', 'platform.support.report.create',
        ],
        'platform_security'    => [
            'platform.dashboard.view', 'platform.establishments.view',
            'platform.security.*', 'platform.audit.view', 'platform.audit.export', 'platform.monitoring.view',
        ],
        'platform_dpo'         => [
            'platform.dashboard.view', 'platform.dpo.view', 'platform.audit.view', 'platform.security.view',
        ],
        'platform_maintenance' => [
            'platform.dashboard.view', 'platform.maintenance.manage', 'platform.system.view', 'platform.system.update',
            'platform.updates.manage', 'platform.backups.view', 'platform.backups.restore', 'platform.monitoring.view',
        ],
        'platform_auditor'     => [
            'platform.dashboard.view', 'platform.audit.view', 'platform.audit.export',
            'platform.monitoring.view', 'platform.establishments.view', 'platform.security.view',
        ],
    ];

    public static function roles(): array { return self::ROLES; }
    public static function permissions(): array { return self::PERMISSIONS; }
    public static function grantsFor(string $role): array { return self::GRANTS[$role] ?? []; }

    public static function isSensitiveRole(string $role): bool
    {
        return !empty(self::ROLES[$role]['sensitive']);
    }

    public static function isSensitivePermission(string $permission): bool
    {
        return !empty(self::PERMISSIONS[$permission]['sensitive']);
    }

    /** Permissions effectives d'un rôle, wildcards résolus contre le catalogue. */
    public static function permissionsFor(string $role): array
    {
        return \API\Security\WildcardGrants::expand(self::grantsFor($role), array_keys(self::PERMISSIONS));
    }

    /** Union des permissions d'un ensemble de rôles. */
    public static function effectivePermissions(array $roles): array
    {
        $out = [];
        foreach ($roles as $r) {
            foreach (self::permissionsFor($r) as $p) {
                $out[$p] = true;
            }
        }
        return array_keys($out);
    }

    /** Un rôle accorde-t-il une permission (wildcards inclus) ? */
    public static function roleGrants(string $role, string $permission): bool
    {
        return \API\Security\WildcardGrants::granted(self::grantsFor($role), $permission);
    }
}
