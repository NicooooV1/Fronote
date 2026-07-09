<?php
// Entrées de la navigation secondaire du module cahier de textes — partagées entre
// cahierdetextes.php, includes/header.php et includes/header_rendus.php pour éviter
// toute divergence (fusion de l'ancien bandeau inline et des ex-onglets .cdt-tabs).
$_cdtCurrent = basename($_SERVER['SCRIPT_NAME'] ?? '');
// Les filtres JS (urgent / semaine / tous) n'agissent que sur les cartes de la liste.
$_cdtOnListe = $_cdtCurrent === 'cahierdetextes.php';
return [
    // Pages accessibles à tout utilisateur authentifié (mêmes gardes que les pages cibles).
    ['href' => 'cahierdetextes.php', 'icon' => 'fas fa-list', 'label' => 'Liste des devoirs',
     'active' => in_array($_cdtCurrent, ['cahierdetextes.php', 'supprimer_devoir.php'], true)],
    ['href' => 'form_devoir.php', 'icon' => 'fas fa-plus', 'label' => 'Ajouter un devoir', 'visible' => canManageDevoirs()],
    // Onglet actif sur toute la chaîne des rendus (mes_devoirs → rendre / corriger / voir_rendu).
    ['href' => 'mes_devoirs.php', 'icon' => 'fas fa-tasks', 'label' => 'Devoirs & rendus',
     'active' => in_array($_cdtCurrent, ['mes_devoirs.php', 'rendre.php', 'corriger.php', 'voir_rendu.php'], true)],
    // Filtres locaux à la liste (gérés par cahierdetextes.js).
    ['href' => '#', 'icon' => 'fas fa-exclamation-circle', 'label' => 'Urgents (< 3 jours)', 'class' => 'filter-link', 'attrs' => ['data-filter' => 'urgent'], 'visible' => $_cdtOnListe],
    ['href' => '#', 'icon' => 'fas fa-clock', 'label' => 'Cette semaine', 'class' => 'filter-link', 'attrs' => ['data-filter' => 'soon'], 'visible' => $_cdtOnListe],
    ['href' => '#', 'icon' => 'fas fa-list', 'label' => 'Tous', 'class' => 'filter-link', 'attrs' => ['data-filter' => 'all'], 'visible' => $_cdtOnListe],
];
