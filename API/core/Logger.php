<?php
/**
 * Classe de journalisation
 */
class Logger
{
    const DEBUG = 'debug';
    const INFO = 'info';
    const WARNING = 'warning';
    const ERROR = 'error';
    
    /**
     * Log un message de debug
     * 
     * @param string $message Message à journaliser
     * @param array  $context Contexte supplémentaire
     */
    public static function debug($message, array $context = [])
    {
        self::log(self::DEBUG, $message, $context);
    }
    
    /**
     * Log un message d'information
     * 
     * @param string $message Message à journaliser
     * @param array  $context Contexte supplémentaire
     */
    public static function info($message, array $context = [])
    {
        self::log(self::INFO, $message, $context);
    }
    
    /**
     * Log un message d'avertissement
     * 
     * @param string $message Message à journaliser
     * @param array  $context Contexte supplémentaire
     */
    public static function warning($message, array $context = [])
    {
        self::log(self::WARNING, $message, $context);
    }
    
    /**
     * Log un message d'erreur
     * 
     * @param string $message Message à journaliser
     * @param array  $context Contexte supplémentaire
     */
    public static function error($message, array $context = [])
    {
        self::log(self::ERROR, $message, $context);
    }
    
    /**
     * Journalise un message dans un fichier
     * 
     * @param string $level   Niveau de log
     * @param string $message Message à journaliser
     * @param array  $context Contexte supplémentaire
     */
    protected static function log($level, $message, array $context = [])
    {
        if (!LOG_ENABLED) {
            return;
        }
        
        // Vérifier si le niveau de log est suffisant
        $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
        if ($levels[$level] < $levels[LOG_LEVEL]) {
            return;
        }
        
        // Créer le répertoire de logs si nécessaire
        if (!is_dir(LOGS_PATH)) {
            mkdir(LOGS_PATH, 0755, true);
        }
        
        // Préparer le message de log
        $logMessage = self::formatLogMessage($level, $message, $context);
        
        // Écrire dans le fichier de log
        $logFile = LOGS_PATH . '/app_' . date('Y-m-d') . '.log';
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
    
    /**
     * Formate un message de log
     * 
     * @param string $level   Niveau de log
     * @param string $message Message à journaliser
     * @param array  $context Contexte supplémentaire
     * @return string Message formaté
     */
    protected static function formatLogMessage($level, $message, array $context = [])
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextString = !empty($context) ? ' ' . json_encode($context) : '';
        
        return "[{$timestamp}] [{$level}] {$message}{$contextString}" . PHP_EOL;
    }
}
