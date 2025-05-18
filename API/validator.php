<?php
/**
 * Système de validation standardisé pour toute l'application
 * Fournit des méthodes pour valider et filtrer les entrées utilisateur
 */

/**
 * Valide une chaîne de caractères
 * @param mixed $input Valeur à valider
 * @param int $min Longueur minimale (0 pour ignorer)
 * @param int $max Longueur maximale (0 pour ignorer)
 * @param string $pattern Motif regex (optionnel)
 * @return array [bool $valid, string $value, string $error]
 */
function validateString($input, $min = 0, $max = 0, $pattern = '') {
    $result = ['valid' => false, 'value' => '', 'error' => ''];
    
    if (is_null($input) || $input === '') {
        $result['error'] = 'La valeur ne peut pas être vide';
        return $result;
    }
    
    // Convertir en chaîne et nettoyer les caractères HTML
    $value = htmlspecialchars(trim((string)$input), ENT_QUOTES, 'UTF-8');
    
    // Vérifier la longueur minimale
    if ($min > 0 && mb_strlen($value) < $min) {
        $result['error'] = "La valeur doit contenir au moins $min caractères";
        $result['value'] = $value;
        return $result;
    }
    
    // Vérifier la longueur maximale
    if ($max > 0 && mb_strlen($value) > $max) {
        $result['error'] = "La valeur ne doit pas dépasser $max caractères";
        $result['value'] = $value;
        return $result;
    }
    
    // Vérifier le pattern si fourni
    if ($pattern && !preg_match($pattern, $value)) {
        $result['error'] = "La valeur ne respecte pas le format requis";
        $result['value'] = $value;
        return $result;
    }
    
    $result['valid'] = true;
    $result['value'] = $value;
    return $result;
}

/**
 * Valide un entier
 * @param mixed $input Valeur à valider
 * @param int $min Valeur minimale (null pour ignorer)
 * @param int $max Valeur maximale (null pour ignorer)
 * @return array [bool $valid, int $value, string $error]
 */
function validateInt($input, $min = null, $max = null) {
    $result = ['valid' => false, 'value' => 0, 'error' => ''];
    
    if (is_null($input) || $input === '') {
        $result['error'] = 'La valeur ne peut pas être vide';
        return $result;
    }
    
    // Vérifier si c'est un entier
    if (!is_numeric($input) || (string)(int)$input !== (string)$input) {
        $result['error'] = 'La valeur doit être un nombre entier';
        return $result;
    }
    
    $value = (int)$input;
    
    // Vérifier minimum
    if ($min !== null && $value < $min) {
        $result['error'] = "La valeur doit être au moins $min";
        $result['value'] = $value;
        return $result;
    }
    
    // Vérifier maximum
    if ($max !== null && $value > $max) {
        $result['error'] = "La valeur ne doit pas dépasser $max";
        $result['value'] = $value;
        return $result;
    }
    
    $result['valid'] = true;
    $result['value'] = $value;
    return $result;
}

/**
 * Valide une date
 * @param mixed $input Valeur à valider
 * @param string $format Format de date (YYYY-MM-DD par défaut)
 * @param mixed $minDate Date minimale (null pour ignorer)
 * @param mixed $maxDate Date maximale (null pour ignorer)
 * @return array [bool $valid, string $value, string $error]
 */
function validateDate($input, $format = 'Y-m-d', $minDate = null, $maxDate = null) {
    $result = ['valid' => false, 'value' => '', 'error' => ''];
    
    if (is_null($input) || $input === '') {
        $result['error'] = 'La date ne peut pas être vide';
        return $result;
    }
    
    $value = trim($input);
    $date = DateTime::createFromFormat($format, $value);
    
    // Vérifier si le format est valide
    if (!$date || $date->format($format) !== $value) {
        $result['error'] = 'Format de date invalide';
        $result['value'] = $value;
        return $result;
    }
    
    // Vérifier la date minimale
    if ($minDate !== null) {
        $min = is_string($minDate) ? new DateTime($minDate) : $minDate;
        if ($date < $min) {
            $result['error'] = 'La date est inférieure à la date minimale autorisée';
            $result['value'] = $value;
            return $result;
        }
    }
    
    // Vérifier la date maximale
    if ($maxDate !== null) {
        $max = is_string($maxDate) ? new DateTime($maxDate) : $maxDate;
        if ($date > $max) {
            $result['error'] = 'La date est supérieure à la date maximale autorisée';
            $result['value'] = $value;
            return $result;
        }
    }
    
    $result['valid'] = true;
    $result['value'] = $value;
    return $result;
}

/**
 * Valide un email
 * @param mixed $input Valeur à valider
 * @return array [bool $valid, string $value, string $error]
 */
function validateEmail($input) {
    $result = ['valid' => false, 'value' => '', 'error' => ''];
    
    if (is_null($input) || $input === '') {
        $result['error'] = 'L\'email ne peut pas être vide';
        return $result;
    }
    
    $value = trim($input);
    
    // Vérifier si c'est un email valide
    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        $result['error'] = 'Format d\'email invalide';
        $result['value'] = $value;
        return $result;
    }
    
    $result['valid'] = true;
    $result['value'] = $value;
    return $result;
}

/**
 * Valide sécuritairement une requête SQL
 * @param mixed $input Valeur à valider
 * @param array $allowedValues Valeurs autorisées (si vide, permet tout sauf caractères dangereux)
 * @return array [bool $valid, string $value, string $error]
 */
function validateSQL($input, $allowedValues = []) {
    $result = ['valid' => false, 'value' => '', 'error' => ''];
    
    if (is_null($input)) {
        $result['error'] = 'La valeur ne peut pas être nulle';
        return $result;
    }
    
    $value = trim((string)$input);
    
    // Si liste de valeurs autorisées fournie
    if (!empty($allowedValues)) {
        if (!in_array($value, $allowedValues, true)) {
            $result['error'] = 'Valeur non autorisée';
            return $result;
        }
    } else {
        // Rechercher des caractères potentiellement dangereux
        $dangerousPatterns = [
            '/\s*--/',           // Commentaires SQL
            '/;\s*$/',           // Points-virgules en fin de chaîne
            '/\/\*.*?\*\//',     // Commentaires multilignes
            '/UNION\s+SELECT/i', // UNION SELECT
            '/SELECT\s+.*\s+FROM/i', // SELECT FROM
            '/INSERT\s+INTO/i',  // INSERT INTO
            '/UPDATE\s+.*\s+SET/i', // UPDATE SET
            '/DELETE\s+FROM/i',  // DELETE FROM
            '/DROP\s+TABLE/i',   // DROP TABLE
            '/EXEC\s+/i',        // EXEC (SQL Server)
            '/EXECUTE\s+/i',     // EXECUTE (SQL Server)
        ];
        
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $value)) {
                $result['error'] = 'Valeur contenant des caractères non autorisés';
                return $result;
            }
        }
    }
    
    $result['valid'] = true;
    $result['value'] = $value;
    return $result;
}

/**
 * Valide une valeur booléenne
 * @param mixed $input Valeur à valider
 * @return array [bool $valid, bool $value, string $error]
 */
function validateBoolean($input) {
    $result = ['valid' => false, 'value' => false, 'error' => ''];
    
    if (is_bool($input)) {
        $result['valid'] = true;
        $result['value'] = $input;
        return $result;
    }
    
    if (is_string($input) || is_numeric($input)) {
        $value = strtolower((string)$input);
        if (in_array($value, ['1', 'true', 'yes', 'on', 'oui'], true)) {
            $result['valid'] = true;
            $result['value'] = true;
            return $result;
        } else if (in_array($value, ['0', 'false', 'no', 'off', 'non'], true)) {
            $result['valid'] = true;
            $result['value'] = false;
            return $result;
        }
    }
    
    $result['error'] = 'Valeur booléenne invalide';
    return $result;
}

/**
 * Nettoie et valide une chaîne pour un usage HTML sécurisé
 * @param mixed $input Valeur à nettoyer
 * @param bool $allowHTML Autoriser les balises HTML
 * @return string Chaîne nettoyée
 */
function sanitizeOutput($input, $allowHTML = false) {
    if (is_null($input) || $input === '') {
        return '';
    }
    
    $value = (string)$input;
    
    if ($allowHTML) {
        // Liste des balises autorisées
        $allowedTags = '<p><br><strong><em><ul><ol><li><h1><h2><h3><h4><h5><h6><a><img>';
        return strip_tags($value, $allowedTags);
    } else {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
