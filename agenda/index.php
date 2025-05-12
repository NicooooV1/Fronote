<?php
session_start();
require_once __DIR__ . '/controllers/AgendaController.php';
$action = $_GET['action'] ?? 'index';
$ctrl   = new AgendaController();

switch ($action) {
    case 'getEvents':
        $ctrl->getEvents();
        break;
    default:
        $ctrl->index();
        break;
}