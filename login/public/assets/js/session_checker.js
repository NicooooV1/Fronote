/**
 * Session Checker - Script de vérification de validité de session
 * 
 * Ce script vérifie périodiquement si la session utilisateur est toujours valide
 * Si la session devient invalide (modification des données utilisateur),
 * l'utilisateur est redirigé vers la page de connexion.
 */

// Intervalle de vérification en millisecondes (30 secondes par défaut)
const CHECK_INTERVAL = 5000;

// URL du script de vérification de session
const CHECK_SESSION_URL = '/~u22405372/SAE/Pronote/login/public/check_session.php';

// URL de redirection en cas de session invalide
const LOGIN_URL = '/~u22405372/SAE/Pronote/login/public/index.php';

/**
 * Vérifie si la session est toujours valide
 */
function checkSession() {
    fetch(CHECK_SESSION_URL, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache',
            'Expires': '0'
        },
        credentials: 'same-origin' // Pour envoyer les cookies de session
    })
    .then(response => response.json())
    .then(data => {
        if (!data.valid) {
            // Session invalide, rediriger vers la page de connexion
            console.log('Session invalide: ' + (data.reason || 'raison inconnue'));
            
            // Afficher un message à l'utilisateur
            alert('Votre session a expiré ou vos informations ont été modifiées. Vous allez être redirigé vers la page de connexion.');
            
            // Rediriger vers la page de connexion
            window.location.href = LOGIN_URL;
        }
    })
    .catch(error => {
        console.error('Erreur lors de la vérification de session:', error);
        // En cas d'erreur, on pourrait décider de continuer sans déconnecter l'utilisateur
        // ou de déconnecter par précaution - ici on continue
    });
}

/**
 * Démarrer la vérification périodique
 */
function startSessionChecker() {
    // Première vérification après un délai initial
    setTimeout(() => {
        // Vérifier la session
        checkSession();
        
        // Puis démarrer les vérifications périodiques
        setInterval(checkSession, CHECK_INTERVAL);
    }, 5000); // Délai initial de 5 secondes
}

// Démarrer la vérification de session quand la page est chargée
document.addEventListener('DOMContentLoaded', startSessionChecker);

/**
 * Ce fichier a été désactivé pour éviter les problèmes de redirection en boucle.
 * Le code ci-dessous est l'original mais il n'est pas exécuté.
 */
/*
document.addEventListener('DOMContentLoaded', function() {
    // Fonction pour vérifier l'état de la session
    function checkSession() {
        fetch('check_session.php')
            .then(response => response.json())
            .then(data => {
                if (!data.loggedIn) {
                    window.location.href = 'login.php';
                }
            })
            .catch(error => {
                console.error('Erreur lors de la vérification de la session:', error);
            });
    }

    // Vérifier la session toutes les 30 secondes
    setInterval(checkSession, 30000);
});
*/