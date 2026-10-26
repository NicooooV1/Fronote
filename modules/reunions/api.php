<?php
declare(strict_types=1);
/**
 * Réunions — actions (POST, JSON). Toutes exigent le jeton CSRF et les droits sur la réunion.
 *   repondre(reponse)                      participant : confirmer / s'excuser
 *   convoquer · demarrer · suivant · activer_point(point) · maj_point(point, champ, valeur)
 *   ajouter_point(titre, duree) · presence(participant, presence) · clore(cr, publier)
 *   enregistrer_cr(cr, publier) · brouillon_cr · annuler                     gestionnaires
 *   reserver(creneau, eleve, commentaire) · annuler_resa(resa)              rencontres parents-professeurs
 */
require_once __DIR__ . '/../../API/bootstrap.php';
requireAuth();
enforceModuleAccess('reunions');
require_once __DIR__ . '/includes/ReunionHub.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = static function (int $code, array $data): never { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { $out(405, ['ok' => false, 'message' => 'POST attendu.']); }
if (!validateCSRFToken((string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) { $out(403, ['ok' => false, 'message' => 'Session expirée : rechargez la page.']); }
// Jetons à usage unique : on remet le suivant au client (en-tête X-Csrf-Token-Next) pour l'action d'après.
try { app('csrf')->emitNextToken(); } catch (\Throwable $e) {}

$hub = new ReunionHub(getPDO());
$id = (int) ($_POST['id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$r = $hub->get($id);
if (!$r || !$hub->peutVoir($r)) { $out(404, ['ok' => false, 'message' => 'Réunion introuvable.']); }
$gestion = $hub->peutGerer($r);
$need = static function (bool $ok) use ($out): void { if (!$ok) { $out(403, ['ok' => false, 'message' => 'Action réservée aux organisateurs de la réunion.']); } };

try {
    switch ($action) {
        case 'repondre':
            $ok = $hub->repondre($id, (string) ($_POST['reponse'] ?? ''));
            $out($ok ? 200 : 400, ['ok' => $ok, 'message' => $ok ? 'Réponse enregistrée.' : 'Vous n\'êtes pas convoqué·e à cette réunion.']);
        case 'convoquer':
            $need($gestion);
            $n = $hub->convoquer($id);
            $out(200, ['ok' => true, 'message' => $n ? "$n convocation" . ($n > 1 ? 's envoyées' : ' envoyée') . '.' : 'Tout le monde est déjà convoqué.']);
        case 'demarrer':
            $need($gestion); $hub->demarrer($id);
            $out(200, ['ok' => true, 'redirect' => 'detail.php?id=' . $id . '&seance=1']);
        case 'suivant':
            $need($gestion);
            $out(200, ['ok' => true, 'point' => $hub->pointSuivant($id)]);
        case 'activer_point':
            $need($gestion); $hub->activerPoint($id, (int) ($_POST['point'] ?? 0));
            $out(200, ['ok' => true]);
        case 'maj_point':
            $need($gestion);
            $champ = (string) ($_POST['champ'] ?? '');
            if (!in_array($champ, ['notes', 'decision', 'statut', 'titre', 'duree_minutes'], true)) { $out(400, ['ok' => false, 'message' => 'Champ inconnu.']); }
            $hub->majPoint($id, (int) ($_POST['point'] ?? 0), [$champ => mb_substr((string) ($_POST['valeur'] ?? ''), 0, 8000)]);
            $out(200, ['ok' => true, 'message' => 'Enregistré']);
        case 'ajouter_point':
            $need($gestion);
            $titre = trim((string) ($_POST['titre'] ?? ''));
            if ($titre === '') { $out(400, ['ok' => false, 'message' => 'Intitulé requis.']); }
            $out(200, ['ok' => true, 'point' => $hub->ajouterPoint($id, mb_substr($titre, 0, 255), (int) ($_POST['duree'] ?? 10))]);
        case 'presence':
            $need($gestion);
            $p = (string) ($_POST['presence'] ?? '');
            $hub->presence($id, (int) ($_POST['participant'] ?? 0), $p === '' ? null : $p);
            $out(200, ['ok' => true]);
        case 'brouillon_cr':
            $need($gestion);
            $out(200, ['ok' => true, 'cr' => $hub->brouillonCompteRendu($id)]);
        case 'enregistrer_cr':
            $need($gestion);
            $hub->enregistrerCompteRendu($id, mb_substr((string) ($_POST['cr'] ?? ''), 0, 60000), !empty($_POST['publier']));
            $out(200, ['ok' => true, 'message' => !empty($_POST['publier']) ? 'Compte rendu publié aux participants.' : 'Compte rendu enregistré (non publié).']);
        case 'clore':
            $need($gestion);
            $hub->clore($id, mb_substr((string) ($_POST['cr'] ?? $hub->brouillonCompteRendu($id)), 0, 60000), !empty($_POST['publier']));
            $out(200, ['ok' => true, 'redirect' => 'detail.php?id=' . $id]);
        case 'annuler':
            $need($gestion); $hub->annuler($id);
            $out(200, ['ok' => true, 'redirect' => 'reunions.php']);
        case 'reserver':
            [$ok, $msg] = $hub->reserver($id, (int) ($_POST['creneau'] ?? 0), (int) ($_POST['eleve'] ?? 0), (string) ($_POST['commentaire'] ?? ''));
            $out($ok ? 200 : 409, ['ok' => $ok, 'message' => $msg]);
        case 'annuler_resa':
            $ok = $hub->annulerReservation($id, (int) ($_POST['resa'] ?? 0));
            $out($ok ? 200 : 403, ['ok' => $ok, 'message' => $ok ? 'Rendez-vous annulé.' : 'Annulation impossible.']);
    }
    $out(400, ['ok' => false, 'message' => 'Action inconnue.']);
} catch (\Throwable $e) {
    error_log('[reunions/api] ' . $action . ' : ' . $e->getMessage());
    $out(500, ['ok' => false, 'message' => 'Erreur : l\'action n\'a pas pu être enregistrée.']);
}
