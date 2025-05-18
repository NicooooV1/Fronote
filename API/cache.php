<?php
/**
 * Système de mise en cache pour l'application Pronote
 * Fournit des fonctions pour mettre en cache des données fréquemment utilisées
 */

/**
 * Répertoire où les fichiers de cache seront stockés
 * @var string CACHE_DIR
 */
define('CACHE_DIR', __DIR__ . '/cache');

/**
 * Durée de validité par défaut du cache (en secondes)
 * @var int CACHE_DEFAULT_TTL
 */
define('CACHE_DEFAULT_TTL', 3600); // 1 heure

/**
 * Initialise le système de cache
 * @return bool Succès de l'initialisation
 */
function initCache() {
    // Créer le répertoire de cache s'il n'existe pas
    if (!is_dir(CACHE_DIR)) {
        if (!mkdir(CACHE_DIR, 0755, true)) {
            error_log("Impossible de créer le répertoire de cache: " . CACHE_DIR);
            return false;
        }
    }
    
    // Vérifier que le répertoire est accessible en écriture
    if (!is_writable(CACHE_DIR)) {
        error_log("Le répertoire de cache n'est pas accessible en écriture: " . CACHE_DIR);
        return false;
    }
    
    return true;
}

/**
 * Génère une clé de cache à partir d'un identifiant et de paramètres
 * @param string $identifier Identifiant de la donnée
 * @param array $params Paramètres supplémentaires
 * @return string Clé de cache générée
 */
function generateCacheKey($identifier, $params = []) {
    $key = $identifier;
    
    if (!empty($params)) {
        // Trier les paramètres pour garantir la cohérence des clés
        ksort($params);
        $key .= '_' . md5(serialize($params));
    }
    
    // Remplacer les caractères non autorisés dans les noms de fichiers
    return preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
}

/**
 * Récupère une donnée du cache
 * @param string $key Clé de cache
 * @return mixed|null Données du cache ou null si non trouvé/expiré
 */
function getCache($key) {
    $cacheFile = CACHE_DIR . '/' . $key . '.cache';
    
    // Vérifier si le fichier de cache existe
    if (!file_exists($cacheFile)) {
        return null;
    }
    
    // Lire le contenu du cache
    $content = file_get_contents($cacheFile);
    if ($content === false) {
        return null;
    }
    
    // Désérialiser les données
    $cache = unserialize($content);
    
    // Vérifier si le cache est valide (non expiré)
    if ($cache['expiration'] < time()) {
        // Supprimer le cache expiré
        @unlink($cacheFile);
        return null;
    }
    
    return $cache['data'];
}

/**
 * Met en cache une donnée
 * @param string $key Clé de cache
 * @param mixed $data Données à mettre en cache
 * @param int $ttl Durée de vie en secondes (0 = utiliser la valeur par défaut)
 * @return bool Succès de l'opération
 */
function setCache($key, $data, $ttl = 0) {
    // Initialiser le cache si nécessaire
    if (!initCache()) {
        return false;
    }
    
    $cacheFile = CACHE_DIR . '/' . $key . '.cache';
    
    // Utiliser la durée par défaut si non spécifiée
    $ttl = $ttl ?: CACHE_DEFAULT_TTL;
    
    // Préparer les données à mettre en cache
    $cache = [
        'expiration' => time() + $ttl,
        'data' => $data
    ];
    
    // Sérialiser et sauvegarder dans le fichier
    $content = serialize($cache);
    
    return file_put_contents($cacheFile, $content) !== false;
}

/**
 * Supprime une donnée du cache
 * @param string $key Clé de cache
 * @return bool Succès de l'opération
 */
function deleteCache($key) {
    $cacheFile = CACHE_DIR . '/' . $key . '.cache';
    
    // Vérifier si le fichier existe
    if (file_exists($cacheFile)) {
        return unlink($cacheFile);
    }
    
    return true;
}

/**
 * Vide le cache (supprime tous les fichiers de cache)
 * @return bool Succès de l'opération
 */
function clearCache() {
    if (!is_dir(CACHE_DIR)) {
        return true;
    }
    
    $success = true;
    
    $files = glob(CACHE_DIR . '/*.cache');
    foreach ($files as $file) {
        if (is_file($file) && !@unlink($file)) {
            error_log("Impossible de supprimer le fichier de cache: " . $file);
            $success = false;
        }
    }
    
    return $success;
}

/**
 * Exécute une fonction et met son résultat en cache
 * Si le résultat est déjà en cache et valide, il est directement retourné
 * 
 * @param callable $callback Fonction à exécuter pour récupérer la donnée
 * @param string $identifier Identifiant de la donnée
 * @param array $params Paramètres pour générer la clé de cache
 * @param int $ttl Durée de vie du cache en secondes
 * @return mixed Résultat de la fonction (mis en cache ou récupéré du cache)
 */
function cacheResult($callback, $identifier, $params = [], $ttl = 0) {
    // Générer la clé de cache
    $cacheKey = generateCacheKey($identifier, $params);
    
    // Essayer de récupérer du cache
    $cached = getCache($cacheKey);
    
    if ($cached !== null) {
        return $cached;
    }
    
    // Exécuter la fonction pour récupérer la donnée
    $result = $callback();
    
    // Mettre en cache le résultat
    setCache($cacheKey, $result, $ttl);
    
    return $result;
}

// Initialiser le cache au chargement du fichier
initCache();
