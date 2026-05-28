<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../../API/bootstrap.php';
requireAuth();
$pdo = getPDO();
require_once __DIR__ . '/ParcoursEducatifService.php';
$parcoursService = new ParcoursEducatifService($pdo);

$activePage = $activePage ?? 'parcours';
$extraCss = ['parcours_educatifs/assets/css/parcours.css'];
$sidebarLinks = [
    ['url' => '/parcours_educatifs/parcours.php', 'icon' => 'fas fa-route',  'label' => 'Parcours', 'id' => 'parcours'],
    ['url' => '/parcours_educatifs/ajouter.php',  'icon' => 'fas fa-plus',   'label' => 'Ajouter',   'id' => 'ajouter'],
];
require_once __DIR__ . '/../../../templates/shared_header.php';
