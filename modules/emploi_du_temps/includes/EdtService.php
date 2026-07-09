<?php
declare(strict_types=1);
/**
 * EdtService — Service métier pour le module Emploi du Temps (M03).
 *
 * Centralise toutes les requêtes SQL : CRUD cours, détection conflits,
 * modifications ponctuelles, requêtes par rôle.
 */
class EdtService
{
    protected \PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Établissement courant — scope toutes les requêtes EDT.
     */
    private function etab(): int
    {
        return \API\Core\EstablishmentContext::id();
    }

    // ─── Créneaux horaires ───────────────────────────────────────

    /**
     * Retourne tous les créneaux horaires ordonnés.
     */
    public function getCreneaux(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM creneaux_horaires WHERE etablissement_id = ? ORDER BY ordre ASC"
        );
        $stmt->execute([$this->etab()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retourne uniquement les créneaux de type 'cours'.
     */
    public function getCreneauxCours(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM creneaux_horaires WHERE etablissement_id = ? AND type = 'cours' ORDER BY ordre ASC"
        );
        $stmt->execute([$this->etab()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── Salles ──────────────────────────────────────────────────

    public function getSalles(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM salles WHERE etablissement_id = ? AND actif = 1 ORDER BY nom ASC"
        );
        $stmt->execute([$this->etab()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSalle(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM salles WHERE id = ? AND etablissement_id = ?");
        $stmt->execute([$id, $this->etab()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createSalle(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO salles (nom, batiment, capacite, type, equipements, etablissement_id) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $data['nom'], $data['batiment'] ?? null, $data['capacite'] ?? null,
            $data['type'] ?? 'standard', $data['equipements'] ?? null, $this->etab()
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Effectif d'une classe (nombre d'élèves actifs).
     */
    public function getClasseEffectif(int $classeId): int
    {
        if ($classeId <= 0) return 0;
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM eleves e
             JOIN classes c ON e.classe = c.nom
             WHERE c.id = ? AND c.etablissement_id = ? AND e.actif = 1"
        );
        $stmt->execute([$classeId, $this->etab()]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Affectation intelligente de salle (CDC §4.3).
     * Retourne les salles compatibles ET libres sur le créneau, triées :
     *   1. type exact si demandé (labo/info/gymnase…)
     *   2. best-fit : plus petite capacité suffisante (préserve les grandes salles)
     *   3. nom
     * Occupancy basée sur emploi_du_temps (source récurrente hebdo).
     *
     * @param array $data Requiert jour, creneau_id ; optionnel classe_id, salle_type, id_exclude.
     * @return array Salles candidates ordonnées (meilleure en premier).
     */
    public function suggestSalle(array $data): array
    {
        if (empty($data['jour']) || empty($data['creneau_id'])) {
            return [];
        }

        $exclude      = (int)($data['id_exclude'] ?? 0);
        $requiredType = $data['salle_type'] ?? null;
        $effectif     = $this->getClasseEffectif((int)($data['classe_id'] ?? 0));

        $sql = "SELECT s.* FROM salles s
                WHERE s.actif = 1 AND s.etablissement_id = ?
                  AND s.id NOT IN (
                      SELECT salle_id FROM emploi_du_temps
                      WHERE etablissement_id = ? AND jour = ? AND creneau_id = ? AND actif = 1
                        AND salle_id IS NOT NULL AND id != ?
                  )";
        $params = [$this->etab(), $this->etab(), $data['jour'], $data['creneau_id'], $exclude];

        if ($effectif > 0) {
            $sql .= " AND (s.capacite IS NULL OR s.capacite >= ?)";
            $params[] = $effectif;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $salles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        usort($salles, function ($a, $b) use ($requiredType) {
            if ($requiredType !== null) {
                $ta = ($a['type'] === $requiredType) ? 0 : 1;
                $tb = ($b['type'] === $requiredType) ? 0 : 1;
                if ($ta !== $tb) return $ta <=> $tb;
            }
            $ca = $a['capacite'] ?? PHP_INT_MAX;
            $cb = $b['capacite'] ?? PHP_INT_MAX;
            if ($ca !== $cb) return $ca <=> $cb;
            return strcmp($a['nom'], $b['nom']);
        });

        return $salles;
    }

    // ─── Cours EDT ───────────────────────────────────────────────

    /**
     * Retourne l'EDT complet d'une classe pour une semaine.
     */
    public function getEdtClasse(int $classeId, ?string $dateRef = null): array
    {
        $sql = "SELECT e.*, m.nom AS matiere_nom, m.couleur AS matiere_couleur,
                       CONCAT(p.prenom, ' ', p.nom) AS professeur_nom,
                       s.nom AS salle_nom, c.label AS creneau_label,
                       c.heure_debut AS creneau_heure_debut, c.heure_fin AS creneau_heure_fin
                FROM emploi_du_temps e
                JOIN matieres m ON e.matiere_id = m.id
                JOIN professeurs p ON e.professeur_id = p.id
                LEFT JOIN salles s ON e.salle_id = s.id
                JOIN creneaux_horaires c ON e.creneau_id = c.id
                WHERE e.classe_id = ? AND e.actif = 1 AND e.etablissement_id = ?
                ORDER BY FIELD(e.jour, 'lundi','mardi','mercredi','jeudi','vendredi','samedi'), c.ordre";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$classeId, $this->etab()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retourne l'EDT d'un professeur.
     */
    public function getEdtProfesseur(int $profId): array
    {
        $sql = "SELECT e.*, m.nom AS matiere_nom, m.couleur AS matiere_couleur,
                       cl.nom AS classe_nom,
                       s.nom AS salle_nom, c.label AS creneau_label,
                       c.heure_debut AS creneau_heure_debut, c.heure_fin AS creneau_heure_fin
                FROM emploi_du_temps e
                JOIN matieres m ON e.matiere_id = m.id
                JOIN classes cl ON e.classe_id = cl.id
                LEFT JOIN salles s ON e.salle_id = s.id
                JOIN creneaux_horaires c ON e.creneau_id = c.id
                WHERE e.professeur_id = ? AND e.actif = 1 AND e.etablissement_id = ?
                ORDER BY FIELD(e.jour, 'lundi','mardi','mercredi','jeudi','vendredi','samedi'), c.ordre";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$profId, $this->etab()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retourne l'EDT d'un élève (via sa classe).
     */
    public function getEdtEleve(int $eleveId): array
    {
        $stmt = $this->pdo->prepare("SELECT classe FROM eleves WHERE id = ? AND etablissement_id = ?");
        $stmt->execute([$eleveId, $this->etab()]);
        $classe = $stmt->fetchColumn();
        if (!$classe) return [];

        // Trouver l'id de la classe
        $stmt = $this->pdo->prepare("SELECT id FROM classes WHERE nom = ? AND actif = 1 AND etablissement_id = ? LIMIT 1");
        $stmt->execute([$classe, $this->etab()]);
        $classeId = $stmt->fetchColumn();
        if (!$classeId) return [];

        return $this->getEdtClasse((int)$classeId);
    }

    /**
     * Retourne l'EDT complet selon le rôle.
     */
    public function getEdtByRole(string $role, int $userId): array
    {
        switch ($role) {
            case 'professeur':
                return $this->getEdtProfesseur($userId);
            case 'eleve':
                return $this->getEdtEleve($userId);
            case 'parent':
                return $this->getEdtParent($userId);
            case 'administrateur':
            case 'vie_scolaire':
                return []; // Sélection manuelle via filtre classe
            default:
                return [];
        }
    }

    /**
     * EDT pour un parent (premier enfant par défaut).
     */
    public function getEdtParent(int $parentId, ?int $eleveId = null): array
    {
        if ($eleveId) {
            // Vérifier que c'est bien un enfant du parent
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM parent_eleve WHERE id_parent = ? AND id_eleve = ?");
            $stmt->execute([$parentId, $eleveId]);
            if ($stmt->fetchColumn() > 0) {
                return $this->getEdtEleve($eleveId);
            }
            return [];
        }

        // Premier enfant
        $stmt = $this->pdo->prepare("SELECT id_eleve FROM parent_eleve WHERE id_parent = ? LIMIT 1");
        $stmt->execute([$parentId]);
        $eId = $stmt->fetchColumn();
        return $eId ? $this->getEdtEleve((int)$eId) : [];
    }

    /**
     * Crée un cours dans l'emploi du temps.
     */
    public function createCours(array $data): int
    {
        // Vérifier les conflits
        $conflits = $this->detecterConflits($data);
        if (!empty($conflits)) {
            throw new \RuntimeException('Conflits détectés : ' . implode(', ', $conflits));
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO emploi_du_temps (classe_id, matiere_id, professeur_id, salle_id, jour,
                creneau_id, heure_debut, heure_fin, groupe, type_cours, recurrence, couleur, etablissement_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $data['classe_id'], $data['matiere_id'], $data['professeur_id'],
            $data['salle_id'] ?? null, $data['jour'], $data['creneau_id'],
            $data['heure_debut'], $data['heure_fin'],
            $data['groupe'] ?? null, $data['type_cours'] ?? 'cours',
            $data['recurrence'] ?? 'hebdomadaire', $data['couleur'] ?? null, $this->etab()
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Met à jour un cours.
     */
    public function updateCours(int $id, array $data): bool
    {
        $data['id_exclude'] = $id;
        $conflits = $this->detecterConflits($data);
        if (!empty($conflits)) {
            throw new \RuntimeException('Conflits détectés : ' . implode(', ', $conflits));
        }

        $stmt = $this->pdo->prepare(
            "UPDATE emploi_du_temps SET classe_id = ?, matiere_id = ?, professeur_id = ?,
                salle_id = ?, jour = ?, creneau_id = ?, heure_debut = ?, heure_fin = ?,
                groupe = ?, type_cours = ?, recurrence = ?, couleur = ?
             WHERE id = ? AND etablissement_id = ?"
        );
        return $stmt->execute([
            $data['classe_id'], $data['matiere_id'], $data['professeur_id'],
            $data['salle_id'] ?? null, $data['jour'], $data['creneau_id'],
            $data['heure_debut'], $data['heure_fin'],
            $data['groupe'] ?? null, $data['type_cours'] ?? 'cours',
            $data['recurrence'] ?? 'hebdomadaire', $data['couleur'] ?? null,
            $id, $this->etab()
        ]);
    }

    /**
     * Supprime (désactive) un cours.
     */
    public function deleteCours(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE emploi_du_temps SET actif = 0 WHERE id = ? AND etablissement_id = ?");
        return $stmt->execute([$id, $this->etab()]);
    }

    /**
     * Récupère un cours par son ID.
     */
    public function getCours(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.*, m.nom AS matiere_nom, CONCAT(p.prenom, ' ', p.nom) AS professeur_nom,
                    s.nom AS salle_nom, c.label AS creneau_label, cl.nom AS classe_nom
             FROM emploi_du_temps e
             JOIN matieres m ON e.matiere_id = m.id
             JOIN professeurs p ON e.professeur_id = p.id
             JOIN classes cl ON e.classe_id = cl.id
             LEFT JOIN salles s ON e.salle_id = s.id
             JOIN creneaux_horaires c ON e.creneau_id = c.id
             WHERE e.id = ? AND e.etablissement_id = ?"
        );
        $stmt->execute([$id, $this->etab()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ─── Conflits ────────────────────────────────────────────────

    /**
     * Détecte les conflits d'emploi du temps :
     *  - Double affectation enseignant (même jour + même créneau)
     *  - Double affectation salle (même jour + même créneau)
     */
    public function detecterConflits(array $data): array
    {
        $conflits = [];
        $exclude = $data['id_exclude'] ?? 0;

        // Conflit professeur
        $sql = "SELECT e.id, cl.nom AS classe_nom
                FROM emploi_du_temps e
                JOIN classes cl ON e.classe_id = cl.id
                WHERE e.professeur_id = ? AND e.jour = ? AND e.creneau_id = ?
                  AND e.actif = 1 AND e.id != ? AND e.etablissement_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$data['professeur_id'], $data['jour'], $data['creneau_id'], $exclude, $this->etab()]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $conflits[] = "Professeur déjà affecté le {$data['jour']} sur ce créneau ({$row['classe_nom']})";
        }

        // Contrainte dure : indisponibilité déclarée de l'enseignant (CDC §7.3)
        if (!$this->isProfAvailable((int)$data['professeur_id'], $data['jour'], (int)$data['creneau_id'])) {
            $conflits[] = "Professeur indisponible le {$data['jour']} sur ce créneau";
        }

        // Conflit salle
        if (!empty($data['salle_id'])) {
            $sql = "SELECT e.id, cl.nom AS classe_nom
                    FROM emploi_du_temps e
                    JOIN classes cl ON e.classe_id = cl.id
                    WHERE e.salle_id = ? AND e.jour = ? AND e.creneau_id = ?
                      AND e.actif = 1 AND e.id != ? AND e.etablissement_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$data['salle_id'], $data['jour'], $data['creneau_id'], $exclude, $this->etab()]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $conflits[] = "Salle déjà occupée le {$data['jour']} sur ce créneau ({$row['classe_nom']})";
            }
        }

        // Conflit classe : double réservation de la classe sur le même créneau.
        // Toléré uniquement si les deux cours ciblent des groupes distincts et définis
        // (ex. groupe A en TP pendant que groupe B est ailleurs).
        $newGroupe = $data['groupe'] ?? null;
        $sql = "SELECT e.id, e.groupe, m.nom AS matiere_nom
                FROM emploi_du_temps e
                JOIN matieres m ON e.matiere_id = m.id
                WHERE e.classe_id = ? AND e.jour = ? AND e.creneau_id = ?
                  AND e.actif = 1 AND e.id != ? AND e.etablissement_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$data['classe_id'], $data['jour'], $data['creneau_id'], $exclude, $this->etab()]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existingGroupe = $row['groupe'] ?? null;
            if ($newGroupe !== null && $existingGroupe !== null && $newGroupe !== $existingGroupe) {
                continue; // groupes distincts → pas de conflit
            }
            $conflits[] = "Classe déjà en cours le {$data['jour']} sur ce créneau ({$row['matiere_nom']})";
        }

        return $conflits;
    }

    /**
     * Analyse globale de tous les conflits dans l'emploi du temps.
     * Retourne les paires de cours en conflit.
     */
    public function scanAllConflits(): array
    {
        $conflits = [];

        // Conflits professeur : même professeur, même jour, même créneau
        $sql = "SELECT e1.id AS cours1_id, e2.id AS cours2_id,
                       e1.jour, c.label AS creneau,
                       CONCAT(p.prenom, ' ', p.nom) AS professeur,
                       cl1.nom AS classe1, cl2.nom AS classe2,
                       m1.nom AS matiere1, m2.nom AS matiere2
                FROM emploi_du_temps e1
                JOIN emploi_du_temps e2 ON e1.professeur_id = e2.professeur_id
                    AND e1.jour = e2.jour AND e1.creneau_id = e2.creneau_id
                    AND e1.id < e2.id AND e2.actif = 1 AND e2.etablissement_id = ?
                JOIN professeurs p ON e1.professeur_id = p.id
                JOIN classes cl1 ON e1.classe_id = cl1.id
                JOIN classes cl2 ON e2.classe_id = cl2.id
                JOIN matieres m1 ON e1.matiere_id = m1.id
                JOIN matieres m2 ON e2.matiere_id = m2.id
                JOIN creneaux_horaires c ON e1.creneau_id = c.id
                WHERE e1.actif = 1 AND e1.etablissement_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->etab(), $this->etab()]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['type'] = 'professeur';
            $row['description'] = "{$row['professeur']} : {$row['classe1']} ({$row['matiere1']}) vs {$row['classe2']} ({$row['matiere2']}) — {$row['jour']} {$row['creneau']}";
            $conflits[] = $row;
        }

        // Conflits salle : même salle, même jour, même créneau
        $sql = "SELECT e1.id AS cours1_id, e2.id AS cours2_id,
                       e1.jour, c.label AS creneau,
                       s.nom AS salle,
                       cl1.nom AS classe1, cl2.nom AS classe2,
                       m1.nom AS matiere1, m2.nom AS matiere2
                FROM emploi_du_temps e1
                JOIN emploi_du_temps e2 ON e1.salle_id = e2.salle_id
                    AND e1.jour = e2.jour AND e1.creneau_id = e2.creneau_id
                    AND e1.id < e2.id AND e2.actif = 1 AND e2.etablissement_id = ?
                JOIN salles s ON e1.salle_id = s.id
                JOIN classes cl1 ON e1.classe_id = cl1.id
                JOIN classes cl2 ON e2.classe_id = cl2.id
                JOIN matieres m1 ON e1.matiere_id = m1.id
                JOIN matieres m2 ON e2.matiere_id = m2.id
                JOIN creneaux_horaires c ON e1.creneau_id = c.id
                WHERE e1.actif = 1 AND e1.salle_id IS NOT NULL AND e1.etablissement_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->etab(), $this->etab()]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['type'] = 'salle';
            $row['description'] = "Salle {$row['salle']} : {$row['classe1']} ({$row['matiere1']}) vs {$row['classe2']} ({$row['matiere2']}) — {$row['jour']} {$row['creneau']}";
            $conflits[] = $row;
        }

        return $conflits;
    }

    /**
     * Retourne le nombre de conflits actifs.
     */
    public function countConflits(): int
    {
        return count($this->scanAllConflits());
    }

    /**
     * Export de l'EDT d'une classe pour ExportService.
     */
    public function getEdtForExport(int $classeId): array
    {
        $cours = $this->getEdtClasse($classeId);
        $jours = ['lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4, 'vendredi' => 5, 'samedi' => 6];
        
        usort($cours, function($a, $b) use ($jours) {
            $dj = ($jours[$a['jour']] ?? 9) - ($jours[$b['jour']] ?? 9);
            return $dj !== 0 ? $dj : strcmp($a['creneau_heure_debut'] ?? '', $b['creneau_heure_debut'] ?? '');
        });

        $result = [];
        foreach ($cours as $c) {
            $result[] = [
                'Jour'       => ucfirst($c['jour']),
                'Créneau'    => $c['creneau_label'] ?? ($c['creneau_heure_debut'] . '-' . $c['creneau_heure_fin']),
                'Matière'    => $c['matiere_nom'],
                'Professeur' => $c['professeur_nom'],
                'Salle'      => $c['salle_nom'] ?? '-',
                'Type'       => ucfirst($c['type_cours'] ?? 'cours'),
            ];
        }
        return $result;
    }

    // ─── Modifications ponctuelles ───────────────────────────────

    /**
     * Crée une modification ponctuelle (annulation, déplacement, remplacement).
     */
    public function createModification(array $data): int
    {
        // Le cours visé doit appartenir à l'établissement courant (edt_modifications n'a pas de colonne dédiée).
        $chk = $this->pdo->prepare("SELECT 1 FROM emploi_du_temps WHERE id = ? AND etablissement_id = ? LIMIT 1");
        $chk->execute([$data['edt_id'] ?? 0, $this->etab()]);
        if (!$chk->fetchColumn()) {
            return 0; // cours hors établissement → refus
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO edt_modifications (edt_id, date_cours, type_modification,
                nouveau_professeur_id, nouvelle_salle_id, nouvelle_heure_debut,
                nouvelle_heure_fin, motif, createur_id, createur_type)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $data['edt_id'], $data['date_cours'], $data['type_modification'],
            $data['nouveau_professeur_id'] ?? null, $data['nouvelle_salle_id'] ?? null,
            $data['nouvelle_heure_debut'] ?? null, $data['nouvelle_heure_fin'] ?? null,
            $data['motif'] ?? null, $data['createur_id'] ?? null, $data['createur_type'] ?? null
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Retourne les modifications pour une date donnée d'un cours.
     */
    public function getModifications(int $edtId, string $date): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT m.* FROM edt_modifications m
             JOIN emploi_du_temps e ON m.edt_id = e.id
             WHERE m.edt_id = ? AND m.date_cours = ? AND e.etablissement_id = ?"
        );
        $stmt->execute([$edtId, $date, $this->etab()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── Helpers ─────────────────────────────────────────────────

    public function getClasses(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM classes WHERE etablissement_id = ? AND actif = 1 ORDER BY niveau, nom"
        );
        $stmt->execute([$this->etab()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMatieres(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM matieres WHERE etablissement_id = ? AND actif = 1 ORDER BY nom"
        );
        $stmt->execute([$this->etab()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProfesseurs(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, nom, prenom, matiere FROM professeurs WHERE etablissement_id = ? AND actif = 1 ORDER BY nom, prenom"
        );
        $stmt->execute([$this->etab()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Organise les cours en grille jour/créneau pour la vue hebdomadaire.
     */
    public function buildGrille(array $cours): array
    {
        $jours = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
        $grille = [];
        foreach ($cours as $c) {
            $grille[$c['creneau_id']][$c['jour']] = $c;
        }
        return $grille;
    }

    /**
     * Organise les cours par jour pour la vue liste.
     */
    public function buildParJour(array $cours): array
    {
        $parJour = [];
        foreach ($cours as $c) {
            $parJour[$c['jour']][] = $c;
        }
        return $parJour;
    }

    /**
     * Statistiques globales.
     */
    public function getStats(): array
    {
        $etab = $this->etab();
        $stats = [];
        $cnt = function (string $table) use ($etab): int {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE etablissement_id = ? AND actif = 1");
            $stmt->execute([$etab]);
            return (int)$stmt->fetchColumn();
        };
        $stats['total_cours']   = $cnt('emploi_du_temps');
        $stats['total_salles']  = $cnt('salles');
        $stats['total_classes'] = $cnt('classes');

        // Heures par professeur
        $stmt = $this->pdo->prepare(
            "SELECT CONCAT(p.prenom, ' ', p.nom) AS prof, COUNT(*) AS nb_cours
             FROM emploi_du_temps e
             JOIN professeurs p ON e.professeur_id = p.id
             WHERE e.actif = 1 AND e.etablissement_id = ?
             GROUP BY e.professeur_id
             ORDER BY nb_cours DESC"
        );
        $stmt->execute([$etab]);
        $stats['heures_par_prof'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $stats;
    }

    // ─── Détection conflits horaires ─────────────────────────────

    /**
     * Détecte les chevauchements horaires pour une classe sur un jour donné.
     * Compare les plages heure_debut/heure_fin pour trouver les cours qui se superposent.
     *
     * @return array Liste des paires de cours en conflit avec détails.
     */
    public function detectConflicts(string $classeId, string $jour): array
    {
        $sql = "SELECT e.id, e.heure_debut, e.heure_fin,
                       m.nom AS matiere_nom,
                       CONCAT(p.prenom, ' ', p.nom) AS professeur_nom,
                       s.nom AS salle_nom
                FROM emploi_du_temps e
                JOIN matieres m ON e.matiere_id = m.id
                JOIN professeurs p ON e.professeur_id = p.id
                LEFT JOIN salles s ON e.salle_id = s.id
                WHERE e.classe_id = :classeId AND e.jour = :jour AND e.actif = 1 AND e.etablissement_id = :etab
                ORDER BY e.heure_debut ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':classeId' => $classeId, ':jour' => $jour, ':etab' => $this->etab()]);
        $cours = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $conflits = [];
        $count = count($cours);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                // Chevauchement : A commence avant la fin de B ET B commence avant la fin de A
                if ($cours[$i]['heure_debut'] < $cours[$j]['heure_fin']
                    && $cours[$j]['heure_debut'] < $cours[$i]['heure_fin']) {
                    $conflits[] = [
                        'cours_a' => $cours[$i],
                        'cours_b' => $cours[$j],
                        'description' => "{$cours[$i]['matiere_nom']} ({$cours[$i]['heure_debut']}-{$cours[$i]['heure_fin']}) "
                            . "chevauche {$cours[$j]['matiere_nom']} ({$cours[$j]['heure_debut']}-{$cours[$j]['heure_fin']})",
                    ];
                }
            }
        }

        return $conflits;
    }

    // ─── Créneaux libres ─────────────────────────────────────────

    /**
     * Retourne les créneaux horaires disponibles pour une classe sur un jour donné.
     * Calcule les « trous » entre les cours existants dans la plage horaire spécifiée.
     *
     * @return array Liste de créneaux libres ['heure_debut' => ..., 'heure_fin' => ...].
     */
    public function findFreeSlots(string $classeId, string $jour, string $heureMin = '08:00', string $heureMax = '18:00'): array
    {
        $sql = "SELECT e.heure_debut, e.heure_fin
                FROM emploi_du_temps e
                WHERE e.classe_id = :classeId AND e.jour = :jour AND e.actif = 1 AND e.etablissement_id = :etab
                ORDER BY e.heure_debut ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':classeId' => $classeId, ':jour' => $jour, ':etab' => $this->etab()]);
        $occupied = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fusionner les créneaux qui se chevauchent
        $merged = [];
        foreach ($occupied as $slot) {
            $debut = max($slot['heure_debut'], $heureMin);
            $fin = min($slot['heure_fin'], $heureMax);
            if ($debut >= $fin) continue;

            if (empty($merged)) {
                $merged[] = ['heure_debut' => $debut, 'heure_fin' => $fin];
            } else {
                $last = &$merged[count($merged) - 1];
                if ($debut <= $last['heure_fin']) {
                    $last['heure_fin'] = max($last['heure_fin'], $fin);
                } else {
                    $merged[] = ['heure_debut' => $debut, 'heure_fin' => $fin];
                }
                unset($last);
            }
        }

        // Calculer les trous
        $free = [];
        $cursor = $heureMin;
        foreach ($merged as $slot) {
            if ($cursor < $slot['heure_debut']) {
                $free[] = ['heure_debut' => $cursor, 'heure_fin' => $slot['heure_debut']];
            }
            $cursor = $slot['heure_fin'];
        }
        if ($cursor < $heureMax) {
            $free[] = ['heure_debut' => $cursor, 'heure_fin' => $heureMax];
        }

        return $free;
    }

    // ─── Semaines A/B ────────────────────────────────────────────

    /**
     * Détermine le type de semaine (A ou B) à partir d'une date.
     * Basé sur le numéro de semaine ISO : impair = A, pair = B.
     *
     * @param string $date Date au format Y-m-d.
     * @return string 'A' ou 'B'.
     */
    public function getWeekType(string $date): string
    {
        $weekNumber = (int)(new \DateTime($date))->format('W');
        return ($weekNumber % 2 !== 0) ? 'A' : 'B';
    }

    // ─── Export ICS ──────────────────────────────────────────────

    /**
     * Génère une chaîne ICS (iCalendar) à partir de l'EDT d'un utilisateur.
     * Compatible avec Outlook, Google Calendar, Apple Calendar.
     *
     * @param int    $userId   Identifiant de l'utilisateur.
     * @param string $userType Type : 'professeur', 'eleve', 'parent'.
     * @return string Contenu ICS complet.
     */
    public function exportIcs(int $userId, string $userType): string
    {
        $cours = $this->getEdtByRole($userType, $userId);

        $ics  = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//Fronote//EDT//FR\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "X-WR-CALNAME:Emploi du temps Fronote\r\n";

        $joursMap = [
            'lundi' => 'MO', 'mardi' => 'TU', 'mercredi' => 'WE',
            'jeudi' => 'TH', 'vendredi' => 'FR', 'samedi' => 'SA',
        ];

        // Calculer le lundi de la semaine courante pour ancrer les événements
        $now = new \DateTime();
        $dayOfWeek = (int)$now->format('N'); // 1=lundi
        $monday = (clone $now)->modify('-' . ($dayOfWeek - 1) . ' days');

        foreach ($cours as $c) {
            $jourOffset = array_search($c['jour'], array_keys($joursMap));
            if ($jourOffset === false) continue;

            $dateJour = (clone $monday)->modify('+' . $jourOffset . ' days')->format('Ymd');
            $heureDebut = str_replace(':', '', $c['heure_debut'] ?? $c['creneau_heure_debut'] ?? '0800');
            $heureFin   = str_replace(':', '', $c['heure_fin'] ?? $c['creneau_heure_fin'] ?? '0900');

            $summary = $c['matiere_nom'] ?? 'Cours';
            $location = $c['salle_nom'] ?? '';
            $description = '';
            if (!empty($c['professeur_nom'])) $description .= 'Prof: ' . $c['professeur_nom'];
            if (!empty($c['classe_nom'])) $description .= ($description ? ' | ' : '') . 'Classe: ' . $c['classe_nom'];

            $uid = 'fronote-edt-' . ($c['id'] ?? uniqid()) . '@fronote';

            $ics .= "BEGIN:VEVENT\r\n";
            $ics .= "UID:{$uid}\r\n";
            $ics .= "DTSTART:{$dateJour}T{$heureDebut}00\r\n";
            $ics .= "DTEND:{$dateJour}T{$heureFin}00\r\n";
            $ics .= "SUMMARY:{$summary}\r\n";
            if ($location) $ics .= "LOCATION:{$location}\r\n";
            if ($description) $ics .= "DESCRIPTION:{$description}\r\n";
            $ics .= "RRULE:FREQ=WEEKLY;BYDAY={$joursMap[$c['jour']]}\r\n";
            $ics .= "END:VEVENT\r\n";
        }

        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    }

    // ─── Notifications remplacement ──────────────────────────────

    /**
     * Récupère les données nécessaires pour notifier une modification d'EDT.
     * Retourne les informations de la modification, du cours original,
     * et la liste des destinataires (élèves + parents de la classe).
     *
     * @param int $modificationId Identifiant de la modification dans edt_modifications.
     * @return array Données structurées pour le dispatch de notifications.
     */
    public function notifyModification(int $modificationId): array
    {
        // Récupérer la modification avec le cours original
        $sql = "SELECT mod.*, mod.type_modification, mod.date_cours, mod.motif,
                       e.classe_id, e.matiere_id, e.professeur_id, e.jour,
                       e.heure_debut, e.heure_fin,
                       m.nom AS matiere_nom,
                       CONCAT(p.prenom, ' ', p.nom) AS professeur_nom,
                       cl.nom AS classe_nom,
                       s.nom AS salle_nom
                FROM edt_modifications mod
                JOIN emploi_du_temps e ON mod.edt_id = e.id
                JOIN matieres m ON e.matiere_id = m.id
                JOIN professeurs p ON e.professeur_id = p.id
                JOIN classes cl ON e.classe_id = cl.id
                LEFT JOIN salles s ON e.salle_id = s.id
                WHERE mod.id = :modificationId AND e.etablissement_id = :etab";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':modificationId' => $modificationId, ':etab' => $this->etab()]);
        $modification = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$modification) {
            return [];
        }

        // Nouveau professeur (si remplacement)
        $nouveauProf = null;
        if (!empty($modification['nouveau_professeur_id'])) {
            $stmt = $this->pdo->prepare(
                "SELECT id, CONCAT(prenom, ' ', nom) AS nom_complet
                 FROM professeurs WHERE id = :profId AND etablissement_id = :etab"
            );
            $stmt->execute([':profId' => $modification['nouveau_professeur_id'], ':etab' => $this->etab()]);
            $nouveauProf = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Nouvelle salle (si déplacement)
        $nouvelleSalle = null;
        if (!empty($modification['nouvelle_salle_id'])) {
            $stmt = $this->pdo->prepare(
                "SELECT id, nom FROM salles WHERE id = :salleId AND etablissement_id = :etab"
            );
            $stmt->execute([':salleId' => $modification['nouvelle_salle_id'], ':etab' => $this->etab()]);
            $nouvelleSalle = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Récupérer les élèves de la classe concernée
        $stmt = $this->pdo->prepare(
            "SELECT e.id, e.nom, e.prenom, e.mail AS email
             FROM eleves e
             JOIN classes cl ON e.classe = cl.nom
             WHERE cl.id = :classeId AND cl.etablissement_id = :etab AND e.actif = 1"
        );
        $stmt->execute([':classeId' => $modification['classe_id'], ':etab' => $this->etab()]);
        $eleves = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Récupérer les parents des élèves
        $parents = [];
        if (!empty($eleves)) {
            $eleveIds = array_column($eleves, 'id');
            $placeholders = implode(',', array_fill(0, count($eleveIds), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT DISTINCT pa.id, pa.nom, pa.prenom, pa.mail AS email
                 FROM parents pa
                 JOIN parent_eleve pe ON pa.id = pe.id_parent
                 WHERE pe.id_eleve IN ({$placeholders})"
            );
            $stmt->execute($eleveIds);
            $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Construire le message de notification
        $typeLabels = [
            'annulation'   => 'Cours annulé',
            'deplacement'  => 'Cours déplacé',
            'remplacement' => 'Remplacement de professeur',
        ];
        $label = $typeLabels[$modification['type_modification']] ?? 'Modification EDT';

        $message = "{$label} : {$modification['matiere_nom']} du {$modification['date_cours']}";
        if ($modification['motif']) {
            $message .= " — {$modification['motif']}";
        }
        if ($nouveauProf) {
            $message .= " (Remplaçant : {$nouveauProf['nom_complet']})";
        }
        if ($nouvelleSalle) {
            $message .= " (Nouvelle salle : {$nouvelleSalle['nom']})";
        }

        return [
            'modification'    => $modification,
            'nouveau_prof'    => $nouveauProf,
            'nouvelle_salle'  => $nouvelleSalle,
            'message'         => $message,
            'destinataires'   => [
                'eleves'  => $eleves,
                'parents' => $parents,
            ],
        ];
    }

    // ─── Disponibilités enseignants (CDC §7.3) ───────────────────

    /**
     * Indisponibilités déclarées d'un enseignant (créneaux récurrents bloqués).
     */
    public function getDisponibilites(int $profId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT d.*, c.label AS creneau_label, c.heure_debut, c.heure_fin
                 FROM enseignant_disponibilites d
                 JOIN creneaux_horaires c ON d.creneau_id = c.id
                 WHERE d.professeur_id = ?
                 ORDER BY FIELD(d.jour,'lundi','mardi','mercredi','jeudi','vendredi','samedi'), c.ordre"
            );
            $stmt->execute([$profId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return []; // table absente avant migration
        }
    }

    /**
     * Vrai si l'enseignant n'a PAS déclaré d'indisponibilité sur ce créneau.
     * Tolérant : si la table n'existe pas encore (avant migration), renvoie true
     * pour ne pas bloquer la détection de conflits sur les installs existants.
     */
    public function isProfAvailable(int $profId, string $jour, int $creneauId): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM enseignant_disponibilites
                 WHERE professeur_id = ? AND jour = ? AND creneau_id = ? LIMIT 1"
            );
            $stmt->execute([$profId, $jour, $creneauId]);
            return $stmt->fetchColumn() === false;
        } catch (\PDOException $e) {
            return true;
        }
    }

    /**
     * Déclare (ou met à jour) une indisponibilité.
     */
    public function setDisponibilite(int $profId, string $jour, int $creneauId, string $type = 'indisponible', ?string $motif = null): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO enseignant_disponibilites (professeur_id, jour, creneau_id, type, motif)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE type = VALUES(type), motif = VALUES(motif)"
            );
            return $stmt->execute([$profId, $jour, $creneauId, $type, $motif]);
        } catch (\PDOException $e) {
            error_log("EdtService::setDisponibilite error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Lève une indisponibilité.
     */
    public function removeDisponibilite(int $profId, string $jour, int $creneauId): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "DELETE FROM enseignant_disponibilites WHERE professeur_id = ? AND jour = ? AND creneau_id = ?"
            );
            return $stmt->execute([$profId, $jour, $creneauId]);
        } catch (\PDOException $e) {
            return false;
        }
    }

    // ─── Préférences enseignants (CDC §7.1/7.2) ──────────────────

    /**
     * Préférences pédagogiques d'un enseignant (null si non définies).
     */
    public function getPreferences(int $profId): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM enseignant_preferences WHERE professeur_id = ?");
            $stmt->execute([$profId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['extra'])) {
                $row['extra'] = $row['extra'] ? json_decode($row['extra'], true) : null;
            }
            return $row ?: null;
        } catch (\PDOException $e) {
            return null;
        }
    }

    /**
     * Enregistre les préférences d'un enseignant (upsert).
     */
    public function savePreferences(int $profId, array $data): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO enseignant_preferences
                    (professeur_id, max_heures_jour, max_heures_consecutives, pas_avant, pas_apres,
                     eviter_mercredi_apresmidi, prefere_matin, prefere_journees_groupees, extra)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    max_heures_jour = VALUES(max_heures_jour),
                    max_heures_consecutives = VALUES(max_heures_consecutives),
                    pas_avant = VALUES(pas_avant),
                    pas_apres = VALUES(pas_apres),
                    eviter_mercredi_apresmidi = VALUES(eviter_mercredi_apresmidi),
                    prefere_matin = VALUES(prefere_matin),
                    prefere_journees_groupees = VALUES(prefere_journees_groupees),
                    extra = VALUES(extra)"
            );
            return $stmt->execute([
                $profId,
                $data['max_heures_jour'] ?? null,
                $data['max_heures_consecutives'] ?? null,
                $data['pas_avant'] ?? null,
                $data['pas_apres'] ?? null,
                !empty($data['eviter_mercredi_apresmidi']) ? 1 : 0,
                !empty($data['prefere_matin']) ? 1 : 0,
                !empty($data['prefere_journees_groupees']) ? 1 : 0,
                isset($data['extra']) ? json_encode($data['extra'], JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (\PDOException $e) {
            error_log("EdtService::savePreferences error: " . $e->getMessage());
            return false;
        }
    }

    // ─── Maquette horaire (besoins, entrée du moteur) ────────────

    /**
     * Besoins de cours définis dans la maquette, prêts pour generateTimetable().
     */
    public function getMaquette(?int $classeId = null): array
    {
        try {
            $sql = "SELECT mq.id, mq.classe_id, mq.matiere_id, mq.professeur_id, mq.nb_creneaux, mq.type_cours, mq.groupe, mq.salle_type
                    FROM edt_maquette mq
                    JOIN classes cl ON mq.classe_id = cl.id
                    WHERE cl.etablissement_id = ?";
            $params = [$this->etab()];
            if ($classeId) {
                $sql .= " AND mq.classe_id = ?";
                $params[] = $classeId;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    public function addMaquette(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO edt_maquette (classe_id, matiere_id, professeur_id, nb_creneaux, type_cours, groupe, salle_type)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            (int)$data['classe_id'], (int)$data['matiere_id'], (int)$data['professeur_id'],
            (int)($data['nb_creneaux'] ?? 1), $data['type_cours'] ?? 'cours',
            $data['groupe'] ?? null, $data['salle_type'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function deleteMaquette(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM edt_maquette WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Reconstruit une liste de besoins à partir de l'EDT actif existant
     * (permet de re-générer/optimiser sans saisir de maquette).
     */
    public function buildRequirementsFromExisting(?int $classeId = null): array
    {
        $sql = "SELECT classe_id, matiere_id, professeur_id, groupe, type_cours,
                       COUNT(*) AS nb_creneaux
                FROM emploi_du_temps
                WHERE actif = 1 AND etablissement_id = ?";
        $params = [$this->etab()];
        if ($classeId) {
            $sql .= " AND classe_id = ?";
            $params[] = $classeId;
        }
        $sql .= " GROUP BY classe_id, matiere_id, professeur_id, groupe, type_cours";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── Génération automatique — moteur glouton (CDC §6) ────────

    /**
     * Génère un emploi du temps par placement glouton (dry-run : n'écrit rien).
     * Respecte les contraintes DURES uniquement : prof libre + disponible,
     * classe libre (group-aware), salle compatible libre. Optimisation des
     * contraintes souples = phase 4 (non incluse).
     *
     * Chaque "besoin" : {classe_id, matiere_id, professeur_id, nb_creneaux,
     *   groupe?, type_cours?, salle_type?, salle_required?}
     *
     * @param array $requirements Liste des besoins de cours à placer.
     * @param array $opts ['jours' => string[]]
     * @return array ['placed' => [...], 'unplaced' => [...], 'score' => [...]]
     */
    public function generateTimetable(array $requirements, array $opts = []): array
    {
        $jours = $opts['jours'] ?? ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi'];
        $creneaux = $this->getCreneauxCours();
        $creneauIds = array_column($creneaux, 'id');

        $key = static fn($j, $c) => $j . '|' . $c;

        // Occupancy seedée depuis l'EDT actif existant (ne pas écraser l'existant)
        $profBusy = $classeBusy = $salleBusy = [];
        $existingStmt = $this->pdo->prepare(
            "SELECT classe_id, professeur_id, salle_id, jour, creneau_id, groupe
             FROM emploi_du_temps WHERE actif = 1 AND etablissement_id = ?"
        );
        $existingStmt->execute([$this->etab()]);
        $existing = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($existing as $e) {
            $k = $key($e['jour'], $e['creneau_id']);
            $profBusy[$e['professeur_id']][$k] = true;
            $classeBusy[$e['classe_id']][$k][] = $e['groupe'];
            if (!empty($e['salle_id'])) $salleBusy[$e['salle_id']][$k] = true;
        }

        // Most-constrained-first : plus gros volume placé en premier
        usort($requirements, static fn($a, $b) => ((int)($b['nb_creneaux'] ?? 1)) <=> ((int)($a['nb_creneaux'] ?? 1)));

        $creneauPos = array_flip(array_values($creneauIds)); // creneau_id => position
        $prefsCache = [];                                    // prof_id => préférences
        $profDayCount = [];                                  // prof_id => [jour => nb cours]
        $placed = [];
        $unplaced = [];

        foreach ($requirements as $req) {
            $need     = (int)($req['nb_creneaux'] ?? 1);
            $classeId = (int)($req['classe_id'] ?? 0);
            $profId   = (int)($req['professeur_id'] ?? 0);
            $groupe   = $req['groupe'] ?? null;
            $salleReq = !empty($req['salle_required']);
            $prefs    = $prefsCache[$profId] ??= ($this->getPreferences($profId) ?: []);
            $maxJour  = isset($prefs['max_heures_jour']) && $prefs['max_heures_jour'] !== null
                        ? (int)$prefs['max_heures_jour'] : null;

            $matiereDayCount = []; // jour => nb de cette matière déjà posé pour la classe ce jour
            $reasons = [];         // tally des contraintes qui ont bloqué des créneaux
            $bump = function (string $r) use (&$reasons) { $reasons[$r] = ($reasons[$r] ?? 0) + 1; };
            $done = 0;

            // Une unité (créneau) placée par itération : on choisit le MEILLEUR créneau
            // faisable (contraintes dures) selon un score de préférences (contraintes souples).
            for ($unit = 0; $unit < $need; $unit++) {
                $candidates = [];

                foreach ($jours as $j) {
                    // Une seule occurrence de cette matière par jour pour la classe.
                    if (($matiereDayCount[$j] ?? 0) >= 1) continue;
                    // Contrainte dure : plafond d'heures/jour du professeur.
                    if ($maxJour !== null && ($profDayCount[$profId][$j] ?? 0) >= $maxJour) {
                        $bump('max_heures_jour');
                        continue;
                    }

                    foreach ($creneaux as $cr) {
                        $cid = (int)$cr['id'];
                        $k = $key($j, $cid);

                        if (!empty($profBusy[$profId][$k])) { $bump('prof_busy'); continue; }
                        if (!$this->isProfAvailable($profId, $j, $cid)) { $bump('prof_indispo'); continue; }
                        if (!$this->classeSlotFree($classeBusy, $classeId, $k, $groupe)) { $bump('classe_occupee'); continue; }
                        if (!$this->timeAllowed($prefs, (string)$cr['heure_debut'])) { $bump('hors_plage_horaire'); continue; }

                        $candidates[] = [
                            'j' => $j, 'cid' => $cid, 'cr' => $cr,
                            'penalty' => $this->slotPenalty($prefs, $j, $cr, $classeBusy, $classeId, $creneauPos, $profDayCount, $profId),
                        ];
                    }
                }

                if (empty($candidates)) break; // plus aucun créneau faisable pour cette unité

                usort($candidates, static fn($a, $b) => $a['penalty'] <=> $b['penalty']);

                // Descendre la liste triée jusqu'à un créneau dont la salle est résoluble.
                $chosen = null; $salleId = null;
                foreach ($candidates as $cand) {
                    $k = $key($cand['j'], $cand['cid']);
                    $sid = null;
                    foreach ($this->suggestSalle([
                        'jour' => $cand['j'], 'creneau_id' => $cand['cid'], 'classe_id' => $classeId,
                        'salle_type' => $req['salle_type'] ?? null,
                    ]) as $cs) {
                        if (empty($salleBusy[$cs['id']][$k])) { $sid = (int)$cs['id']; break; }
                    }
                    if ($sid === null && $salleReq) { $bump('pas_de_salle'); continue; }
                    $chosen = $cand; $salleId = $sid; break;
                }

                if ($chosen === null) break; // aucune salle disponible pour les candidats

                $j = $chosen['j']; $cid = $chosen['cid']; $cr = $chosen['cr'];
                $k = $key($j, $cid);
                $placed[] = [
                    'classe_id'     => $classeId,
                    'matiere_id'    => (int)($req['matiere_id'] ?? 0),
                    'professeur_id' => $profId,
                    'salle_id'      => $salleId,
                    'jour'          => $j,
                    'creneau_id'    => $cid,
                    'heure_debut'   => $cr['heure_debut'],
                    'heure_fin'     => $cr['heure_fin'],
                    'groupe'        => $groupe,
                    'type_cours'    => $req['type_cours'] ?? 'cours',
                ];
                $profBusy[$profId][$k] = true;
                $classeBusy[$classeId][$k][] = $groupe;
                if ($salleId !== null) $salleBusy[$salleId][$k] = true;
                $matiereDayCount[$j] = ($matiereDayCount[$j] ?? 0) + 1;
                $profDayCount[$profId][$j] = ($profDayCount[$profId][$j] ?? 0) + 1;
                $done++;
            }

            if ($done < $need) {
                $unplaced[] = [
                    'requirement' => $req,
                    'placed'      => $done,
                    'missing'     => $need - $done,
                    'reasons'     => $reasons,
                    'suggestion'  => $this->explainUnplaced($reasons),
                ];
            }
        }

        return [
            'placed'   => $placed,
            'unplaced' => $unplaced,
            'score'    => $this->scorePlan($placed, $creneauIds, $prefsCache),
        ];
    }

    /**
     * Vrai si l'heure de début respecte les bornes pas_avant / pas_apres du prof
     * (contraintes dures). pas_avant = pas de cours AVANT cette heure ;
     * pas_apres = pas de cours APRÈS cette heure.
     */
    private function timeAllowed(array $prefs, string $heureDebut): bool
    {
        $h = substr($heureDebut, 0, 5);
        if (!empty($prefs['pas_avant']) && $h < substr((string)$prefs['pas_avant'], 0, 5)) return false;
        if (!empty($prefs['pas_apres']) && $h > substr((string)$prefs['pas_apres'], 0, 5)) return false;
        return true;
    }

    /**
     * Score de préférences (contraintes souples) d'un créneau candidat. Plus bas = mieux.
     */
    private function slotPenalty(array $prefs, string $jour, array $cr, array $classeBusy, int $classeId, array $creneauPos, array $profDayCount, int $profId): int
    {
        $penalty = 0;
        $apresMidi = substr((string)$cr['heure_debut'], 0, 5) >= '12:00';

        if (!empty($prefs['prefere_matin']) && $apresMidi) $penalty += 3;
        if (!empty($prefs['eviter_mercredi_apresmidi']) && $jour === 'mercredi' && $apresMidi) $penalty += 5;

        // Contiguïté : pénaliser un créneau non adjacent aux cours déjà posés ce jour pour la classe.
        $pos = $creneauPos[(int)$cr['id']] ?? 0;
        $dayPositions = [];
        foreach (($classeBusy[$classeId] ?? []) as $bk => $occ) {
            if (str_starts_with($bk, $jour . '|')) {
                $bcid = (int)substr($bk, strlen($jour) + 1);
                $dayPositions[] = $creneauPos[$bcid] ?? 0;
            }
        }
        if (!empty($dayPositions)) {
            $minDist = PHP_INT_MAX;
            foreach ($dayPositions as $dp) $minDist = min($minDist, abs((float) ($dp - $pos)));
            if ($minDist > 1) $penalty += 2; // crée un trou
        }

        // Regrouper les journées du prof : pénaliser l'ouverture d'un nouveau jour.
        if (!empty($prefs['prefere_journees_groupees']) && ($profDayCount[$profId][$jour] ?? 0) === 0) {
            $penalty += 1;
        }

        return $penalty;
    }

    /**
     * Construit un message d'explication à partir du tally des contraintes bloquantes.
     */
    private function explainUnplaced(array $reasons): string
    {
        if (empty($reasons)) return "Aucun créneau libre compatible.";
        arsort($reasons);
        $dominant = array_key_first($reasons);
        return match ($dominant) {
            'prof_busy'          => "Professeur déjà occupé sur la plupart des créneaux — alléger sa charge ou changer de professeur.",
            'prof_indispo'       => "Professeur indisponible sur la plupart des créneaux — lever une indisponibilité ou changer de professeur.",
            'classe_occupee'     => "Classe déjà pleine sur les créneaux candidats — libérer un créneau ou utiliser un groupe distinct.",
            'hors_plage_horaire' => "Créneaux exclus par les préférences horaires du professeur (pas avant/après) — élargir la plage.",
            'max_heures_jour'    => "Plafond d'heures/jour du professeur atteint — augmenter le plafond ou répartir sur plus de jours.",
            'pas_de_salle'       => "Aucune salle compatible disponible — ajouter une salle ou assouplir le type requis.",
            default              => "Aucun créneau libre compatible.",
        };
    }

    /**
     * Vrai si le créneau de la classe est libre pour ce groupe.
     * Deux cours simultanés tolérés seulement si groupes distincts et définis.
     */
    private function classeSlotFree(array $classeBusy, int $classeId, string $k, ?string $groupe): bool
    {
        $occ = $classeBusy[$classeId][$k] ?? null;
        if (empty($occ)) return true;
        if ($groupe === null) return false;
        foreach ($occ as $g) {
            if ($g === null || $g === $groupe) return false;
        }
        return true;
    }

    /**
     * Score qualité simple d'un plan (CDC §15.2) : nombre de "trous" élèves
     * (créneaux vides entre deux cours d'une même classe le même jour). Plus bas = mieux.
     */
    private function scorePlan(array $placed, array $creneauIds, array $prefsCache = []): array
    {
        $ordre = array_flip(array_values($creneauIds)); // creneau_id => position
        $byClasseDay = [];
        $prefsViolations = 0;
        foreach ($placed as $p) {
            $byClasseDay[$p['classe_id']][$p['jour']][] = $ordre[$p['creneau_id']] ?? 0;

            // Violations de préférences souples (les dures ne sont jamais placées en violation).
            $prefs = $prefsCache[$p['professeur_id']] ?? [];
            if (!empty($prefs)) {
                $apresMidi = substr((string)$p['heure_debut'], 0, 5) >= '12:00';
                if (!empty($prefs['prefere_matin']) && $apresMidi) $prefsViolations++;
                if (!empty($prefs['eviter_mercredi_apresmidi']) && $p['jour'] === 'mercredi' && $apresMidi) $prefsViolations++;
            }
        }
        $trous = 0;
        foreach ($byClasseDay as $days) {
            foreach ($days as $positions) {
                sort($positions);
                $span = end($positions) - $positions[0];
                $trous += max(0, $span - (count($positions) - 1));
            }
        }
        return [
            'placed'           => count($placed),
            'trous_classes'    => $trous,
            'prefs_violations' => $prefsViolations,
            'score_global'     => $trous + $prefsViolations,
        ];
    }

    /**
     * Persiste un plan généré dans emploi_du_temps (transaction).
     * Si $replaceClasses, désactive d'abord les cours actifs des classes concernées.
     *
     * @return int Nombre de cours insérés (0 = échec/rollback).
     */
    public function applyGeneratedPlan(array $placed, bool $replaceClasses = false): int
    {
        if (empty($placed)) return 0;

        try {
            $this->pdo->beginTransaction();

            $etabId = $this->etab();

            if ($replaceClasses) {
                $classeIds = array_values(array_unique(array_map(static fn($p) => (int)$p['classe_id'], $placed)));
                $in = implode(',', array_fill(0, count($classeIds), '?'));
                $del = $this->pdo->prepare("UPDATE emploi_du_temps SET actif = 0 WHERE classe_id IN ({$in}) AND actif = 1 AND etablissement_id = ?");
                $del->execute(array_merge($classeIds, [$etabId]));
            }

            $ins = $this->pdo->prepare(
                "INSERT INTO emploi_du_temps
                    (classe_id, matiere_id, professeur_id, salle_id, jour, creneau_id,
                     heure_debut, heure_fin, groupe, type_cours, recurrence, etablissement_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'hebdomadaire', ?)"
            );
            $n = 0;
            foreach ($placed as $p) {
                $ins->execute([
                    $p['classe_id'], $p['matiere_id'], $p['professeur_id'], $p['salle_id'] ?? null,
                    $p['jour'], $p['creneau_id'], $p['heure_debut'], $p['heure_fin'],
                    $p['groupe'] ?? null, $p['type_cours'] ?? 'cours', $etabId,
                ]);
                $n++;
            }

            $this->pdo->commit();
            return $n;
        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("EdtService::applyGeneratedPlan error: " . $e->getMessage());
            return 0;
        }
    }
}
