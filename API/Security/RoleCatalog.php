<?php
declare(strict_types=1);

namespace API\Security;

/**
 * RoleCatalog — catalogue RBAC/ABAC de Fronote (source de vérité en code).
 *
 * Modèle : Rôle = identité ; Permission = ce qu'il fait ("<domaine>.<action>") ;
 * Périmètre (scope) = sur quoi. JAMAIS de pouvoir absolu hors super_admin.
 *
 * Conventions de permission :
 *   action ∈ {view, create, edit, delete, export, validate, publish, manage}
 *   manage = toutes actions du domaine. On exprime "toutes" par le wildcard 'domaine.*'.
 *
 * Périmètres (scope_type) :
 *   global | establishment | establishments | self | children | assigned | own_classes
 *
 * Domaines SENSIBLES (sensitive=true) : medical, psy, social, pai, handicap, rgpd ;
 * plus etablissement.purge, users.delete, audit.*, backup.*, securite.*.
 *
 * Règles transverses appliquées ici :
 *  - medical/psy/social/pai/handicap UNIQUEMENT aux rôles santé/social/handicap désignés.
 *  - etablissement.purge UNIQUEMENT super_admin.
 *  - notes.create/edit/delete UNIQUEMENT professeur & dérivés pédagogiques.
 *  - modification de rôles UNIQUEMENT super_admin / administrateur / direction /
 *    chef_etablissement / responsable_permissions.
 *
 * Consommé par RoleSync (réconciliation vers la base) et RBAC (résolution runtime).
 */
final class RoleCatalog
{
    // ─────────────────────────────────────────────────────────────────────────
    // ROLES : role_key => ['label', 'tier', 'scope', 'sensitive', 'accounts', 'is_system'?]
    // accounts = types de compte de base couverts par ce rôle (vide sauf les 7 socles).
    // ─────────────────────────────────────────────────────────────────────────
    private const ROLES = [
        // ───────── PLATEFORME (tier=plateforme) ─────────
        'super_admin'           => ['label' => 'Super administrateur',          'tier' => 'plateforme', 'scope' => 'global',         'sensitive' => true,  'accounts' => ['super_admin']],
        'administrateur'        => ['label' => 'Administrateur',                'tier' => 'plateforme', 'scope' => 'global',         'sensitive' => false, 'accounts' => ['administrateur']],
        'technicien'            => ['label' => 'Technicien',                    'tier' => 'plateforme', 'scope' => 'establishments', 'sensitive' => false, 'accounts' => ['technicien']],
        'support'               => ['label' => 'Support',                       'tier' => 'plateforme', 'scope' => 'global',         'sensitive' => false, 'accounts' => []],
        'auditeur'              => ['label' => 'Auditeur',                      'tier' => 'plateforme', 'scope' => 'global',         'sensitive' => true,  'accounts' => []],
        'responsable_securite'  => ['label' => 'Responsable sécurité',          'tier' => 'plateforme', 'scope' => 'global',         'sensitive' => true,  'accounts' => []],
        'dpo'                   => ['label' => 'Délégué à la protection des données (DPO)', 'tier' => 'plateforme', 'scope' => 'global', 'sensitive' => true, 'accounts' => []],
        'developpeur'           => ['label' => 'Développeur',                   'tier' => 'plateforme', 'scope' => 'global',         'sensitive' => false, 'accounts' => []],

        // ───────── DIRECTION (tier=direction, scope=establishment) ─────────
        'direction'                 => ['label' => 'Direction',                     'tier' => 'direction', 'scope' => 'establishment',  'sensitive' => false, 'accounts' => []],
        'chef_etablissement'        => ['label' => "Chef d'établissement",          'tier' => 'direction', 'scope' => 'establishment',  'sensitive' => false, 'accounts' => []],
        'direction_adjointe'        => ['label' => 'Direction adjointe',            'tier' => 'direction', 'scope' => 'establishment',  'sensitive' => false, 'accounts' => []],
        'directeur_multi'           => ['label' => 'Directeur multi-établissements', 'tier' => 'direction', 'scope' => 'establishments', 'sensitive' => false, 'accounts' => []],
        'secretariat_direction'     => ['label' => 'Secrétariat de direction',      'tier' => 'direction', 'scope' => 'establishment',  'sensitive' => false, 'accounts' => []],
        'responsable_pedagogique'   => ['label' => 'Responsable pédagogique',       'tier' => 'direction', 'scope' => 'establishment',  'sensitive' => false, 'accounts' => []],
        'responsable_administratif' => ['label' => 'Responsable administratif',     'tier' => 'direction', 'scope' => 'establishment',  'sensitive' => false, 'accounts' => []],
        'responsable_permissions'   => ['label' => 'Responsable des permissions',   'tier' => 'direction', 'scope' => 'establishment',  'sensitive' => true,  'accounts' => []],

        // ───────── ADMINISTRATIF (tier=administratif, scope=establishment) ─────────
        'administration'             => ['label' => 'Administration',                'tier' => 'administratif', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'secretariat_scolaire'       => ['label' => 'Secrétariat scolaire',          'tier' => 'administratif', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'gestionnaire_administratif' => ['label' => 'Gestionnaire administratif',     'tier' => 'administratif', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'gestionnaire_comptable'     => ['label' => 'Gestionnaire comptable',         'tier' => 'administratif', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'intendance'                 => ['label' => 'Intendance',                    'tier' => 'administratif', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'responsable_inscriptions'   => ['label' => 'Responsable des inscriptions',  'tier' => 'administratif', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'responsable_documents'      => ['label' => 'Responsable des documents',     'tier' => 'administratif', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'referent_comptes'           => ['label' => 'Référent comptes',              'tier' => 'administratif', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],

        // ───────── VIE SCOLAIRE (tier=vie_scolaire, scope=establishment) ─────────
        'cpe'                       => ['label' => "Conseiller principal d'éducation (CPE)", 'tier' => 'vie_scolaire', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'vie_scolaire'              => ['label' => 'Vie scolaire',                   'tier' => 'vie_scolaire', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => ['vie_scolaire']],
        'aed'                       => ['label' => "Assistant d'éducation (AED)",    'tier' => 'vie_scolaire', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'surveillant'              => ['label' => 'Surveillant',                    'tier' => 'vie_scolaire', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'referent_absences'        => ['label' => 'Référent absences',              'tier' => 'vie_scolaire', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'referent_retards'         => ['label' => 'Référent retards',               'tier' => 'vie_scolaire', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'referent_discipline'      => ['label' => 'Référent discipline',            'tier' => 'vie_scolaire', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'responsable_permanence'   => ['label' => 'Responsable permanence',         'tier' => 'vie_scolaire', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'responsable_remplacements'=> ['label' => 'Responsable remplacements',      'tier' => 'vie_scolaire', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'responsable_appels'       => ['label' => 'Responsable des appels',         'tier' => 'vie_scolaire', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],

        // ───────── PÉDAGOGIQUE (tier=pedagogique) ─────────
        'professeur'              => ['label' => 'Professeur',                    'tier' => 'pedagogique', 'scope' => 'own_classes',   'sensitive' => false, 'accounts' => ['professeur']],
        'professeur_principal'    => ['label' => 'Professeur principal',          'tier' => 'pedagogique', 'scope' => 'own_classes',   'sensitive' => false, 'accounts' => []],
        'professeur_remplacant'   => ['label' => 'Professeur remplaçant',         'tier' => 'pedagogique', 'scope' => 'own_classes',   'sensitive' => false, 'accounts' => []],
        'professeur_vacataire'    => ['label' => 'Professeur vacataire',          'tier' => 'pedagogique', 'scope' => 'own_classes',   'sensitive' => false, 'accounts' => []],
        'coordinateur_matiere'    => ['label' => 'Coordinateur de matière',       'tier' => 'pedagogique', 'scope' => 'establishment',  'sensitive' => false, 'accounts' => []],
        'coordinateur_niveau'     => ['label' => 'Coordinateur de niveau',        'tier' => 'pedagogique', 'scope' => 'establishment',  'sensitive' => false, 'accounts' => []],
        'responsable_classe'      => ['label' => 'Responsable de classe',         'tier' => 'pedagogique', 'scope' => 'own_classes',   'sensitive' => false, 'accounts' => []],
        'responsable_bulletin'    => ['label' => 'Responsable des bulletins',     'tier' => 'pedagogique', 'scope' => 'establishment',  'sensitive' => false, 'accounts' => []],
        'responsable_conseils'    => ['label' => 'Responsable des conseils',      'tier' => 'pedagogique', 'scope' => 'establishment',  'sensitive' => false, 'accounts' => []],
        'responsable_examens'     => ['label' => 'Responsable des examens',       'tier' => 'pedagogique', 'scope' => 'establishment',  'sensitive' => false, 'accounts' => []],
        'responsable_orientation' => ['label' => "Responsable de l'orientation",  'tier' => 'pedagogique', 'scope' => 'establishment',  'sensitive' => false, 'accounts' => []],
        'documentaliste'          => ['label' => 'Documentaliste',               'tier' => 'pedagogique', 'scope' => 'establishment',  'sensitive' => false, 'accounts' => []],
        'tuteur_pedagogique'      => ['label' => 'Tuteur pédagogique',           'tier' => 'pedagogique', 'scope' => 'assigned',      'sensitive' => false, 'accounts' => []],

        // ───────── SANTÉ / SOCIAL / ACCOMPAGNEMENT (tier=sante_social, sensitive) ─────────
        'infirmerie'          => ['label' => 'Infirmerie',                  'tier' => 'sante_social', 'scope' => 'establishment', 'sensitive' => true, 'accounts' => []],
        'infirmier_scolaire'  => ['label' => 'Infirmier scolaire',          'tier' => 'sante_social', 'scope' => 'establishment', 'sensitive' => true, 'accounts' => []],
        'medecin_scolaire'    => ['label' => 'Médecin scolaire',            'tier' => 'sante_social', 'scope' => 'establishment', 'sensitive' => true, 'accounts' => []],
        'psychologue'         => ['label' => 'Psychologue',                 'tier' => 'sante_social', 'scope' => 'assigned',      'sensitive' => true, 'accounts' => []],
        'assistant_social'    => ['label' => 'Assistant social',            'tier' => 'sante_social', 'scope' => 'assigned',      'sensitive' => true, 'accounts' => []],
        'aesh'                => ['label' => "Accompagnant d'élèves en situation de handicap (AESH)", 'tier' => 'sante_social', 'scope' => 'assigned', 'sensitive' => true, 'accounts' => []],
        'referent_handicap'   => ['label' => 'Référent handicap',           'tier' => 'sante_social', 'scope' => 'establishment', 'sensitive' => true, 'accounts' => []],
        'referent_pai'        => ['label' => 'Référent PAI',                'tier' => 'sante_social', 'scope' => 'establishment', 'sensitive' => true, 'accounts' => []],
        'referent_pap'        => ['label' => 'Référent PAP',                'tier' => 'sante_social', 'scope' => 'establishment', 'sensitive' => true, 'accounts' => []],
        'tuteur_eleve'        => ['label' => 'Tuteur élève',                'tier' => 'sante_social', 'scope' => 'assigned',      'sensitive' => true, 'accounts' => []],
        'mediateur_scolaire'  => ['label' => 'Médiateur scolaire',          'tier' => 'sante_social', 'scope' => 'assigned',      'sensitive' => true, 'accounts' => []],

        // ───────── ÉLÈVES / FAMILLES (tier=eleve_famille) ─────────
        'eleve'                 => ['label' => 'Élève',                     'tier' => 'eleve_famille', 'scope' => 'self',     'sensitive' => false, 'accounts' => ['eleve']],
        'parent'                => ['label' => 'Parent',                    'tier' => 'eleve_famille', 'scope' => 'children', 'sensitive' => false, 'accounts' => ['parent']],
        'responsable_legal'     => ['label' => 'Responsable légal',        'tier' => 'eleve_famille', 'scope' => 'children', 'sensitive' => false, 'accounts' => []],
        'responsable_financier' => ['label' => 'Responsable financier',    'tier' => 'eleve_famille', 'scope' => 'children', 'sensitive' => false, 'accounts' => []],
        'tuteur_legal'          => ['label' => 'Tuteur légal',             'tier' => 'eleve_famille', 'scope' => 'children', 'sensitive' => false, 'accounts' => []],
        'famille_lecture_seule' => ['label' => 'Famille (lecture seule)',  'tier' => 'eleve_famille', 'scope' => 'children', 'sensitive' => false, 'accounts' => []],
        'ancien_eleve'          => ['label' => 'Ancien élève',             'tier' => 'eleve_famille', 'scope' => 'self',     'sensitive' => false, 'accounts' => []],
        'ancien_parent'         => ['label' => 'Ancien parent',            'tier' => 'eleve_famille', 'scope' => 'children', 'sensitive' => false, 'accounts' => []],

        // ───────── EDT / ORGANISATION (tier=organisation, scope=establishment) ─────────
        'responsable_edt'         => ['label' => "Responsable de l'emploi du temps", 'tier' => 'organisation', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'gestionnaire_salles'     => ['label' => 'Gestionnaire des salles',       'tier' => 'organisation', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'gestionnaire_ressources' => ['label' => 'Gestionnaire des ressources',   'tier' => 'organisation', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'responsable_groupes'     => ['label' => 'Responsable des groupes',       'tier' => 'organisation', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'responsable_matieres'    => ['label' => 'Responsable des matières',      'tier' => 'organisation', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'responsable_periodes'    => ['label' => 'Responsable des périodes',      'tier' => 'organisation', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'responsable_calendrier'  => ['label' => 'Responsable du calendrier',     'tier' => 'organisation', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'responsable_sorties'     => ['label' => 'Responsable des sorties',       'tier' => 'organisation', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],

        // ───────── COMMUNICATION (tier=communication, scope=establishment) ─────────
        'responsable_communication'  => ['label' => 'Responsable communication',     'tier' => 'communication', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'moderateur_messagerie'      => ['label' => 'Modérateur messagerie',         'tier' => 'communication', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'emetteur_annonces_etab'     => ['label' => "Émetteur d'annonces (établissement)", 'tier' => 'communication', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'emetteur_annonces_classe'   => ['label' => "Émetteur d'annonces (classe)",  'tier' => 'communication', 'scope' => 'own_classes',   'sensitive' => false, 'accounts' => []],
        'gestionnaire_notifications' => ['label' => 'Gestionnaire des notifications', 'tier' => 'communication', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'referent_relation_familles' => ['label' => 'Référent relation familles',    'tier' => 'communication', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],

        // ───────── DOCUMENTS (tier=documents, scope=establishment) ─────────
        'gestionnaire_documents'    => ['label' => 'Gestionnaire des documents',    'tier' => 'documents', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'validateur_documents'      => ['label' => 'Validateur de documents',       'tier' => 'documents', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'archiviste'                => ['label' => 'Archiviste',                    'tier' => 'documents', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'responsable_justificatifs' => ['label' => 'Responsable des justificatifs', 'tier' => 'documents', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'gestionnaire_modeles'      => ['label' => 'Gestionnaire des modèles',      'tier' => 'documents', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],

        // ───────── SERVICES ANNEXES (tier=services, scope=establishment) ─────────
        'responsable_cantine'   => ['label' => 'Responsable cantine',        'tier' => 'services', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'personnel_cantine'     => ['label' => 'Personnel de cantine',       'tier' => 'services', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'responsable_internat'  => ['label' => "Responsable d'internat",     'tier' => 'services', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'surveillant_internat'  => ['label' => "Surveillant d'internat",     'tier' => 'services', 'scope' => 'assigned',      'sensitive' => false, 'accounts' => []],
        'responsable_transport' => ['label' => 'Responsable transport',      'tier' => 'services', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'responsable_clubs'     => ['label' => 'Responsable des clubs',      'tier' => 'services', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],

        // ───────── STAGES / ALTERNANCE (tier=stages) ─────────
        'responsable_stages'        => ['label' => 'Responsable des stages',       'tier' => 'stages', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'referent_entreprise'       => ['label' => 'Référent entreprise',          'tier' => 'stages', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'tuteur_entreprise'         => ['label' => 'Tuteur en entreprise',         'tier' => 'stages', 'scope' => 'assigned',      'sensitive' => false, 'accounts' => []],
        'maitre_apprentissage'      => ['label' => "Maître d'apprentissage",       'tier' => 'stages', 'scope' => 'assigned',      'sensitive' => false, 'accounts' => []],
        'professeur_referent_stage' => ['label' => 'Professeur référent de stage',  'tier' => 'stages', 'scope' => 'assigned',      'sensitive' => false, 'accounts' => []],
        'coordinateur_alternance'   => ['label' => "Coordinateur de l'alternance", 'tier' => 'stages', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'entreprise_partenaire'     => ['label' => 'Entreprise partenaire',        'tier' => 'stages', 'scope' => 'assigned',      'sensitive' => false, 'accounts' => []],

        // ───────── LECTURE SEULE / CONTRÔLE (tier=controle) ─────────
        'lecteur_etablissement'   => ['label' => "Lecteur d'établissement",       'tier' => 'controle', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'lecteur_pedagogique'     => ['label' => 'Lecteur pédagogique',           'tier' => 'controle', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'lecteur_vie_scolaire'    => ['label' => 'Lecteur vie scolaire',          'tier' => 'controle', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'auditeur_etablissement'  => ['label' => "Auditeur d'établissement",      'tier' => 'controle', 'scope' => 'establishment', 'sensitive' => true,  'accounts' => []],
        'inspecteur_academie'     => ['label' => "Inspecteur d'académie",         'tier' => 'controle', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],
        'invite_temporaire'       => ['label' => 'Invité temporaire',             'tier' => 'controle', 'scope' => 'assigned',      'sensitive' => false, 'accounts' => []],
        'compte_demonstration'    => ['label' => 'Compte de démonstration',       'tier' => 'controle', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => []],

        // ───────── SYSTÈME (tier=systeme, is_system=true, scope=global) ─────────
        'systeme'                  => ['label' => 'Système (automate)',            'tier' => 'systeme', 'scope' => 'global', 'sensitive' => true,  'accounts' => [], 'is_system' => true],
        'service_api'              => ['label' => 'Service API',                   'tier' => 'systeme', 'scope' => 'global', 'sensitive' => false, 'accounts' => [], 'is_system' => true],
        'service_import'           => ['label' => "Service d'import",              'tier' => 'systeme', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => [], 'is_system' => true],
        'service_export'           => ['label' => "Service d'export",              'tier' => 'systeme', 'scope' => 'establishment', 'sensitive' => false, 'accounts' => [], 'is_system' => true],
        'service_notifications'    => ['label' => 'Service de notifications',      'tier' => 'systeme', 'scope' => 'global', 'sensitive' => false, 'accounts' => [], 'is_system' => true],
        'service_sauvegarde'       => ['label' => 'Service de sauvegarde',         'tier' => 'systeme', 'scope' => 'global', 'sensitive' => true,  'accounts' => [], 'is_system' => true],
        'service_synchronisation'  => ['label' => 'Service de synchronisation',    'tier' => 'systeme', 'scope' => 'global', 'sensitive' => true,  'accounts' => [], 'is_system' => true],
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // PERMISSIONS : permission_key => ['label', 'category' (=domaine), 'sensitive']
    // Déclare au minimum toutes les clés non-wildcard référencées dans GRANTS,
    // plus les clés sensibles canoniques. La sensibilité d'un domaine entier est
    // aussi calculée dynamiquement par isSensitive().
    // ─────────────────────────────────────────────────────────────────────────
    private const PERMISSIONS = [
        // ─── etablissement ───
        'etablissement.view'    => ['label' => "Voir l'établissement",          'category' => 'etablissement', 'sensitive' => false],
        'etablissement.edit'    => ['label' => "Modifier l'établissement",      'category' => 'etablissement', 'sensitive' => false],
        'etablissement.create'  => ['label' => "Créer un établissement",        'category' => 'etablissement', 'sensitive' => false],
        'etablissement.purge'   => ['label' => "Purger un établissement",       'category' => 'etablissement', 'sensitive' => true],

        // ─── users ───
        'users.view'    => ['label' => 'Voir les utilisateurs',     'category' => 'users', 'sensitive' => false],
        'users.create'  => ['label' => 'Créer un utilisateur',      'category' => 'users', 'sensitive' => false],
        'users.edit'    => ['label' => 'Modifier un utilisateur',   'category' => 'users', 'sensitive' => false],
        'users.delete'  => ['label' => 'Supprimer un utilisateur',  'category' => 'users', 'sensitive' => true],

        // ─── roles ───
        'roles.view'   => ['label' => 'Voir les rôles',        'category' => 'roles', 'sensitive' => false],
        'roles.manage' => ['label' => 'Gérer les rôles',       'category' => 'roles', 'sensitive' => true],

        // ─── modules / params / system ───
        'modules.view'   => ['label' => 'Voir les modules',     'category' => 'modules', 'sensitive' => false],
        'params.view'    => ['label' => 'Voir les paramètres',  'category' => 'params',  'sensitive' => false],
        'params.edit'    => ['label' => 'Modifier les paramètres', 'category' => 'params', 'sensitive' => false],
        'system.view'    => ['label' => "Voir l'état système",   'category' => 'system',  'sensitive' => false],

        // ─── sécurité / audit / logs / backup / sync ───
        'securite.view'   => ['label' => 'Voir la sécurité',          'category' => 'securite', 'sensitive' => true],
        'securite.manage' => ['label' => 'Gérer la sécurité',         'category' => 'securite', 'sensitive' => true],
        'sessions.revoke' => ['label' => 'Révoquer les sessions',     'category' => 'securite', 'sensitive' => true],
        'audit.view'      => ['label' => "Voir le journal d'audit",   'category' => 'audit',    'sensitive' => true],
        'logs.view'       => ['label' => 'Voir les journaux',         'category' => 'logs',     'sensitive' => false],
        'backup.view'     => ['label' => 'Voir les sauvegardes',      'category' => 'backup',   'sensitive' => true],
        'sync.view'       => ['label' => 'Voir la synchronisation',   'category' => 'sync',     'sensitive' => false],

        // ─── import / export ───
        'import.view'   => ['label' => "Voir l'import",   'category' => 'import',         'sensitive' => false],
        'export_service.export' => ['label' => "Exporter (service d'export)", 'category' => 'export_service', 'sensitive' => false],
        'export.export' => ['label' => 'Exporter',        'category' => 'export',         'sensitive' => false],

        // ─── tickets / support ───
        'tickets.view'   => ['label' => 'Voir les tickets',   'category' => 'tickets', 'sensitive' => false],
        'tickets.manage' => ['label' => 'Gérer les tickets',  'category' => 'tickets', 'sensitive' => false],

        // ─── notes / appréciations / bulletins / conseils / examens / orientation ───
        'notes.view'           => ['label' => 'Voir les notes',           'category' => 'notes',         'sensitive' => false],
        'notes.create'         => ['label' => 'Saisir des notes',         'category' => 'notes',         'sensitive' => false],
        'notes.edit'           => ['label' => 'Modifier des notes',       'category' => 'notes',         'sensitive' => false],
        'notes.delete'         => ['label' => 'Supprimer des notes',      'category' => 'notes',         'sensitive' => false],
        'resultats.view'       => ['label' => 'Voir les résultats',        'category' => 'resultats',     'sensitive' => false],
        'appreciations.view'   => ['label' => 'Voir les appréciations',   'category' => 'appreciations', 'sensitive' => false],
        'appreciations.create' => ['label' => 'Saisir des appréciations', 'category' => 'appreciations', 'sensitive' => false],
        'appreciations.edit'   => ['label' => 'Modifier des appréciations', 'category' => 'appreciations', 'sensitive' => false],
        'bulletins.view'       => ['label' => 'Voir les bulletins',       'category' => 'bulletins',     'sensitive' => false],
        'bulletins.create'     => ['label' => 'Générer des bulletins',    'category' => 'bulletins',     'sensitive' => false],
        'bulletins.validate'   => ['label' => 'Valider les bulletins',    'category' => 'bulletins',     'sensitive' => false],
        'bulletins.publish'    => ['label' => 'Publier les bulletins',    'category' => 'bulletins',     'sensitive' => false],
        'examens.view'         => ['label' => 'Voir les examens',         'category' => 'examens',       'sensitive' => false],
        'orientation.view'     => ['label' => "Voir l'orientation",       'category' => 'orientation',   'sensitive' => false],

        // ─── absences / retards / discipline ───
        'absences.view'      => ['label' => 'Voir les absences',      'category' => 'absences',   'sensitive' => false],
        'absences.create'    => ['label' => 'Créer une absence',      'category' => 'absences',   'sensitive' => false],
        'absences.edit'      => ['label' => 'Modifier une absence',   'category' => 'absences',   'sensitive' => false],
        'absences.export'    => ['label' => 'Exporter les absences',  'category' => 'absences',   'sensitive' => false],
        'absences.justify'   => ['label' => 'Justifier une absence',  'category' => 'absences',   'sensitive' => false],
        'retards.view'       => ['label' => 'Voir les retards',       'category' => 'retards',    'sensitive' => false],
        'retards.create'     => ['label' => 'Créer un retard',        'category' => 'retards',    'sensitive' => false],
        'retards.edit'       => ['label' => 'Modifier un retard',     'category' => 'retards',    'sensitive' => false],
        'retards.export'     => ['label' => 'Exporter les retards',   'category' => 'retards',    'sensitive' => false],
        'discipline.view'    => ['label' => 'Voir la discipline',     'category' => 'discipline', 'sensitive' => false],
        'incidents.view'     => ['label' => 'Voir les incidents',     'category' => 'incidents',  'sensitive' => false],
        'incidents.create'   => ['label' => 'Signaler un incident',   'category' => 'incidents',  'sensitive' => false],
        'sanctions.view'     => ['label' => 'Voir les sanctions',     'category' => 'sanctions',  'sensitive' => false],

        // ─── appels / permanences / remplacements ───
        'appels.view'        => ['label' => 'Voir les appels',        'category' => 'appels',       'sensitive' => false],
        'appels.create'      => ['label' => 'Faire un appel',         'category' => 'appels',       'sensitive' => false],
        'appels.edit'        => ['label' => 'Gérer les appels',       'category' => 'appels',       'sensitive' => false],
        'permanences.view'   => ['label' => 'Voir les permanences',   'category' => 'permanences',  'sensitive' => false],
        'remplacements.view' => ['label' => 'Voir les remplacements', 'category' => 'remplacements', 'sensitive' => false],
        'presences.create'   => ['label' => 'Enregistrer une présence', 'category' => 'presences',  'sensitive' => false],

        // ─── élèves / classes / groupes / matières / périodes ───
        'eleves.view'    => ['label' => 'Voir les élèves',      'category' => 'eleves',   'sensitive' => false],
        'eleves.create'  => ['label' => 'Créer un élève',       'category' => 'eleves',   'sensitive' => false],
        'eleves.edit'    => ['label' => 'Modifier un élève',    'category' => 'eleves',   'sensitive' => false],
        'classes.view'   => ['label' => 'Voir les classes',     'category' => 'classes',  'sensitive' => false],
        'groupes.view'   => ['label' => 'Voir les groupes',     'category' => 'groupes',  'sensitive' => false],
        'matieres.view'  => ['label' => 'Voir les matières',    'category' => 'matieres', 'sensitive' => false],
        'periodes.view'  => ['label' => 'Voir les périodes',    'category' => 'periodes', 'sensitive' => false],

        // ─── EDT / cours / devoirs / évaluations ───
        'edt.view'         => ['label' => "Voir l'emploi du temps",  'category' => 'edt',         'sensitive' => false],
        'edt.publish'      => ['label' => "Publier l'emploi du temps", 'category' => 'edt',       'sensitive' => false],
        'cours.view'       => ['label' => 'Voir les cours',         'category' => 'cours',        'sensitive' => false],
        'devoirs.view'     => ['label' => 'Voir les devoirs',       'category' => 'devoirs',      'sensitive' => false],
        'devoirs.create'   => ['label' => 'Créer un devoir',        'category' => 'devoirs',      'sensitive' => false],
        'devoirs.submit'   => ['label' => 'Rendre un devoir',       'category' => 'devoirs',      'sensitive' => false],
        'evaluations.view'   => ['label' => 'Voir les évaluations',   'category' => 'evaluations', 'sensitive' => false],
        'evaluations.create' => ['label' => 'Créer une évaluation',   'category' => 'evaluations', 'sensitive' => false],

        // ─── messagerie / annonces / notifications / documents / justificatifs / modèles ───
        'messagerie.view'      => ['label' => 'Accéder à la messagerie',   'category' => 'messagerie',     'sensitive' => false],
        'messagerie.moderate'  => ['label' => 'Modérer la messagerie',     'category' => 'messagerie',     'sensitive' => false],
        'annonces.view'        => ['label' => 'Voir les annonces',         'category' => 'annonces',       'sensitive' => false],
        'annonces.create'      => ['label' => 'Créer une annonce',         'category' => 'annonces',       'sensitive' => false],
        'annonces.edit'        => ['label' => 'Modifier une annonce',      'category' => 'annonces',       'sensitive' => false],
        'annonces.publish'     => ['label' => 'Publier une annonce',       'category' => 'annonces',       'sensitive' => false],
        'notifications.view'   => ['label' => 'Voir les notifications',     'category' => 'notifications',  'sensitive' => false],
        'notifications.send'   => ['label' => 'Envoyer des notifications',  'category' => 'notifications',  'sensitive' => false],
        'documents.view'       => ['label' => 'Voir les documents',        'category' => 'documents',      'sensitive' => false],
        'documents.publish'    => ['label' => 'Publier un document',       'category' => 'documents',      'sensitive' => false],
        'documents.validate'   => ['label' => 'Valider un document',       'category' => 'documents',      'sensitive' => false],
        'documents.sign'       => ['label' => 'Signer un document',        'category' => 'documents',      'sensitive' => false],
        'justificatifs.view'     => ['label' => 'Voir les justificatifs',   'category' => 'justificatifs', 'sensitive' => false],
        'justificatifs.validate' => ['label' => 'Valider un justificatif',  'category' => 'justificatifs', 'sensitive' => false],

        // ─── DOMAINES SENSIBLES : medical / psy / social / pai / handicap / pap / rgpd ───
        'medical.view'     => ['label' => 'Voir le dossier médical',         'category' => 'medical',  'sensitive' => true],
        'medical.create'   => ['label' => 'Créer une entrée médicale',       'category' => 'medical',  'sensitive' => true],
        'medical.edit'     => ['label' => 'Modifier une entrée médicale',    'category' => 'medical',  'sensitive' => true],
        'psy.view'         => ['label' => 'Voir le suivi psychologique',     'category' => 'psy',      'sensitive' => true],
        'psy.create'       => ['label' => 'Créer un suivi psychologique',    'category' => 'psy',      'sensitive' => true],
        'psy.edit'         => ['label' => 'Modifier un suivi psychologique', 'category' => 'psy',      'sensitive' => true],
        'social.view'      => ['label' => 'Voir le suivi social',            'category' => 'social',   'sensitive' => true],
        'social.create'    => ['label' => 'Créer un suivi social',           'category' => 'social',   'sensitive' => true],
        'social.edit'      => ['label' => 'Modifier un suivi social',        'category' => 'social',   'sensitive' => true],
        'handicap.view'    => ['label' => 'Voir le dossier handicap',        'category' => 'handicap', 'sensitive' => true],
        'pai.view'         => ['label' => 'Voir le PAI',                     'category' => 'pai',      'sensitive' => true],
        'pai.edit'         => ['label' => 'Modifier le PAI',                 'category' => 'pai',      'sensitive' => true],
        'rgpd.view'        => ['label' => 'Voir les demandes RGPD',          'category' => 'rgpd',     'sensitive' => true],

        // ─── services annexes : cantine / internat / transport / clubs / salles / ressources ───
        'cantine.view'      => ['label' => 'Voir la cantine',        'category' => 'cantine',    'sensitive' => false],
        'cantine.presence'  => ['label' => 'Pointer un repas',       'category' => 'cantine',    'sensitive' => false],
        'internat.view'     => ['label' => "Voir l'internat",        'category' => 'internat',   'sensitive' => false],
        'internat.appel'    => ['label' => "Faire l'appel internat", 'category' => 'internat',   'sensitive' => false],
        'transport.view'    => ['label' => 'Voir le transport',      'category' => 'transport',  'sensitive' => false],
        'clubs.view'        => ['label' => 'Voir les clubs',         'category' => 'clubs',      'sensitive' => false],
        'sorties.view'      => ['label' => 'Voir les sorties',       'category' => 'sorties',    'sensitive' => false],
        'salles.view'       => ['label' => 'Voir les salles',        'category' => 'salles',     'sensitive' => false],
        'ressources.view'   => ['label' => 'Voir les ressources',    'category' => 'ressources', 'sensitive' => false],

        // ─── stages / alternance / entreprises / CDI / facturation / inscriptions ───
        'stages.view'         => ['label' => 'Voir les stages',          'category' => 'stages',       'sensitive' => false],
        'alternance.view'     => ['label' => "Voir l'alternance",        'category' => 'alternance',   'sensitive' => false],
        'entreprises.view'    => ['label' => 'Voir les entreprises',     'category' => 'entreprises',  'sensitive' => false],
        'cdi.view'            => ['label' => 'Voir le CDI',              'category' => 'cdi',          'sensitive' => false],
        'facturation.view'    => ['label' => 'Voir la facturation',      'category' => 'facturation',  'sensitive' => false],
        'inscriptions.view'   => ['label' => 'Voir les inscriptions',    'category' => 'inscriptions', 'sensitive' => false],
        'inscriptions.create' => ['label' => 'Créer une inscription',    'category' => 'inscriptions', 'sensitive' => false],

        // ─── conseils ───
        'conseils.view'   => ['label' => 'Voir les conseils de classe', 'category' => 'conseils', 'sensitive' => false],

        // ─── démonstration / mediation / archivage / suivi ───
        'demonstration.view' => ['label' => 'Mode démonstration',      'category' => 'demonstration', 'sensitive' => false],
        'archivage.view'     => ['label' => "Voir l'archivage",        'category' => 'archivage',     'sensitive' => false],
        'mediation.create'   => ['label' => 'Créer un compte-rendu de médiation', 'category' => 'mediation', 'sensitive' => false],
        'suivi.create'       => ['label' => 'Créer un suivi',          'category' => 'suivi',         'sensitive' => false],
        'observations.create'=> ['label' => 'Créer une observation',   'category' => 'observations',  'sensitive' => false],
        'rdv.view'           => ['label' => 'Voir les rendez-vous',    'category' => 'rdv',           'sensitive' => false],

        // ─── parents / coordonnées / certificats / dossiers (administratif) ───
        'parents.view'        => ['label' => 'Voir les responsables',     'category' => 'parents',     'sensitive' => false],
        'coordonnees.edit'    => ['label' => 'Modifier les coordonnées',  'category' => 'coordonnees', 'sensitive' => false],
        'certificats.create'  => ['label' => 'Émettre un certificat',     'category' => 'certificats', 'sensitive' => false],
        'dossiers.view'       => ['label' => 'Voir les dossiers',         'category' => 'dossiers',    'sensitive' => false],
        'autorisations.validate' => ['label' => 'Valider une autorisation', 'category' => 'autorisations', 'sensitive' => false],

        // ─── back-office admin (core, repris de l'ancien RBAC pour zéro régression) ───
        'admin.access'        => ['label' => 'Accès back-office',             'category' => 'admin', 'sensitive' => false],
        'admin.users'         => ['label' => 'Back-office utilisateurs',      'category' => 'admin', 'sensitive' => false],
        'admin.users.create'  => ['label' => 'Back-office créer utilisateur', 'category' => 'admin', 'sensitive' => false],
        'admin.users.delete'  => ['label' => 'Back-office supprimer utilisateur', 'category' => 'admin', 'sensitive' => true],
        'admin.users.import'  => ['label' => 'Back-office importer utilisateurs', 'category' => 'admin', 'sensitive' => false],
        'admin.scolaire'      => ['label' => 'Back-office scolaire',          'category' => 'admin', 'sensitive' => false],
        'admin.modules'       => ['label' => 'Back-office modules',           'category' => 'admin', 'sensitive' => false],
        'admin.systeme'       => ['label' => 'Back-office système',           'category' => 'admin', 'sensitive' => false],
        'admin.etablissement' => ['label' => 'Back-office établissement',     'category' => 'admin', 'sensitive' => false],
        'admin.messagerie'    => ['label' => 'Back-office messagerie',        'category' => 'admin', 'sensitive' => false],
        'admin.classes'       => ['label' => 'Back-office classes',           'category' => 'admin', 'sensitive' => false],

        // ─── modèles de documents (référencé par responsable_documents / gestionnaire_modeles) ───
        'modeles.view'   => ['label' => 'Voir les modèles',    'category' => 'modeles', 'sensitive' => false],
        'modeles.create' => ['label' => 'Créer un modèle',     'category' => 'modeles', 'sensitive' => false],
        'modeles.edit'   => ['label' => 'Modifier un modèle',  'category' => 'modeles', 'sensitive' => false],
        'modeles.delete' => ['label' => 'Supprimer un modèle', 'category' => 'modeles', 'sensitive' => false],

        // ─── RGPD (compléments des clés héritées) ───
        'rgpd.manage'  => ['label' => 'Gérer les demandes RGPD', 'category' => 'rgpd', 'sensitive' => true],
        'rgpd.my_data' => ['label' => 'Voir mes données',        'category' => 'rgpd', 'sensitive' => false],

        // ─── API ───
        'api.access' => ['label' => "Accès API",   'category' => 'api', 'sensitive' => false],
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // GRANTS : role_key => [permission_key | 'domaine.*' | '*']
    // Wildcards 'domaine.*' pour les gestionnaires d'un domaine ; '*' = super_admin seul.
    // ─────────────────────────────────────────────────────────────────────────
    private const GRANTS = [
        // ───────── PLATEFORME ─────────
        'super_admin' => ['*'],

        'administrateur' => [
            'admin.access', 'admin.users', 'admin.users.create', 'admin.users.delete', 'admin.users.import',
            'admin.scolaire', 'admin.modules', 'admin.systeme', 'admin.etablissement', 'admin.messagerie', 'admin.classes',
            'etablissement.view', 'etablissement.edit', 'etablissement.create',
            'users.view', 'users.create', 'users.edit',
            'roles.view', 'roles.manage',
            'modules.view', 'params.view', 'params.edit',
            'logs.view', 'import.view', 'export.export',
            'inscriptions.view', 'inscriptions.create',
            'classes.view', 'eleves.view', 'parents.view',
            'messagerie.view', 'annonces.view',
            'rgpd.view', 'rgpd.manage', 'rgpd.my_data', 'notifications.view',
        ],

        'technicien' => [
            'system.view', 'logs.view', 'params.view',
            'users.view', 'users.edit', 'sessions.revoke',
            'modules.view', 'sync.view',
        ],

        'support' => [
            'users.view', 'users.edit', 'sessions.revoke',
            'logs.view', 'tickets.view', 'tickets.manage',
        ],

        'auditeur' => [
            'audit.view', 'logs.view', 'securite.view',
            'users.view', 'params.view', 'export.export',
        ],

        'responsable_securite' => [
            'securite.view', 'securite.manage', 'sessions.revoke',
            'audit.view', 'logs.view', 'users.view', 'users.edit',
        ],

        'dpo' => [
            'rgpd.view', 'audit.view', 'documents.view', 'documents.validate',
        ],

        'developpeur' => [
            'logs.view', 'system.view', 'audit.view',
        ],

        // ───────── DIRECTION ─────────
        'direction' => [
            'classes.*', 'eleves.*', 'users.view', 'users.create', 'users.edit',
            'roles.view', 'roles.manage', 'modules.view',
            'params.view', 'matieres.*', 'groupes.*', 'periodes.*',
            'notes.view', 'absences.view', 'retards.view', 'sanctions.view', 'bulletins.view',
            'annonces.*', 'messagerie.view',
        ],

        'chef_etablissement' => [
            'classes.*', 'eleves.*', 'users.view', 'users.create', 'users.edit',
            'roles.view', 'roles.manage', 'modules.view',
            'params.view', 'params.edit', 'matieres.*', 'groupes.*', 'periodes.*',
            'absences.view', 'retards.view', 'sanctions.view',
            'bulletins.view', 'bulletins.validate', 'bulletins.publish',
            'conseils.view', 'annonces.*', 'messagerie.view',
        ],

        'direction_adjointe' => [
            'classes.*', 'eleves.*', 'users.view', 'users.create', 'users.edit',
            'roles.view', 'matieres.*', 'groupes.*', 'periodes.view',
            'absences.view', 'retards.view', 'sanctions.view', 'bulletins.view',
            'annonces.*', 'messagerie.view',
        ],

        'directeur_multi' => [
            'etablissement.view', 'classes.view', 'eleves.view', 'users.view',
            'params.view', 'bulletins.view', 'absences.view', 'retards.view',
            'annonces.view',
        ],

        'secretariat_direction' => [
            'documents.*', 'rdv.view', 'dossiers.view', 'coordonnees.edit',
            'messagerie.view', 'annonces.view',
        ],

        'responsable_pedagogique' => [
            'matieres.*', 'groupes.*', 'classes.view', 'edt.view',
            'bulletins.view', 'bulletins.validate',
            'conseils.*', 'notes.view', 'appreciations.view',
        ],

        'responsable_administratif' => [
            'eleves.*', 'parents.view', 'documents.*',
            'inscriptions.*', 'certificats.create', 'dossiers.view', 'coordonnees.edit',
        ],

        'responsable_permissions' => [
            'roles.view', 'roles.manage', 'users.view',
        ],

        // ───────── ADMINISTRATIF ─────────
        'administration' => [
            'eleves.*', 'parents.view', 'classes.*', 'documents.*',
            'users.create', 'users.edit', 'inscriptions.*', 'coordonnees.edit',
        ],

        'secretariat_scolaire' => [
            'eleves.create', 'eleves.edit', 'eleves.view',
            'documents.*', 'certificats.create', 'parents.view', 'coordonnees.edit',
            'inscriptions.view', 'inscriptions.create',
        ],

        'gestionnaire_administratif' => [
            'dossiers.view', 'export_service.export', 'documents.*', 'archivage.view',
        ],

        'gestionnaire_comptable' => [
            'facturation.*', 'cantine.view',
        ],

        'intendance' => [
            'cantine.*', 'ressources.*', 'eleves.view',
        ],

        'responsable_inscriptions' => [
            'inscriptions.*', 'eleves.view', 'eleves.create',
            'documents.view', 'classes.view', 'users.create', 'coordonnees.edit',
        ],

        'responsable_documents' => [
            'documents.*', 'justificatifs.view', 'modeles.*',
        ],

        'referent_comptes' => [
            'users.view', 'users.create', 'users.edit', 'sessions.revoke',
        ],

        // ───────── VIE SCOLAIRE ─────────
        'cpe' => [
            'eleves.view', 'classes.view',
            'absences.*', 'retards.*', 'discipline.*', 'incidents.*', 'sanctions.*',
            'justificatifs.view', 'justificatifs.validate',
            'observations.create', 'messagerie.view',
        ],

        'vie_scolaire' => [
            'eleves.view', 'classes.view', 'edt.view',
            'absences.create', 'absences.edit', 'absences.view',
            'retards.create', 'retards.edit', 'retards.view',
            'appels.view', 'appels.edit', 'remplacements.view', 'permanences.view',
        ],

        'aed' => [
            'eleves.view', 'retards.create', 'appels.view', 'appels.create',
            'incidents.create', 'permanences.view',
        ],

        'surveillant' => [
            'eleves.view', 'presences.create', 'permanences.view',
            'retards.view', 'incidents.create',
        ],

        'referent_absences' => [
            'absences.*', 'justificatifs.view', 'justificatifs.validate',
            'notifications.send',
        ],

        'referent_retards' => [
            'retards.*', 'notifications.send',
        ],

        'referent_discipline' => [
            'incidents.*', 'sanctions.*', 'discipline.view',
        ],

        'responsable_permanence' => [
            'permanences.*', 'presences.create', 'salles.view', 'incidents.create',
        ],

        'responsable_remplacements' => [
            'cours.view', 'remplacements.*', 'edt.view', 'notifications.send',
        ],

        'responsable_appels' => [
            'appels.view', 'appels.edit',
        ],

        // ───────── PÉDAGOGIQUE ─────────
        'professeur' => [
            'edt.view', 'classes.view', 'eleves.view',
            'appels.view', 'appels.create',
            'devoirs.*', 'evaluations.*',
            'notes.view', 'notes.create', 'notes.edit', 'notes.delete',
            'appreciations.create', 'appreciations.edit', 'appreciations.view',
            'documents.publish', 'messagerie.view',
        ],

        'professeur_principal' => [
            'edt.view', 'classes.view', 'eleves.view',
            'appels.view', 'appels.create',
            'devoirs.*', 'evaluations.*',
            'notes.view', 'notes.create', 'notes.edit', 'notes.delete',
            'appreciations.create', 'appreciations.edit', 'appreciations.view',
            'documents.publish', 'messagerie.view',
            'resultats.view', 'absences.view', 'conseils.view',
        ],

        'professeur_remplacant' => [
            'cours.view', 'eleves.view', 'edt.view',
            'appels.view', 'appels.create', 'devoirs.create',
            'messagerie.view',
        ],

        'professeur_vacataire' => [
            'cours.view', 'classes.view', 'eleves.view',
            'appels.view', 'appels.create', 'devoirs.create',
            'notes.view', 'notes.create', 'notes.edit',
            'messagerie.view',
        ],

        'coordinateur_matiere' => [
            'matieres.view', 'classes.view', 'ressources.view',
            'evaluations.view', 'evaluations.create', 'notes.view',
        ],

        'coordinateur_niveau' => [
            'classes.view', 'notes.view', 'absences.view',
            'notifications.send', 'rdv.view',
        ],

        'responsable_classe' => [
            'eleves.view', 'absences.view', 'notes.view',
            'messagerie.view',
        ],

        'responsable_bulletin' => [
            'bulletins.view', 'bulletins.create', 'bulletins.validate', 'bulletins.publish',
            'appreciations.view', 'notes.view',
        ],

        'responsable_conseils' => [
            'conseils.*', 'appreciations.view', 'bulletins.view', 'notes.view',
        ],

        'responsable_examens' => [
            'examens.*', 'salles.view', 'export.export',
        ],

        'responsable_orientation' => [
            'orientation.*', 'bulletins.view', 'rdv.view', 'messagerie.view',
        ],

        'documentaliste' => [
            'cdi.*', 'ressources.view', 'eleves.view', 'documents.publish',
        ],

        'tuteur_pedagogique' => [
            'eleves.view', 'notes.view', 'absences.view',
            'suivi.create', 'rdv.view', 'messagerie.view',
        ],

        // ───────── SANTÉ / SOCIAL / ACCOMPAGNEMENT ─────────
        'infirmerie' => [
            'eleves.view', 'medical.view', 'medical.create', 'medical.edit',
            'pai.view', 'notifications.send',
        ],

        'infirmier_scolaire' => [
            'eleves.view', 'medical.view', 'medical.create', 'medical.edit',
            'pai.view', 'notifications.send',
        ],

        'medecin_scolaire' => [
            'eleves.view', 'medical.*', 'pai.view', 'pai.edit',
            'documents.validate',
        ],

        'psychologue' => [
            'eleves.view', 'psy.view', 'psy.create', 'psy.edit',
            'rdv.view',
        ],

        'assistant_social' => [
            'eleves.view', 'social.view', 'social.create', 'social.edit',
            'rdv.view', 'messagerie.view',
        ],

        'aesh' => [
            'eleves.view', 'edt.view', 'classes.view',
            'handicap.view', 'observations.create', 'messagerie.view',
        ],

        'referent_handicap' => [
            'eleves.view', 'handicap.*', 'pai.view', 'messagerie.view',
        ],

        'referent_pai' => [
            'pai.view', 'pai.edit', 'medical.view', 'notifications.send',
        ],

        'referent_pap' => [
            'eleves.view', 'notifications.send', 'suivi.create',
        ],

        'tuteur_eleve' => [
            'eleves.view', 'absences.view', 'suivi.create', 'rdv.view',
        ],

        'mediateur_scolaire' => [
            'mediation.create', 'rdv.view', 'messagerie.view',
        ],

        // ───────── ÉLÈVES / FAMILLES ─────────
        'eleve' => [
            'edt.view', 'devoirs.view', 'devoirs.submit',
            'notes.view', 'bulletins.view', 'absences.view', 'retards.view',
            'messagerie.view', 'documents.view', 'notifications.view',
        ],

        'parent' => [
            'notes.view', 'devoirs.view', 'edt.view',
            'absences.view', 'absences.justify', 'retards.view', 'bulletins.view',
            'documents.view', 'messagerie.view', 'notifications.view',
        ],

        'responsable_legal' => [
            'notes.view', 'devoirs.view', 'edt.view',
            'absences.view', 'absences.justify', 'retards.view', 'bulletins.view',
            'documents.view', 'documents.sign', 'autorisations.validate',
            'justificatifs.view', 'coordonnees.edit', 'messagerie.view', 'notifications.view',
        ],

        'responsable_financier' => [
            'facturation.view', 'documents.view', 'coordonnees.edit', 'notifications.view',
        ],

        'tuteur_legal' => [
            'notes.view', 'devoirs.view', 'edt.view',
            'absences.view', 'absences.justify', 'retards.view', 'bulletins.view',
            'documents.view', 'documents.sign', 'autorisations.validate',
            'justificatifs.view', 'coordonnees.edit', 'messagerie.view', 'notifications.view',
        ],

        'famille_lecture_seule' => [
            'notes.view', 'devoirs.view', 'edt.view',
            'absences.view', 'retards.view', 'bulletins.view',
            'documents.view', 'notifications.view',
        ],

        'ancien_eleve' => [
            'bulletins.view', 'documents.view', 'coordonnees.edit',
        ],

        'ancien_parent' => [
            'bulletins.view', 'documents.view',
        ],

        // ───────── EDT / ORGANISATION ─────────
        'responsable_edt' => [
            'edt.*', 'cours.view', 'remplacements.view', 'salles.view', 'groupes.view',
        ],

        'gestionnaire_salles' => [
            'salles.*',
        ],

        'gestionnaire_ressources' => [
            'ressources.*',
        ],

        'responsable_groupes' => [
            'groupes.*', 'eleves.edit', 'classes.view',
        ],

        'responsable_matieres' => [
            'matieres.*',
        ],

        'responsable_periodes' => [
            'periodes.*',
        ],

        'responsable_calendrier' => [
            'sorties.view', 'rdv.view', 'conseils.view', 'notifications.send',
        ],

        'responsable_sorties' => [
            'sorties.*', 'classes.view', 'autorisations.validate', 'documents.view',
        ],

        // ───────── COMMUNICATION ─────────
        'responsable_communication' => [
            'annonces.*', 'messagerie.view', 'notifications.send',
        ],

        'moderateur_messagerie' => [
            'messagerie.view', 'messagerie.moderate',
        ],

        'emetteur_annonces_etab' => [
            'annonces.view', 'annonces.create', 'annonces.edit', 'annonces.publish',
        ],

        'emetteur_annonces_classe' => [
            'annonces.view', 'annonces.create', 'annonces.publish',
        ],

        'gestionnaire_notifications' => [
            'notifications.view', 'notifications.send',
        ],

        'referent_relation_familles' => [
            'messagerie.view', 'rdv.view', 'notifications.send',
        ],

        // ───────── DOCUMENTS ─────────
        'gestionnaire_documents' => [
            'documents.*',
        ],

        'validateur_documents' => [
            'documents.view', 'documents.validate',
        ],

        'archiviste' => [
            'archivage.view', 'documents.view',
        ],

        'responsable_justificatifs' => [
            'justificatifs.view', 'justificatifs.validate', 'notifications.send',
        ],

        'gestionnaire_modeles' => [
            'modeles.*',
        ],

        // ───────── SERVICES ANNEXES ─────────
        'responsable_cantine' => [
            'cantine.*',
        ],

        'personnel_cantine' => [
            'cantine.view', 'cantine.presence',
        ],

        'responsable_internat' => [
            'internat.*', 'messagerie.view',
        ],

        'surveillant_internat' => [
            'internat.view', 'internat.appel', 'incidents.create',
        ],

        'responsable_transport' => [
            'transport.*', 'notifications.send',
        ],

        'responsable_clubs' => [
            'clubs.*',
        ],

        // ───────── STAGES / ALTERNANCE ─────────
        'responsable_stages' => [
            'stages.*', 'entreprises.view', 'documents.view',
        ],

        'referent_entreprise' => [
            'entreprises.*', 'stages.view',
        ],

        'tuteur_entreprise' => [
            'eleves.view', 'stages.view', 'evaluations.create', 'suivi.create',
            'messagerie.view',
        ],

        'maitre_apprentissage' => [
            'eleves.view', 'edt.view', 'presences.create',
            'evaluations.create', 'suivi.create', 'messagerie.view',
        ],

        'professeur_referent_stage' => [
            'eleves.view', 'entreprises.view', 'stages.view',
            'evaluations.create', 'suivi.create', 'messagerie.view',
        ],

        'coordinateur_alternance' => [
            'alternance.*', 'entreprises.view', 'documents.view', 'evaluations.view',
        ],

        'entreprise_partenaire' => [
            'eleves.view', 'stages.view', 'documents.view', 'evaluations.create',
        ],

        // ───────── LECTURE SEULE / CONTRÔLE ─────────
        'lecteur_etablissement' => [
            'etablissement.view', 'classes.view', 'eleves.view', 'export.export',
        ],

        'lecteur_pedagogique' => [
            'classes.view', 'bulletins.view', 'appreciations.view', 'notes.view',
        ],

        'lecteur_vie_scolaire' => [
            'absences.view', 'retards.view', 'incidents.view',
        ],

        'auditeur_etablissement' => [
            'audit.view', 'logs.view', 'roles.view', 'export.export',
        ],

        'inspecteur_academie' => [
            'classes.view', 'bulletins.view', 'documents.view',
        ],

        'invite_temporaire' => [
            'documents.view',
        ],

        'compte_demonstration' => [
            'demonstration.view',
        ],

        // ───────── SYSTÈME (automates) ─────────
        'systeme' => [
            'notifications.send', 'system.view', 'archivage.view',
        ],

        'service_api' => [
            'api.access',
        ],

        'service_import' => [
            'import.*', 'users.view', 'users.create', 'eleves.view', 'eleves.create',
        ],

        'service_export' => [
            'export_service.*',
        ],

        'service_notifications' => [
            'notifications.send',
        ],

        'service_sauvegarde' => [
            'backup.*',
        ],

        'service_synchronisation' => [
            'sync.*',
        ],
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // ACCOUNT_BASE_ROLE : type de compte (table de comptes) => role_key de base.
    // ─────────────────────────────────────────────────────────────────────────
    private const ACCOUNT_BASE_ROLE = [
        'super_admin'    => 'super_admin',
        'administrateur' => 'administrateur',
        'technicien'     => 'technicien',
        'vie_scolaire'   => 'vie_scolaire',
        'professeur'     => 'professeur',
        'parent'         => 'parent',
        'eleve'          => 'eleve',
    ];

    public static function roles(): array { return self::ROLES; }

    public static function permissions(): array { return self::PERMISSIONS; }

    public static function grants(): array { return self::GRANTS; }

    public static function grantsFor(string $role): array { return self::GRANTS[$role] ?? []; }

    public static function baseRoleForAccount(string $type): string { return self::ACCOUNT_BASE_ROLE[$type] ?? $type; }

    public static function isSensitive(string $permission): bool
    {
        if (isset(self::PERMISSIONS[$permission])) {
            return !empty(self::PERMISSIONS[$permission]['sensitive']);
        }
        $d = explode('.', $permission)[0];
        return in_array($d, ['medical', 'psy', 'social', 'pai', 'handicap', 'rgpd', 'audit', 'backup', 'securite'], true);
    }
}
