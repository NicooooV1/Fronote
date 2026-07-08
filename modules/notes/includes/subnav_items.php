<?php
// Entrées de la navigation secondaire du module notes — partagées entre
// notes.php et includes/header.php pour éviter toute divergence.
return [
    ['href' => 'notes.php',     'icon' => 'fas fa-list-ol',       'label' => 'Notes'],
    ['href' => 'form_note.php', 'icon' => 'fas fa-pen-to-square', 'label' => 'Saisie', 'visible' => canManageNotes()],
    ['href' => 'export.php',    'icon' => 'fas fa-file-export',   'label' => 'Export', 'visible' => isAdmin() || isVieScolaire() || isTeacher()],
];
