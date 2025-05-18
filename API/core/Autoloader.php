<?php
/**
 * Autoloader pour l'application Pronote
 */
class Autoloader
{
    /**
     * Enregistre l'autoloader
     */
    public static function register()
    {
        spl_autoload_register([self::class, 'autoload']);
    }

    /**
     * Autoload d'une classe
     * 
     * @param string $class Le nom complet de la classe
     */
    public static function autoload($class)
    {
        // Convertir les séparateurs de namespace en séparateurs de répertoire
        $class = str_replace('\\', DIRECTORY_SEPARATOR, $class);
        
        // Chemins de base pour les classes
        $paths = [
            API_DIR . '/core',      // Classes du noyau
            API_DIR . '/models',    // Modèles
            API_DIR . '/controllers', // Contrôleurs
            API_DIR . '/helpers',   // Classes utilitaires
        ];
        
        // Essayer de charger la classe depuis les différents chemins
        foreach ($paths as $path) {
            $file = $path . DIRECTORY_SEPARATOR . $class . '.php';
            
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
        
        // Si la classe n'a pas d'extension .php, essayer de la charger directement
        foreach ($paths as $path) {
            $file = $path . DIRECTORY_SEPARATOR . $class;
            
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
}
