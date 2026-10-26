<?php
declare(strict_types=1);

namespace API\Services\Privacy;

use PDO;

/**
 * Mesure d'audience INTERNE (hébergée par l'établissement, aucun service tiers).
 *
 * Collecte uniquement après consentement (ConsentService::allows('audience')), et de façon
 * minimale : chemin normalisé (sans paramètres ni identifiants), module, rôle (élève, parent…),
 * type d'appareil, jour/heure, durée de la page. Le visiteur n'est reconnu que par une empreinte
 * QUOTIDIENNE non réversible (elle change chaque jour : pas de suivi d'un jour à l'autre).
 * Ni nom, ni identifiant de compte, ni adresse IP. Conservation : 13 mois.
 */
final class AudienceService
{
    private PDO $pdo;
    private int $etab;

    public function __construct(PDO $pdo, ?int $etab = null)
    {
        $this->pdo = $pdo;
        $this->etab = $etab ?? (int) \API\Core\EstablishmentContext::id();
    }

    public static function normalizePath(string $url): array
    {
        $p = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
        $p = preg_replace('#/+#', '/', $p) ?? '/';
        // segments numériques ou hexadécimaux longs → :id (aucun identifiant ne quitte la page)
        $p = preg_replace('#/(\d+|[a-f0-9]{16,})(?=/|$)#', '/:id', $p) ?? $p;
        $p = preg_replace('#/index\.php$#', '/', $p) ?? $p;
        if (!preg_match('#^/[a-z0-9/_:.\-]*$#', $p)) { $p = '/autre'; }
        $p = mb_substr($p, 0, 160);
        $module = null;
        if (preg_match('#/modules/([a-z0-9_]+)#', $p, $m)) { $module = $m[1]; }
        elseif (preg_match('#^/(admin|accueil|login|parametres|rgpd|demo|tenant)\b#', $p, $m)) { $module = $m[1]; }
        return [$p, $module];
    }

    public function record(string $url, int $viewport, ?int $duration, string $role, string $visitorSeed): bool
    {
        [$path, $module] = self::normalizePath($url);
        $device = $viewport > 0 && $viewport < 700 ? 'mobile' : ($viewport > 0 && $viewport < 1100 ? 'tablette' : 'ordinateur');
        $salt = (string) (getenv('APP_KEY') ?: getenv('JWT_SECRET') ?: 'fronote');
        $visitor = substr(hash_hmac('sha256', $visitorSeed . '|' . date('Y-m-d'), $salt), 0, 16);
        $role = in_array($role, ['eleve', 'parent', 'professeur', 'vie_scolaire', 'administrateur'], true) ? $role : 'anonyme';
        $dur = $duration !== null ? max(0, min(3600, $duration)) : null;
        if ($dur !== null) { // signal de sortie : complète la page vue correspondante (pas de double comptage)
            $st = $this->pdo->prepare('UPDATE audience_events SET duration = ? WHERE etablissement_id = ? AND day = ? AND visitor = ? AND path = ? AND duration IS NULL ORDER BY id DESC LIMIT 1');
            $st->execute([$dur, $this->etab, date('Y-m-d'), $visitor, $path]);
            return $st->rowCount() > 0;
        }
        $this->pdo->prepare('INSERT INTO audience_events (etablissement_id, day, hour, weekday, path, module, role, device, visitor, duration) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$this->etab, date('Y-m-d'), (int) date('G'), (int) date('N'), $path, $module, $role, $device, $visitor, $dur]);
        return true;
    }

    /** @return array tableau de bord sur $days jours */
    public function stats(int $days = 30): array
    {
        $days = max(1, min(395, $days));
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $q = function (string $sql) use ($from): array {
            $st = $this->pdo->prepare($sql); $st->execute([$this->etab, $from]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        };
        $w = 'WHERE etablissement_id = ? AND day >= ?';
        $daily = $q("SELECT day, COUNT(*) pv, COUNT(DISTINCT visitor) uv FROM audience_events {$w} GROUP BY day ORDER BY day");
        $tot = $q("SELECT COUNT(*) pv, COUNT(DISTINCT visitor, day) visits, AVG(duration) dur FROM audience_events {$w}")[0] ?? [];
        $modules = $q("SELECT COALESCE(module,'autre') module, COUNT(*) pv, COUNT(DISTINCT visitor, day) uv FROM audience_events {$w} GROUP BY module ORDER BY pv DESC LIMIT 12");
        $roles = $q("SELECT role, COUNT(DISTINCT visitor, day) uv FROM audience_events {$w} GROUP BY role ORDER BY uv DESC");
        $devices = $q("SELECT device, COUNT(DISTINCT visitor, day) uv FROM audience_events {$w} GROUP BY device ORDER BY uv DESC");
        $heat = $q("SELECT weekday, hour, COUNT(*) pv FROM audience_events {$w} GROUP BY weekday, hour");
        $pages = $q("SELECT path, COUNT(*) pv, ROUND(AVG(duration)) dur FROM audience_events {$w} GROUP BY path ORDER BY pv DESC LIMIT 10");
        // jours sans visite : remplis à 0 pour une courbe continue
        $map = []; foreach ($daily as $d) { $map[$d['day']] = $d; }
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $series[] = ['day' => $d, 'pv' => (int) ($map[$d]['pv'] ?? 0), 'uv' => (int) ($map[$d]['uv'] ?? 0)];
        }
        return [
            'days' => $days, 'series' => $series,
            'pageviews' => (int) ($tot['pv'] ?? 0), 'visits' => (int) ($tot['visits'] ?? 0),
            'avg_duration' => $tot['dur'] !== null ? (int) round((float) ($tot['dur'] ?? 0)) : null,
            'modules' => $modules, 'roles' => $roles, 'devices' => $devices, 'heat' => $heat, 'pages' => $pages,
        ];
    }

    public function purge(): int
    {
        return (int) $this->pdo->exec('DELETE FROM audience_events WHERE day < DATE_SUB(CURDATE(), INTERVAL 13 MONTH)');
    }
}
