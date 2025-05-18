<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pronote - Mot de passe oublié</title>
    <link rel="stylesheet" href="assets/css/pronote-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="login-container">
        <div class="app-header">
            <div class="app-logo">P</div>
            <h1 class="app-title">Pronote</h1>
        </div>
        
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= $success_message ?>
            </div>
            
            <div class="form-actions" style="margin-top: 20px;">
                <a href="index.php" class="btn-connect">Retour à la connexion</a>
            </div>
        <?php else: ?>
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= $error_message ?>
                </div>
            <?php else: ?>
                <div class="info-message" style="margin-bottom: 20px; text-align: center;">
                    <p>Veuillez entrer votre adresse email pour réinitialiser votre mot de passe.</p>
                </div>
            <?php endif; ?>
            
            <form action="forgot_password.php" method="post">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                
                <div class="form-actions">
                    <a href="index.php" class="btn-cancel">Annuler</a>
                    <button type="submit" class="btn-connect">Réinitialiser</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
