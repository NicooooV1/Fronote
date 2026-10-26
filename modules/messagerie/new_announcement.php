<?php
declare(strict_types=1);
// Ancienne adresse (messagerie < 4.3) — redirige vers la nouvelle messagerie.
header('Location: index.php?nouveau=1', true, 301);
