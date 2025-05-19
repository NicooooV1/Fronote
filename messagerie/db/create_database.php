<?php
/**
 * Script d'initialisation et de mise à jour de la base de données
 */
require_once __DIR__ . '/../config/config.php';

// Afficher les erreurs pendant l'exécution de ce script
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Vérifier la connexion à la base de données
if (!isset($pdo)) {
    die("Erreur: La connexion à la base de données n'est pas disponible.");
}

// Fonction pour exécuter une requête SQL en toute sécurité
function executeSQL($sql, $params = []) {
    global $pdo;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return true;
    } catch (PDOException $e) {
        echo "Erreur SQL: " . $e->getMessage() . "<br>";
        return false;
    }
}

// Fonction pour vérifier si une colonne existe dans une table
function columnExists($table, $column) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT 1 FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return $stmt->fetchColumn() ? true : false;
    } catch (PDOException $e) {
        echo "Erreur lors de la vérification de la colonne: " . $e->getMessage() . "<br>";
        return false;
    }
}

echo "<h1>Script de mise à jour de la base de données</h1>";

// Vérifier et ajouter la colonne is_deleted à la table messages si elle n'existe pas
if (!columnExists('messages', 'is_deleted')) {
    echo "Ajout de la colonne is_deleted à la table messages...<br>";
    
    if (executeSQL("ALTER TABLE messages ADD COLUMN is_deleted BOOLEAN NOT NULL DEFAULT 0")) {
        echo "Colonne is_deleted ajoutée avec succès.<br>";
    } else {
        echo "Échec de l'ajout de la colonne is_deleted.<br>";
    }
} else {
    echo "La colonne is_deleted existe déjà dans la table messages.<br>";
}

// Vérifier et ajouter la colonne version à la table conversation_participants si elle n'existe pas
if (!columnExists('conversation_participants', 'version')) {
    echo "Ajout de la colonne version à la table conversation_participants...<br>";
    
    if (executeSQL("ALTER TABLE conversation_participants ADD COLUMN version INT NOT NULL DEFAULT 1")) {
        echo "Colonne version ajoutée avec succès.<br>";
    } else {
        echo "Échec de l'ajout de la colonne version.<br>";
    }
} else {
    echo "La colonne version existe déjà dans la table conversation_participants.<br>";
}

// Vérifier et ajouter la colonne type à la table conversations si elle n'existe pas
if (!columnExists('conversations', 'type')) {
    echo "Ajout de la colonne type à la table conversations...<br>";
    
    if (executeSQL("ALTER TABLE conversations ADD COLUMN type VARCHAR(50) DEFAULT 'standard'")) {
        echo "Colonne type ajoutée avec succès.<br>";
        
        // Mettre à jour les types de conversations existantes
        echo "Mise à jour des types de conversation basés sur les messages...<br>";
        $updateSQL = "
            UPDATE conversations c
            SET c.type = 
                CASE 
                    WHEN EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = c.id AND m.status = 'annonce') THEN 'annonce'
                    ELSE 'standard'
                END
        ";
        
        if (executeSQL($updateSQL)) {
            echo "Types de conversations mis à jour avec succès.<br>";
        } else {
            echo "Échec de la mise à jour des types de conversations.<br>";
        }
    } else {
        echo "Échec de l'ajout de la colonne type.<br>";
    }
} else {
    echo "La colonne type existe déjà dans la table conversations.<br>";
}

echo "<p>Mise à jour terminée.</p>";

// Ajouter un lien pour retourner à l'application
echo '<p><a href="../index.php" style="display: inline-block; padding: 8px 12px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 4px;">Retourner à l\'application</a></p>';
?>
