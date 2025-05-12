<?php
/**
 * Mise en œuvre d'un autoloader PSR-4
 */

spl_autoload_register(function ($class) {
    // Conversion du nom de classe en chemin de fichier
    $prefix = '';
    $baseDir = __DIR__ . '/../';
    
    // Remplacer les namespace par des dossiers
    $file = $baseDir . str_replace('\\', '/', $class) . '.php';
    
    // Si le fichier existe, l'inclure
    if (file_exists($file)) {
        require $file;
        return true;
    }
    
    // Essayer avec le mapping alternatif pour la rétrocompatibilité
    $classMapping = [
        'ApiHandler' => 'core/api_handler.php',
        'DatabaseService' => 'core/database_service.php',
        'ConversationRepository' => 'repositories/ConversationRepository.php',
        'MessageRepository' => 'repositories/MessageRepository.php',
        'ParticipantRepository' => 'repositories/ParticipantRepository.php',
        'NotificationRepository' => 'repositories/NotificationRepository.php'
    ];
    
    if (isset($classMapping[$class])) {
        $file = $baseDir . $classMapping[$class];
        if (file_exists($file)) {
            require $file;
            return true;
        }
    }
    
    return false;
});