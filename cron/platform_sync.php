<?php
declare(strict_types=1);
/**
 * Support — synchronisation avec la plateforme Fronote (toutes les 5 min, cf. /etc/cron.d/fronote) :
 * envoie les demandes escaladées en attente (nouvel essai progressif) et relève les réponses.
 * Sans effet si l'instance n'est pas reliée à la plateforme (ni .fronote-license ni PLATFORM_API_KEY)
 * ou si aucune demande n'a jamais été escaladée.
 */
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__) . '/API/bootstrap.php';
require_once dirname(__DIR__) . '/modules/support/includes/PlatformLink.php';
try {
    $pdo = getPDO();
    $has = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'support_escalations'")->fetchColumn();
    if (!$has || !(int) $pdo->query('SELECT COUNT(*) FROM support_escalations')->fetchColumn()) { exit(0); }
    $link = new PlatformLink($pdo);
    if (!$link->disponible()) { exit(0); }
    $r = $link->synchroniser(false);
    if ($r['envoyees'] || $r['messages'] || $r['recus'] || $r['erreur']) {
        echo '[' . date('Y-m-d H:i:s') . '] [platform-sync] envoyées=' . $r['envoyees'] . ' messages=' . $r['messages'] . ' reçus=' . $r['recus'] . ($r['erreur'] ? ' erreur=' . $r['erreur'] : '') . "\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '[platform-sync] ' . $e->getMessage() . "\n");
    exit(1);
}
