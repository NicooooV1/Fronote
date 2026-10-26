<?php
declare(strict_types=1);
/**
 * Consentement cookies (CNIL) — inclus par shared_header.php et par la page de connexion.
 * Affiché tant qu'aucun choix valide n'existe ; réouvrable à tout moment via tout élément
 * [data-cc-open] (lien « Gérer mes cookies »). Charge la mesure d'audience SEULEMENT si acceptée.
 */
use API\Services\Privacy\ConsentService;

$__ccNeeds = ConsentService::needsPrompt();
$__ccAud = ConsentService::allows('audience');
$__ccBase = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';
$__ccNonce = $_hdr_nonce ?? ($cspNonce ?? (function_exists('csp_nonce') ? csp_nonce() : ''));
$__ccV = static function (string $rel) use ($__ccBase): string {
    $f = dirname(__DIR__) . '/' . $rel;
    return $__ccBase . '/' . $rel . (is_file($f) ? '?v=' . filemtime($f) : '');
};
?>
<div class="cc" id="cc" role="dialog" aria-modal="false" aria-labelledby="cc-title" aria-describedby="cc-desc"
     data-endpoint="<?= htmlspecialchars($__ccBase . '/API/endpoints/cookie_consent.php') ?>"
     data-audience-src="<?= htmlspecialchars($__ccV('assets/js/audience.js')) ?>"
     data-audience-endpoint="<?= htmlspecialchars($__ccBase . '/API/endpoints/audience.php') ?>"
     data-analytics="<?= $__ccAud ? '1' : '0' ?>"<?= $__ccNeeds ? '' : ' hidden' ?>>
  <div class="cc-card">
    <div class="cc-main">
      <h2 id="cc-title"><i class="fas fa-cookie-bite" aria-hidden="true"></i> Vos données, vos choix</h2>
      <p id="cc-desc">Fronote utilise des cookies <strong>essentiels</strong> (connexion, sécurité) et, si vous l'acceptez,
        une <strong>mesure d'audience interne</strong> pour aider l'établissement à améliorer le service.
        Ces statistiques sont anonymes, restent sur le serveur de l'établissement et ne sont jamais partagées.</p>
      <div class="cc-details" id="cc-details" hidden>
        <div class="cc-cat">
          <div class="cc-cat-head"><b>Essentiels</b><span class="cc-always">Toujours actifs</span></div>
          <p>Session de connexion, protection contre les attaques, connexion mémorisée si vous la demandez, appareil de confiance pour la double authentification, choix du thème. Indispensables au fonctionnement : exemptés de consentement.</p>
        </div>
        <div class="cc-cat">
          <div class="cc-cat-head"><b>Mesure d'audience</b>
            <label class="cc-switch"><input type="checkbox" id="cc-analytics"<?= $__ccAud ? ' checked' : '' ?>><span aria-hidden="true"></span><em class="cc-sr">Autoriser la mesure d'audience</em></label></div>
          <p>Pages et modules consultés, type d'appareil, heure, durée de visite — sans nom, sans identifiant de compte, sans adresse IP. Le visiteur n'est reconnu que par une empreinte qui change chaque jour. Conservation : 13 mois.</p>
        </div>
        <p class="cc-more"><a href="<?= htmlspecialchars($__ccBase) ?>/rgpd/cookies.php">Politique cookies détaillée</a> · Votre choix est valable 6 mois et modifiable à tout moment (lien « Gérer mes cookies »).</p>
      </div>
    </div>
    <div class="cc-actions">
      <button type="button" class="cc-btn" data-cc="reject_all">Tout refuser</button>
      <button type="button" class="cc-btn" data-cc="customize" aria-expanded="false" aria-controls="cc-details">Personnaliser</button>
      <button type="button" class="cc-btn" data-cc="save" hidden>Enregistrer mes choix</button>
      <button type="button" class="cc-btn cc-btn--accent" data-cc="accept_all">Tout accepter</button>
    </div>
  </div>
</div>
<script src="<?= htmlspecialchars($__ccV('assets/js/cookie-consent.js')) ?>" nonce="<?= htmlspecialchars((string) $__ccNonce) ?>" defer></script>
