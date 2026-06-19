<?php
declare(strict_types=1);

namespace API\Services\Scolaire;

use PDO;

class AdminDashboardService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    private function etabId(): int
    {
        return \API\Core\EstablishmentContext::id();
    }

    public function getUserCounts(): array
    {
        $etab = $this->etabId();
        return app('cache')->remember('dashboard:user_counts:' . $etab, 120, function () use ($etab) {
            $counts = [];
            $tables = ['eleves', 'professeurs', 'parents', 'vie_scolaire', 'administrateurs'];
            foreach ($tables as $table) {
                try {
                    $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE actif = 1 AND etablissement_id = ?");
                    $stmt->execute([$etab]);
                    $counts[$table] = (int) $stmt->fetchColumn();
                } catch (\Throwable $e) {
                    $counts[$table] = 0;
                }
            }
            return $counts;
        });
    }

    public function getAlerts(): array
    {
        $alerts = [];
        try {
            $etab = $this->etabId();
            $stmt = $this->pdo->prepare("
                SELECT identifiant, locked_until, 'eleve' as type FROM eleves WHERE locked_until > NOW() AND etablissement_id = :e1
                UNION ALL SELECT identifiant, locked_until, 'professeur' FROM professeurs WHERE locked_until > NOW() AND etablissement_id = :e2
                UNION ALL SELECT identifiant, locked_until, 'parent' FROM parents WHERE locked_until > NOW() AND etablissement_id = :e3
                UNION ALL SELECT identifiant, locked_until, 'vie_scolaire' FROM vie_scolaire WHERE locked_until > NOW() AND etablissement_id = :e4
                UNION ALL SELECT identifiant, locked_until, 'administrateur' FROM administrateurs WHERE locked_until > NOW() AND etablissement_id = :e5
            ");
            $stmt->execute([':e1' => $etab, ':e2' => $etab, ':e3' => $etab, ':e4' => $etab, ':e5' => $etab]);
            $alerts['locked_accounts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { $alerts['locked_accounts'] = []; }
        try {
            $alerts['reset_pending'] = (int) $this->pdo->query("SELECT COUNT(*) FROM demandes_reinitialisation WHERE status = 'pending'")->fetchColumn();
        } catch (\Throwable $e) { $alerts['reset_pending'] = 0; }
        try {
            $stmtJ = $this->pdo->prepare("SELECT COUNT(*) FROM justificatifs WHERE traite = 0 AND etablissement_id = ?");
            $stmtJ->execute([$this->etabId()]);
            $alerts['justificatifs_pending'] = (int) $stmtJ->fetchColumn();
        } catch (\Throwable $e) { $alerts['justificatifs_pending'] = 0; }
        return $alerts;
    }

    public function getKPIs(): array
    {
        return app('cache')->remember('dashboard:kpis:' . $this->etabId(), 30, function () {
            return $this->computeKPIs();
        });
    }

    private function computeKPIs(): array
    {
        $kpi = ['taux_absenteisme' => null, 'moyenne_generale' => null, 'taux_remplissage_notes' => null, 'ws_status' => 'disabled', 'sessions_actives' => 0];
        $etab = $this->etabId();
        $trimestre = $this->getCurrentTrimestre();
        try {
            $s = $this->pdo->prepare("SELECT COUNT(*) FROM eleves WHERE actif = 1 AND etablissement_id = ?"); $s->execute([$etab]);
            $totalEleves = max((int) $s->fetchColumn(), 1);
            $s = $this->pdo->prepare("SELECT COUNT(DISTINCT id_eleve) FROM absences WHERE date_debut >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND etablissement_id = ?"); $s->execute([$etab]);
            $kpi['taux_absenteisme'] = round(((int) $s->fetchColumn() / $totalEleves) * 100, 1);
        } catch (\Throwable $e) { error_log('[AdminDashboardService.php] ' . $e->getMessage()); }
        try {
            $stmt = $this->pdo->prepare("SELECT ROUND(AVG(note / note_sur * 20), 2) FROM notes WHERE trimestre = ? AND etablissement_id = ?"); $stmt->execute([$trimestre, $etab]);
            $kpi['moyenne_generale'] = $stmt->fetchColumn() ?: null;
        } catch (\Throwable $e) { error_log('[AdminDashboardService.php] ' . $e->getMessage()); }
        try {
            $stmtProfs = $this->pdo->prepare("SELECT COUNT(DISTINCT id_professeur) FROM notes WHERE trimestre = ? AND etablissement_id = ?"); $stmtProfs->execute([$trimestre, $etab]);
            $profsAvecNotes = (int) $stmtProfs->fetchColumn();
            $sp = $this->pdo->prepare("SELECT COUNT(*) FROM professeurs WHERE actif = 1 AND etablissement_id = ?"); $sp->execute([$etab]);
            $kpi['taux_remplissage_notes'] = round(($profsAvecNotes / max((int) $sp->fetchColumn(), 1)) * 100, 1);
        } catch (\Throwable $e) { error_log('[AdminDashboardService.php] ' . $e->getMessage()); }
        try {
            $wsUrl = function_exists('env') ? env('WEBSOCKET_CLIENT_URL', '') : '';
            if ($wsUrl) {
                $wsHealth = @file_get_contents(rtrim($wsUrl, '/') . '/health', false, stream_context_create(['http' => ['timeout' => 2]]));
                $kpi['ws_status'] = $wsHealth !== false ? 'ok' : 'down';
                if ($wsHealth) { $wsData = json_decode($wsHealth, true); $kpi['ws_connections'] = $wsData['connections'] ?? 0; }
            }
        } catch (\Throwable $e) { $kpi['ws_status'] = 'error'; }
        try { $kpi['sessions_actives'] = (int) $this->pdo->query("SELECT COUNT(*) FROM session_security WHERE is_active = 1 AND expires_at > NOW()")->fetchColumn(); } catch (\Throwable $e) { error_log('[AdminDashboardService.php] ' . $e->getMessage()); }
        return $kpi;
    }

    public function getRecentLogins(int $limit = 10): array
    {
        try {
            // Placeholders nommés DISTINCTS par sous-requête : avec PDO::ATTR_EMULATE_PREPARES=false
            // (cf. Database.php) un même nom ne peut PAS être réutilisé (erreur HY093).
            $stmt = $this->pdo->prepare("
                (SELECT identifiant, last_login, 'eleve' as type FROM eleves WHERE last_login IS NOT NULL AND etablissement_id = :e1 ORDER BY last_login DESC LIMIT :lim1)
                UNION ALL
                (SELECT identifiant, last_login, 'professeur' FROM professeurs WHERE last_login IS NOT NULL AND etablissement_id = :e2 ORDER BY last_login DESC LIMIT :lim2)
                UNION ALL
                (SELECT identifiant, last_login, 'parent' FROM parents WHERE last_login IS NOT NULL AND etablissement_id = :e3 ORDER BY last_login DESC LIMIT :lim3)
                UNION ALL
                (SELECT identifiant, last_login, 'vie_scolaire' FROM vie_scolaire WHERE last_login IS NOT NULL AND etablissement_id = :e4 ORDER BY last_login DESC LIMIT :lim4)
                ORDER BY last_login DESC LIMIT :total
            ");
            $etab = $this->etabId();
            $per  = (int) ceil($limit / 2);
            foreach (['1', '2', '3', '4'] as $i) {
                $stmt->bindValue(":e{$i}", $etab, PDO::PARAM_INT);
                $stmt->bindValue(":lim{$i}", $per, PDO::PARAM_INT);
            }
            $stmt->bindValue(':total', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { return []; }
    }

    public function getRecentMessages(int $limit = 5): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT m.id, m.body, m.sender_type, m.created_at, c.subject FROM messages m JOIN conversations c ON m.conversation_id = c.id WHERE m.is_deleted = 0 ORDER BY m.created_at DESC LIMIT :lim");
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT); $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { return []; }
    }

    public function getModuleStats(): array
    {
        try {
            $total   = (int) $this->pdo->query("SELECT COUNT(*) FROM modules_config")->fetchColumn();
            $enabled = (int) $this->pdo->query("SELECT COUNT(*) FROM modules_config WHERE enabled = 1")->fetchColumn();
            return ['total' => $total, 'enabled' => $enabled];
        } catch (\Throwable $e) { return ['total' => 0, 'enabled' => 0]; }
    }

    public function getExtraCounts(): array
    {
        $extra = []; $etab = $this->etabId();
        try { $s = $this->pdo->prepare("SELECT COUNT(*) FROM classes WHERE etablissement_id = ?"); $s->execute([$etab]); $extra['classes'] = (int) $s->fetchColumn(); } catch (\Throwable $e) { $extra['classes'] = 0; }
        try { $s = $this->pdo->prepare("SELECT COUNT(*) FROM absences WHERE DATE(date_debut) = CURDATE() AND etablissement_id = ?"); $s->execute([$etab]); $extra['absences_today'] = (int) $s->fetchColumn(); } catch (\Throwable $e) { $extra['absences_today'] = 0; }
        try { $extra['messages_24h'] = (int) $this->pdo->query("SELECT COUNT(*) FROM messages WHERE is_deleted = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(); } catch (\Throwable $e) { $extra['messages_24h'] = 0; }
        return $extra;
    }

    private function getCurrentTrimestre(): int
    {
        $mois = (int) date('n');
        if ($mois >= 9 && $mois <= 12) return 1;
        if ($mois >= 1 && $mois <= 3) return 2;
        return 3;
    }
}
