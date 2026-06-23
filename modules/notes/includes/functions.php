<?php
/**
 * Fonctions utilitaires pour le module Notes
 * formatDate, sanitizeInput, generateCSRFToken, validateCSRFToken sont fournis par l'API (Bridge)
 *
 * NOTE : calculateGrade, getSubjectColor, formatGrade, getCurrentTrimester, getTrimesterLabel
 *        ont été supprimés car dupliqués par NoteService (getMoyenneGenerale, getMatieres, getTrimestreCourant).
 */

if (!function_exists('validateDate')) {
    /**
     * Valide une date.
     */
    function validateDate(string $date, string $format = 'Y-m-d'): bool
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
}
