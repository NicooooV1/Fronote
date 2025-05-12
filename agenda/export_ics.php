<?php
session_start();
require_once __DIR__ . '/controllers/AgendaController.php';
$ctrl = new AgendaController();
$ctrl->exportIcs();