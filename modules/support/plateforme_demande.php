<?php
declare(strict_types=1);
/**
 * M34 – Support — Une demande à la PLATEFORME Fronote : création (depuis une demande interne ou
 * directe) et fil d'échange avec le support Fronote. Direction seulement.
 */
$pageTitle = 'Aide & support';
require_once __DIR__ . '/includes/header.php';
if (!$peutEscalader) { header('Location: tickets.php'); exit; }

$link = new PlatformLink(getPDO());
$id = (int) ($_GET['id'] ?? 0);
$ticketId = (int) ($_GET['ticket'] ?? 0);
$val = static fn(string $k, string $d = '') => is_string($_POST[$k] ?? null) ? $_POST[$k] : $d;
$moi = trim((function_exists('getUserFullName') ? (string) getUserFullName() : '') ?: 'Direction');
$error = null;

// ── Création ──────────────────────────────────────────────────────────────────
if ($id === 0) {
    if (!$link->disponible()) { header('Location: plateforme.php'); exit; }
    $ticket = $ticketId ? $supportService->getTicket($ticketId) : null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken()) {
        $sujet = trim($val('sujet'));
        $message = trim($val('message'));
        if ($sujet === '' || $message === '') {
            $error = 'Indiquez un objet et décrivez le problème.';
        } else {
            $newId = $link->escalader([
                'ticket_id' => $ticket ? (int) $ticket['id'] : null, 'sujet' => $sujet, 'message' => $message,
                'categorie' => in_array($val('categorie'), ['technique', 'compte', 'donnees', 'autre'], true) ? $val('categorie') : 'technique',
                'priorite' => $val('priorite', 'normale'), 'user_id' => getUserId(), 'user_type' => getUserRole(), 'user_nom' => $moi,
            ]);
            if ($ticket) {
                $supportService->ajouterNoteInterne((int) $ticket['id'], 'Transmise à la plateforme Fronote par ' . $moi . '.', (int) getUserId());
            }
            $e = $link->get($newId);
            $_SESSION['success_message'] = $e && $e['envoi'] === 'envoye'
                ? 'Demande transmise au support Fronote. Vous serez notifié de sa réponse.'
                : 'Demande enregistrée : elle partira automatiquement dès que la plateforme sera joignable.';
            header('Location: plateforme_demande.php?id=' . $newId);
            exit;
        }
    }
    // Préremplissage depuis une demande interne (sans l'identité du demandeur : seulement le problème).
    $sujetDef = $ticket ? $ticket['sujet'] : '';
    $msgDef = $ticket ? "Problème signalé dans l'établissement (demande interne #" . (int) $ticket['id'] . ") :\n\n" . $ticket['description'] . "\n\nCe que nous avons constaté :\n" : '';
    ?>
<div class="sp">
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" class="sp-card sp-form">
        <?= csrfField() ?>
        <h2 class="sp-title"><i class="fas fa-headset"></i> Demande au support Fronote</h2>
        <p class="sp-sub">Réservé aux problèmes que l'établissement ne peut pas régler lui-même : anomalie de Fronote, panne, données incohérentes… N'y mettez pas d'informations personnelles sur les élèves si elles ne sont pas nécessaires.</p>
        <div class="form-group">
            <label for="sujet">Objet</label>
            <input type="text" id="sujet" name="sujet" class="form-control" maxlength="255" required value="<?= htmlspecialchars($val('sujet', $sujetDef)) ?>">
        </div>
        <div class="sp-form-row">
            <div class="form-group">
                <label for="categorie">Nature</label>
                <select id="categorie" name="categorie" class="form-control">
                    <?php foreach (['technique' => 'Anomalie / panne de Fronote', 'compte' => 'Comptes et accès', 'donnees' => 'Données (import, export, incohérence)', 'autre' => 'Autre'] as $k => $l): ?>
                    <option value="<?= $k ?>" <?= $val('categorie', 'technique') === $k ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="priorite">Urgence</label>
                <select id="priorite" name="priorite" class="form-control">
                    <?php foreach (['basse' => 'Basse', 'normale' => 'Normale', 'haute' => 'Haute — gêne importante', 'urgente' => 'Urgente — service bloqué'] as $k => $l): ?>
                    <option value="<?= $k ?>" <?= $val('priorite', 'normale') === $k ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label for="message">Description</label>
            <textarea id="message" name="message" class="form-control" rows="9" required placeholder="Que se passe-t-il ? Depuis quand ? Sur quelle page ? Quel message d'erreur ?"><?= htmlspecialchars($val('message', $msgDef)) ?></textarea>
        </div>
        <div class="sp-actions">
            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Transmettre au support Fronote</button>
            <a href="<?= $ticket ? 'voir_ticket.php?id=' . (int) $ticket['id'] : 'plateforme.php' ?>" class="btn btn-outline"><?= __('btn.cancel') ?></a>
        </div>
    </form>
</div>
<?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ── Fil d'une demande existante ──────────────────────────────────────────────────
$esc = $link->get($id);
if (!$esc) { header('Location: plateforme.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken()) {
    $txt = trim($val('message'));
    $clore = $val('action') === 'clore';
    if ($txt !== '' || $clore) {
        $link->repondre($id, $moi, $txt, $clore);
        $_SESSION['success_message'] = $clore ? 'Demande close.' : 'Message envoyé au support Fronote.';
    }
    header('Location: plateforme_demande.php?id=' . $id);
    exit;
}
$link->synchroniser(true);
$esc = $link->get($id);
$msgs = $link->messages($id);
[$sl, $st] = PlatformLink::STATUTS[$esc['statut']] ?? [$esc['statut'], 'secondary'];
$close = in_array($esc['statut'], ['ferme', 'ferme_demande'], true);
?>
<div class="sp">
    <?php if (!empty($_SESSION['success_message'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success_message']) ?></div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    <div class="sp-head">
        <div>
            <h2 class="sp-title"><?= htmlspecialchars($esc['sujet']) ?></h2>
            <p class="sp-sub">
                <span class="badge badge-<?= $st ?>"><?= htmlspecialchars($sl) ?></span>
                Réf. <?= htmlspecialchars($esc['ref']) ?> · transmise par <?= htmlspecialchars($esc['cree_par_nom']) ?> le <?= formatDateTime($esc['date_creation']) ?>
                <?php if ($esc['ticket_id']): ?> · <a href="voir_ticket.php?id=<?= (int) $esc['ticket_id'] ?>">demande interne #<?= (int) $esc['ticket_id'] ?></a><?php endif; ?>
            </p>
        </div>
        <a href="plateforme.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> <?= __('btn.back') ?></a>
    </div>
    <?php if ($esc['envoi'] === 'refuse'): ?>
        <div class="sp-hint sp-hint--warn"><i class="fas fa-ban"></i> La plateforme a refusé cette demande : <?= htmlspecialchars((string) $esc['derniere_erreur']) ?></div>
    <?php elseif ($esc['envoi'] === 'a_envoyer'): ?>
        <div class="sp-hint sp-hint--warn"><i class="fas fa-hourglass-half"></i> Pas encore envoyée<?= $esc['derniere_erreur'] ? ' — ' . htmlspecialchars((string) $esc['derniere_erreur']) : '' ?>. Elle partira automatiquement.</div>
    <?php endif; ?>

    <div class="sp-thread">
        <?php foreach ($msgs as $m): $in = $m['sens'] === 'entrant'; ?>
        <article class="sp-msg<?= $in ? ' sp-msg--in' : '' ?>">
            <header><strong><?= htmlspecialchars($m['auteur']) ?></strong> <span><?= formatDateTime($m['date_creation']) ?><?= !$in && $m['a_envoyer'] ? ' · en attente d\'envoi' : '' ?></span></header>
            <p><?= nl2br(htmlspecialchars($m['contenu'])) ?></p>
        </article>
        <?php endforeach; ?>
    </div>

    <?php if (!$close && $esc['envoi'] !== 'refuse'): ?>
    <form method="post" class="sp-card sp-form">
        <?= csrfField() ?>
        <div class="form-group">
            <label for="message">Répondre au support Fronote</label>
            <textarea id="message" name="message" class="form-control" rows="4" placeholder="Votre message…"></textarea>
        </div>
        <div class="sp-actions">
            <button type="submit" name="action" value="repondre" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Envoyer</button>
            <button type="submit" name="action" value="clore" class="btn btn-outline"><i class="fas fa-check"></i> Le problème est réglé</button>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
