<?php
declare(strict_types=1);

namespace API\Tenant;

/**
 * TenantRoleCatalog — source de vérité (en code) des RÔLES et PERMISSIONS du monde
 * ÉTABLISSEMENT. Ne contient AUCUN rôle plateforme (super_admin, platform_*, support).
 * Le rôle ambigu « administrateur » est supprimé au profit de rôles précis
 * (directeur, direction, administration, responsable_permissions).
 *
 * Les octrois sont pilotés par CATÉGORIE (défaut) + surcharges par rôle, pour rester
 * maintenable face aux ~50 rôles. Synchronisé en base (tenant_roles) par TenantRoleSync.
 */
final class TenantRoleCatalog
{
    /** role_key => [label, category, scope (défaut), sensitive] */
    private const ROLES = [
        // direction
        'directeur'            => ['Directeur', 'direction', 'establishment', false],
        'chef_etablissement'   => ["Chef d'établissement", 'direction', 'establishment', false],
        'direction'            => ['Direction', 'direction', 'establishment', false],
        'direction_adjointe'   => ['Direction adjointe', 'direction', 'establishment', false],
        // administratif
        'administration'       => ['Administration', 'administratif', 'establishment', false],
        'secretariat_scolaire' => ['Secrétariat scolaire', 'administratif', 'establishment', false],
        'responsable_permissions' => ['Responsable des permissions', 'administratif', 'establishment', true],
        // vie scolaire
        'cpe'                  => ["Conseiller principal d'éducation", 'vie_scolaire', 'establishment', false],
        'vie_scolaire'         => ['Vie scolaire', 'vie_scolaire', 'establishment', false],
        'aed'                  => ["Assistant d'éducation", 'vie_scolaire', 'establishment', false],
        'surveillant'         => ['Surveillant', 'vie_scolaire', 'establishment', false],
        // pédagogique
        'professeur'           => ['Professeur', 'pedagogique', 'own_classes', false],
        'professeur_principal' => ['Professeur principal', 'pedagogique', 'own_classes', false],
        'professeur_remplacant'=> ['Professeur remplaçant', 'pedagogique', 'own_classes', false],
        'professeur_vacataire' => ['Professeur vacataire', 'pedagogique', 'own_classes', false],
        'coordinateur_matiere' => ['Coordinateur de matière', 'pedagogique', 'establishment', false],
        'responsable_examens'  => ['Responsable examens', 'pedagogique', 'establishment', false],
        // santé / social (sensibles)
        'infirmerie'           => ['Infirmerie', 'sante_social', 'establishment', true],
        'medecin_scolaire'     => ['Médecin scolaire', 'sante_social', 'establishment', true],
        'psychologue'          => ['Psychologue', 'sante_social', 'assigned', true],
        'assistant_social'     => ['Assistant social', 'sante_social', 'assigned', true],
        'aesh'                 => ['AESH', 'sante_social', 'assigned', true],
        'referent_handicap'    => ['Référent handicap', 'sante_social', 'establishment', true],
        'referent_pai'         => ['Référent PAI', 'sante_social', 'establishment', true],
        'referent_pap'         => ['Référent PAP', 'sante_social', 'establishment', true],
        // élève / famille
        'eleve'                => ['Élève', 'eleve', 'self', false],
        'ancien_eleve'         => ['Ancien élève', 'eleve', 'self', false],
        'parent'               => ['Parent', 'famille', 'children', false],
        'responsable_legal'    => ['Responsable légal', 'famille', 'children', false],
        'responsable_financier'=> ['Responsable financier', 'famille', 'children', false],
        'famille_lecture_seule'=> ['Famille (lecture seule)', 'famille', 'children', false],
        'ancien_parent'        => ['Ancien parent', 'famille', 'children', false],
        // organisation / transverse
        'responsable_documents'=> ['Responsable documents', 'administratif', 'establishment', false],
        'responsable_emploi_du_temps' => ["Responsable emploi du temps", 'administratif', 'establishment', false],
        'responsable_cantine'  => ['Responsable cantine', 'administratif', 'establishment', false],
        'responsable_internat' => ['Responsable internat', 'administratif', 'establishment', false],
        // externes
        'tuteur_entreprise'    => ['Tuteur entreprise', 'externe', 'assigned', false],
        'maitre_apprentissage' => ["Maître d'apprentissage", 'externe', 'assigned', false],
        'entreprise_partenaire'=> ['Entreprise partenaire', 'externe', 'company', false],
        'inspecteur'           => ['Inspecteur', 'externe', 'establishment', false],
        'invite_temporaire'    => ['Invité temporaire', 'externe', 'temporary', false],
    ];

    /** Octrois par CATÉGORIE (défaut). Wildcards 'domaine.*' et '*' autorisés. */
    private const CATEGORY_GRANTS = [
        'direction'    => ['tenant.*', 'students.*', 'grades.*', 'homework.*', 'attendance.*', 'documents.*', 'messages.*'],
        'administratif'=> ['tenant.dashboard.view', 'tenant.users.*', 'tenant.roles.*', 'tenant.classes.*',
                           'tenant.imports.manage', 'tenant.exports.manage', 'tenant.audit.view',
                           'students.view', 'students.create', 'students.edit', 'documents.view'],
        'vie_scolaire' => ['tenant.dashboard.view', 'students.view', 'attendance.view', 'attendance.justify',
                           'homework.view', 'documents.view', 'messages.view', 'messages.send'],
        'pedagogique'  => ['tenant.dashboard.view', 'students.view', 'grades.view', 'grades.create', 'grades.edit',
                           'homework.view', 'homework.create', 'homework.edit', 'attendance.view', 'documents.view'],
        'sante_social' => ['tenant.dashboard.view', 'students.view'],
        'eleve'        => ['tenant.dashboard.view', 'students.view', 'grades.view', 'homework.view',
                           'attendance.view', 'documents.view', 'messages.view'],
        'famille'      => ['tenant.dashboard.view', 'students.view', 'grades.view', 'homework.view',
                           'attendance.view', 'documents.view', 'messages.view'],
        'externe'      => ['tenant.dashboard.view', 'students.view'],
        'systeme'      => [],
    ];

    /** Surcharges fines par rôle (priment sur la catégorie). */
    private const ROLE_GRANTS = [
        'directeur'            => ['tenant.*', 'students.*', 'grades.*', 'homework.*', 'attendance.*',
                                   'documents.*', 'messages.*', 'medical.view', 'psychology.view', 'social.view'],
        'responsable_permissions' => ['tenant.dashboard.view', 'tenant.roles.*', 'tenant.users.view', 'tenant.audit.view'],
        // Rôles « responsable » OPÉRATIONNELS : surcharges NARROW — sans elles, la catégorie
        // 'administratif' leur accorderait tenant.users.*/tenant.roles.* (escalade : accès à
        // l'espace admin / gestion des comptes), ce qu'un responsable cantine/internat/EDT/docs
        // ne doit pas avoir.
        'responsable_documents'       => ['tenant.dashboard.view', 'students.view', 'documents.view', 'documents.create', 'documents.edit'],
        'responsable_emploi_du_temps' => ['tenant.dashboard.view', 'students.view', 'homework.view'],
        'responsable_cantine'         => ['tenant.dashboard.view', 'students.view'],
        'responsable_internat'        => ['tenant.dashboard.view', 'students.view', 'documents.view'],
        'professeur'           => ['tenant.dashboard.view', 'students.view', 'grades.view', 'grades.create', 'grades.edit',
                                   'homework.view', 'homework.create', 'homework.edit', 'attendance.view', 'attendance.justify', 'documents.view'],
        'coordinateur_matiere' => ['tenant.dashboard.view', 'students.view', 'grades.view', 'homework.view'],
        'responsable_examens'  => ['tenant.dashboard.view', 'students.view', 'grades.view', 'grades.edit'],
        'infirmerie'           => ['tenant.dashboard.view', 'students.view', 'medical.view', 'medical.edit'],
        'medecin_scolaire'     => ['tenant.dashboard.view', 'students.view', 'medical.view', 'medical.edit'],
        'psychologue'          => ['tenant.dashboard.view', 'students.view', 'psychology.view', 'psychology.edit'],
        'assistant_social'     => ['tenant.dashboard.view', 'students.view', 'social.view'],
        'aesh'                 => ['tenant.dashboard.view', 'students.view', 'medical.view'],
        'eleve'                => ['tenant.dashboard.view', 'students.view', 'grades.view', 'homework.view', 'attendance.view', 'documents.view'],
        'ancien_eleve'         => ['tenant.dashboard.view', 'grades.view', 'documents.view'],
        'parent'               => ['tenant.dashboard.view', 'students.view', 'grades.view', 'homework.view', 'attendance.view', 'attendance.justify', 'documents.view', 'documents.sign', 'messages.view', 'messages.send'],
        'responsable_legal'    => ['tenant.dashboard.view', 'students.view', 'grades.view', 'attendance.view', 'attendance.justify', 'documents.view', 'documents.sign'],
        'responsable_financier'=> ['tenant.dashboard.view', 'students.view', 'attendance.view', 'documents.view'],
        'famille_lecture_seule'=> ['tenant.dashboard.view', 'students.view', 'grades.view', 'attendance.view', 'documents.view'],
        'tuteur_entreprise'    => ['tenant.dashboard.view', 'students.view', 'documents.view'],
    ];

    public static function roles(): array
    {
        $out = [];
        foreach (self::ROLES as $key => [$label, $cat, $scope, $sensitive]) {
            $out[$key] = ['label' => $label, 'category' => $cat, 'scope' => $scope, 'sensitive' => $sensitive];
        }
        return $out;
    }

    public static function exists(string $role): bool { return isset(self::ROLES[$role]); }

    public static function meta(string $role): ?array
    {
        return self::roles()[$role] ?? null;
    }

    public static function isSensitiveRole(string $role): bool
    {
        return !empty(self::ROLES[$role][3]);
    }

    public static function defaultScope(string $role): string
    {
        return self::ROLES[$role][2] ?? 'establishment';
    }

    public static function grantsFor(string $role): array
    {
        if (isset(self::ROLE_GRANTS[$role])) {
            return self::ROLE_GRANTS[$role];
        }
        $cat = self::ROLES[$role][1] ?? null;
        return $cat !== null ? (self::CATEGORY_GRANTS[$cat] ?? []) : [];
    }

    /** Un rôle accorde-t-il une permission (wildcards 'domaine.*' / '*') ? */
    public static function roleGrants(string $role, string $permission): bool
    {
        $grants = self::grantsFor($role);
        if (in_array('*', $grants, true) || in_array($permission, $grants, true)) {
            return true;
        }
        $parts = explode('.', $permission);
        for ($i = count($parts) - 1; $i >= 1; $i--) {
            if (in_array(implode('.', array_slice($parts, 0, $i)) . '.*', $grants, true)) {
                return true;
            }
        }
        return false;
    }

    /** Rôles compatibles avec un type de compte (filtre l'assistant de création). */
    public static function rolesForAccountType(string $accountType): array
    {
        $byType = [
            'director' => ['directeur', 'chef_etablissement', 'direction'],
            'staff'    => ['direction', 'direction_adjointe', 'administration', 'secretariat_scolaire',
                           'responsable_permissions', 'cpe', 'vie_scolaire', 'aed', 'surveillant',
                           'professeur', 'professeur_principal', 'professeur_remplacant', 'professeur_vacataire',
                           'coordinateur_matiere', 'responsable_examens', 'infirmerie', 'medecin_scolaire',
                           'psychologue', 'assistant_social', 'aesh', 'referent_handicap', 'referent_pai', 'referent_pap',
                           'responsable_documents', 'responsable_emploi_du_temps', 'responsable_cantine', 'responsable_internat'],
            'student'  => ['eleve', 'ancien_eleve'],
            'family'   => ['parent', 'responsable_legal', 'responsable_financier', 'famille_lecture_seule', 'ancien_parent'],
            'external' => ['tuteur_entreprise', 'maitre_apprentissage', 'entreprise_partenaire', 'inspecteur', 'invite_temporaire'],
            'temporary'=> ['invite_temporaire', 'professeur_remplacant'],
            'system'   => [],
        ];
        $keys = $byType[$accountType] ?? [];
        $out = [];
        foreach ($keys as $k) {
            if (isset(self::ROLES[$k])) { $out[$k] = self::meta($k); }
        }
        return $out;
    }
}
