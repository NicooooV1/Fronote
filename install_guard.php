<?php
/**
 * Protection du fichier d'installation
 * Ce script vérifie si l'installation est terminée
 * et protège le fichier d'installation si nécessaire
 */

// Ne pas afficher d'erreur
error_reporting(0);
ini_set('display_errors', 0);

// Vérifier si l'installation est terminée
$installFile = __DIR__ . '/install.php';
$installLockFile = __DIR__ . '/install.lock';

// Si le fichier d'installation existe et que l'installation est terminée (lock file)
if (file_exists($installFile) && file_exists($installLockFile)) {
    try {
        // Vérifier la date du fichier de lock pour éviter les suppression accidentelles récentes
        $lockFileTime = filemtime($installLockFile);
        $currentTime = time();
        $timeDiff = $currentTime - $lockFileTime;
        
        // Si le fichier de lock a plus de 24 heures
        if ($timeDiff > 86400) {
            // Option 1: Essayer de renommer le fichier
            if (!rename($installFile, $installFile . '.disabled')) {
                throw new Exception("Impossible de renommer le fichier d'installation");
            }
        }
    } catch (Exception $e) {
        try {
            // Option 2: Créer un .htaccess pour bloquer l'accès
            $htaccess = __DIR__ . '/.htaccess';
            $htaccessExists = file_exists($htaccess);
            
            if ($htaccessExists) {
                $content = file_get_contents($htaccess);
                if (strpos($content, '# Protection du fichier d\'installation') === false) {
                    $content .= "\n# Protection du fichier d'installation\n";
                    $content .= "<Files \"install.php\">\n";
                    $content .= "    Order allow,deny\n";
                    $content .= "    Deny from all\n";
                    $content .= "</Files>\n";
                    file_put_contents($htaccess, $content);
                }
            } else {
                $content = "# Protection du fichier d'installation\n";
                $content .= "<Files \"install.php\">\n";
                $content .= "    Order allow,deny\n";
                $content .= "    Deny from all\n";
                $content .= "</Files>\n";
                file_put_contents($htaccess, $content);
            }
        } catch (Exception $e2) {
            // Journaliser l'erreur
            error_log("Impossible de protéger le fichier d'installation: " . $e2->getMessage());
        }
    }
}
