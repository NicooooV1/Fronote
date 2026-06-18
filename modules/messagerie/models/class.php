<?php
/**
 * Modèle pour la gestion des classes
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/utils.php';
require_once __DIR__ . '/conversation.php';
require_once __DIR__ . '/message.php';

/**
 * Récupère les membres d'une classe
 * @param string $classeId
 * @param bool $includeEleves
 * @param bool $includeParents
 * @param bool $includeProfesseurs
 * @return array
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
    
    // Récupérer UNIQUEMENT les parents des élèves de la classe via la table de
    // liaison parent_eleve. $classeId est le NOM de la classe (eleves.classe = nom).
    if ($includeParents) {
        $parents = $pdo->prepare("
            SELECT DISTINCT p.id, 'parent' as type, CONCAT(p.prenom, ' ', p.nom) as nom_complet
            FROM parents p
            JOIN parent_eleve pe ON pe.id_parent = p.id
            JOIN eleves e ON e.id = pe.id_eleve
            WHERE e.classe = ? AND p.est_parent_eleve = 'oui'
        ");
        $parents->execute([$classeId]);
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
 * @param int $professeurId
 * @param string $classeId
 * @param string $titre
 * @param string $contenu
 * @param string $importance
 * @param bool $notificationObligatoire
 * @param bool $includeParents
 * @param array $filesData
 * @return int
 */
function sendMessageToClass($professeurId, $classeId, $titre, $contenu, $importance = 'normal', 
                           $notificationObligatoire = false, $includeParents = false, $filesData = []) {
    
    // Récupérer les membres de la classe
    $members = getClassMembers($classeId, true, $includeParents, false);
    
    // Formater les participants
    $participants = [];
    foreach ($members as $member) {
        // Ne pas inclure le créateur dans les participants
        if ($member['id'] != $professeurId || $member['type'] != 'professeur') {
            $participants[] = ['id' => $member['id'], 'type' => $member['type']];
        }
    }
    
    // Créer la conversation
    $convId = createConversation($titre, 'classe', $professeurId, 'professeur', $participants);
    
    // Envoyer le message initial
    addMessage(
        $convId,
        $professeurId,
        'professeur',
        $contenu,
        $importance,            // importance
        false,                  // estAnnonce
        $notificationObligatoire,
        null,                   // parentMessageId
        'standard',             // typeMessage
        $filesData
    );
    
    return $convId;
}

/**
 * Récupère les classes disponibles
 * @return array
 */
function getAvailableClasses() {
    global $pdo;
    
    // D'abord essayer de récupérer les classes depuis le fichier établissement.json
    $etablissementFile = dirname(__DIR__, 3) . '/login/data/etablissement.json';
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