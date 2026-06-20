<?php
declare(strict_types=1);

namespace API\Tenant;

use PDO;
use API\Platform\DirectorInvitationService;

/**
 * TenantOnboardingService — acceptation d'une invitation Directeur + création/jonction
 * d'établissement. Crée le compte Directeur (tenant_account, jamais platform), le(s)
 * établissement(s), l'appartenance et le rôle directeur (scope establishment), puis
 * marque l'invitation acceptée — le tout dans une transaction.
 */
final class TenantOnboardingService
{
    /** Types d'établissement acceptés par la table etablissements. */
    private const ETAB_TYPES = ['college', 'lycee', 'superieur', 'primaire', 'polyvalent'];

    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public static function slugify(string $name): string
    {
        $s = strtolower(trim($name));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-') ?: 'etablissement';
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base; $i = 1;
        while (true) {
            $st = $this->pdo->prepare("SELECT 1 FROM etablissements WHERE slug = ? OR code = ? LIMIT 1");
            $st->execute([$slug, substr($slug, 0, 50)]);
            if (!$st->fetchColumn()) { return $slug; }
            $slug = $base . '-' . (++$i);
        }
    }

    /** Crée un établissement (statut active). @return int id. */
    public function createEstablishment(array $data, ?int $createdByAccountId = null, ?int $inviteId = null): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') { throw new \RuntimeException("Nom d'établissement obligatoire."); }
        $type = (string) ($data['type'] ?? 'college');
        if (!in_array($type, self::ETAB_TYPES, true)) { $type = 'college'; }
        $slug = $this->uniqueSlug(self::slugify($data['slug'] ?? $name));

        $this->pdo->prepare(
            "INSERT INTO etablissements (nom, code, slug, type, ville, status, created_from_invite_id, created_by_tenant_account_id, actif)
             VALUES (?, ?, ?, ?, ?, 'active', ?, ?, 1)"
        )->execute([
            $name, substr($slug, 0, 50), $slug, $type, $data['city'] ?? null, $inviteId, $createdByAccountId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Accepte une invitation Directeur et provisionne tout.
     * $director : username?, first_name, last_name, password.
     * $establishment : name, type, city (pour create_establishment).
     * @return array{account_id:int, slug:string, establishments:int[]}
     * @throws \RuntimeException
     */
    public function acceptInvitation(array $invitation, array $director, array $establishment = []): array
    {
        $roleId = (int) ($this->pdo->query("SELECT id FROM tenant_roles WHERE role_key = '" . ($invitation['default_tenant_role'] ?? 'directeur') . "'")->fetchColumn()
            ?: $this->pdo->query("SELECT id FROM tenant_roles WHERE role_key = 'directeur'")->fetchColumn());
        if ($roleId <= 0) { throw new \RuntimeException("Rôle directeur absent (lancer tenant_sync)."); }

        $this->pdo->beginTransaction();
        try {
            $accSvc = new TenantAccountService($this->pdo);
            $mbrSvc = new TenantMembershipService($this->pdo);

            $username = trim((string) ($director['username'] ?? '')) ?: (string) $invitation['email'];
            $accId = $accSvc->createAccount([
                'account_type' => 'director',
                'username'     => $username,
                'email'        => $invitation['email'],
                'password'     => (string) ($director['password'] ?? ''),
                'first_name'   => $director['first_name'] ?? ($invitation['first_name'] ?? 'Directeur'),
                'last_name'    => $director['last_name'] ?? ($invitation['last_name'] ?? ''),
                'status'       => 'active',
                'must_change_password' => 0,
            ]);

            // Quels établissements ?
            $estabIds = [];
            if (($invitation['invitation_type'] ?? '') === 'create_establishment') {
                $estabIds[] = $this->createEstablishment($establishment, $accId, (int) $invitation['id']);
            } else {
                $allowed = json_decode((string) ($invitation['allowed_establishment_ids'] ?? '[]'), true) ?: [];
                foreach ($allowed as $eid) { $estabIds[] = (int) $eid; }
            }
            if ($estabIds === []) { throw new \RuntimeException('Aucun établissement à rattacher.'); }

            foreach ($estabIds as $eid) {
                $mId = $mbrSvc->ensure($eid, $accId);
                // Rôle directeur scope establishment (insertion directe : contexte d'onboarding).
                $sel = $this->pdo->prepare("SELECT id FROM tenant_membership_roles WHERE membership_id = ? AND tenant_role_id = ? LIMIT 1");
                $sel->execute([$mId, $roleId]);
                if (!$sel->fetchColumn()) {
                    $this->pdo->prepare("INSERT INTO tenant_membership_roles (membership_id, tenant_role_id, scope_type, is_active) VALUES (?, ?, 'establishment', 1)")
                        ->execute([$mId, $roleId]);
                }
            }

            (new DirectorInvitationService($this->pdo))->markAccepted((int) $invitation['id']);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $primarySlug = (string) ($this->pdo->query("SELECT slug FROM etablissements WHERE id = " . (int) $estabIds[0])->fetchColumn() ?: '');
        return ['account_id' => $accId, 'slug' => $primarySlug, 'establishments' => $estabIds];
    }
}
