<?php
session_start();
require_once __DIR__ . '/controllers/AgendaController.php';

// Vérification de la méthode
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /agenda/?error=method_not_allowed');
    exit;
}

// Vérification CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    header('Location: /agenda/?error=csrf_validation_failed');
    exit;
}

// Traiter l'import
$controller = new AgendaController();
$controller->importIcs();