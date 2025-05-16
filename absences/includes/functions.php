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
    
    try {
        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute([
            $data['id_eleve'],
            $data['date_debut'],
            $data['date_fin'],
            $data['type_absence'],
            $data['motif'] ?? null,
            isset($data['justifie']) ? $data['justifie'] : false,
            $data['commentaire'] ?? null,
            $data['signale_par']
        ]);
        
        if ($success) {
            return $pdo->lastInsertId();
        } else {
            return false;
        }
    } catch (PDOException $e) {
        error_log("Erreur lors de l'ajout d'une absence: " . $e->getMessage());
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
?>