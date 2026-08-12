<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;
use API\Security\Authorization;

/**
 * Vérifie le pont sous-fonction de compte → rôle catalogue : un compte vie_scolaire
 * porteur du flag est_infirmerie / est_CPE obtient automatiquement le rôle catalogue
 * correspondant (et donc l'accès médical / disciplinaire) SANS attribution manuelle.
 *
 * Régression de sécurité testée : sans le flag, AUCUN accès médical.
 */
final class RoleBridgeTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Extension pdo_sqlite non disponible.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (method_exists($this->pdo, 'sqliteCreateFunction')) {
            $this->pdo->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'));
        }

        $this->pdo->exec('CREATE TABLE parent_eleve (id_parent INTEGER, id_eleve INTEGER)');
        $this->pdo->exec('CREATE TABLE professeur_classes (id_professeur INTEGER, nom_classe TEXT)');
        $this->pdo->exec(
            'CREATE TABLE user_roles (
                user_type TEXT, user_id INTEGER, role_key TEXT, etablissement_id INTEGER,
                scope_type TEXT, scope_json TEXT, valid_from TEXT, valid_until TEXT
            )'
        );
        $this->pdo->exec('CREATE TABLE rbac_grants (role TEXT, permission TEXT, granted INTEGER)');
        $this->pdo->exec(
            'CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT, model TEXT, model_id INTEGER,
                user_id INTEGER, user_type TEXT, ip_address TEXT, user_agent TEXT, new_values TEXT
            )'
        );
        // Table métier porteuse des sous-fonctions.
        $this->pdo->exec('CREATE TABLE vie_scolaire (id INTEGER PRIMARY KEY, est_CPE TEXT, est_infirmerie TEXT)');
    }

    private function authFor(array $user): Authorization
    {
        return new Authorization($this->pdo, $user);
    }

    public function testPlainVieScolaireHasNoMedicalAccess(): void
    {
        $this->pdo->exec("INSERT INTO vie_scolaire (id, est_CPE, est_infirmerie) VALUES (8, 'non', 'non')");
        $auth = $this->authFor(['id' => 8, 'type' => 'vie_scolaire', 'etablissement_id' => 1]);
        $this->assertNotContains('infirmerie', $auth->roleKeys());
        $this->assertFalse($auth->can('medical.view'), 'Un vie_scolaire sans flag NE DOIT PAS avoir accès médical.');
    }

    public function testEstInfirmerieDerivesInfirmerieRoleAndMedicalAccess(): void
    {
        $this->pdo->exec("INSERT INTO vie_scolaire (id, est_CPE, est_infirmerie) VALUES (7, 'non', 'oui')");
        $auth = $this->authFor(['id' => 7, 'type' => 'vie_scolaire', 'etablissement_id' => 1]);
        $this->assertContains('infirmerie', $auth->roleKeys(), 'est_infirmerie doit dériver le rôle infirmerie.');
        $this->assertTrue($auth->can('medical.view'), 'Le personnel infirmier doit avoir accès médical.');
    }

    public function testEstCpeDerivesCpeRole(): void
    {
        $this->pdo->exec("INSERT INTO vie_scolaire (id, est_CPE, est_infirmerie) VALUES (9, 'oui', 'non')");
        $auth = $this->authFor(['id' => 9, 'type' => 'vie_scolaire', 'etablissement_id' => 1]);
        $this->assertContains('cpe', $auth->roleKeys(), 'est_CPE doit dériver le rôle cpe.');
    }
}
