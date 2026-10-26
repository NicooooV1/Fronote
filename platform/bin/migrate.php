<?php
declare(strict_types=1);
// Applique schema.sql (idempotent). Usage : php bin/migrate.php
require __DIR__ . '/../src/bootstrap.php';
$sql = (string) file_get_contents(__DIR__ . '/../schema.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$n = 0;
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) { pf_db()->exec($stmt); $n++; }
echo "Schéma appliqué ($n instructions).\n";
