<?php
declare(strict_types=1);
/**
 * M34 – Support — Demandes transmises à la PLATEFORME Fronote (direction seulement).
 */
$pageTitle = 'Aide & support';
require_once __DIR__ . '/includes/header.php';
if (!$peutEscalader) { header('Location: tickets.php'); exit; }

$link = new PlatformLink(getPDO());
$sync = $link->synchroniser(true); // relève les réponses (au plus toutes les 2 min)
$liste = $link->liste();
?>
<div class="sp">
    <?php if (!empty($_SESSION['success_message'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success_message']) ?></div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    <div class="sp-head">
        <div>
            <h2 class="sp-title">Plateforme Fronote</h2>
            <p class="sp-sub">Les problèmes que l'établissement ne peut pas régler lui-même, transmis au support Fronote. Les demandes des élèves, familles et professeurs restent dans « Demandes reçues ».</p>
        </div>
        <?php if ($link->disponible()): ?>
        <a href="plateforme_demande.php?nouvelle=1" class="btn btn-primary"><i class="fas fa-headset"></i> Nouvelle demande au support</a>
        <?php endif; ?>
    </div>

    <?php if (!$link->disponible()): ?>
        <div class="sp-hint sp-hint--warn"><i class="fas fa-plug-circle-xmark"></i> Cette installation n'est pas reliée à la plateforme : aucun jeton d'installation (.fronote-license) ni clé <code>PLATFORM_API_KEY</code> dans le fichier .env. Demandez une clé d'API à votre prestataire.</div>
    <?php elseif ($sync['erreur'] === 'injoignable'): ?>
        <div class="sp-hint sp-hint--warn"><i class="fas fa-wifi"></i> La plateforme est momentanément injoignable : vos demandes partiront automatiquement dès son retour.</div>
    <?php endif; ?>

    <?php if (!$liste): ?>
        <div class="empty-state"><i class="fas fa-headset"></i><p>Aucune demande transmise à la plateforme.</p></div>
    <?php else: ?>
    <div class="sp-list">
        <?php foreach ($liste as $e): [$sl, $st] = PlatformLink::STATUTS[$e['statut']] ?? [$e['statut'], 'secondary']; ?>
        <a class="sp-item<?= $e['statut'] === 'attente_etablissement' ? ' is-unread' : '' ?>" href="plateforme_demande.php?id=<?= (int) $e['id'] ?>">
            <span class="sp-item__ic"><i class="fas fa-headset" aria-hidden="true"></i></span>
            <span class="sp-item__main">
                <strong><?= htmlspecialchars($e['sujet']) ?></strong>
                <span class="sp-item__meta">Réf. <?= htmlspecialchars($e['ref']) ?> · par <?= htmlspecialchars($e['cree_par_nom']) ?> · <?= formatDateTime($e['derniere_activite'] ?? $e['date_creation']) ?><?= (int) $e['nb_reponses'] ? ' · ' . (int) $e['nb_reponses'] . ' réponse(s)' : '' ?></span>
            </span>
            <span class="sp-item__badges">
                <?php if ($e['envoi'] === 'refuse'): ?><span class="badge badge-danger">Refusée</span>
                <?php elseif ($e['envoi'] === 'a_envoyer' && $e['tentatives'] > 0): ?><span class="badge badge-warning" title="<?= htmlspecialchars((string) $e['derniere_erreur']) ?>">Envoi en attente</span>
                <?php else: ?><span class="badge badge-<?= $st ?>"><?= htmlspecialchars($sl) ?></span><?php endif; ?>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
