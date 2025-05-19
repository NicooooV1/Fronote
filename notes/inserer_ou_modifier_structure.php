<?php
/**
 * Script pour vérifier et corriger la structure de la base de données des notes
 */

// Inclure les fichiers de base
require_once __DIR__ . '/includes/db.php';
session_start();

// Vérifier si l'utilisateur est administrateur
if (!isset($_SESSION['user']) || $_SESSION['user']['profil'] !== 'administrateur') {
    die("Accès non autorisé. Vous devez être administrateur pour exécuter ce script.");
}

// Fonction pour vérifier si une colonne existe
function columnExists($table, $column) {
    global $pdo;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $stmt && $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Fonction pour vérifier si une table existe
function tableExists($table) {
    global $pdo;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        return $stmt && $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// En-tête HTML
echo '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance de la base de données - Notes</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Maintenance de la base de données - Notes</h1>
';

// Vérifier l'existence de la table notes
if (!tableExists('notes')) {
    echo '<div class="info">La table "notes" n\'existe pas. Création en cours...</div>';
    
    try {
        $sql = "CREATE TABLE `notes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `eleve_id` INT NOT NULL,
            `nom_eleve` VARCHAR(100) NOT NULL,
            `classe` VARCHAR(50) NOT NULL,
            `matiere` VARCHAR(100) NOT NULL,
            `note` FLOAT NOT NULL,
            `note_sur` FLOAT NOT NULL DEFAULT 20,
            `commentaire` TEXT,
            `nom_professeur` VARCHAR(100) NOT NULL,
            `date_creation` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `date_evaluation` DATE DEFAULT NULL,
            `coefficient` INT DEFAULT 1,
            `description` VARCHAR(255),
            `trimestre` INT DEFAULT 1
        )";
        
        $pdo->exec($sql);
        echo '<div class="success">Table "notes" créée avec succès !</div>';
    } catch (PDOException $e) {
        echo '<div class="error">Erreur lors de la création de la table: ' . $e->getMessage() . '</div>';
    }
} else {
    echo '<div class="info">La table "notes" existe déjà.</div>';
    
    // Vérifier les colonnes obligatoires
    $requiredColumns = [
        'matiere' => 'VARCHAR(100) NOT NULL',
        'date_evaluation' => 'DATE DEFAULT NULL',
        'date_creation' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'coefficient' => 'INT DEFAULT 1',
        'description' => 'VARCHAR(255)',
        'trimestre' => 'INT DEFAULT 1'
    ];
    
    foreach ($requiredColumns as $column => $definition) {
        if (!columnExists('notes', $column)) {
            echo '<div class="info">Ajout de la colonne "' . $column . '"...</div>';
            
            try {
                $pdo->exec("ALTER TABLE notes ADD COLUMN `$column` $definition");
                echo '<div class="success">Colonne "' . $column . '" ajoutée avec succès !</div>';
            } catch (PDOException $e) {
                echo '<div class="error">Erreur lors de l\'ajout de la colonne "' . $column . '": ' . $e->getMessage() . '</div>';
            }
        } else {
            echo '<div class="info">La colonne "' . $column . '" existe déjà.</div>';
        }
    }
    
    // Vérifier si nom_matiere existe et la remplacer par matiere si nécessaire
    if (columnExists('notes', 'nom_matiere') && columnExists('notes', 'matiere')) {
        echo '<div class="info">Les colonnes "nom_matiere" et "matiere" existent toutes les deux. Migration des données...</div>';
        
        try {
            // Mettre à jour les enregistrements où matiere est NULL mais nom_matiere ne l'est pas
            $pdo->exec("UPDATE notes SET matiere = nom_matiere WHERE matiere IS NULL AND nom_matiere IS NOT NULL");
            echo '<div class="success">Données migrées avec succès !</div>';
            
            // Supprimer la colonne nom_matiere
            $pdo->exec("ALTER TABLE notes DROP COLUMN nom_matiere");
            echo '<div class="success">Colonne "nom_matiere" supprimée avec succès !</div>';
        } catch (PDOException $e) {
            echo '<div class="error">Erreur lors de la migration: ' . $e->getMessage() . '</div>';
        }
    } else if (columnExists('notes', 'nom_matiere') && !columnExists('notes', 'matiere')) {
        echo '<div class="info">Renommage de la colonne "nom_matiere" en "matiere"...</div>';
        
        try {
            $pdo->exec("ALTER TABLE notes CHANGE nom_matiere matiere VARCHAR(100) NOT NULL");
            echo '<div class="success">Colonne renommée avec succès !</div>';
        } catch (PDOException $e) {
            echo '<div class="error">Erreur lors du renommage: ' . $e->getMessage() . '</div>';
        }
    }
}

// Afficher la structure actuelle
try {
    $stmt = $pdo->query("DESCRIBE notes");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo '<h2>Structure actuelle de la table "notes"</h2>';
    echo '<pre>';
    foreach ($columns as $column) {
        echo $column['Field'] . ' ' . $column['Type'];
        if ($column['Null'] === 'NO') {
            echo ' NOT NULL';
        }
        if ($column['Default'] !== null) {
            echo ' DEFAULT ' . $column['Default'];
        }
        echo "\n";
    }
    echo '</pre>';
} catch (PDOException $e) {
    echo '<div class="error">Erreur lors de la récupération de la structure: ' . $e->getMessage() . '</div>';
}

// Lien de retour
echo '<p><a href="notes.php">Retour à la gestion des notes</a></p>';

// Pied de page HTML
echo '</body></html>';
?>
