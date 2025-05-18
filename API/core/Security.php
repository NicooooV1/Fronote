<?php
/**
 * Classe de sécurité
 */
class Security
{
    /**
     * Génère un token CSRF
     * 
     * @return string Token CSRF
     */
    public static function generateCsrfToken()
    {
        if (!Session::has('csrf_token')) {
            $token = bin2hex(random_bytes(32));
            Session::set('csrf_token', $token);
        }
        
        return Session::get('csrf_token');
    }
    
    /**
     * Vérifie la validité d'un token CSRF
     * 
     * @param string $token Token à vérifier
     * @return bool True si le token est valide
     */
    public static function validateCsrfToken($token)
    {
        $storedToken = Session::get('csrf_token');
        
        if ($storedToken === null || $token !== $storedToken) {
            Logger::warning('CSRF token validation failed', [
                'received' => substr($token, 0, 8) . '...',
                'expected' => $storedToken ? substr($storedToken, 0, 8) . '...' : 'null'
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Nettoie les données d'entrée
     * 
     * @param string|array $data Données à nettoyer
     * @return string|array Données nettoyées
     */
    public static function sanitize($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::sanitize($value);
            }
        } else {
            $data = trim($data);
            $data = stripslashes($data);
            $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        }
        
        return $data;
    }
    
    /**
     * Valide une valeur selon un type spécifié
     * 
     * @param mixed  $value Valeur à valider
     * @param string $type  Type de validation (int, email, string, etc.)
     * @param array  $options Options supplémentaires de validation
     * @return bool True si la validation réussit
     */
    public static function validate($value, $type, array $options = [])
    {
        switch ($type) {
            case 'int':
                $min = $options['min'] ?? PHP_INT_MIN;
                $max = $options['max'] ?? PHP_INT_MAX;
                $valid = filter_var($value, FILTER_VALIDATE_INT) !== false;
                return $valid && $value >= $min && $value <= $max;
            
            case 'float':
                $min = $options['min'] ?? PHP_FLOAT_MIN;
                $max = $options['max'] ?? PHP_FLOAT_MAX;
                $valid = filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
                return $valid && $value >= $min && $value <= $max;
            
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
            
            case 'url':
                return filter_var($value, FILTER_VALIDATE_URL) !== false;
            
            case 'date':
                $format = $options['format'] ?? 'Y-m-d';
                $date = DateTime::createFromFormat($format, $value);
                return $date && $date->format($format) === $value;
            
            case 'time':
                $format = $options['format'] ?? 'H:i';
                $time = DateTime::createFromFormat($format, $value);
                return $time && $time->format($format) === $value;
            
            case 'datetime':
                $format = $options['format'] ?? 'Y-m-d H:i:s';
                $datetime = DateTime::createFromFormat($format, $value);
                return $datetime && $datetime->format($format) === $value;
            
            case 'string':
                $min = $options['min'] ?? 0;
                $max = $options['max'] ?? PHP_INT_MAX;
                $length = strlen($value);
                return is_string($value) && $length >= $min && $length <= $max;
            
            case 'regex':
                $pattern = $options['pattern'] ?? '';
                return !empty($pattern) && preg_match($pattern, $value);
                
            default:
                return false;
        }
    }
    
    /**
     * Génère un hash de mot de passe
     * 
     * @param string $password Mot de passe en clair
     * @return string Hash du mot de passe
     */
    public static function hashPassword($password)
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    /**
     * Vérifie un mot de passe par rapport à un hash
     * 
     * @param string $password Mot de passe en clair
     * @param string $hash     Hash stocké
     * @return bool True si le mot de passe correspond
     */
    public static function verifyPassword($password, $hash)
    {
        return password_verify($password, $hash);
    }
}
