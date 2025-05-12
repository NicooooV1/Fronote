<?php
require_once __DIR__ . '/includes/db.php';

$jsonPath = __DIR__ . '/~u22405372/SAE/Pronote/login/data/etablissement.json';

if (!file_exists($jsonPath)) {
    die("❌ Fichier etablissement.json non trouvé à : $jsonPath");
}

$data = json_decode(file_get_contents($jsonPath), true);

if (!$data) {
    die("❌ Erreur de lecture du JSON.");
}

// Insertion des matières
if (isset($data['matieres'])) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO matieres (nom) VALUES (?)");
    foreach ($data['matieres'] as $matiere) {
        if (is_array($matiere)) {
            $stmt->execute([$matiere['nom']]);
        } else {
            $stmt->execute([$matiere]);
        }
    }
}

// Insertion des classes
if (isset($data['classes'])) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO classes (nom) VALUES (?)");
    foreach ($data['classes'] as $classe) {
        if (is_array($classe)) {
            $stmt->execute([$classe['nom']]);
        } else {
            $stmt->execute([$classe]);
        }
    }
}

echo "✅ Importation terminée avec succès.";
