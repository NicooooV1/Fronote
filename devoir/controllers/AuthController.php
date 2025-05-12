<?php
/**
 * Contrôleur pour la gestion de l'authentification
 */
class AuthController {
    private $db;
    private $userModel;
    
    /**
     * Constructeur
     */
    public function __construct() {
        $this->db = Database::getInstance();
        
        // Initialiser le modèle User
        require_once ROOT_PATH . '/models/User.php';
        $this->userModel = new User();
    }
    
    /**
     * Traite la connexion d'un utilisateur
     * @return bool Succès ou échec de la connexion
     */
    public function login() {
        // Vérifier que le formulaire a été soumis
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return false;
        }
        
        // Récupérer les données du formulaire
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember_me']);
        
        if (empty($username) || empty($password)) {
            $_SESSION['error'] = "Veuillez remplir tous les champs.";
            return false;
        }
        
        // Vérifier si le compte est verrouillé
        require_once ROOT_PATH . '/utils/Authentication.php';
        $auth = new Authentication();
        
        if ($auth->isAccountLocked($username)) {
            $_SESSION['error'] = "Compte temporairement verrouillé suite à plusieurs tentatives échouées. Veuillez réessayer plus tard.";
            return false;
        }
        
        // Tenter l'authentification
        $user = $auth->login($username, $password, $remember);
        
        if (!$user) {
            $_SESSION['error'] = "Identifiants incorrects. Veuillez réessayer.";
            return false;
        }
        
        // Définir les variables de session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_type'] = $user['user_type'];
        $_SESSION['user_fullname'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['is_admin'] = ($user['user_type'] === TYPE_ADMIN);
        
        // Rediriger vers la page appropriée selon le type d'utilisateur
        $redirectUrl = $this->getRedirectUrl($user['user_type']);
        header('Location: ' . $redirectUrl);
        exit;
    }
    
    /**
     * Déconnecte l'utilisateur
     */
    public function logout() {
        // Utiliser la méthode logout de la classe Authentication
        require_once ROOT_PATH . '/utils/Authentication.php';
        $auth = new Authentication();
        $auth->logout();
        
        // Rediriger vers la page de connexion
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    
    /**
     * Traite la demande de réinitialisation de mot de passe
     * @return bool Succès ou échec de la demande
     */
    public function forgotPassword() {
        // Vérifier que le formulaire a été soumis
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return false;
        }
        
        // Récupérer l'email
        $email = $_POST['email'] ?? '';
        
        if (empty($email)) {
            $_SESSION['error'] = "Veuillez saisir votre adresse email.";
            return false;
        }
        
        // Vérifier si l'email existe
        require_once ROOT_PATH . '/utils/Authentication.php';
        $auth = new Authentication();
        
        if (!$auth->emailExists($email)) {
            // Pour des raisons de sécurité, ne pas indiquer si l'email existe ou non
            $_SESSION['success'] = "Si cette adresse email est associée à un compte, vous recevrez un email contenant les instructions pour réinitialiser votre mot de passe.";
            return true;
        }
        
        // Générer un token de réinitialisation
        $token = $auth->createPasswordResetToken($email);
        
        if (!$token) {
            $_SESSION['error'] = "Une erreur est survenue. Veuillez réessayer ultérieurement.";
            return false;
        }
        
        // Envoyer l'email avec le lien de réinitialisation
        $resetLink = BASE_URL . '/reset_password.php?token=' . $token;
        
        $to = $email;
        $subject = APP_NAME . ' - Réinitialisation de mot de passe';
        
        $headers = "From: " . NOTIFICATION_SENDER . "\r\n";
        $headers .= "Reply-To: " . NOTIFICATION_SENDER . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        
        $message = "
        <html>
        <head>
            <title>Réinitialisation de mot de passe</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #1976D2; color: white; padding: 10px; text-align: center; }
                .content { padding: 20px; border: 1px solid #ddd; }
                .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #777; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Réinitialisation de mot de passe</h2>
                </div>
                <div class='content'>
                    <p>Vous avez demandé la réinitialisation de votre mot de passe pour votre compte " . APP_NAME . ".</p>
                    <p>Cliquez sur le lien ci-dessous pour réinitialiser votre mot de passe :</p>
                    <p><a href='$resetLink'>Réinitialiser mon mot de passe</a></p>
                    <p>Si vous n'êtes pas à l'origine de cette demande, veuillez ignorer cet email.</p>
                    <p>Ce lien expirera dans 1 heure.</p>
                </div>
                <div class='footer'>
                    <p>Ce message est envoyé automatiquement, merci de ne pas y répondre.</p>
                </div>
            </div>