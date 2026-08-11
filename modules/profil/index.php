<?php
declare(strict_types=1);
/**
 * Profil utilisateur — page « Mon profil ».
 *
 * Affiche l'identité du compte et un bloc « Rôles & poste » façon Discord :
 * le POSTE (rôle de plus haut tier, mis en avant) et l'ensemble des rôles
 * effectifs rendus en badges colorés (couleur = tier, info-bulle = description).
 * Les réglages personnels restent gérés dans parametres/parametres.php ; on y
 * renvoie via des liens explicites (?section=…).
 */

$pageTitle  = 'Mon profil';
$activePage = 'profil';
require_once __DIR__ . '/../../API/module_boot.php';

// Rôles effectifs (type de compte de base + rôles attribués) via l'authz de l'app.
$roleKeys = [];
try {
    $roleKeys = getEffectiveRoles();
} catch (\Throwable $e) {
    $roleKeys = [];
}
if (empty($roleKeys)) {
    // Repli : rôle de base déduit du type de compte.
    try {
        $roleKeys = [\API\Security\RoleCatalog::baseRoleForAccount($user_role)];
    } catch (\Throwable $e) {
        $roleKeys = $user_role !== '' ? [$user_role] : [];
    }
}

require_once __DIR__ . '/includes/role_badges.php';
$poste = rc_derive_poste($roleKeys);

include __DIR__ . '/../../templates/shared_header.php';
include __DIR__ . '/../../templates/shared_topbar.php';
?>

            <!-- Contenu principal -->
            <div class="content-container">

                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-id-badge"></i> Mon profil</h2>
                    </div>
                    <div class="card-body">

                        <!-- Identité -->
                        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:20px">
                            <div style="width:64px;height:64px;border-radius:50%;flex:0 0 auto;display:flex;
                                        align-items:center;justify-content:center;font-size:1.5rem;font-weight:700;
                                        color:#fff;background:<?= htmlspecialchars($poste['color'] ?? \API\Security\RoleCatalog::TIER_COLOR_DEFAULT, ENT_QUOTES) ?>">
                                <?= e($user_initials) ?>
                            </div>
                            <div>
                                <div style="font-size:1.3rem;font-weight:700"><?= e($user_fullname) ?></div>
                                <?php if (!empty($user['email'])): ?>
                                    <div style="color:var(--text-muted,#64748b);font-size:.9rem"><?= e((string) $user['email']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- ═══════ Rôles & poste ═══════ -->
                        <h3 style="font-size:1em;margin:8px 0 10px;color:var(--text-muted,#64748b)">Rôles &amp; poste</h3>

                        <div class="rc-poste">
                            <span class="rc-poste__chip" style="background:<?= htmlspecialchars($poste['color'] ?? \API\Security\RoleCatalog::TIER_COLOR_DEFAULT, ENT_QUOTES) ?>">
                                <i class="fas fa-user-tag"></i>
                                <?= e($poste['label'] ?? 'Utilisateur') ?>
                            </span>
                            <div class="rc-poste__meta">
                                <span class="rc-poste__kicker">Poste</span>
                                <?php if (!empty($poste['description'])): ?>
                                    <span class="rc-poste__desc"><?= e($poste['description']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="margin-top:6px">
                            <?= rc_render_badges($roleKeys, $poste['key'] ?? null) ?>
                        </div>

                        <p style="color:var(--text-muted,#64748b);font-size:.82rem;margin-top:12px">
                            <i class="fas fa-info-circle"></i>
                            Les rôles déterminent vos accès. Ils sont attribués par la direction de votre établissement.
                        </p>

                        <!-- Renvois vers les réglages personnels -->
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:18px">
                            <a class="btn-sm" style="background:var(--primary-color,#667eea);color:#fff;text-decoration:none;padding:8px 14px;border-radius:8px"
                               href="<?= $rootPrefix ?>parametres/parametres.php?section=profil">
                                <i class="fas fa-user-edit"></i> Modifier mon profil
                            </a>
                            <a class="btn-sm" style="background:var(--bg-muted,#e2e8f0);color:var(--text-color,#1e293b);text-decoration:none;padding:8px 14px;border-radius:8px"
                               href="<?= $rootPrefix ?>parametres/parametres.php?section=securite">
                                <i class="fas fa-shield-alt"></i> Sécurité &amp; connexion
                            </a>
                        </div>

                    </div>
                </div>

            </div>

<?php
include __DIR__ . '/../../templates/shared_footer.php';
