<?php
function getMatieres() {
    $jsonPath = __DIR__ . '/../login/data/etablissement.json';
    if (!file_exists($jsonPath)) return [];

    $data = json_decode(file_get_contents($jsonPath), true);
    return isset($data['matieres']) ? $data['matieres'] : [];
}

function getClasses() {
    $jsonPath = __DIR__ . '/../login/data/etablissement.json';
    if (!file_exists($jsonPath)) return [];

    $data = json_decode(file_get_contents($jsonPath), true);
    return isset($data['classes']) ? $data['classes'] : [];
}

function getDevoirs($pdo, $filters = []) {
    $sql = "SELECT * FROM devoirs WHERE 1";

    if (!empty($filters['matiere'])) {
        $sql .= " AND matiere = " . $pdo->quote($filters['matiere']);
    }
    if (!empty($filters['classe'])) {
        $sql .= " AND classe = " . $pdo->quote($filters['classe']);
    }
    $sql .= " ORDER BY date_rendu ASC";

    return $pdo->query($sql)->fetchAll();
}
