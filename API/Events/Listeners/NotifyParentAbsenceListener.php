<?php

declare(strict_types=1);

namespace API\Events\Listeners;

use Modules\Absences\Events\AbsenceCreated;

/**
 * Notifie IN-APP les parents d'un élève à la création d'une absence.
 *
 * Envoi SYNCHRONE (même schéma que discipline/facturation/signalements) : il n'y a
 * pas de file de jobs (aucun worker) ni d'e-mail sortant (pas de relais SMTP) sur
 * ce déploiement. Best-effort et fail-closed vis-à-vis de l'absence : toute erreur
 * est journalisée sans jamais casser la création de l'absence. Piloté par le
 * feature flag `absences.notify_parents`.
 */
class NotifyParentAbsenceListener
{
    public function handle(AbsenceCreated $event): void
    {
        try {
            $features = app('features');
            if ($features && !$features->isEnabled('absences.notify_parents')) {
                return;
            }

            $eleveId = (int) ($event->data['id_eleve'] ?? 0);
            if ($eleveId <= 0) {
                return;
            }

            $pdo = app('db')->getConnection();

            // Parents rattachés à l'élève (table de liaison legacy).
            $stmt = $pdo->prepare("SELECT id_parent FROM parent_eleve WHERE id_eleve = ?");
            $stmt->execute([$eleveId]);
            $parentIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            if (!$parentIds) {
                return;
            }

            // Nom de l'élève pour un message lisible.
            $e = $pdo->prepare("SELECT prenom, nom FROM eleves WHERE id = ?");
            $e->execute([$eleveId]);
            $eleve = $e->fetch(\PDO::FETCH_ASSOC) ?: [];
            $nomEleve = trim(($eleve['prenom'] ?? '') . ' ' . ($eleve['nom'] ?? '')) ?: 'votre enfant';

            $date    = $event->data['date_debut'] ?? null;
            $titre   = 'Absence signalée';
            $contenu = 'Une absence de ' . $nomEleve . ($date ? ' le ' . $date : '') . ' a été enregistrée.';

            if (!class_exists('\\NotificationService')) {
                require_once dirname(__DIR__, 3) . '/modules/notifications/includes/NotificationService.php';
            }
            $notif = new \NotificationService($pdo);
            foreach ($parentIds as $pid) {
                $notif->creer(
                    (int) $pid, 'parent', 'absence', $titre, $contenu,
                    '/modules/absences/absences.php', 'normale', 'absence', (int) $event->absenceId
                );
            }
        } catch (\Throwable $e) {
            error_log('NotifyParentAbsenceListener: ' . $e->getMessage());
        }
    }
}
