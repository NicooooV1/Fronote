<?php
/**
 * Fonctions utilitaires pour la gestion des absences et retards
 */

/**
 * Récupère la liste des absences d'un élève
 * 
 * @param PDO $pdo Connexion à la base de données
 * @param int $id_eleve ID de l'élève
 * @param string $date_debut Date de début (optionnel)
 * @param string $date_fin Date de fin (optionnel)
 * @return array Liste des absences
 */
function getAbsencesEleve($pdo, $id_eleve, $date_debut = null, $date_fin = null) {
    $params = [$id_eleve];
    $sql = "SELECT a.*, e.nom, e.prenom, e.classe 
            FROM absences a 
            JOIN eleves e ON a.id_eleve = e.id 
            WHERE a.id_eleve = ?";
    
    if ($date_debut) {
        $sql .= " AND a.date_debut >= ?";
        $params[] = $date_debut;
    }
    
    if ($date_fin) {
        $sql .= " AND a.date_debut <= ?";
        $params[] = $date_fin;
    }
    
    $sql .= " ORDER BY a.date_debut DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Récupère la liste des absences pour une classe
 * 
 * @param PDO $pdo Connexion à la base de données
 * @param string $classe Classe concernée
 * @param string $date_debut Date de début (optionnel)
 * @param string $date_fin Date de fin (optionnel)
 * @return array Liste des absences
 */
function getAbsencesClasse($pdo, $classe, $date_debut = null, $date_fin = null) {
    $params = [$classe];
    $sql = "SELECT a.*, e.nom, e.prenom, e.classe 
            FROM absences a 
            JOIN eleves e ON a.id_eleve = e.id 
            WHERE e.classe = ?";
    
    if ($date_debut) {
        $sql .= " AND a.date_debut >= ?";
        $params[] = $date_debut;
    }
    
    if ($date_fin) {
        $sql .= " AND a.date_debut <= ?";
        $params[] = $date_fin;
    }
    
    $sql .= " ORDER BY e.nom, e.prenom, a.date_debut DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ajoute une absence dans la base de données
 * 
 * @param PDO $pdo Connexion à la base de données
 * @param array $data Données de l'absence
 * @return bool|int ID de l'absence créée ou false en cas d'erreur
 */
function ajouterAbsence($pdo, $data) {
    try {
        // Enable PDO error mode for debugging
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Check if all required fields are present
        $required_fields = ['id_eleve', 'date_debut', 'date_fin', 'type_absence', 'signale_par'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                error_log("Missing required field for absence: $field");
                return false;
            }
        }
        
        // Set default values for optional fields
        $data['motif'] = isset($data['motif']) ? $data['motif'] : null;
        $data['justifie'] = isset($data['justifie']) ? $data['justifie'] : false;
        $data['commentaire'] = isset($data['commentaire']) ? $data['commentaire'] : null;
        
        // Verify the SQL table structure and adjust the query accordingly
        $sql = "INSERT INTO absences (
                    id_eleve, 
                    date_debut, 
                    date_fin, 
                    type_absence, 
                    motif, 
                    justifie, 
                    commentaire, 
                    signale_par
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        
        // Log the data we're trying to insert
        error_log("Attempting to add absence with data: " . json_encode($data));
        
        $success = $stmt->execute([
            $data['id_eleve'],
            $data['date_debut'],
            $data['date_fin'],
            $data['type_absence'],
            $data['motif'],
            $data['justifie'] ? 1 : 0, // Convert boolean to int for MySQL
            $data['commentaire'],
            $data['signale_par']
        ]);
        
        if ($success) {
            $id = $pdo->lastInsertId();
            error_log("Successfully added absence with ID: $id");
            return $id;
        } else {
            error_log("Failed to add absence: " . json_encode($stmt->errorInfo()));
            return false;
        }
    } catch (PDOException $e) {
        error_log("PDO Exception when adding absence: " . $e->getMessage());
        // Display more detailed error for development
        error_log("Error details: " . $e->getTraceAsString());
        return false;
    } catch (Exception $e) {
        error_log("General Exception when adding absence: " . $e->getMessage());
        return false;
    }
}

/**
 * Récupère les détails d'une absence
 * 
 * @param PDO $pdo Connexion à la base de données
 * @param int $id ID de l'absence
 * @return array|bool Détails de l'absence ou false si non trouvée
 */
function getAbsenceById($pdo, $id) {
    $sql = "SELECT a.*, e.nom, e.prenom, e.classe 
            FROM absences a 
            JOIN eleves e ON a.id_eleve = e.id 
            WHERE a.id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Met à jour une absence
 * 
 * @param PDO $pdo Connexion à la base de données
 * @param int $id ID de l'absence
 * @param array $data Nouvelles données
 * @return bool Succès de la mise à jour
 */
function modifierAbsence($pdo, $id, $data) {
    $sql = "UPDATE absences SET 
                date_debut = ?, 
                date_fin = ?, 
                type_absence = ?, 
                motif = ?, 
                justifie = ?, 
                commentaire = ? 
            WHERE id = ?";
    
    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            $data['date_debut'],
            $data['date_fin'],
            $data['type_absence'],
            $data['motif'] ?? null,
            isset($data['justifie']) ? $data['justifie'] : false,
            $data['commentaire'] ?? null,
            $id
        ]);
    } catch (PDOException $e) {
        error_log("Erreur lors de la modification d'une absence: " . $e->getMessage());
        return false;
    }
}

/**
 * Supprime une absence
 * 
 * @param PDO $pdo Connexion à la base de données
 * @param int $id ID de l'absence
 * @return bool Succès de la suppression
 */
function supprimerAbsence($pdo, $id) {
    $sql = "DELETE FROM absences WHERE id = ?";
    
    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$id]);
    } catch (PDOException $e) {
        error_log("Erreur lors de la suppression d'une absence: " . $e->getMessage());
        return false;
    }
}

/**
 * Fonctions similaires pour les retards...
 */
function getRetardsEleve($pdo, $id_eleve, $date_debut = null, $date_fin = null) {
    // Similaire à getAbsencesEleve mais pour les retards
}

function ajouterRetard($pdo, $data) {
    // Similaire à ajouterAbsence mais pour les retards
}

/**
 * Récupère les retards pour une classe
 * 
 * @param PDO $pdo Connexion à la base de données
 * @param string $classe Classe concernée
 * @param string $date_debut Date de début (optionnel)
 * @param string $date_fin Date de fin (optionnel)
 * @return array Liste des retards
 */
function getRetardsClasse($pdo, $classe, $date_debut = null, $date_fin = null) {
    $params = [$classe];
    $sql = "SELECT r.*, e.nom, e.prenom, e.classe 
            FROM retards r 
            JOIN eleves e ON r.id_eleve = e.id 
            WHERE e.classe = ?";
    
    if ($date_debut) {
        $sql .= " AND r.date >= ?";
        $params[] = $date_debut;
    }
    
    if ($date_fin) {
        $sql .= " AND r.date <= ?";
        $params[] = $date_fin;
    }
    
    $sql .= " ORDER BY e.nom, e.prenom, r.date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fonctions pour les justificatifs
 */
function ajouterJustificatif($pdo, $data) {
    // Code pour ajouter un justificatif
}

function getJustificatifsEleve($pdo, $id_eleve) {
    // Code pour récupérer les justificatifs d'un élève
}

/**
 * Vérifie si l'utilisateur est autorisé à gérer les absences
 * 
 * @return bool
 */
function canManageAbsences() {
    return (isTeacher() || isAdmin() || isVieScolaire());
}

/**
 * Calcule les statistiques d'absences pour un élève
 * 
 * @param PDO $pdo Connexion à la base de données
 * @param int $id_eleve ID de l'élève
 * @param string $periode Période (mois, trimestre, annee)
 * @return array Statistiques
 */
function calculerStatistiquesAbsences($pdo, $id_eleve, $periode = 'annee') {
    // Code pour calculer les statistiques d'absences
    // (nombre d'absences, durée totale, par matière, etc.)
}

/**
 * Crée une table absences dans la base de données si elle n'existe pas
 * 
 * @param PDO $pdo Connexion à la base de données
 * @return bool Succès de la création
 */
function createAbsencesTableIfNotExists($pdo) {
    try {
        $sql = "CREATE TABLE IF NOT EXISTS absences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_eleve INT NOT NULL,
            date_debut DATETIME NOT NULL,
            date_fin DATETIME NOT NULL,
            type_absence VARCHAR(20) NOT NULL,
            motif VARCHAR(100) NULL,
            justifie BOOLEAN DEFAULT FALSE,
            commentaire TEXT NULL,
            signale_par VARCHAR(100) NOT NULL,
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        
        return $pdo->exec($sql) !== false;
    } catch (PDOException $e) {
        error_log("Error creating absences table: " . $e->getMessage());
        return false;
    }
}

/**
 * Crée une table retards dans la base de données si elle n'existe pas
 * 
 * @param PDO $pdo Connexion à la base de données
 * @return bool Succès de la création
 */
function createRetardsTableIfNotExists($pdo) {
    try {
        $sql = "CREATE TABLE IF NOT EXISTS retards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_eleve INT NOT NULL,
            date DATETIME NOT NULL,
            duree INT NOT NULL,
            motif VARCHAR(100) NULL,
            justifie BOOLEAN DEFAULT FALSE,
            commentaire TEXT NULL,
            signale_par VARCHAR(100) NOT NULL,
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        
        return $pdo->exec($sql) !== false;
    } catch (PDOException $e) {
        error_log("Error creating retards table: " . $e->getMessage());
        return false;
    }
}

/**
 * Crée une table professeur_classes dans la base de données si elle n'existe pas
 * 
 * @param PDO $pdo Connexion à la base de données
 * @return bool Succès de la création
 */
function createProfesseurClassesTableIfNotExists($pdo) {
    try {
        $sql = "CREATE TABLE IF NOT EXISTS professeur_classes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_professeur INT NOT NULL,
            nom_classe VARCHAR(50) NOT NULL,
            UNIQUE KEY unique_prof_class (id_professeur, nom_classe)
        )";
        
        return $pdo->exec($sql) !== false;
    } catch (PDOException $e) {
        error_log("Error creating professeur_classes table: " . $e->getMessage());
        return false;
    }
}

/**
 * Vérifie si les classes existent déjà dans la table professeur_classes
 * Si non, initialise la table avec les données des professeurs
 * Cette fonction est provisoire pour la migration
 */
function initializeProfesseurClasses($pdo) {
    try {
        // Check if table is empty
        $stmt = $pdo->query("SELECT COUNT(*) FROM professeur_classes");
        $count = $stmt->fetchColumn();
        
        if ($count == 0) {
            // Table is empty, let's get data from professeurs table if it exists
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM professeurs LIKE 'classe'");
                $column_exists = $stmt->fetch();
                
                if ($column_exists) {
                    // Old structure with 'classe' column
                    $stmt = $pdo->query("SELECT id, classe FROM professeurs WHERE classe IS NOT NULL AND classe != ''");
                    $profs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($profs as $prof) {
                        // A professor might teach multiple classes separated by commas
                        $classes = explode(',', $prof['classe']);
                        foreach ($classes as $classe) {
                            $classe = trim($classe);
                            if (!empty($classe)) {
                                $insert = $pdo->prepare("INSERT IGNORE INTO professeur_classes (id_professeur, nom_classe) VALUES (?, ?)");
                                $insert->execute([$prof['id'], $classe]);
                            }
                        }
                    }
                    
                    error_log("Initialized professeur_classes table from professeurs.classe");
                } else {
                    // Create a test entry for debugging
                    error_log("No 'classe' column in professeurs table, creating test data");
                    $insert = $pdo->prepare("INSERT IGNORE INTO professeur_classes (id_professeur, nom_classe) VALUES (1, '6A'), (1, '5B')");
                    $insert->execute();
                }
            } catch (PDOException $e) {
                error_log("Error migrating professeur classes: " . $e->getMessage());
            }
        }
        return true;
    } catch (PDOException $e) {
        error_log("Error initializing professeur_classes: " . $e->getMessage());
        return false;
    }
}

// Try to create tables on include
try {
    createAbsencesTableIfNotExists($pdo);
    createRetardsTableIfNotExists($pdo);
    createProfesseurClassesTableIfNotExists($pdo);
    initializeProfesseurClasses($pdo);
} catch (Exception $e) {
    error_log("Error initializing tables: " . $e->getMessage());
}
?>