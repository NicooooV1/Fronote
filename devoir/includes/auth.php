<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: /~u22405372/SAE/Pronote/login/public/index.php');
    exit();
}

function isProf() {
    return $_SESSION['user']['role'] === 'prof';
}

function isEleve() {
    return $_SESSION['user']['role'] === 'eleve';
}
?>