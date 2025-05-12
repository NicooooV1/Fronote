<?php
class Permissions {
    // Définition des permissions par type d'utilisateur et module
    private static $permissions = [
        'eleve' => [
            'agenda' => ['view', 'export']
        ],
        'parent' => [
            'agenda' => ['view', 'export']
        ],
        'professeur' => [
            'agenda' => ['view', 'modify', 'export', 'import']
        ],
        'cpe' => [
            'agenda' => ['view', 'modify', 'export', 'import']
        ],
        'admin' => [
            'agenda' => ['view', 'modify', 'export', 'import']
        ]
    ];
    
    // Vérifie si un utilisateur a une permission donnée
    public static function hasPermission($userType, $module, $action) {
        if (!isset(self::$permissions[$userType])) {
            return false;
        }
        
        if (!isset(self::$permissions[$userType][$module])) {
            return false;
        }
        
        return in_array($action, self::$permissions[$userType][$module]);
    }
}