<?php
declare(strict_types=1);
$errorCode = 403;
$errorTitle = 'Acces interdit';
$errorMessage = 'Vous n\'avez pas les permissions necessaires pour acceder a cette page.';
$errorIcon = '&#128274;';
require __DIR__ . '/error_layout.php';
