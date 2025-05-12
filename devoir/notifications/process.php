<?php
// Ce script doit être exécuté régulièrement via une tâche CRON

require_once __DIR__ . '/../config.php';

// Traitement des notifications de publication en attente
$stmt = $pdo->prepare("
    SELECT n.id, n.id_devoir, d.titre, d.matiere, d.classe, d.date_remise
    FROM notifications n
    JOIN devoirs d ON n.id_devoir = d.id
    WHERE n.type = 'publication' AND n.statut = 'en_attente'
    LIMIT 50
");
$stmt->execute();
$notifications = $stmt->fetchAll();

foreach ($notifications as $notif) {
    // Ici, le code pour envoyer un email ou une notification push
    // Exemple simplifié pour l'envoi d'email
    $message = "Un nouveau devoir '{$notif['titre']}' a été publié pour la classe {$notif['classe']} en {$notif['matiere']}.";
    
    try {
        // Récupérer les emails des élèves de la classe concernée
        $stmtUsers = $pdo->prepare("
            SELECT mail FROM eleves WHERE classe = ?
            UNION
            SELECT mail FROM parents WHERE est_parent_eleve = 'oui'
        ");
        $stmtUsers->execute([$notif['classe']]);
        $emails = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);
        
        // Envoi (code fictif, à adapter selon votre configuration SMTP)
        // mail(implode(',', $emails), 'Nouveau devoir', $message);
        
        // Mise à jour du statut
        $stmtUpdate = $pdo->prepare("UPDATE notifications SET statut = 'envoye', date_envoi = NOW() WHERE id = ?");
        $stmtUpdate->execute([$notif['id']]);
    } catch (Exception $e) {
        // En cas d'erreur, marquer la notification en erreur
        $stmtError = $pdo->prepare("UPDATE notifications SET statut = 'erreur' WHERE id = ?");
        $stmtError->execute([$notif['id']]);
    }
}

// Traitement des rappels 24h avant la date de remise
$stmt = $pdo->prepare("
    SELECT d.id, d.titre, d.matiere, d.classe, d.date_remise
    FROM devoirs d
    WHERE 
        d.date_remise BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)
        AND NOT EXISTS (
            SELECT 1 FROM notifications 
            WHERE id_devoir = d.id AND type = 'rappel' AND (statut = 'envoye' OR statut = 'en_attente')
        )
");
$stmt->execute();
$devoirs = $stmt->fetchAll();

foreach ($devoirs as $devoir) {
    // Créer la notification de rappel
    $stmtCreate = $pdo->prepare("
        INSERT INTO notifications (type, id_devoir, statut)
        VALUES ('rappel', ?, 'en_attente')
    ");
    $stmtCreate->execute([$devoir['id']]);
}

echo "Traitement des notifications terminé.";