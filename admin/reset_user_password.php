<?php
// Démarrage de la session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérification des droits d'accès (administrateur uniquement)
if (!isset($_SESSION['user']) || $_SESSION['user']['profil'] !== 'administrateur') {
    // Rediriger vers la page d'accueil
    header("Location: ../accueil/accueil.php");
    exit;
}

// Inclusion des fichiers nécessaires
require_once '../login/config/database.php';

// Récupérer les informations de l'utilisateur connecté
$admin = $_SESSION['user'];
$admin_initials = strtoupper(mb_substr($admin['prenom'], 0, 1) . mb_substr($admin['nom'], 0, 1));

// Générer un token CSRF pour sécuriser les formulaires
if (empty($_SESSION['reset_pwd_token'])) {
    $_SESSION['reset_pwd_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['reset_pwd_token'];

// Constantes pour les messages
define('MIN_PWD_LENGTH', 10);

// Variables pour les messages
$success = '';
$error = '';

// Générer un mot de passe aléatoire sécurisé
function generatePassword($length = 12) {
    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $numbers = '0123456789';
    $symbols = '!@#$%^&*()-_=+[]{}|;:,.<>?';
    
    $all = $lowercase . $uppercase . $numbers . $symbols;
    $password = '';
    
    // Assurer qu'il y a au moins un caractère de chaque type
    $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
    $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
    $password .= $numbers[random_int(0, strlen($numbers) - 1)];
    $password .= $symbols[random_int(0, strlen($symbols) - 1)];
    
    // Compléter avec des caractères aléatoires
    for ($i = 4; $i < $length; $i++) {
        $password .= $all[random_int(0, strlen($all) - 1)];
    }
    
    // Mélanger les caractères
    $password = str_shuffle($password);
    
    return $password;
}

// Récupérer la liste des utilisateurs (sauf administrateurs)
$users = [];
try {
    // Récupérer les élèves
    $stmt = $pdo->query("SELECT 'eleve' AS type, id, nom, prenom, identifiant FROM eleves ORDER BY nom, prenom");
    if ($stmt) {
        $eleves = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($eleves as $eleve) {
            $users[] = [
                'type' => 'eleve',
                'table' => 'eleves',
                'id' => $eleve['id'],
                'nom' => $eleve['nom'],
                'prenom' => $eleve['prenom'],
                'identifiant' => $eleve['identifiant']
            ];
        }
    }
    
    // Récupérer les professeurs
    $stmt = $pdo->query("SELECT 'professeur' AS type, id, nom, prenom, identifiant FROM professeurs ORDER BY nom, prenom");
    if ($stmt) {
        $professeurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($professeurs as $prof) {
            $users[] = [
                'type' => 'professeur',
                'table' => 'professeurs',
                'id' => $prof['id'],
                'nom' => $prof['nom'],
                'prenom' => $prof['prenom'],
                'identifiant' => $prof['identifiant']
            ];
        }
    }
    
    // Récupérer les membres de la vie scolaire
    $stmt = $pdo->query("SELECT 'vie_scolaire' AS type, id, nom, prenom, identifiant FROM vie_scolaire ORDER BY nom, prenom");
    if ($stmt) {
        $vie_scolaire = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($vie_scolaire as $vs) {
            $users[] = [
                'type' => 'vie_scolaire',
                'table' => 'vie_scolaire',
                'id' => $vs['id'],
                'nom' => $vs['nom'],
                'prenom' => $vs['prenom'],
                'identifiant' => $vs['identifiant']
            ];
        }
    }
    
    // Récupérer les parents
    $stmt = $pdo->query("SELECT 'parent' AS type, id, nom, prenom, identifiant FROM parents ORDER BY nom, prenom");
    if ($stmt) {
        $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($parents as $parent) {
            $users[] = [
                'type' => 'parent',
                'table' => 'parents',
                'id' => $parent['id'],
                'nom' => $parent['nom'],
                'prenom' => $parent['prenom'],
                'identifiant' => $parent['identifiant']
            ];
        }
    }
} catch (PDOException $e) {
    $error = "Erreur lors de la récupération des utilisateurs: " . $e->getMessage();
}

// Traitement du formulaire de réinitialisation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    // Vérifier le token CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = "Erreur de sécurité: jeton CSRF invalide.";
    } else {
        // Récupérer les données du formulaire
        $user_type = filter_input(INPUT_POST, 'user_type', FILTER_SANITIZE_STRING);
        $user_table = filter_input(INPUT_POST, 'user_table', FILTER_SANITIZE_STRING);
        $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        
        // Vérifier que tous les champs sont valides
        if (!$user_type || !$user_table || !$user_id) {
            $error = "Utilisateur invalide sélectionné.";
        } else if ($user_type === 'administrateur') {
            $error = "Impossible de réinitialiser le mot de passe d'un administrateur.";
        } else {
            // Vérifier si nous utilisons un mot de passe généré ou personnalisé
            $useGeneratedPassword = isset($_POST['use_generated_password']) && $_POST['use_generated_password'] === '1';
            
            if ($useGeneratedPassword) {
                // Générer un mot de passe aléatoire
                $newPassword = generatePassword();
            } else {
                // Utiliser le mot de passe fourni par l'administrateur
                $newPassword = $_POST['new_password'] ?? '';
                
                // Vérifier la complexité du mot de passe
                if (strlen($newPassword) < MIN_PWD_LENGTH) {
                    $error = "Le mot de passe doit contenir au moins " . MIN_PWD_LENGTH . " caractères.";
                }
                // Vérifier si le mot de passe contient au moins une lettre majuscule, une minuscule, un chiffre et un caractère spécial
                else if (!preg_match('/[A-Z]/', $newPassword) || 
                         !preg_match('/[a-z]/', $newPassword) || 
                         !preg_match('/[0-9]/', $newPassword) || 
                         !preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
                    $error = "Le mot de passe doit contenir au moins une lettre majuscule, une minuscule, un chiffre et un caractère spécial.";
                }
            }
            
            // Si pas d'erreur, procéder à la réinitialisation
            if (empty($error)) {
                try {
                    // Tableau des tables autorisées pour éviter les injections SQL
                    $allowedTables = ['eleves', 'professeurs', 'parents', 'vie_scolaire'];
                    
                    // Vérifier que la table demandée est valide
                    if (!in_array($user_table, $allowedTables)) {
                        throw new Exception("Table invalide.");
                    }
                    
                    // Hasher le mot de passe
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    
                    // Mettre à jour le mot de passe dans la base de données avec une requête préparée
                    $stmt = $pdo->prepare("UPDATE `{$user_table}` SET mot_de_passe = ? WHERE id = ?");
                    $stmt->execute([$hashedPassword, $user_id]);
                    
                    // Vérifier si la mise à jour a réussi
                    if ($stmt->rowCount() > 0) {
                        // Récupérer les informations de l'utilisateur dont le mot de passe a été réinitialisé
                        $userInfo = null;
                        foreach ($users as $u) {
                            if ($u['table'] === $user_table && $u['id'] == $user_id) {
                                $userInfo = $u;
                                break;
                            }
                        }
                        
                        $userName = $userInfo ? $userInfo['prenom'] . ' ' . $userInfo['nom'] : 'Utilisateur inconnu';
                        
                        $success = "Le mot de passe de {$userName} a été réinitialisé avec succès.";
                        
                        // Enregistrer dans le journal d'activité
                        $logMessage = date('Y-m-d H:i:s') . " - L'administrateur {$admin['identifiant']} a réinitialisé le mot de passe de {$userName} ({$user_type}).";
                        error_log($logMessage, 3, "../login/logs/password_resets.log");
                        
                        // Stocker le nouveau mot de passe pour l'affichage
                        $resetPassword = $newPassword;
                        $resetUserName = $userName;
                        $resetUserIdentifiant = $userInfo ? $userInfo['identifiant'] : '';
                    } else {
                        $error = "Aucun utilisateur n'a été mis à jour. Veuillez vérifier l'ID utilisateur.";
                    }
                } catch (Exception $e) {
                    $error = "Erreur lors de la réinitialisation du mot de passe: " . $e->getMessage();
                    error_log($e->getMessage());
                }
            }
        }
    }
    
    // Renouveler le jeton CSRF après chaque soumission pour éviter les attaques par rejeu
    $_SESSION['reset_pwd_token'] = bin2hex(random_bytes(32));
    $csrf_token = $_SESSION['reset_pwd_token'];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des mots de passe - PRONOTE</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../accueil/assets/css/accueil.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .user-list {
            max-height: 600px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 20px;
        }
        
        .user-list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            background: #f5f5f5;
            border-bottom: 1px solid #ddd;
            font-weight: bold;
            position: sticky;
            top: 0;
        }
        
        .user-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 10px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .user-item:hover {
            background-color: #f9f9f9;
        }
        
        .user-item.selected {
            background-color: #e3f2fd;
        }
        
        .user-details {
            display: flex;
            align-items: center;
        }
        
        .user-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            margin-right: 10px;
        }
        
        .user-badge.eleve { background-color: #e3f2fd; color: #1976d2; }
        .user-badge.parent { background-color: #e8f5e9; color: #4caf50; }
        .user-badge.professeur { background-color: #fff3e0; color: #ff9800; }
        .user-badge.vie_scolaire { background-color: #f3e5f5; color: #9c27b0; }
        
        .reset-form {
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .search-container {
            display: flex;
            margin-bottom: 15px;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        
        .search-input {
            flex-grow: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .filter-container {
            display: flex;
            margin-bottom: 15px;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 8px 12px;
            border-radius: 16px;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        
        .filter-btn.active {
            font-weight: bold;
        }
        
        .filter-btn.all { background-color: #f5f5f5; color: #333; }
        .filter-btn.eleve { background-color: #e3f2fd; color: #1976d2; }
        .filter-btn.parent { background-color: #e8f5e9; color: #4caf50; }
        .filter-btn.professeur { background-color: #fff3e0; color: #ff9800; }
        .filter-btn.vie_scolaire { background-color: #f3e5f5; color: #9c27b0; }
        
        .password-options {
            margin-bottom: 20px;
        }
        
        .password-strength-meter {
            height: 10px;
            background-color: #eee;
            border-radius: 5px;
            margin-top: 5px;
            overflow: hidden;
        }
        
        .password-strength-meter div {
            height: 100%;
            border-radius: 5px;
            transition: width 0.3s, background-color 0.3s;
        }
        
        .password-requirements {
            margin-top: 10px;
            font-size: 14px;
            color: #666;
        }
        
        .requirement {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
        }
        
        .requirement i {
            margin-right: 5px;
            color: #ccc;
        }
        
        .requirement.valid i {
            color: #4caf50;
        }
        
        .alert-success {
            background-color: #e8f5e9;
            color: #2e7d32;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #4caf50;
        }
        
        .alert-error {
            background-color: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #e53935;
        }
        
        .credentials-info {
            background-color: #f5f5f5;
            border: 1px dashed #999;
            padding: 15px;
            margin: 15px 0;
            border-radius: 8px;
            font-family: monospace;
        }
    </style>
</head>
<body>

<div class="app-container">
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo-container">
            <div class="app-logo">P</div>
            <div class="app-title">PRONOTE</div>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-section-header">Navigation</div>
            <div class="sidebar-nav">
                <a href="../accueil/accueil.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-home"></i></span>
                    <span>Accueil</span>
                </a>
                <a href="../notes/notes.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-chart-bar"></i></span>
                    <span>Notes</span>
                </a>
                <a href="../agenda/agenda.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-calendar"></i></span>
                    <span>Agenda</span>
                </a>
                <a href="../cahierdetextes/cahierdetextes.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-book"></i></span>
                    <span>Cahier de textes</span>
                </a>
                <a href="../messagerie/index.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-envelope"></i></span>
                    <span>Messagerie</span>
                </a>
                <a href="../absences/absences.php" class="sidebar-nav-item">
                    <span class="sidebar-nav-icon"><i class="fas fa-calendar-times"></i></span>
                    <span>Absences</span>
                </a>
                <a href="../admin/reset_user_password.php" class="sidebar-nav-item active">
                    <span class="sidebar-nav-icon"><i class="fas fa-key"></i></span>
                    <span>Gestion des mots de passe</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="top-header">
            <div class="page-title">
                <h1>Gestion des mots de passe</h1>
            </div>
            
            <div class="header-actions">
                <a href="../login/public/register.php" class="admin-action-button" title="Inscrire un nouvel utilisateur">
                    <i class="fas fa-user-plus"></i>
                </a>
                <a href="/~u22405372/SAE/Pronote/login/public/logout.php" class="logout-button" title="Déconnexion">⏻</a>
                <div class="user-avatar"><?= $admin_initials ?></div>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="content-section">
            <?php if (!empty($error)): ?>
                <div class="alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($success) ?>
                    
                    <?php if (isset($resetPassword) && isset($resetUserName) && isset($resetUserIdentifiant)): ?>
                        <div class="credentials-info">
                            <p><strong>Utilisateur:</strong> <?= htmlspecialchars($resetUserName) ?></p>
                            <p><strong>Identifiant:</strong> <?= htmlspecialchars($resetUserIdentifiant) ?></p>
                            <p><strong>Nouveau mot de passe:</strong> <?= htmlspecialchars($resetPassword) ?></p>
                            <p class="warning">Veuillez communiquer ces informations à l'utilisateur de façon sécurisée.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="content-container">
                <div class="content-area">
                    <h2>Réinitialisation de mot de passe utilisateur</h2>
                    <p>Sélectionnez un utilisateur et réinitialisez son mot de passe. <strong>Note:</strong> Les mots de passe des administrateurs ne peuvent pas être réinitialisés par cette interface.</p>
                    
                    <div class="filter-container">
                        <button class="filter-btn all active" data-filter="all">Tous</button>
                        <button class="filter-btn eleve" data-filter="eleve">Élèves</button>
                        <button class="filter-btn parent" data-filter="parent">Parents</button>
                        <button class="filter-btn professeur" data-filter="professeur">Professeurs</button>
                        <button class="filter-btn vie_scolaire" data-filter="vie_scolaire">Vie Scolaire</button>
                    </div>
                    
                    <div class="search-container">
                        <input type="text" id="searchInput" class="search-input" placeholder="Rechercher un utilisateur...">
                    </div>
                    
                    <div class="user-list">
                        <div class="user-list-header">
                            <span>Nom</span>
                            <span>Identifiant</span>
                        </div>
                        <?php foreach ($users as $user): ?>
                            <div class="user-item" data-type="<?= htmlspecialchars($user['type']) ?>" data-table="<?= htmlspecialchars($user['table']) ?>" data-id="<?= htmlspecialchars($user['id']) ?>" data-name="<?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?>">
                                <div class="user-details">
                                    <span class="user-badge <?= htmlspecialchars($user['type']) ?>">
                                        <?= htmlspecialchars(ucfirst($user['type'])) ?>
                                    </span>
                                    <span><?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?></span>
                                </div>
                                <span><?= htmlspecialchars($user['identifiant']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="sidebar-content">
                    <div class="reset-form">
                        <h3>Réinitialiser le mot de passe</h3>
                        <form method="post" action="">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="user_type" id="selectedUserType" value="">
                            <input type="hidden" name="user_table" id="selectedUserTable" value="">
                            <input type="hidden" name="user_id" id="selectedUserId" value="">
                            
                            <div class="form-group">
                                <label>Utilisateur sélectionné:</label>
                                <div id="selectedUserDisplay" class="selected-user-display">Aucun utilisateur sélectionné</div>
                            </div>
                            
                            <div class="password-options">
                                <div class="form-group">
                                    <label>
                                        <input type="radio" name="use_generated_password" value="1" checked> 
                                        Générer un mot de passe aléatoire
                                    </label>
                                </div>
                                
                                <div class="form-group">
                                    <label>
                                        <input type="radio" name="use_generated_password" value="0"> 
                                        Définir un mot de passe personnalisé
                                    </label>
                                </div>
                            </div>
                            
                            <div id="customPasswordFields" style="display: none;">
                                <div class="form-group">
                                    <label for="new_password">Nouveau mot de passe:</label>
                                    <input type="password" id="new_password" name="new_password" class="form-control">
                                    
                                    <div class="password-strength-meter">
                                        <div id="strengthBar"></div>
                                    </div>
                                    <div id="strengthText" class="strength-text">Force du mot de passe</div>
                                    
                                    <div class="password-requirements">
                                        <div class="requirement" id="lengthReq">
                                            <i class="fas fa-circle"></i> Au moins <?= MIN_PWD_LENGTH ?> caractères
                                        </div>
                                        <div class="requirement" id="uppercaseReq">
                                            <i class="fas fa-circle"></i> Au moins une lettre majuscule
                                        </div>
                                        <div class="requirement" id="lowercaseReq">
                                            <i class="fas fa-circle"></i> Au moins une lettre minuscule
                                        </div>
                                        <div class="requirement" id="numberReq">
                                            <i class="fas fa-circle"></i> Au moins un chiffre
                                        </div>
                                        <div class="requirement" id="specialReq">
                                            <i class="fas fa-circle"></i> Au moins un caractère spécial
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="confirm_password">Confirmer le mot de passe:</label>
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-control">
                                    <div id="passwordMatch" class="password-match"></div>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" name="reset_password" class="btn btn-primary" id="resetBtn" disabled>
                                    <i class="fas fa-key"></i> Réinitialiser le mot de passe
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Référence aux éléments du DOM
    const searchInput = document.getElementById('searchInput');
    const userItems = document.querySelectorAll('.user-item');
    const filterButtons = document.querySelectorAll('.filter-btn');
    const passwordOptions = document.querySelectorAll('input[name="use_generated_password"]');
    const customPasswordFields = document.getElementById('customPasswordFields');
    const newPasswordInput = document.getElementById('new_password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');
    const passwordMatch = document.getElementById('passwordMatch');
    const resetButton = document.getElementById('resetBtn');
    const selectedUserType = document.getElementById('selectedUserType');
    const selectedUserTable = document.getElementById('selectedUserTable');
    const selectedUserId = document.getElementById('selectedUserId');
    const selectedUserDisplay = document.getElementById('selectedUserDisplay');
    
    // Vérification des exigences de mot de passe
    const lengthReq = document.getElementById('lengthReq');
    const uppercaseReq = document.getElementById('uppercaseReq');
    const lowercaseReq = document.getElementById('lowercaseReq');
    const numberReq = document.getElementById('numberReq');
    const specialReq = document.getElementById('specialReq');
    
    // Recherche d'utilisateurs
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        
        userItems.forEach(function(item) {
            const userName = item.getAttribute('data-name').toLowerCase();
            const userType = item.getAttribute('data-type').toLowerCase();
            const display = userName.includes(searchTerm) ? '' : 'none';
            
            // Vérifier également le filtre actuel
            const activeFilter = document.querySelector('.filter-btn.active').getAttribute('data-filter');
            if (activeFilter !== 'all' && activeFilter !== userType) {
                item.style.display = 'none';
            } else {
                item.style.display = display;
            }
        });
    });
    
    // Filtrage par type d'utilisateur
    filterButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            const filter = this.getAttribute('data-filter');
            
            filterButtons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            
            userItems.forEach(function(item) {
                const userType = item.getAttribute('data-type');
                const userName = item.getAttribute('data-name').toLowerCase();
                const searchTerm = searchInput.value.toLowerCase();
                
                if ((filter === 'all' || filter === userType) && userName.includes(searchTerm)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    });
    
    // Sélection d'un utilisateur
    userItems.forEach(function(item) {
        item.addEventListener('click', function() {
            userItems.forEach(i => i.classList.remove('selected'));
            this.classList.add('selected');
            
            const userType = this.getAttribute('data-type');
            const userTable = this.getAttribute('data-table');
            const userId = this.getAttribute('data-id');
            const userName = this.getAttribute('data-name');
            
            selectedUserType.value = userType;
            selectedUserTable.value = userTable;
            selectedUserId.value = userId;
            selectedUserDisplay.textContent = userName + ' (' + userType + ')';
            
            // Activer le bouton de réinitialisation
            resetButton.disabled = false;
        });
    });
    
    // Gestion des options de mot de passe
    passwordOptions.forEach(function(option) {
        option.addEventListener('change', function() {
            if (this.value === '1') { // Option de génération automatique
                customPasswordFields.style.display = 'none';
            } else { // Option de mot de passe personnalisé
                customPasswordFields.style.display = 'block';
            }
        });
    });
    
    // Vérification de la force du mot de passe
    newPasswordInput?.addEventListener('input', function() {
        checkPasswordStrength();
        checkPasswordMatch();
    });
    
    confirmPasswordInput?.addEventListener('input', checkPasswordMatch);
    
    function checkPasswordStrength() {
        const password = newPasswordInput.value;
        let strength = 0;
        let status = {
            length: password.length >= <?= MIN_PWD_LENGTH ?>,
            uppercase: /[A-Z]/.test(password),
            lowercase: /[a-z]/.test(password),
            number: /[0-9]/.test(password),
            special: /[^A-Za-z0-9]/.test(password)
        };
        
        // Calculer la force du mot de passe
        if (status.length) strength += 20;
        if (status.uppercase) strength += 20;
        if (status.lowercase) strength += 20;
        if (status.number) strength += 20;
        if (status.special) strength += 20;
        
        // Mise à jour de la barre de force
        strengthBar.style.width = strength + '%';
        
        // Mise à jour de la couleur selon la force
        if (strength <= 40) {
            strengthBar.style.backgroundColor = '#f44336'; // Rouge
            strengthText.textContent = 'Faible';
        } else if (strength <= 80) {
            strengthBar.style.backgroundColor = '#ff9800'; // Orange
            strengthText.textContent = 'Moyen';
        } else {
            strengthBar.style.backgroundColor = '#4caf50'; // Vert
            strengthText.textContent = 'Fort';
        }
        
        // Mise à jour des indicateurs d'exigence
        updateRequirementStatus(lengthReq, status.length);
        updateRequirementStatus(uppercaseReq, status.uppercase);
        updateRequirementStatus(lowercaseReq, status.lowercase);
        updateRequirementStatus(numberReq, status.number);
        updateRequirementStatus(specialReq, status.special);
    }
    
    function updateRequirementStatus(element, isMet) {
        const icon = element.querySelector('i');
        
        if (isMet) {
            element.classList.add('valid');
            icon.className = 'fas fa-check-circle';
        } else {
            element.classList.remove('valid');
            icon.className = 'fas fa-circle';
        }
    }
    
    function checkPasswordMatch() {
        if (!confirmPasswordInput.value) {
            passwordMatch.textContent = '';
            return;
        }
        
        if (newPasswordInput.value === confirmPasswordInput.value) {
            passwordMatch.textContent = 'Les mots de passe correspondent';
            passwordMatch.style.color = '#4caf50';
        } else {
            passwordMatch.textContent = 'Les mots de passe ne correspondent pas';
            passwordMatch.style.color = '#f44336';
        }
    }
});
</script>

</body>
</html>
