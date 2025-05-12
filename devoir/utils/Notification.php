<?php
/**
 * Classe pour la gestion des notifications
 */
class Notification {
    private $db;
    
    /**
     * Constructeur
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Crée une nouvelle notification
     * @param array $data Données de la notification
     * @return int|false ID de la notification créée ou false en cas d'échec
     */
    public function createNotification($data) {
        // Valider les données requises
        if (empty($data['destinataire_id']) || empty($data['titre']) || empty($data['contenu'])) {
            return false;
        }
        
        // Préparer les données
        $notificationData = [
            'destinataire_id' => $data['destinataire_id'],
            'titre' => $data['titre'],
            'contenu' => $data['contenu'],
            'date_creation' => date('Y-m-d H:i:s'),
            'lu' => 0
        ];
        
        // Ajouter les références optionnelles
        if (!empty($data['devoir_id'])) {
            $notificationData['devoir_id'] = $data['devoir_id'];
        }
        
        if (!empty($data['seance_id'])) {
            $notificationData['seance_id'] = $data['seance_id'];
        }
        
        if (!empty($data['rendu_id'])) {
            $notificationData['rendu_id'] = $data['rendu_id'];
        }
        
        // Ajouter les flags
        $notificationData['est_urgent'] = !empty($data['est_urgent']) ? 1 : 0;
        $notificationData['est_rappel'] = !empty($data['est_rappel']) ? 1 : 0;
        $notificationData['est_recapitulatif'] = !empty($data['est_recapitulatif']) ? 1 : 0;
        
        // Insérer la notification
        $notificationId = $this->db->insert('notifications', $notificationData);
        
        // Si la création a réussi et les notifications par email sont activées
        if ($notificationId && ENABLE_EMAIL_NOTIFICATIONS) {
            $this->sendEmailNotification($notificationData);
        }
        
        return $notificationId;
    }
    
    /**
     * Récupère les notifications d'un utilisateur
     * @param int $userId ID de l'utilisateur
     * @param int $page Numéro de page
     * @param int $perPage Nombre d'éléments par page
     * @param bool $onlyUnread Ne récupérer que les notifications non lues
     * @return array Liste des notifications
     */
    public function getUserNotifications($userId, $page = 1, $perPage = ITEMS_PER_PAGE, $onlyUnread = false) {
        $params = [':destinataire_id' => $userId];
        $where = "destinataire_id = :destinataire_id";
        
        if ($onlyUnread) {
            $where .= " AND lu = 0";
        }
        
        // Calculer l'offset pour la pagination
        $offset = ($page - 1) * $perPage;
        
        // Requête SQL
        $sql = "SELECT * FROM notifications 
                WHERE $where 
                ORDER BY date_creation DESC 
                LIMIT :limit OFFSET :offset";
        
        $params[':limit'] = $perPage;
        $params[':offset'] = $offset;
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Compte le nombre total de notifications d'un utilisateur
     * @param int $userId ID de l'utilisateur
     * @param bool $onlyUnread Ne compter que les notifications non lues
     * @return int Nombre de notifications
     */
    public function countUserNotifications($userId, $onlyUnread = false) {
        $params = [':destinataire_id' => $userId];
        $where = "destinataire_id = :destinataire_id";
        
        if ($onlyUnread) {
            $where .= " AND lu = 0";
        }
        
        $sql = "SELECT COUNT(*) as total FROM notifications WHERE $where";
        
        $result = $this->db->fetch($sql, $params);
        return (int) $result['total'];
    }
    
    /**
     * Marque une notification comme lue
     * @param int $notificationId ID de la notification
     * @param int $userId ID de l'utilisateur (pour vérification)
     * @return bool Succès ou échec
     */
    public function markAsRead($notificationId, $userId) {
        return $this->db->update('notifications', 
                               ['lu' => 1], 
                               'id = :id AND destinataire_id = :destinataire_id', 
                               [':id' => $notificationId, ':destinataire_id' => $userId]);
    }
    
    /**
     * Marque toutes les notifications d'un utilisateur comme lues
     * @param int $userId ID de l'utilisateur
     * @return bool Succès ou échec
     */
    public function markAllAsRead($userId) {
        return $this->db->update('notifications', 
                               ['lu' => 1], 
                               'destinataire_id = :destinataire_id', 
                               [':destinataire_id' => $userId]);
    }
    
    /**
     * Supprime une notification
     * @param int $notificationId ID de la notification
     * @param int $userId ID de l'utilisateur (pour vérification)
     * @return bool Succès ou échec
     */
    public function deleteNotification($notificationId, $userId) {
        return $this->db->delete('notifications', 
                               'id = :id AND destinataire_id = :destinataire_id', 
                               [':id' => $notificationId, ':destinataire_id' => $userId]);
    }
    
    /**
     * Supprime toutes les notifications d'un utilisateur
     * @param int $userId ID de l'utilisateur
     * @return bool Succès ou échec
     */
    public function deleteAllNotifications($userId) {
        return $this->db->delete('notifications', 
                               'destinataire_id = :destinataire_id', 
                               [':destinataire_id' => $userId]);
    }
    
    /**
     * Récupère les détails d'une notification
     * @param int $notificationId ID de la notification
     * @return array|false Détails de la notification ou false si non trouvée
     */
    public function getNotificationById($notificationId) {
        $sql = "SELECT * FROM notifications WHERE id = :id";
        return $this->db->fetch($sql, [':id' => $notificationId]);
    }
    
    /**
     * Compte le nombre de notifications non lues pour un utilisateur
     * @param int $userId ID de l'utilisateur
     * @return int Nombre de notifications non lues
     */
    public function countUnreadNotifications($userId) {
        $sql = "SELECT COUNT(*) as total FROM notifications 
                WHERE destinataire_id = :destinataire_id AND lu = 0";
        
        $result = $this->db->fetch($sql, [':destinataire_id' => $userId]);
        return (int) $result['total'];
    }
    
    /**
     * Envoie une notification par email
     * @param array $notification Données de la notification
     * @return bool Succès ou échec
     */
    private function sendEmailNotification($notification) {
        if (!ENABLE_EMAIL_NOTIFICATIONS) {
            return false;
        }
        
        // Récupérer les informations de l'utilisateur
        $sql = "SELECT first_name, last_name, email FROM users WHERE id = :id";
        $user = $this->db->fetch($sql, [':id' => $notification['destinataire_id']]);
        
        if (!$user || empty($user['email'])) {
            return false;
        }
        
        // Récupérer la configuration des notifications de l'utilisateur
        $sql = "SELECT * FROM configurations_notifications WHERE user_id = :user_id";
        $config = $this->db->fetch($sql, [':user_id' => $notification['destinataire_id']]);
        
        // Vérifier si l'utilisateur accepte les emails
        if ($config && $config['rappels_email'] == 0) {
            return false;
        }
        
        // Vérifier le type de notification
        if ($notification['est_rappel'] && $config && $config['rappels_email'] == 0) {
            return false;
        }
        
        if ($notification['est_recapitulatif'] && $config && $config['recapitulatif_hebdo'] == 0) {
            return false;
        }
        
        // Préparer l'email
        $to = $user['email'];
        $subject = APP_NAME . ' - ' . $notification['titre'];
        
        $headers = "From: " . NOTIFICATION_SENDER . "\r\n";
        $headers .= "Reply-To: " . NOTIFICATION_SENDER . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        
        // Construire le corps de l'email
        $message = "
        <html>
        <head>
            <title>{$notification['titre']}</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #1976D2; color: white; padding: 10px; text-align: center; }
                .content { padding: 20px; border: 1px solid #ddd; }
                .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #777; }
                a { color: #1976D2; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>{$notification['titre']}</h2>
                </div>
                <div class='content'>
                    <p>Bonjour {$user['first_name']} {$user['last_name']},</p>
                    <p>" . nl2br(htmlspecialchars($notification['contenu'])) . "</p>
                    <p>Pour plus de détails, veuillez vous connecter à votre espace " . APP_NAME . ".</p>
                </div>
                <div class='footer'>
                    <p>Ce message est envoyé automatiquement, merci de ne pas y répondre.</p>
                    <p>Pour gérer vos préférences de notification, rendez-vous dans les paramètres de votre compte.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        // Envoyer l'email
        return mail($to, $subject, $message, $headers);
    }
    
    /**
     * Envoie des notifications pour un devoir
     * @param int $devoirId ID du devoir
     * @param string $type Type de notification ('creation', 'modification', 'rappel')
     * @return bool Succès ou échec
     */
    public function sendDevoirNotifications($devoirId, $type = 'creation') {
        // Récupérer les informations du devoir
        $sql = "SELECT d.*, c.nom as classe_nom, 
                   u.first_name as auteur_prenom, u.last_name as auteur_nom
                FROM devoirs d
                JOIN classes c ON d.classe_id = c.id
                JOIN users u ON d.auteur_id = u.id
                WHERE d.id = :id";
        
        $devoir = $this->db->fetch($sql, [':id' => $devoirId]);
        
        if (!$devoir) {
            return false;
        }
        
        // Récupérer les élèves concernés
        $eleves = [];
        
        // Si le devoir est associé à des groupes spécifiques
        $sql = "SELECT g.id FROM groupes g
                JOIN devoir_groupe dg ON g.id = dg.groupe_id
                WHERE dg.devoir_id = :devoir_id";
        
        $groupes = $this->db->fetchAll($sql, [':devoir_id' => $devoirId]);
        
        if (!empty($groupes)) {
            foreach ($groupes as $groupe) {
                $sql = "SELECT u.* FROM users u
                        JOIN eleve_groupe eg ON u.id = eg.eleve_id
                        WHERE eg.groupe_id = :groupe_id AND u.user_type = 'eleve'";
                
                $elevesGroupe = $this->db->fetchAll($sql, [':groupe_id' => $groupe['id']]);
                $eleves = array_merge($eleves, $elevesGroupe);
            }
        } else {
            // Sinon, tous les élèves de la classe
            $sql = "SELECT u.* FROM users u
                    JOIN eleve_classe ec ON u.id = ec.eleve_id
                    WHERE ec.classe_id = :classe_id AND u.user_type = 'eleve'";
            
            $eleves = $this->db->fetchAll($sql, [':classe_id' => $devoir['classe_id']]);
        }
        
        // Définir le titre et le contenu selon le type
        $titre = "";
        $contenu = "";
        $estRappel = false;
        
        switch ($type) {
            case 'creation':
                $titre = "Nouveau devoir : {$devoir['titre']}";
                $contenu = "Un nouveau devoir a été créé pour la classe {$devoir['classe_nom']}.\n\n";
                $contenu .= "Titre : {$devoir['titre']}\n";
                $contenu .= "Date limite : " . formatDate($devoir['date_limite'], 'datetime');
                break;
                
            case 'modification':
                $titre = "Devoir modifié : {$devoir['titre']}";
                $contenu = "Le devoir \"{$devoir['titre']}\" pour la classe {$devoir['classe_nom']} a été modifié.\n\n";
                $contenu .= "Date limite : " . formatDate($devoir['date_limite'], 'datetime');
                break;
                
            case 'rappel':
                $titre = "Rappel : {$devoir['titre']}";
                $contenu = "Rappel : le devoir \"{$devoir['titre']}\" pour la classe {$devoir['classe_nom']} est à rendre avant le " . formatDate($devoir['date_limite'], 'datetime');
                $estRappel = true;
                break;
        }
        
        // Créer une notification pour chaque élève
        $success = true;
        
        foreach ($eleves as $eleve) {
            // Vérifier si l'élève a déjà rendu le devoir
            if ($type === 'rappel') {
                $sql = "SELECT id FROM rendus 
                        WHERE devoir_id = :devoir_id AND eleve_id = :eleve_id";
                
                $rendu = $this->db->fetch($sql, [
                    ':devoir_id' => $devoirId,
                    ':eleve_id' => $eleve['id']
                ]);
                
                // Ne pas envoyer de rappel si le devoir est déjà rendu
                if ($rendu) {
                    continue;
                }
            }
            
            $notificationData = [
                'destinataire_id' => $eleve['id'],
                'titre' => $titre,
                'contenu' => $contenu,
                'devoir_id' => $devoirId,
                'est_rappel' => $estRappel
            ];
            
            $notificationId = $this->createNotification($notificationData);
            
            if (!$notificationId) {
                $success = false;
            }
            
            // Notifier aussi les parents de l'élève
            $sql = "SELECT p.id FROM users p
                    JOIN parent_eleve pe ON p.id = pe.parent_id
                    WHERE pe.eleve_id = :eleve_id AND p.user_type = 'parent'";
            
            $parents = $this->db->fetchAll($sql, [':eleve_id' => $eleve['id']]);
            
            foreach ($parents as $parent) {
                $notificationParentData = [
                    'destinataire_id' => $parent['id'],
                    'titre' => "Pour votre enfant : " . $titre,
                    'contenu' => "Pour votre enfant {$eleve['first_name']} {$eleve['last_name']} : \n\n" . $contenu,
                    'devoir_id' => $devoirId,
                    'est_rappel' => $estRappel
                ];
                
                $notificationId = $this->createNotification($notificationParentData);
                
                if (!$notificationId) {
                    $success = false;
                }
            }
        }
        
        return $success;
    }
    
    /**
     * Envoie des notifications pour une séance
     * @param string $seanceId ID de la séance
     * @param string $type Type de notification ('creation', 'modification', 'annulation')
     * @return bool Succès ou échec
     */
    public function sendSeanceNotifications($seanceId, $type = 'creation') {
        // Récupérer les informations de la séance
        $sql = "SELECT s.*, m.nom as matiere_nom, c.nom as classe_nom,
                   u.first_name as professeur_prenom, u.last_name as professeur_nom
                FROM seances s
                JOIN matieres m ON s.matiere_id = m.id
                JOIN classes c ON s.classe_id = c.id
                JOIN users u ON s.professeur_id = u.id
                WHERE s.id = :id";
        
        $seance = $this->db->fetch($sql, [':id' => $seanceId]);
        
        if (!$seance) {
            return false;
        }
        
        // Récupérer les élèves de la classe
        $sql = "SELECT u.* FROM users u
                JOIN eleve_classe ec ON u.id = ec.eleve_id
                WHERE ec.classe_id = :classe_id AND u.user_type = 'eleve'";
        
        $eleves = $this->db->fetchAll($sql, [':classe_id' => $seance['classe_id']]);
        
        // Définir le titre et le contenu selon le type
        $titre = "";
        $contenu = "";
        
        switch ($type) {
            case 'creation':
                $titre = "Nouvelle séance : {$seance['titre']}";
                $contenu = "Une nouvelle séance de {$seance['matiere_nom']} a été programmée pour la classe {$seance['classe_nom']}.\n\n";
                $contenu .= "Date : " . formatDate($seance['date_debut'], 'datetime') . " - " . formatDate($seance['date_fin'], 'time');
                break;
                
            case 'modification':
                $titre = "Séance modifiée : {$seance['titre']}";
                $contenu = "La séance de {$seance['matiere_nom']} pour la classe {$seance['classe_nom']} a été modifiée.\n\n";
                $contenu .= "Date : " . formatDate($seance['date_debut'], 'datetime') . " - " . formatDate($seance['date_fin'], 'time');
                break;
                
            case 'annulation':
                $titre = "Séance annulée : {$seance['titre']}";
                $contenu = "La séance de {$seance['matiere_nom']} pour la classe {$seance['classe_nom']} prévue le " . formatDate($seance['date_debut'], 'datetime') . " a été annulée.";
                break;
        }
        
        // Créer une notification pour chaque élève
        $success = true;
        
        foreach ($eleves as $eleve) {
            $notificationData = [
                'destinataire_id' => $eleve['id'],
                'titre' => $titre,
                'contenu' => $contenu,
                'seance_id' => $seanceId
            ];
            
            $notificationId = $this->createNotification($notificationData);
            
            if (!$notificationId) {
                $success = false;
            }
            
            // Notifier aussi les parents de l'élève
            $sql = "SELECT p.id FROM users p
                    JOIN parent_eleve pe ON p.id = pe.parent_id
                    WHERE pe.eleve_id = :eleve_id AND p.user_type = 'parent'";
            
            $parents = $this->db->fetchAll($sql, [':eleve_id' => $eleve['id']]);
            
            foreach ($parents as $parent) {
                $notificationParentData = [
                    'destinataire_id' => $parent['id'],
                    'titre' => "Pour votre enfant : " . $titre,
                    'contenu' => "Pour votre enfant {$eleve['first_name']} {$eleve['last_name']} : \n\n" . $contenu,
                    'seance_id' => $seanceId
                ];
                
                $notificationId = $this->createNotification($notificationParentData);
                
                if (!$notificationId) {
                    $success = false;
                }
            }
        }
        
        return $success;
    }
    
    /**
     * Crée et envoie un récapitulatif hebdomadaire des devoirs pour chaque élève
     * @return bool Succès ou échec
     */
    public function sendWeeklyRecap() {
        // Récupérer tous les élèves
        $sql = "SELECT * FROM users WHERE user_type = 'eleve'";
        $eleves = $this->db->fetchAll($sql);
        
        if (empty($eleves)) {
            return false;
        }
        
        // Date actuelle et date dans une semaine
        $now = date('Y-m-d H:i:s');
        $nextWeek = date('Y-m-d H:i:s', strtotime('+1 week'));
        
        $success = true;
        
        foreach ($eleves as $eleve) {
            // Récupérer la configuration des notifications de l'élève
            $sql = "SELECT * FROM configurations_notifications WHERE user_id = :user_id";
            $config = $this->db->fetch($sql, [':user_id' => $eleve['id']]);
            
            // Vérifier si l'élève accepte les récapitulatifs
            if ($config && $config['recapitulatif_hebdo'] == 0) {
                continue;
            }
            
            // Récupérer les classes de l'élève
            $sql = "SELECT c.id FROM classes c
                    JOIN eleve_classe ec ON c.id = ec.classe_id
                    WHERE ec.eleve_id = :eleve_id";
            
            $classes = $this->db->fetchAll($sql, [':eleve_id' => $eleve['id']]);
            
            if (empty($classes)) {
                continue;
            }
            
            $classesIds = array_column($classes, 'id');
            $placeholders = implode(',', array_fill(0, count($classesIds), '?'));
            
            // Récupérer les devoirs à venir pour cet élève
            $sql = "SELECT d.*, c.nom as classe_nom,
                       u.first_name as auteur_prenom, u.last_name as auteur_nom,
                       r.id as rendu_id, r.statut as rendu_statut
                    FROM devoirs d
                    JOIN classes c ON d.classe_id = c.id
                    JOIN users u ON d.auteur_id = u.id
                    LEFT JOIN rendus r ON d.id = r.devoir_id AND r.eleve_id = ?
                    WHERE d.classe_id IN ($placeholders)
                      AND d.date_limite BETWEEN ? AND ?
                      AND d.est_visible = 1
                    ORDER BY d.date_limite ASC";
            
            $params = array_merge([$eleve['id']], $classesIds, [$now, $nextWeek]);
            $devoirs = $this->db->fetchAll($sql, $params);
            
            if (empty($devoirs)) {
                continue;
            }
            
            // Construire le contenu du récapitulatif
            $contenu = "Voici la liste des devoirs à rendre cette semaine :\n\n";
            
            foreach ($devoirs as $devoir) {
                $statut = "À faire";
                
                if ($devoir['rendu_id']) {
                    $statut = getStatutDevoir($devoir['rendu_statut']);
                }
                
                $contenu .= "- {$devoir['titre']} ({$devoir['classe_nom']}) : à rendre le " . formatDate($devoir['date_limite'], 'datetime') . " - Statut : $statut\n";
            }
            
            // Créer la notification
            $notificationData = [
                'destinataire_id' => $eleve['id'],
                'titre' => "Récapitulatif hebdomadaire des devoirs",
                'contenu' => $contenu,
                'est_recapitulatif' => true
            ];
            
            $notificationId = $this->createNotification($notificationData);
            
            if (!$notificationId) {
                $success = false;
            }
            
            // Notifier aussi les parents de l'élève
            $sql = "SELECT p.id FROM users p
                    JOIN parent_eleve pe ON p.id = pe.parent_id
                    WHERE pe.eleve_id = :eleve_id AND p.user_type = 'parent'";
            
            $parents = $this->db->fetchAll($sql, [':eleve_id' => $eleve['id']]);
            
            foreach ($parents as $parent) {
                $notificationParentData = [
                    'destinataire_id' => $parent['id'],
                    'titre' => "Récapitulatif des devoirs de {$eleve['first_name']} {$eleve['last_name']}",
                    'contenu' => "Pour votre enfant {$eleve['first_name']} {$eleve['last_name']} : \n\n" . $contenu,
                    'est_recapitulatif' => true
                ];
                
                $notificationId = $this->createNotification($notificationParentData);
                
                if (!$notificationId) {
                    $success = false;
                }
            }
        }
        
        return $success;
    }
}