<?php
declare(strict_types=1);

namespace API\Services;

/**
 * CodeAuditService — audit statique « qualité » du dépôt (CDC §20).
 *
 * Détecte les anti-patterns à fort signal :
 *   - modules incomplets (module.json invalide / champs manquants / permissions absentes)
 *   - placeholders / TODO / FIXME visibles en production
 *   - endpoints traitant des POST sans vérification CSRF
 *   - requêtes métier sur des tables scopées sans filtre etablissement_id
 *
 * Heuristique : zéro exécution de code analysé, lecture de fichiers + regex.
 * Les résultats « scope » et « csrf » sont best-effort et marqués à vérifier.
 */
class CodeAuditService
{
    private string $basePath;

    /** Dossiers ignorés pendant le scan. */
    private const SKIP_DIRS = [
        'vendor', 'node_modules', '.git', 'storage', 'temp', 'uploads',
        'websocket-server', 'lang', 'assets', 'tests', 'API/Legacy',
    ];

    /** Tables métier devant porter etablissement_id (sous-ensemble à fort signal). */
    private const SCOPED_TABLES = [
        'eleves', 'classes', 'matieres', 'professeurs', 'salles', 'emploi_du_temps',
        'notes', 'absences', 'retards', 'devoirs', 'annonces', 'documents', 'evenements',
        'bulletins', 'sanctions', 'incidents',
    ];

    /** Plafond de fichiers scannés (garde-fou perf). */
    private const MAX_FILES = 3000;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    /**
     * Lance l'audit complet.
     * @return array{findings: array<int,array>, summary: array<string,int>, scanned: int}
     */
    public function run(): array
    {
        $findings = [];
        $scanned = 0;

        $findings = array_merge($findings, $this->auditModules());

        foreach ($this->iterateFiles() as $path) {
            $scanned++;
            if ($scanned > self::MAX_FILES) break;

            $rel = $this->relative($path);
            $content = @file_get_contents($path);
            if ($content === false) continue;

            $isPhp = str_ends_with($path, '.php');

            $findings = array_merge($findings, $this->checkPlaceholders($rel, $content));
            if ($isPhp) {
                $findings = array_merge($findings, $this->checkCsrf($rel, $content));
                $findings = array_merge($findings, $this->checkScope($rel, $content));
            }
        }

        // Tri par gravité.
        $rank = ['critique' => 0, 'haute' => 1, 'moyenne' => 2, 'faible' => 3];
        usort($findings, static fn($a, $b) => ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9));

        $summary = ['critique' => 0, 'haute' => 0, 'moyenne' => 0, 'faible' => 0];
        foreach ($findings as $f) {
            $summary[$f['severity']] = ($summary[$f['severity']] ?? 0) + 1;
        }

        return ['findings' => $findings, 'summary' => $summary, 'scanned' => $scanned];
    }

    // ─── Checks ──────────────────────────────────────────────────────

    private function auditModules(): array
    {
        $out = [];
        foreach (glob($this->basePath . '/*/module.json') ?: [] as $manifestPath) {
            $rel = $this->relative($manifestPath);
            $raw = @file_get_contents($manifestPath);
            $data = $raw !== false ? json_decode($raw, true) : null;

            if (!is_array($data)) {
                $out[] = $this->finding('haute', 'module', $rel, 'module.json illisible ou JSON invalide.', 'Corriger la syntaxe JSON du manifeste.');
                continue;
            }
            foreach (['key', 'name'] as $req) {
                if (empty($data[$req])) {
                    $out[] = $this->finding('haute', 'module', $rel, "Champ obligatoire manquant : '{$req}'.", "Ajouter '{$req}' au module.json.");
                }
            }
            if (empty($data['permissions'])) {
                $out[] = $this->finding('faible', 'module', $rel, 'Aucune permission déclarée.', 'Déclarer un bloc "permissions" (view/manage…).');
            }
        }
        return $out;
    }

    private function checkPlaceholders(string $rel, string $content): array
    {
        // Ne pas s'auto-signaler : ce service contient littéralement les marqueurs
        // recherchés dans ses propres regex (faux positif systématique).
        if (str_contains($rel, 'CodeAuditService')) return [];
        // Marqueurs à fort signal uniquement (éviter de matcher les attributs HTML placeholder="…").
        if (preg_match_all('/\b(TODO|FIXME|HACK|XXX)\b|disponible prochainement|bient[oô]t disponible|coming soon/i', $content, $m)) {
            $n = count($m[0]);
            return [$this->finding('moyenne', 'placeholder', $rel, "{$n} marqueur(s) TODO/FIXME ou texte « prochainement » détecté(s).", 'Compléter ou retirer avant la production (politique « aucune coquille vide »).')];
        }
        return [];
    }

    private function checkCsrf(string $rel, string $content): array
    {
        $handlesPost = str_contains($content, '$_POST')
            || preg_match('/REQUEST_METHOD.{0,12}POST/', $content);
        if (!$handlesPost) return [];

        // Reconnaît les styles de validation réellement utilisés dans le projet :
        //  - statique     : CSRF::validate() / CSRF::check()
        //  - helper Bridge : validateCSRFToken() / csrfField()
        //  - instance      : app('csrf')->validate() / $csrf->validate()/->check()
        //  - champ de form  : name="csrf_token" ou name="_token"
        $hasCsrf = preg_match(
            '/CSRF::(validate|check|verify|verifyOrFail)'
            // helpers réels du projet (snake_case + camelCase)
            . '|csrf_verify|csrf_field|csrf_token|csrf_meta'
            . '|validateCSRFToken|generateCSRFToken|checkCsrf|csrfField'
            // appels d'instance : ->validate( / ->check( / ->verifyOrFail( / ->validateFromRequest(
            . '|->\s*(validate|check|verifyOrFail|validateFromRequest)\s*\('
            . "|app\\(\\s*['\"]csrf['\"]\\s*\\)"
            // comparaison constante d'un token (\$_POST/\$_SESSION) en temps constant
            . '|hash_equals\s*\([^)]*token'
            . '|[\'"]_token[\'"]/i',
            $content
        );
        if ($hasCsrf) return [];

        return [$this->finding('haute', 'csrf', $rel, 'Traite des requêtes POST sans vérification CSRF apparente.', 'Valider le token : CSRF::validate($_POST[\'csrf_token\'] ?? \'\').')];
    }

    private function checkScope(string $rel, string $content): array
    {
        // Cibler les fichiers métier (services / endpoints), éviter le bruit.
        if (!preg_match('#(includes|Services|endpoints)#', $rel)) return [];
        // Déjà scopé explicitement : colonne etablissement_id ou contexte d'établissement.
        if (stripos($content, 'etablissement_id') !== false) return [];
        if (stripos($content, 'EstablishmentContext') !== false) return [];
        // Les fournisseurs de widgets / requêtes filtrées par utilisateur sont scopés
        // de fait (les identifiants d'utilisateur/élève sont uniques tous établissements
        // confondus) → ne pas signaler.
        if (str_contains($rel, 'WidgetProvider')
            && preg_match('/\b(user_id|eleve_id|id_eleve|professeur_id|id_professeur)\b/i', $content)) {
            return [];
        }

        foreach (self::SCOPED_TABLES as $table) {
            $t = preg_quote($table, '/');
            // Lecture/écriture sur la table.
            $touches = preg_match('/\bFROM\s+`?' . $t . '`?\b/i', $content)
                || preg_match('/\b(UPDATE|INTO)\s+`?' . $t . '`?\b/i', $content);
            if (!$touches) continue;
            // Faux positif courant : un SELECT/DELETE/UPDATE sur clé primaire unique
            //   « FROM <table> [alias] WHERE [alias.]id = … » est scopé par la PK.
            if (preg_match('/\b(FROM|UPDATE)\s+`?' . $t . '`?\s+(?:AS\s+)?\w*\s*WHERE\s+(?:\w+\.)?`?id`?\s*(=|IN)\b/i', $content)
                || preg_match('/\b(FROM|UPDATE)\s+`?' . $t . '`?\s*WHERE\s+(?:\w+\.)?`?id`?\s*(=|IN)\b/i', $content)) {
                continue;
            }
            // Faux positif courant : la table est jointe à une table déjà scopée
            //   (le filtre etablissement_id porte alors sur l'autre table).
            if (preg_match('/\bJOIN\s+`?' . $t . '`?\b/i', $content)) {
                continue;
            }
            return [$this->finding('haute', 'scope', $rel, "Requête sur « {$table} » sans aucune référence à etablissement_id (à vérifier).", 'Filtrer la requête par etablissement_id (EstablishmentContext::id()).')];
        }
        return [];
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function finding(string $severity, string $category, string $location, string $message, string $recommendation): array
    {
        return compact('severity', 'category', 'location', 'message', 'recommendation');
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace('\\', '/', substr($path, strlen($this->basePath))), '/');
    }

    /**
     * Itère les fichiers .php/.js du dépôt en sautant les dossiers ignorés.
     * @return \Generator<string>
     */
    private function iterateFiles(): \Generator
    {
        $skip = array_map(fn($d) => str_replace('\\', '/', $this->basePath . '/' . $d), self::SKIP_DIRS);

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $p = str_replace('\\', '/', $file->getPathname());

            foreach ($skip as $s) {
                if (str_starts_with($p, $s . '/') || $p === $s) continue 2;
            }
            $ext = strtolower($file->getExtension());
            if ($ext !== 'php' && $ext !== 'js') continue;
            if ($file->getSize() > 512000) continue; // ignorer fichiers énormes
            yield $p;
        }
    }
}
