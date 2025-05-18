<?php
/**
 * Protection du fichier d'installation
 * Ce script vérifie si l'installation est terminée
 * et protège le fichier d'installation si nécessaire
 */

// Vérifier si l'installation est terminée
$installFile = __DIR__ . '/install.php';
$installLockFile = __DIR__ . '/install.lock';

// Si le fichier d'installation existe et que l'installation est terminée (lock file)
if (file_exists($installFile) && file_exists($installLockFile)) {
    try {
        // Option 1: Essayer de renommer le fichier
        rename($installFile, $installFile . '.disabled');
    } catch (Exception $e) {
        try {
            // Option 2: Créer un .htaccess pour bloquer l'accès
            $htaccess = __DIR__ . '/.htaccess';
            $htaccessExists = file_exists($htaccess);
            
            $content = "";
            if ($htaccessExists) {
                $content = file_get_contents($htaccess);
                if (strpos($content, '# Protection du fichier d\'installation') === false) {
                    $content .= "\n# Protection du fichier d'installation\n";
                    $content .= "<Files \"install.php\">\n";
                    $content .= "    Order allow,deny\n";
                    $content .= "    Deny from all\n";
                    $content .= "</Files>\n";
                }
            } else {
                $content = "# Protection du fichier d'installation\n";
                $content .= "<Files \"install.php\">\n";
                $content .= "    Order allow,deny\n";
                $content .= "    Deny from all\n";
                $content .= "</Files>\n";
            }
            
            file_put_contents($htaccess, $content);
        } catch (Exception $e2) {
            // Journaliser l'erreur
            error_log("Impossible de protéger le fichier d'installation: " . $e2->getMessage());
        }
    }
}
