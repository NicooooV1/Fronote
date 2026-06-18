<?php
/**
 * Gate d'onboarding partagé.
 *
 * Force un administrateur (ou super-admin) à configurer son établissement avant
 * d'utiliser l'application, tant que l'établissement courant porte le code
 * 'default'. Puis, une fois configuré, force la redéfinition des périodes quand
 * l'année scolaire définie est terminée.
 *
 * Appelé depuis API/module_boot.php (pages /modules/ et accueil) ET depuis
 * admin/includes/header.php (pages /admin/*), pour que le wizard soit
 * réellement incontournable quel que soit le point d'entrée.
 *
 * Exemptions (sinon boucle de redirection) :
 *   - la page wizard elle-même définit FRONOTE_ONBOARDING ;
 *   - les pages de configuration de l'établissement (/admin/etablissement/*),
 *     car ce sont elles qui permettent justement de sortir de l'état 'default'
 *     et de redéfinir les périodes.
 */

if (!function_exists('enforceOnboardingGate')) {
    function enforceOnboardingGate(): void
    {
        // La page wizard définit cette constante pour éviter de se rediriger vers
        // elle-même.
        if (defined('FRONOTE_ONBOARDING')) {
            return;
        }

        // Pages de configuration de l'établissement : toujours accessibles.
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        if (strpos($script, '/admin/etablissement/') !== false) {
            return;
        }

        $role = function_exists('getUserRole') ? getUserRole() : null;
        if (!in_array($role, ['administrateur', 'super_admin'], true)) {
            return;
        }

        $base = defined('BASE_URL') ? BASE_URL : '';

        // 1) Onboarding non terminé : l'établissement courant a encore le code 'default'.
        if (empty($_SESSION['onboarding_done'])) {
            try {
                $etab = app('etablissement')->getCurrent();
                // Ne marquer terminé que si l'on peut confirmer un code réel : sur
                // échec transitoire de résolution on ne persiste rien (la requête
                // suivante réévaluera) pour ne pas désactiver le wizard à tort.
                if ($etab !== null) {
                    $_SESSION['onboarding_done'] = (($etab['code'] ?? '') !== 'default');
                }
            } catch (\Throwable $e) {
                // résolution impossible : ne pas bloquer cette requête, ne rien persister
            }
        }
        if (empty($_SESSION['onboarding_done'])) {
            if (!headers_sent()) {
                header('Location: ' . $base . '/modules/onboarding/index.php');
            }
            exit;
        }

        // 2) Année scolaire terminée : l'établissement a des périodes mais aucune ne
        // couvre la date du jour. On force la redéfinition des plages.
        try {
            $pdo = getPDO();
            $eid = \API\Core\EstablishmentContext::id();
            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM periodes WHERE etablissement_id = ?");
            $cntStmt->execute([$eid]);
            if ((int) $cntStmt->fetchColumn() > 0) {
                $curStmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM periodes WHERE etablissement_id = ? AND CURDATE() BETWEEN date_debut AND date_fin"
                );
                $curStmt->execute([$eid]);
                if ((int) $curStmt->fetchColumn() === 0) {
                    if (function_exists('setFlashMessage')) {
                        setFlashMessage('warning', "L'année scolaire définie est terminée. Veuillez redéfinir les plages de trimestres/semestres pour continuer.");
                    }
                    if (!headers_sent()) {
                        header('Location: ' . $base . '/admin/etablissement/periodes.php?reconfigure=1');
                    }
                    exit;
                }
            }
        } catch (\Throwable $e) {
            // ne pas bloquer l'accès si la résolution échoue
        }
    }
}
