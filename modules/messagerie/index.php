<?php
declare(strict_types=1);
/**
 * Messagerie — page unique (liste des conversations + fil + rédaction).
 * L'interface est pilotée par assets/js/messagerie.js via api.php.
 *
 * Paramètres : ?c=<id> ouvre une conversation ; ?nouveau=1[&a=type:id] ouvre la rédaction
 * (avec un destinataire pré-rempli, ex. depuis un trombinoscope ou une fiche élève).
 */
$rootPrefix = '../../';
require_once __DIR__ . '/../../API/bootstrap.php';
requireAuth();
enforceModuleAccess('messagerie');

use API\Services\Messaging\MessagingService;
use API\Services\Messaging\AttachmentStore;

try {
    $svc = MessagingService::forCurrentUser();
    $me = $svc->me();
} catch (\Throwable $e) {
    $svc = null;
    $me = null;
}

$activePage = 'messagerie';
$pageTitle = 'Messagerie';
$extraCss = ['assets/css/messagerie.css'];
$extraJs = ['assets/js/messagerie.js'];
$demo = class_exists('\API\Core\DemoMode') && \API\Core\DemoMode::active();
$config = [
    'api' => $rootPrefix . 'modules/messagerie/api.php',
    'download' => $rootPrefix . 'modules/messagerie/download.php',
    'me' => $me,
    'staff' => in_array(getUserRole(), ['professeur', 'vie_scolaire', 'administrateur'], true),
    'open' => (int) ($_GET['c'] ?? $_GET['id'] ?? 0),
    'compose' => !empty($_GET['nouveau']),
    'prefill' => preg_match('/^[a-z_]+:\d+$/', (string) ($_GET['a'] ?? '')) ? (string) $_GET['a'] : null,
    'uploads' => !$demo,
    'maxFiles' => AttachmentStore::MAX_FILES,
    'maxBytes' => AttachmentStore::MAX_BYTES,
];

require_once __DIR__ . '/../../templates/shared_header.php';
require_once __DIR__ . '/../../templates/shared_topbar.php';
?>
<?php if (!$svc): ?>
<div class="content-wrapper"><div class="msg-empty-page">La messagerie n'est pas disponible pour ce type de compte.</div></div>
<?php else: ?>
<div class="msg-app" id="msg-app" data-view="list">
  <!-- ═══ Liste des conversations ═══ -->
  <aside class="msg-side" aria-label="Conversations">
    <div class="msg-side-head">
      <h1 class="msg-title">Messagerie</h1>
      <button type="button" class="msg-btn msg-btn--primary" data-act="compose"><i class="fas fa-pen" aria-hidden="true"></i><span>Nouveau message</span></button>
    </div>
    <div class="msg-search">
      <i class="fas fa-search" aria-hidden="true"></i>
      <input type="search" id="msg-q" placeholder="Rechercher une conversation, un message…" autocomplete="off" aria-label="Rechercher">
    </div>
    <div class="msg-boxes" role="tablist">
      <button type="button" role="tab" class="msg-box" data-box="inbox" aria-selected="true">Boîte de réception</button>
      <button type="button" role="tab" class="msg-box" data-box="unread" aria-selected="false">Non lus <span class="msg-count" id="msg-unread-total" hidden></span></button>
      <button type="button" role="tab" class="msg-box" data-box="archived" aria-selected="false">Archivés</button>
    </div>
    <ul class="msg-list" id="msg-list" aria-live="polite"></ul>
  </aside>

  <!-- ═══ Fil de la conversation ═══ -->
  <section class="msg-main" aria-label="Conversation">
    <div class="msg-placeholder" id="msg-placeholder">
      <div class="msg-placeholder-ico"><i class="far fa-comments" aria-hidden="true"></i></div>
      <h2>Vos échanges avec l'établissement</h2>
      <p>Sélectionnez une conversation ou écrivez un nouveau message.</p>
      <button type="button" class="msg-btn msg-btn--primary" data-act="compose"><i class="fas fa-pen" aria-hidden="true"></i> Nouveau message</button>
    </div>
    <div class="msg-thread" id="msg-thread" hidden>
      <header class="msg-thread-head">
        <button type="button" class="msg-icon-btn msg-back" data-act="back" aria-label="Retour aux conversations"><i class="fas fa-arrow-left" aria-hidden="true"></i></button>
        <div class="msg-thread-title">
          <h2 id="msg-thread-subject"></h2>
          <p id="msg-thread-people"></p>
        </div>
        <div class="msg-thread-actions">
          <button type="button" class="msg-icon-btn" data-act="pin" title="Épingler"><i class="fas fa-thumbtack" aria-hidden="true"></i></button>
          <button type="button" class="msg-icon-btn" data-act="mute" title="Mettre en sourdine"><i class="fas fa-bell-slash" aria-hidden="true"></i></button>
          <button type="button" class="msg-icon-btn" data-act="archive" title="Archiver"><i class="fas fa-archive" aria-hidden="true"></i></button>
          <button type="button" class="msg-icon-btn" data-act="leave" title="Supprimer de ma boîte"><i class="far fa-trash-alt" aria-hidden="true"></i></button>
        </div>
      </header>
      <div class="msg-stream" id="msg-stream" tabindex="0" aria-label="Messages"></div>
      <div class="msg-typing" id="msg-typing" hidden></div>
      <footer class="msg-composer" id="msg-composer">
        <div class="msg-locked" id="msg-locked" hidden>
          <i class="fas fa-lock" aria-hidden="true"></i>
          <span>Message d'information : les réponses vont uniquement à l'expéditeur.</span>
          <button type="button" class="msg-btn msg-btn--ghost" data-act="private">Répondre en privé</button>
        </div>
        <form class="msg-reply" id="msg-reply" autocomplete="off">
          <div class="msg-files" id="msg-reply-files"></div>
          <div class="msg-reply-row">
            <?php if ($config['uploads']): ?>
            <label class="msg-icon-btn" title="Joindre un fichier"><i class="fas fa-paperclip" aria-hidden="true"></i><input type="file" multiple hidden data-files="reply"></label>
            <?php endif; ?>
            <textarea id="msg-reply-body" rows="1" placeholder="Écrire un message…" aria-label="Votre message" maxlength="10000"></textarea>
            <button type="submit" class="msg-send" aria-label="Envoyer"><i class="fas fa-paper-plane" aria-hidden="true"></i></button>
          </div>
          <p class="msg-hint">Entrée pour envoyer · Maj+Entrée pour aller à la ligne</p>
        </form>
      </footer>
    </div>
  </section>
</div>

<!-- ═══ Rédaction d'un nouveau message ═══ -->
<div class="msg-modal" id="msg-compose" hidden role="dialog" aria-modal="true" aria-labelledby="msg-compose-title">
  <form class="msg-modal-card" id="msg-compose-form" autocomplete="off">
    <header class="msg-modal-head">
      <h2 id="msg-compose-title">Nouveau message</h2>
      <button type="button" class="msg-icon-btn" data-act="close" aria-label="Fermer"><i class="fas fa-times" aria-hidden="true"></i></button>
    </header>
    <div class="msg-field">
      <label for="msg-to-q">À</label>
      <div class="msg-to" id="msg-to">
        <div class="msg-chips" id="msg-chips"></div>
        <input type="text" id="msg-to-q" placeholder="Nom d'un professeur, de la vie scolaire, d'une classe…" autocomplete="off">
      </div>
      <div class="msg-picker" id="msg-picker" role="listbox" hidden></div>
      <p class="msg-note" id="msg-to-note" hidden></p>
    </div>
    <div class="msg-field">
      <label for="msg-subject">Objet</label>
      <input type="text" id="msg-subject" maxlength="200" placeholder="Objet du message">
    </div>
    <div class="msg-field msg-field--grow">
      <label for="msg-body">Message</label>
      <textarea id="msg-body" rows="8" maxlength="10000" placeholder="Votre message…"></textarea>
      <div class="msg-files" id="msg-compose-files"></div>
    </div>
    <footer class="msg-modal-foot">
      <?php if ($config['uploads']): ?>
      <label class="msg-btn msg-btn--ghost"><i class="fas fa-paperclip" aria-hidden="true"></i> Joindre<input type="file" multiple hidden data-files="compose"></label>
      <?php endif; ?>
      <?php if ($config['staff']): ?>
      <select id="msg-status" class="msg-select" aria-label="Importance">
        <option value="normal">Normal</option><option value="important">Important</option><option value="urgent">Urgent</option>
      </select>
      <?php endif; ?>
      <span class="msg-modal-error" id="msg-compose-error" role="alert"></span>
      <button type="submit" class="msg-btn msg-btn--primary"><i class="fas fa-paper-plane" aria-hidden="true"></i> Envoyer</button>
    </footer>
  </form>
</div>
<script type="application/json" id="msg-config"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../templates/shared_footer.php';
