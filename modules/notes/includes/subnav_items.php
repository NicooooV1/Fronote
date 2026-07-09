<?php
declare(strict_types=1);
/**
 * Navigation secondaire du module notes — source unique du câblage,
 * partagée entre notes.php et includes/header.php pour éviter toute divergence.
 */

require_once __DIR__ . '/../../../templates/module_subnav.php';

/**
 * Rend la navigation secondaire du module (bandeau .module-subnav
 * affiché par shared_topbar.php via $sidebarExtraContent).
 */
function renderNotesSubnav(): string
{
    return renderModuleSubnav([
        ['href' => 'notes.php',     'icon' => 'fas fa-list-ol',       'label' => 'Notes'],
        ['href' => 'form_note.php', 'icon' => 'fas fa-pen-to-square', 'label' => 'Saisie', 'visible' => canManageNotes()],
        ['href' => 'export.php',    'icon' => 'fas fa-file-export',   'label' => 'Export', 'visible' => isAdmin() || isVieScolaire() || isTeacher()],
    ]);
}
