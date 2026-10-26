<?php
declare(strict_types=1);

namespace API\Services\Home;

use PDO;

/**
 * Accueil — données précises par rôle (élève, parent, professeur, vie scolaire, direction).
 * Chaque méthode renvoie des données prêtes à afficher, bornées à l'établissement courant.
 */
final class HomeService
{
    private PDO $pdo;
    private int $etab;
    private const JOURS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi'];

    public function __construct(PDO $pdo, ?int $etab = null)
    {
        $this->pdo = $pdo;
        $this->etab = $etab ?? (int) \API\Core\EstablishmentContext::id();
    }

    private function all(string $sql, array $p = []): array
    {
        try { $st = $this->pdo->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); }
        catch (\Throwable $e) { error_log('[accueil] ' . $e->getMessage()); return []; }
    }
    private function one(string $sql, array $p = []): mixed
    {
        try { $st = $this->pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); }
        catch (\Throwable $e) { error_log('[accueil] ' . $e->getMessage()); return false; }
    }

    // ───────────────────────────── Période ─────────────────────────────

    /** Période (trimestre) en cours : [id, numero, nom, debut, fin] */
    public function periode(): ?array
    {
        $r = $this->all('SELECT id, numero, nom, date_debut, date_fin FROM periodes WHERE etablissement_id = ? AND CURDATE() BETWEEN date_debut AND date_fin ORDER BY numero LIMIT 1', [$this->etab]);
        if ($r) { return $r[0]; }
        $r = $this->all('SELECT id, numero, nom, date_debut, date_fin FROM periodes WHERE etablissement_id = ? AND date_debut <= CURDATE() ORDER BY date_debut DESC LIMIT 1', [$this->etab]);
        return $r[0] ?? null;
    }

    // ─────────────────────────── Emploi du temps ───────────────────────────

    /**
     * Journée de cours (aujourd'hui, ou le prochain jour de classe dans les 7 jours).
     * @param array{classe_id?:int,professeur_id?:int} $who
     * @return array{date:string,is_today:bool,items:array}
     */
    public function day(array $who): array
    {
        $d = new \DateTimeImmutable('today');
        for ($i = 0; $i < 8; $i++) {
            $items = $this->coursesOn($d, $who);
            $isToday = $i === 0;
            // aujourd'hui : on garde la journée même terminée si elle existait (affichage « terminé »)
            if ($items) { return ['date' => $d->format('Y-m-d'), 'is_today' => $isToday, 'items' => $items]; }
            $d = $d->modify('+1 day');
        }
        return ['date' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'is_today' => true, 'items' => []];
    }

    private function coursesOn(\DateTimeImmutable $d, array $who): array
    {
        $jour = self::JOURS[(int) $d->format('N')] ?? null;
        if (!$jour) { return []; }
        $date = $d->format('Y-m-d');
        $w = 'e.etablissement_id = ? AND e.jour = ? AND e.actif = 1
              AND (e.date_debut_validite IS NULL OR e.date_debut_validite <= ?) AND (e.date_fin_validite IS NULL OR e.date_fin_validite >= ?)';
        $p = [$this->etab, $jour, $date, $date];
        if (!empty($who['classe_id'])) { $w .= ' AND e.classe_id = ?'; $p[] = (int) $who['classe_id']; }
        if (!empty($who['professeur_id'])) { $w .= ' AND e.professeur_id = ?'; $p[] = (int) $who['professeur_id']; }
        $rows = $this->all("SELECT e.id, e.classe_id, e.matiere_id, e.professeur_id, e.heure_debut, e.heure_fin, e.type_cours,
                   m.nom AS matiere, COALESCE(e.couleur, m.couleur) AS couleur, s.nom AS salle, c.nom AS classe,
                   CONCAT(p.prenom, ' ', p.nom) AS professeur
              FROM emploi_du_temps e
              LEFT JOIN matieres m ON m.id = e.matiere_id
              LEFT JOIN salles s ON s.id = e.salle_id
              LEFT JOIN classes c ON c.id = e.classe_id
              LEFT JOIN professeurs p ON p.id = e.professeur_id
             WHERE {$w} ORDER BY e.heure_debut", $p);
        // fusion des créneaux consécutifs identiques (EPS 2 h…) pour une lecture claire
        $out = [];
        foreach ($rows as $r) {
            $last = $out ? $out[count($out) - 1] : null;
            if ($last && $last['matiere_id'] === $r['matiere_id'] && $last['classe_id'] === $r['classe_id'] && $last['professeur_id'] === $r['professeur_id']
                && substr((string) $last['heure_fin'], 0, 5) === substr((string) $r['heure_debut'], 0, 5)) {
                $out[count($out) - 1]['heure_fin'] = $r['heure_fin'];
                continue;
            }
            $out[] = $r;
        }
        $now = date('H:i:s'); $isToday = $date === date('Y-m-d');
        foreach ($out as &$c) {
            $c['debut'] = substr((string) $c['heure_debut'], 0, 5);
            $c['fin'] = substr((string) $c['heure_fin'], 0, 5);
            $c['etat'] = !$isToday ? 'avenir' : ($now >= $c['heure_fin'] ? 'passe' : ($now >= $c['heure_debut'] ? 'encours' : 'avenir'));
        }
        unset($c);
        return $out;
    }

    /** Appels du professeur pour une date : [classe_id|heure_debut => [id, statut]] */
    public function appels(int $profId, string $date): array
    {
        $map = [];
        foreach ($this->all('SELECT id, classe_id, heure_debut, statut FROM appels WHERE etablissement_id = ? AND professeur_id = ? AND date_appel = ?', [$this->etab, $profId, $date]) as $a) {
            $map[(int) $a['classe_id'] . '|' . substr((string) $a['heure_debut'], 0, 5)] = $a;
        }
        return $map;
    }

    // ───────────────────────────── Élève ─────────────────────────────

    public function eleve(int $id): ?array
    {
        $r = $this->all('SELECT e.id, e.prenom, e.nom, e.classe, c.id AS classe_id FROM eleves e LEFT JOIN classes c ON c.nom = e.classe AND c.etablissement_id = e.etablissement_id
                          WHERE e.id = ? AND e.etablissement_id = ?', [$id, $this->etab]);
        return $r[0] ?? null;
    }

    /** Dernières notes avec la moyenne de la classe sur la MÊME évaluation. */
    public function dernieresNotes(int $eleveId, string $classe, int $limit = 6): array
    {
        $rows = $this->all("SELECT n.id, n.note, n.note_sur, n.coefficient, n.type_evaluation, n.date_note, n.commentaire, n.id_matiere, n.id_professeur,
                   m.nom AS matiere, m.couleur,
                   (SELECT ROUND(AVG(n2.note * 20 / NULLIF(n2.note_sur, 0)), 2) FROM notes n2 JOIN eleves e2 ON e2.id = n2.id_eleve
                     WHERE n2.id_matiere = n.id_matiere AND n2.id_professeur = n.id_professeur AND n2.date_note = n.date_note
                       AND n2.type_evaluation <=> n.type_evaluation AND e2.classe = ? AND n2.etablissement_id = n.etablissement_id) AS moy_classe
              FROM notes n LEFT JOIN matieres m ON m.id = n.id_matiere
             WHERE n.id_eleve = ? AND n.etablissement_id = ?
             ORDER BY n.date_note DESC, n.id DESC LIMIT " . (int) $limit, [$classe, $eleveId, $this->etab]);
        foreach ($rows as &$r) {
            $sur = (float) $r['note_sur'] ?: 20.0;
            $r['sur20'] = round((float) $r['note'] * 20 / $sur, 2);
            $r['nouveau'] = strtotime((string) $r['date_note']) >= strtotime('-3 days');
        }
        unset($r);
        return $rows;
    }

    /**
     * Moyennes par matière (période en cours) de l'élève et de sa classe, + moyenne générale.
     * @return array{matieres:array,generale:?float,generale_classe:?float}
     */
    public function moyennes(int $eleveId, string $classe, ?array $periode): array
    {
        $tri = $periode ? (int) $periode['numero'] : null;
        $cond = $tri ? ' AND n.trimestre = ' . $tri : '';
        $mine = $this->all("SELECT n.id_matiere, m.nom, m.couleur, COALESCE(m.coefficient, 1) AS coef_m,
                   SUM(n.note * 20 / NULLIF(n.note_sur, 0) * n.coefficient) / NULLIF(SUM(n.coefficient), 0) AS moy, COUNT(*) AS nb
              FROM notes n JOIN matieres m ON m.id = n.id_matiere
             WHERE n.id_eleve = ? AND n.etablissement_id = ? {$cond} GROUP BY n.id_matiere, m.nom, m.couleur, m.coefficient ORDER BY m.nom", [$eleveId, $this->etab]);
        // moyenne de la classe par matière = moyenne des moyennes des élèves
        $cls = $this->all("SELECT x.id_matiere, AVG(x.moy) AS moy, MIN(x.moy) AS mini, MAX(x.moy) AS maxi FROM (
                SELECT n.id_eleve, n.id_matiere, SUM(n.note * 20 / NULLIF(n.note_sur, 0) * n.coefficient) / NULLIF(SUM(n.coefficient), 0) AS moy
                  FROM notes n JOIN eleves e ON e.id = n.id_eleve
                 WHERE e.classe = ? AND n.etablissement_id = ? {$cond} GROUP BY n.id_eleve, n.id_matiere) x GROUP BY x.id_matiere", [$classe, $this->etab]);
        $c = []; foreach ($cls as $r) { $c[(int) $r['id_matiere']] = $r; }
        $out = []; $sum = 0; $w = 0; $sumC = 0; $wC = 0;
        foreach ($mine as $r) {
            $mid = (int) $r['id_matiere'];
            $moy = $r['moy'] !== null ? round((float) $r['moy'], 2) : null;
            $mc = isset($c[$mid]) ? round((float) $c[$mid]['moy'], 2) : null;
            $out[] = ['matiere' => $r['nom'], 'couleur' => $r['couleur'], 'moy' => $moy, 'classe' => $mc, 'nb' => (int) $r['nb'],
                'mini' => isset($c[$mid]) ? round((float) $c[$mid]['mini'], 1) : null, 'maxi' => isset($c[$mid]) ? round((float) $c[$mid]['maxi'], 1) : null];
            if ($moy !== null) { $sum += $moy * (float) $r['coef_m']; $w += (float) $r['coef_m']; }
            if ($mc !== null) { $sumC += $mc * (float) $r['coef_m']; $wC += (float) $r['coef_m']; }
        }
        return ['matieres' => $out, 'generale' => $w ? round($sum / $w, 2) : null, 'generale_classe' => $wC ? round($sumC / $wC, 2) : null];
    }

    /** Devoirs à faire (à partir d'aujourd'hui), avec l'état « fait » de l'élève. */
    public function devoirs(string $classe, ?int $eleveId, int $limit = 10): array
    {
        $rows = $this->all('SELECT d.id, d.titre, d.description, d.nom_matiere AS matiere, d.nom_professeur AS professeur, d.date_rendu, d.date_ajout,
                   m.couleur, ' . ($eleveId ? 'COALESCE(s.fait, 0)' : '0') . ' AS fait
              FROM devoirs d LEFT JOIN matieres m ON m.nom = d.nom_matiere AND m.etablissement_id = d.etablissement_id '
            . ($eleveId ? 'LEFT JOIN devoirs_statuts_eleve s ON s.devoir_id = d.id AND s.eleve_id = ' . (int) $eleveId : '') . '
             WHERE d.classe = ? AND d.etablissement_id = ? AND d.date_rendu >= CURDATE()
             ORDER BY d.date_rendu, d.nom_matiere LIMIT ' . (int) $limit, [$classe, $this->etab]);
        foreach ($rows as &$r) { $r['fait'] = (bool) $r['fait']; $r['nouveau'] = strtotime((string) $r['date_ajout']) >= strtotime('-2 days'); }
        unset($r);
        return $rows;
    }

    public function setDevoirFait(int $eleveId, int $devoirId, bool $fait): bool
    {
        $ok = $this->one('SELECT 1 FROM devoirs d JOIN eleves e ON e.classe = d.classe AND e.etablissement_id = d.etablissement_id WHERE d.id = ? AND e.id = ? AND d.etablissement_id = ?', [$devoirId, $eleveId, $this->etab]);
        if (!$ok) { return false; }
        $this->pdo->prepare('DELETE FROM devoirs_statuts_eleve WHERE eleve_id = ? AND devoir_id = ?')->execute([$eleveId, $devoirId]);
        $this->pdo->prepare('INSERT INTO devoirs_statuts_eleve (eleve_id, devoir_id, fait, date_marque) VALUES (?,?,?,NOW())')->execute([$eleveId, $devoirId, $fait ? 1 : 0]);
        return true;
    }

    /** Bilan absences / retards sur la période. */
    public function vieScolaire(int $eleveId, ?array $periode): array
    {
        $from = $periode['date_debut'] ?? date('Y-m-d', strtotime('-90 days'));
        $abs = $this->all('SELECT date_debut, date_fin, motif, justifie, type_absence FROM absences WHERE id_eleve = ? AND etablissement_id = ? AND date_debut >= ? ORDER BY date_debut DESC', [$eleveId, $this->etab, $from]);
        $ret = $this->all('SELECT date_retard, duree_minutes, justifie FROM retards WHERE id_eleve = ? AND etablissement_id = ? AND date_retard >= ? ORDER BY date_retard DESC', [$eleveId, $this->etab, $from]);
        $heures = 0;
        foreach ($abs as $a) { $heures += max(0, (strtotime((string) $a['date_fin']) - strtotime((string) $a['date_debut'])) / 3600); }
        return [
            'absences' => count($abs), 'non_justifiees' => count(array_filter($abs, fn($a) => !(int) $a['justifie'])),
            'heures' => (int) round(min($heures, count($abs) * 9.5)), 'retards' => count($ret),
            'retards_nj' => count(array_filter($ret, fn($r) => !(int) $r['justifie'])), 'derniere' => $abs[0] ?? null, 'dernier_retard' => $ret[0] ?? null,
        ];
    }

    /** Événements à venir (classe facultative). */
    public function evenements(?string $classe = null, int $days = 21, int $limit = 5): array
    {
        $sql = "SELECT id, titre, date_debut, date_fin, type_evenement, lieu, classes FROM evenements
                 WHERE etablissement_id = ? AND statut = 'actif' AND date_fin >= NOW() AND date_debut <= DATE_ADD(NOW(), INTERVAL ? DAY)";
        $p = [$this->etab, $days];
        if ($classe !== null) { $sql .= " AND (classes IS NULL OR classes = '' OR FIND_IN_SET(?, REPLACE(classes, ' ', '')))"; $p[] = $classe; }
        return $this->all($sql . ' ORDER BY date_debut LIMIT ' . (int) $limit, $p);
    }

    public function menu(): ?array
    {
        $r = $this->all('SELECT date_menu, entree, plat_principal, accompagnement, dessert, regime_special FROM menus_cantine WHERE etablissement_id = ? AND date_menu >= CURDATE() ORDER BY date_menu LIMIT 1', [$this->etab]);
        return $r[0] ?? null;
    }

    // ───────────────────────────── Parent ─────────────────────────────

    public function enfants(int $parentId): array
    {
        return $this->all('SELECT e.id, e.prenom, e.nom, e.classe FROM parent_eleve pe JOIN eleves e ON e.id = pe.id_eleve
                            WHERE pe.id_parent = ? AND e.etablissement_id = ? AND e.actif = 1 ORDER BY e.date_naissance', [$parentId, $this->etab]);
    }

    // ─────────────────────────── Professeur ───────────────────────────

    /** Dernières évaluations saisies (regroupées) avec moyenne, min, max, nombre de copies. */
    public function evaluationsProf(int $profId, int $limit = 5): array
    {
        return $this->all("SELECT n.id_matiere, m.nom AS matiere, m.couleur, e.classe, n.date_note, n.type_evaluation, COUNT(*) AS copies,
                   ROUND(AVG(n.note * 20 / NULLIF(n.note_sur, 0)), 2) AS moyenne, ROUND(MIN(n.note * 20 / NULLIF(n.note_sur, 0)), 1) AS mini,
                   ROUND(MAX(n.note * 20 / NULLIF(n.note_sur, 0)), 1) AS maxi, MAX(n.date_creation) AS saisie
              FROM notes n JOIN eleves e ON e.id = n.id_eleve LEFT JOIN matieres m ON m.id = n.id_matiere
             WHERE n.id_professeur = ? AND n.etablissement_id = ?
             GROUP BY n.id_matiere, m.nom, m.couleur, e.classe, n.date_note, n.type_evaluation
             ORDER BY n.date_note DESC, MAX(n.id) DESC LIMIT " . (int) $limit, [$profId, $this->etab]);
    }

    /** Classes du professeur avec effectif, moyenne de ses notes et absents du jour. */
    public function classesProf(int $profId): array
    {
        $cls = $this->all('SELECT DISTINCT c.id, c.nom FROM classes c WHERE c.etablissement_id = ? AND (c.nom IN (SELECT nom_classe FROM professeur_classes WHERE id_professeur = ?)
                             OR c.id IN (SELECT classe_id FROM emploi_du_temps WHERE professeur_id = ?)) ORDER BY c.nom', [$this->etab, $profId, $profId]);
        foreach ($cls as &$c) {
            $c['effectif'] = (int) $this->one('SELECT COUNT(*) FROM eleves WHERE classe = ? AND etablissement_id = ? AND actif = 1', [$c['nom'], $this->etab]);
            $m = $this->one('SELECT ROUND(AVG(n.note * 20 / NULLIF(n.note_sur, 0)), 2) FROM notes n JOIN eleves e ON e.id = n.id_eleve WHERE e.classe = ? AND n.id_professeur = ? AND n.etablissement_id = ?', [$c['nom'], $profId, $this->etab]);
            $c['moyenne'] = $m !== false && $m !== null ? (float) $m : null;
            $c['absents'] = (int) $this->one('SELECT COUNT(DISTINCT a.id_eleve) FROM absences a JOIN eleves e ON e.id = a.id_eleve WHERE e.classe = ? AND a.etablissement_id = ? AND DATE(a.date_debut) <= CURDATE() AND DATE(a.date_fin) >= CURDATE()', [$c['nom'], $this->etab]);
            $c['pp'] = (bool) $this->one('SELECT 1 FROM classes WHERE id = ? AND professeur_principal_id = ?', [$c['id'], $profId]);
        }
        unset($c);
        return $cls;
    }

    /** Devoirs donnés par le professeur, à venir. */
    public function devoirsDonnes(string $nomProf, int $limit = 6): array
    {
        return $this->all('SELECT id, titre, classe, nom_matiere AS matiere, date_rendu FROM devoirs WHERE etablissement_id = ? AND date_rendu >= CURDATE() AND nom_professeur = ? ORDER BY date_rendu LIMIT ' . (int) $limit, [$this->etab, $nomProf]);
    }

    // ───────────────────── Vie scolaire / direction ─────────────────────

    public function vieScolaireJour(): array
    {
        $absents = $this->all("SELECT a.id, a.id_eleve, e.prenom, e.nom, e.classe, a.date_debut, a.date_fin, a.justifie, a.motif
              FROM absences a JOIN eleves e ON e.id = a.id_eleve
             WHERE a.etablissement_id = ? AND DATE(a.date_debut) <= CURDATE() AND DATE(a.date_fin) >= CURDATE() ORDER BY e.classe, e.nom", [$this->etab]);
        $retards = (int) $this->one('SELECT COUNT(*) FROM retards WHERE etablissement_id = ? AND DATE(date_retard) = CURDATE()', [$this->etab]);
        $aTraiter = $this->all("SELECT a.id, e.prenom, e.nom, e.classe, a.date_debut, a.date_fin, a.motif
              FROM absences a JOIN eleves e ON e.id = a.id_eleve
             WHERE a.etablissement_id = ? AND a.justifie = 0 AND a.date_debut >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             ORDER BY a.date_debut DESC LIMIT 8", [$this->etab]);
        $nbATraiter = (int) $this->one('SELECT COUNT(*) FROM absences WHERE etablissement_id = ? AND justifie = 0 AND date_debut >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', [$this->etab]);
        $incidents = (int) $this->one('SELECT COUNT(*) FROM incidents WHERE etablissement_id = ? AND date_incident >= DATE_SUB(NOW(), INTERVAL 7 DAY)', [$this->etab]);
        $effectif = (int) $this->one('SELECT COUNT(*) FROM eleves WHERE etablissement_id = ? AND actif = 1', [$this->etab]);
        $nAbs = count(array_unique(array_column($absents, 'id_eleve')));
        return ['absents' => $absents, 'nb_absents' => $nAbs, 'retards' => $retards, 'a_traiter' => $aTraiter, 'nb_a_traiter' => $nbATraiter,
            'incidents' => $incidents, 'effectif' => $effectif, 'presence' => $effectif ? round(100 * ($effectif - $nAbs) / $effectif, 1) : null];
    }

    public function direction(?array $periode): array
    {
        $tri = $periode ? (int) $periode['numero'] : null;
        $q = fn($sql, $p = []) => (int) $this->one($sql, array_merge([$this->etab], $p));
        $moy = $this->one('SELECT ROUND(AVG(note * 20 / NULLIF(note_sur, 0)), 2) FROM notes WHERE etablissement_id = ?' . ($tri ? ' AND trimestre = ' . $tri : ''), [$this->etab]);
        $notesSemaine = $q('SELECT COUNT(*) FROM notes WHERE etablissement_id = ? AND date_creation >= DATE_SUB(NOW(), INTERVAL 7 DAY)');
        $parJour = $this->all('SELECT DATE(date_creation) d, COUNT(*) n FROM notes WHERE etablissement_id = ? AND date_creation >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(date_creation)', [$this->etab]);
        $map = []; foreach ($parJour as $r) { $map[$r['d']] = (int) $r['n']; }
        $serie = [];
        for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-{$i} days")); $serie[] = ['d' => $d, 'n' => $map[$d] ?? 0]; }
        $classes = $this->all("SELECT e.classe, COUNT(DISTINCT e.id) eleves, ROUND(AVG(n.note * 20 / NULLIF(n.note_sur, 0)), 2) moyenne
              FROM eleves e LEFT JOIN notes n ON n.id_eleve = e.id" . ($tri ? ' AND n.trimestre = ' . $tri : '') . "
             WHERE e.etablissement_id = ? AND e.actif = 1 GROUP BY e.classe ORDER BY e.classe", [$this->etab]);
        return [
            'eleves' => $q('SELECT COUNT(*) FROM eleves WHERE etablissement_id = ? AND actif = 1'),
            'professeurs' => $q('SELECT COUNT(*) FROM professeurs WHERE etablissement_id = ? AND actif = 1'),
            'moyenne' => $moy !== false && $moy !== null ? (float) $moy : null, 'notes_semaine' => $notesSemaine, 'serie_notes' => $serie,
            'comptes_bloques' => $q('SELECT COUNT(*) FROM accounts WHERE etablissement_id = ? AND locked_until > NOW()'),
            'classes' => $classes,
        ];
    }

    public function annonces(int $limit = 4): array
    {
        return $this->all('SELECT id, titre, contenu, type, epingle, date_publication FROM annonces WHERE etablissement_id = ? AND publie = 1
                             AND (date_expiration IS NULL OR date_expiration > NOW()) ORDER BY epingle DESC, date_publication DESC LIMIT ' . (int) $limit, [$this->etab]);
    }

    // ───────────────────────── Préférences ─────────────────────────

    /** @return array{order:string[],hidden:string[]} */
    public function prefs(int $uid, string $utype): array
    {
        $raw = $this->one('SELECT accueil_config FROM user_settings WHERE user_id = ? AND user_type = ?', [$uid, $utype]);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        $order = is_array($j['order'] ?? null) ? array_values(array_filter($j['order'], 'is_string')) : [];
        $hidden = is_array($j['hidden'] ?? null) ? array_values(array_filter($j['hidden'], 'is_string')) : [];
        return ['order' => $order, 'hidden' => $hidden];
    }

    public function savePrefs(int $uid, string $utype, array $order, array $hidden): void
    {
        $json = json_encode(['v' => 2, 'order' => array_slice(array_values($order), 0, 40), 'hidden' => array_slice(array_values($hidden), 0, 40)]);
        $exists = $this->one('SELECT id FROM user_settings WHERE user_id = ? AND user_type = ?', [$uid, $utype]);
        if ($exists) { $this->pdo->prepare('UPDATE user_settings SET accueil_config = ? WHERE id = ?')->execute([$json, (int) $exists]); }
        else { $this->pdo->prepare('INSERT INTO user_settings (etablissement_id, user_id, user_type, accueil_config) VALUES (?,?,?,?)')->execute([$this->etab, $uid, $utype, $json]); }
    }
}
