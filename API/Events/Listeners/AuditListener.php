<?php

declare(strict_types=1);

namespace API\Events\Listeners;

/**
 * Écrit une ligne d'audit pour chaque événement domaine dispatché.
 * Remplace les appels manuels logAudit() dispersés dans les pages admin.
 */
class AuditListener
{
    /**
     * Map classe d'événement → label d'action lisible.
     */
    // Clés = nom COURT de la classe d'événement (sans namespace), car les événements
    // sont dispatchés sous des namespaces variés : API\Events\* (alias core) ET
    // Modules\<Module>\Events\* (implémentation réelle des modules). Le matching par
    // nom court fonctionne pour les deux.
    private static array $actionMap = [
        // Notes
        'NoteCreated'           => 'note.created',
        'NoteUpdated'           => 'note.updated',
        'NoteDeleted'           => 'note.deleted',
        // Absences
        'AbsenceCreated'        => 'absence.created',
        'AbsenceDeleted'        => 'absence.deleted',
        'RetardCreated'         => 'retard.created',
        'RetardDeleted'         => 'retard.deleted',
        'JustificatifApproved'  => 'justificatif.approved',
        'JustificatifRejected'  => 'justificatif.rejected',
        // Devoirs
        'DevoirCreated'         => 'devoir.created',
        'DevoirUpdated'         => 'devoir.updated',
        'DevoirDeleted'         => 'devoir.deleted',
        // Événements agenda
        'EvenementCreated'      => 'evenement.created',
        'EvenementUpdated'      => 'evenement.updated',
        'EvenementDeleted'      => 'evenement.deleted',
        // Matières
        'MatiereCreated'        => 'matiere.created',
        'MatiereUpdated'        => 'matiere.updated',
        'MatiereDeleted'        => 'matiere.deleted',
        // Périodes
        'PeriodeCreated'        => 'periode.created',
        'PeriodeUpdated'        => 'periode.updated',
        'PeriodeDeleted'        => 'periode.deleted',
        // Classes
        'ClasseCreated'         => 'classe.created',
        'ClasseUpdated'         => 'classe.updated',
        'ClasseDeleted'         => 'classe.deleted',
    ];

    public function handle(object $event): void
    {
        try {
            $audit = app('audit');
            if (!$audit) {
                return;
            }

            $class  = get_class($event);
            // Nom court (sans namespace) pour matcher quel que soit le namespace de l'événement.
            $short  = (($p = strrpos($class, '\\')) !== false) ? substr($class, $p + 1) : $class;
            // Fallback : CamelCase → dotted.lower (NoteCreated → note.created).
            $action = self::$actionMap[$short] ?? strtolower(preg_replace('/(?<!^)([A-Z])/', '.$1', $short));

            $props = get_object_vars($event);
            $modelId = null;

            foreach ($props as $key => $value) {
                if (is_int($value) && str_ends_with($key, 'Id')) {
                    $modelId = $value;
                    break;
                }
            }

            $audit->log($action, $modelId, ['new' => $props]);
        } catch (\Throwable $e) {
            error_log("AuditListener: Failed to log event " . get_class($event) . ": " . $e->getMessage());
        }
    }
}
