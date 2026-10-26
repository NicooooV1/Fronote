<?php
declare(strict_types=1);
// Ancienne adresse (messagerie < 4.3) — redirige vers la nouvelle messagerie.
header('Location: index.php?c=' . (int) ($_GET['id'] ?? 0), true, 301);
