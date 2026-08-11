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

    /**
     * Établissement « gabarit » dont on recopie le catalogue (modules_config /
     * feature_flags) vers chaque nouveau tenant. C'est l'établissement de
     * référence (id 1) : ses lignes reflètent le catalogue courant (63 modules,
     * 190 flags) au-delà de ce que le pronote.sql initial insérait.
     */
    private const TEMPLATE_ETAB_ID = 1;

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
        $etabId = (int) $this->pdo->lastInsertId();

        // Sans cette étape, le nouvel établissement naît vide (0 modules_config,
        // 0 feature_flags, 0 annees_scolaires) : coquille non fonctionnelle.
        // Semis best-effort : un échec ne doit PAS empêcher la création (le tenant reste
        // réparable via seedEstablishment()). Le semis utilise des requêtes MariaDB
        // (INSERT IGNORE) ; sous un moteur de test SQLite il est simplement ignoré.
        try {
            $this->seedEstablishment($etabId);
        } catch (\Throwable $seedErr) {
            error_log('[TenantOnboarding] semis établissement ' . $etabId . ' échoué (réparable) : ' . $seedErr->getMessage());
        }

        return $etabId;
    }

    /**
     * Provisionne les données de base d'un établissement (idempotent, réexécutable
     * pour RÉPARER un tenant vide) :
     *  (a) modules_config — catalogue des modules (copie du gabarit etab 1,
     *      is_core/enabled par défaut du catalogue) ;
     *  (b) feature_flags — catalogue des flags (copie du gabarit, valeurs par défaut) ;
     *  (c) annees_scolaires — année scolaire courante, active.
     *
     * Ne gère PAS sa propre transaction : appelé soit dans la transaction
     * d'acceptInvitation (atomicité), soit en réparation autonome (chaque INSERT
     * IGNORE / test-avant-insert reste sûr). Retourne le compte de lignes créées.
     *
     * @return array{modules:int, flags:int, annees:int}
     */
    public function seedEstablishment(int $etabId): array
    {
        if ($etabId <= 0) { throw new \RuntimeException('etablissement_id invalide pour le seed.'); }

        $tpl = self::TEMPLATE_ETAB_ID;

        // (a) modules_config — recopie du gabarit vers le nouvel établissement.
        //     INSERT IGNORE : la clé uk_module_etab (module_key, etablissement_id)
        //     empêche les doublons → réexécution sûre. On n'importe PAS id/updated_at.
        $this->pdo->prepare(
            "INSERT IGNORE INTO modules_config
                 (etablissement_id, module_key, label, description, icon, route_path, category,
                  enabled, establishment_types, config_json, roles_autorises, sort_order,
                  sidebar_sort, is_core, sidebar_hidden, topbar_category, topbar_sort_order)
             SELECT ?, module_key, label, description, icon, route_path, category,
                    enabled, establishment_types, config_json, roles_autorises, sort_order,
                    sidebar_sort, is_core, sidebar_hidden, topbar_category, topbar_sort_order
               FROM modules_config WHERE etablissement_id = ?"
        )->execute([$etabId, $tpl]);
        $modules = $this->lastAffected();

        // (b) feature_flags — recopie du gabarit (uk_flag_etab garde l'idempotence).
        $this->pdo->prepare(
            "INSERT IGNORE INTO feature_flags
                 (etablissement_id, flag_key, label, description, establishment_types, enabled, config)
             SELECT ?, flag_key, label, description, establishment_types, enabled, config
               FROM feature_flags WHERE etablissement_id = ?"
        )->execute([$etabId, $tpl]);
        $flags = $this->lastAffected();

        // (c) annees_scolaires — année courante active. Pas de clé unique sur
        //     (etablissement_id, code) : on teste l'existence avant d'insérer.
        $annees = 0;
        [$code, $libelle, $debut, $fin] = $this->currentSchoolYear();
        $chk = $this->pdo->prepare("SELECT 1 FROM annees_scolaires WHERE etablissement_id = ? AND code = ? LIMIT 1");
        $chk->execute([$etabId, $code]);
        if (!$chk->fetchColumn()) {
            $this->pdo->prepare(
                "INSERT INTO annees_scolaires (etablissement_id, code, libelle, date_debut, date_fin, actif)
                 VALUES (?, ?, ?, ?, ?, 1)"
            )->execute([$etabId, $code, $libelle, $debut, $fin]);
            $annees = 1;
        }

        return ['modules' => $modules, 'flags' => $flags, 'annees' => $annees];
    }

    /** Nombre de lignes affectées par la dernière requête (rowCount du dernier statement). */
    private function lastAffected(): int
    {
        // rowCount n'est pas fiable après un prepared jeté ; on relit via une requête légère.
        // Ici on s'appuie sur ROW_COUNT() de MariaDB qui reflète la dernière commande DML.
        try {
            return (int) $this->pdo->query('SELECT ROW_COUNT()')->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Année scolaire courante (rentrée en septembre). En août on est déjà sur
     * la nouvelle année. @return array{0:string,1:string,2:string,3:string}
     *                              code, libelle, date_debut, date_fin
     */
    private function currentSchoolYear(): array
    {
        $y = (int) date('Y');
        $m = (int) date('n');
        $start = ($m >= 8) ? $y : $y - 1;   // rentrée : à partir d'août on bascule
        $end   = $start + 1;
        $code  = $start . '-' . $end;        // ex: 2026-2027 (≤ 10 car.)
        return [$code, $code, sprintf('%d-09-01', $start), sprintf('%d-08-31', $end)];
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
        // role_key vient de l'invitation : jamais concaténé dans le SQL (2nd-order SQLi),
        // on le lie en paramètre, avec repli sur 'directeur' si le rôle demandé est absent.
        $stRole = $this->pdo->prepare("SELECT id FROM tenant_roles WHERE role_key = ?");
        $stRole->execute([(string) ($invitation['default_tenant_role'] ?? 'directeur')]);
        $roleId = (int) $stRole->fetchColumn();
        if ($roleId <= 0) {
            $stRole->execute(['directeur']);
            $roleId = (int) $stRole->fetchColumn();
        }
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
