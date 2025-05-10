<?php
// /includes/class_functions.php - Fonctions pour la gestion des classes

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/message_functions.php';

/**
 * Récupère les membres d'une classe
 * @param string $classeId ID de la classe
 * @param bool $includeEleves Inclure les élèves
 * @param bool $includeParents Inclure les parents
 * @param bool $includeProfesseurs Inclure les professeurs
 * @return array Membres de la classe
 */
function getClassMembers($classeId, $includeEleves = true, $includeParents = false, $includeProfesseurs = false) {
    global $pdo;
    $members = [];
    
    // Récupérer les élèves de la classe
    if ($includeEleves) {
        $eleves = $pdo->prepare("
            SELECT id, 'eleve' as type, CONCAT(prenom, ' ', nom) as nom_complet 
            FROM eleves WHERE classe = ?
        ");
        $eleves->execute([$classeId]);
        $members = array_merge($members, $eleves->fetchAll());
    }
    
    // Récupérer les parents (dans la nouvelle DB, la relation parent-élève n'est pas explicite)
    if ($includeParents) {
        $parents = $pdo->prepare("
            SELECT id, 'parent' as type, CONCAT(prenom, ' ', nom) as nom_complet
            FROM parents WHERE est_parent_eleve = 'oui'
        ");
        $parents->execute();
        $members = array_merge($members, $parents->fetchAll());
    }
    
    // Récupérer les professeurs de la classe
    if ($includeProfesseurs) {
        $professeurs = $pdo->prepare("
            SELECT id, 'professeur' as type, CONCAT(prenom, ' ', nom) as nom_complet
            FROM professeurs 
            WHERE professeur_principal = ? OR professeur_principal = 'oui'
        ");
        $professeurs->execute([$classeId]);
        $members = array_merge($members, $professeurs->fetchAll());
    }
    
    return $members;
}

/**
 * Envoie un message à toute une classe
 * @param int $professeurId ID du professeur
 * @param string $classeId ID de la classe
 * @param string $titre Titre du message
 * @param string $contenu Contenu du message
 * @param string $importance Importance du message
 * @param bool $notificationObligatoire Si la notification est obligatoire
 * @param bool $includeParents Inclure les parents dans les destinataires
 * @param array $filesData Données des fichiers joints
 * @return int ID de la conversation créée
 */
function sendMessageToClass($professeurId, $classeId, $titre, $contenu, $importance = 'normal', 
                          $notificationObligatoire = false, $includeParents = false, $filesData = []) {
    
    // Récupérer les membres de la classe
    $members = getClassMembers($classeId, true, $includeParents, false);
    
    // Formater les participants pour createConversation
    $participants = [];
    foreach ($members as $member) {
        // Ne pas inclure le créateur dans les participants (il sera ajouté automatiquement)
        if ($member['id'] != $professeurId || $member['type'] != 'professeur') {
            $participants[] = ['id' => $member['id'], 'type' => $member['type']];
        }
    }
    
    // Créer la conversation
    $convId = createConversation($titre, 'classe', $professeurId, 'professeur', $participants);
    
    // Envoyer le message initial
    $messageId = addMessage(
        $convId, 
        $professeurId, 
        'professeur', 
        $contenu, 
        $importance, 
        false, // Est annonce
        $notificationObligatoire, 
        false, // Accusé de réception
        null, // Parent message ID
        'standard', // Type message
        $filesData
    );
    
    return $convId;
}

/**
 * Récupère les classes disponibles
 * @return array Liste des classes
 */
function getAvailableClasses() {
    global $pdo;
    
    // D'abord essayer de récupérer les classes depuis le fichier établissement.json
    $etablissementFile = dirname(__DIR__) . '/../login/data/etablissement.json';
    $classes = [];

    if (file_exists($etablissementFile)) {
        $etablissement = json_decode(file_get_contents($etablissementFile), true);
        if (isset($etablissement['classes']) && is_array($etablissement['classes'])) {
            $classes = $etablissement['classes'];
        }
    } 
    
    // Si pas de classes dans le fichier, récupérer depuis la base de données
    if (empty($classes)) {
        $query = $pdo->query("SELECT DISTINCT classe FROM eleves ORDER BY classe");
        $classes = $query->fetchAll(PDO::FETCH_COLUMN);
    }
    
    return $classes;
}

/**
 * Vérifie si un professeur est professeur principal d'une classe
 * @param int $professeurId ID du professeur
 * @param string $classeId ID de la classe
 * @return bool True si le professeur est professeur principal
 */
function isProfesseurPrincipal($professeurId, $classeId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT id FROM professeurs
        WHERE id = ? AND (professeur_principal = ? OR professeur_principal = 'oui')
    ");
    $stmt->execute([$professeurId, $classeId]);
    
    return $stmt->fetch() !== false;
}

/**
 * Vérifie si un professeur enseigne dans une classe
 * @param int $professeurId ID du professeur
 * @param string $classeId ID de la classe
 * @return bool True si le professeur enseigne dans la classe
 */
function isTeachingClass($professeurId, $classeId) {
    global $pdo;
    
    // Cette fonction dépend de la structure de la base de données
    // À adapter selon votre schéma
    $stmt = $pdo->prepare("
        SELECT id FROM professeurs
        WHERE id = ? AND classe_enseignee LIKE ?
    ");
    $stmt->execute([$professeurId, "%$classeId%"]);
    
    return $stmt->fetch() !== false || isProfesseurPrincipal($professeurId, $classeId);
}