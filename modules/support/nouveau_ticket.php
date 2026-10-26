<?php
declare(strict_types=1);
/**
 * M34 – Support — Nouvelle demande (INTERNE : adressée à l'équipe de l'établissement).
 */
$pageTitle = 'Nouvelle demande';
$pageSubtitle = 'Votre demande est adressée à l\'équipe de votre établissement.';
require_once __DIR__ . '/includes/header.php';

$cats = SupportService::categoriesDetail();
$messagerieActive = true;
try { $messagerieActive = app('modules')->isEnabled('messagerie'); } catch (\Throwable $e) {}
$error = null;
$val = static fn(string $k, string $d = '') => is_string($_POST[$k] ?? null) ? $_POST[$k] : $d;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRFToken()) {
    $sujet = trim($val('sujet'));
    $description = trim($val('description'));
    $categorie = isset($cats[$val('categorie')]) ? $val('categorie') : 'communication';
    // Seule l'équipe fixe une priorité ; les autres demandes arrivent en « normale » et sont triées.
    $priorite = $estEquipe && in_array($val('priorite'), ['basse', 'normale', 'haute', 'urgente'], true) ? $val('priorite') : 'normale';
    if ($sujet === '' || $description === '') {
        $error = 'Indiquez un objet et décrivez votre demande.';
    } else {
        $id = $supportService->creerTicket(getUserId(), getUserRole(), mb_substr($sujet, 0, 255), $description, $categorie, $priorite);
        $_SESSION['success_message'] = 'Demande envoyée à votre établissement. Vous serez notifié de la réponse.';
        header('Location: voir_ticket.php?id=' . $id);
        exit;
    }
}
$choisie = $val('categorie', $messagerieActive ? 'pedagogique' : 'communication');
?>
<div class="sp">
    <?php if (!$messagerieActive): ?>
    <div class="sp-hint"><i class="fas fa-circle-info"></i> La messagerie n'est pas activée dans votre établissement : utilisez ce formulaire pour lui écrire, l'équipe vous répondra ici.</div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="post" class="sp-card sp-form">
        <?= csrfField() ?>
        <fieldset class="sp-cats">
            <legend>Votre demande concerne…</legend>
            <?php foreach ($cats as $k => [$label, $aide, $icone]): ?>
            <label class="sp-cat">
                <input type="radio" name="categorie" value="<?= htmlspecialchars($k) ?>" <?= $choisie === $k ? 'checked' : '' ?>>
                <span class="sp-cat__box">
                    <i class="<?= htmlspecialchars($icone) ?>" aria-hidden="true"></i>
                    <strong><?= htmlspecialchars($label) ?></strong>
                    <?php if ($aide !== ''): ?><small><?= htmlspecialchars($aide) ?></small><?php endif; ?>
                </span>
            </label>
            <?php endforeach; ?>
        </fieldset>

        <div class="form-group">
            <label for="sujet">Objet</label>
            <input type="text" name="sujet" id="sujet" class="form-control" required maxlength="255" value="<?= htmlspecialchars($val('sujet')) ?>" placeholder="En quelques mots">
        </div>
        <div class="form-group">
            <label for="description">Votre message</label>
            <textarea name="description" id="description" class="form-control" rows="6" required placeholder="Donnez les détails utiles : la matière, la date, la page concernée…"><?= htmlspecialchars($val('description')) ?></textarea>
        </div>
        <?php if ($estEquipe): ?>
        <div class="form-group sp-narrow">
            <label for="priorite">Priorité</label>
            <select name="priorite" id="priorite" class="form-control">
                <?php foreach (['basse' => 'Basse', 'normale' => 'Normale', 'haute' => 'Haute', 'urgente' => 'Urgente'] as $k => $l): ?>
                <option value="<?= $k ?>" <?= $val('priorite', 'normale') === $k ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="sp-actions">
            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Envoyer à l'établissement</button>
            <a href="tickets.php" class="btn btn-outline"><?= __('btn.cancel') ?></a>
            <?php if ($peutEscalader): ?>
            <span class="sp-aside">Un problème que l'établissement ne peut pas régler ? <a href="plateforme_demande.php?nouvelle=1">Écrire à la plateforme Fronote</a></span>
            <?php endif; ?>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
