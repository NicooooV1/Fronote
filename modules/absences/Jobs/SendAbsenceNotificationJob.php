<?php
declare(strict_types=1);

namespace Modules\Absences\Jobs;

class SendAbsenceNotificationJob
{
    public function handle(array $payload): void
    {
        $pdo   = app('db')?->getConnection();
        $email = app('email');

        if (!$pdo || !$email) {
            throw new \RuntimeException('Dépendances indisponibles (db ou email)');
        }

        $eleveId = (int) ($payload['eleve_id'] ?? 0);
        if ($eleveId === 0) return;

        $stmt = $pdo->prepare('SELECT nom, prenom, classe FROM eleves WHERE id = ?');
        $stmt->execute([$eleveId]);
        $eleve = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$eleve) return;

        $stmt = $pdo->prepare(
            'SELECT p.mail FROM parents p JOIN parent_eleve pe ON pe.parent_id = p.id
             WHERE pe.eleve_id = ? AND p.mail IS NOT NULL AND p.mail != \'\''
        );
        $stmt->execute([$eleveId]);
        $parentEmails = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (empty($parentEmails)) return;

        $nomEleve = trim($eleve['prenom'] . ' ' . $eleve['nom']);
        $result   = $email->sendTemplate($parentEmails, "Absence de {$nomEleve}", 'absence', [
            'eleve_nom'  => $nomEleve,
            'classe'     => $eleve['classe'] ?? '',
            'date_debut' => $payload['date_debut'] ?? '',
            'date_fin'   => $payload['date_fin']   ?? '',
            'type'       => $payload['type']  ?? 'Absence',
            'motif'      => $payload['motif'] ?? 'Non renseigné',
        ]);

        if (!($result['success'] ?? false)) {
            throw new \RuntimeException('Échec envoi notification absence : ' . ($result['message'] ?? 'erreur inconnue'));
        }
    }
}
