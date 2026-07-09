<?php
/**
 * M34 – Support — Voir un ticket (fil de discussion)
 */
$pageTitle = 'Détail ticket';
require_once __DIR__ . '/includes/header.php';

$id = (int)($_GET['id'] ?? 0);
$ticket = $supportService->getTicket($id);
if (!$ticket) { header('Location: tickets.php'); exit; }

// Vérifier accès (admin ou propriétaire)
$isOwner = ((int)$ticket['user_id'] === (int)getUserId() && $ticket['user_type'] === getUserRole());
if (!isAdmin() && !$isOwner) {
    header('Location: tickets.php');
    exit;
}

// ─── Traitement POST ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'repondre' && isAdmin()) {
        $txt = trim($_POST['reponse'] ?? '');
        if ($txt !== '') { $supportService->repondre($id, $txt, getUserId(), getUserRole()); $_SESSION['success_message'] = 'Réponse envoyée.'; }
    } elseif ($action === 'message' && $isOwner) {
        $txt = trim($_POST['contenu'] ?? '');
        if ($txt !== '') { $supportService->repondreUtilisateur($id, getUserId(), getUserRole(), $txt); $_SESSION['success_message'] = 'Message envoyé.'; }
    } elseif ($action === 'statut' && isAdmin()) {
        $supportService->changerStatut($id, $_POST['statut'] ?? 'ouvert'); $_SESSION['success_message'] = 'Statut mis à jour.';
    } elseif ($action === 'note_interne' && isAdmin()) {
        $txt = trim($_POST['note'] ?? '');
        if ($txt !== '') { $supportService->ajouterNoteInterne($id, $txt, getUserId()); $_SESSION['success_message'] = 'Note interne ajoutée.'; }
    } elseif ($action === 'satisfaction' && $isOwner) {
        $note = (int)($_POST['note'] ?? 0);
        if ($note >= 1 && $note <= 5) { $supportService->noterSatisfaction($id, $note, trim($_POST['commentaire'] ?? '')); $_SESSION['success_message'] = 'Merci pour votre retour !'; }
    }
    header('Location: voir_ticket.php?id=' . $id);
    exit;
}

$messages      = $supportService->getMessages($id);
$notesInternes = isAdmin() ? $supportService->getNotesInternes($id) : [];
$sla           = isAdmin() ? $supportService->getSlaStatus($ticket) : null;
$cats          = SupportService::categoriesTicket();
$canReply      = $ticket['statut'] !== 'ferme';
?>

<div class="content-wrapper">
    <div class="content-header">
        <h1><i class="fas fa-ticket-alt"></i> Ticket #<?= $id ?></h1>
        <a href="tickets.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Retour</a>
    </div>

    <?php if (!empty($_SESSION['success_message'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success_message']) ?></div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <div class="ticket-detail">
        <div class="card">
            <div class="card-header">
                <div class="ticket-detail-header">
                    <div>
                        <h2><?= htmlspecialchars($ticket['sujet']) ?></h2>
                        <div class="ticket-meta">
                            <?= SupportService::statutBadge($ticket['statut']) ?>
                            <?= SupportService::prioriteBadge($ticket['priorite']) ?>
                            <span><?= htmlspecialchars($cats[$ticket['categorie']] ?? $ticket['categorie']) ?></span>
                            <span>Créé le <?= formatDateTime($ticket['date_creation']) ?></span>
                            <?php if (isAdmin() && $sla): ?>
                                <span class="badge <?= $sla['resolution_overdue'] ? 'badge-danger' : ($sla['response_overdue'] ? 'badge-warning' : 'badge-success') ?>">
                                    SLA <?= $sla['resolution_overdue'] ? 'dépassé' : ($sla['response_overdue'] ? 'réponse en retard' : 'OK') ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (isAdmin()): ?>
                    <form method="post" class="statut-form">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="statut">
                        <select name="statut" class="form-control form-control-sm" data-fr-change="submitOwn">
                            <option value="ouvert" <?= $ticket['statut'] === 'ouvert' ? 'selected' : '' ?>>Ouvert</option>
                            <option value="en_cours" <?= $ticket['statut'] === 'en_cours' ? 'selected' : '' ?>>En cours</option>
                            <option value="resolu" <?= $ticket['statut'] === 'resolu' ? 'selected' : '' ?>>Résolu</option>
                            <option value="ferme" <?= $ticket['statut'] === 'ferme' ? 'selected' : '' ?>>Fermé</option>
                        </select>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <!-- Message d'origine -->
                <div class="ticket-message ticket-question">
                    <div class="message-avatar"><i class="fas fa-user"></i></div>
                    <div class="message-body">
                        <div class="message-header">
                            <strong>Demandeur</strong>
                            <span><?= formatDateTime($ticket['date_creation']) ?></span>
                        </div>
                        <p><?= nl2br(htmlspecialchars($ticket['description'])) ?></p>
                    </div>
                </div>

                <!-- Fil de discussion -->
                <?php if (empty($messages) && !empty($ticket['reponse'])): ?>
                    <!-- Rétro-compat : réponse legacy (ticket d'avant le fil multi-messages) -->
                    <div class="ticket-message ticket-response">
                        <div class="message-avatar admin-avatar"><i class="fas fa-user-shield"></i></div>
                        <div class="message-body">
                            <div class="message-header"><strong>Support</strong><span><?= formatDateTime($ticket['date_reponse']) ?></span></div>
                            <p><?= nl2br(htmlspecialchars($ticket['reponse'])) ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                <?php foreach ($messages as $m): $staff = !empty($m['is_staff']); ?>
                    <div class="ticket-message <?= $staff ? 'ticket-response' : 'ticket-question' ?>">
                        <div class="message-avatar <?= $staff ? 'admin-avatar' : '' ?>"><i class="fas <?= $staff ? 'fa-user-shield' : 'fa-user' ?>"></i></div>
                        <div class="message-body">
                            <div class="message-header">
                                <strong><?= $staff ? 'Support' : 'Demandeur' ?></strong>
                                <span><?= formatDateTime($m['date_creation']) ?></span>
                            </div>
                            <p><?= nl2br(htmlspecialchars($m['contenu'])) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Répondre -->
                <?php if (isAdmin() && $canReply): ?>
                <div class="ticket-reply">
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="repondre">
                        <div class="form-group">
                            <label>Répondre au demandeur</label>
                            <textarea name="reponse" class="form-control" rows="4" required placeholder="Votre réponse..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-reply"></i> Envoyer la réponse</button>
                    </form>
                </div>
                <?php elseif ($isOwner && $canReply): ?>
                <div class="ticket-reply">
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="message">
                        <div class="form-group">
                            <label>Ajouter un message</label>
                            <textarea name="contenu" class="form-control" rows="3" required placeholder="Apporter une précision, relancer…"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Envoyer</button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Satisfaction (demandeur, ticket résolu/fermé, pas encore noté) -->
                <?php if ($isOwner && in_array($ticket['statut'], ['resolu', 'ferme'], true) && empty($ticket['satisfaction_note'])): ?>
                <div class="ticket-reply" style="margin-top:14px;border-top:1px solid #e2e8f0;padding-top:14px">
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="satisfaction">
                        <label>Votre demande a-t-elle été bien traitée ?</label>
                        <div style="display:flex;gap:6px;align-items:center;margin:6px 0">
                            <?php for ($n = 1; $n <= 5; $n++): ?>
                                <label style="cursor:pointer"><input type="radio" name="note" value="<?= $n ?>" required> <?= $n ?>★</label>
                            <?php endfor; ?>
                        </div>
                        <textarea name="commentaire" class="form-control" rows="2" placeholder="Commentaire (optionnel)"></textarea>
                        <button type="submit" class="btn btn-secondary" style="margin-top:6px"><i class="fas fa-star"></i> Envoyer mon avis</button>
                    </form>
                </div>
                <?php elseif ($isOwner && !empty($ticket['satisfaction_note'])): ?>
                    <p style="color:#059669;margin-top:12px"><i class="fas fa-check-circle"></i> Merci, vous avez noté ce ticket <?= (int)$ticket['satisfaction_note'] ?>★.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Notes internes (staff uniquement) -->
        <?php if (isAdmin()): ?>
        <div class="card" style="margin-top:16px">
            <div class="card-header"><h3><i class="fas fa-lock"></i> Notes internes (non visibles par le demandeur)</h3></div>
            <div class="card-body">
                <?php if (empty($notesInternes)): ?>
                    <p style="color:#718096">Aucune note interne.</p>
                <?php else: foreach ($notesInternes as $ni): ?>
                    <div class="ticket-message" style="background:#fffbeb;border-radius:8px;padding:8px 12px;margin-bottom:8px">
                        <div class="message-header"><strong><?= htmlspecialchars($ni['auteur_nom'] ?? 'Staff') ?></strong> <span><?= formatDateTime($ni['created_at']) ?></span></div>
                        <p><?= nl2br(htmlspecialchars($ni['contenu'])) ?></p>
                    </div>
                <?php endforeach; endif; ?>
                <form method="post" style="margin-top:8px">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="note_interne">
                    <textarea name="note" class="form-control" rows="2" required placeholder="Ajouter une note interne…"></textarea>
                    <button type="submit" class="btn btn-secondary" style="margin-top:6px"><i class="fas fa-plus"></i> Ajouter une note</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
