<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use API\Core\EstablishmentContext;

/**
 * Isolation multi-établissement de bout en bout : une ressource appartenant à
 * l'établissement B ne doit JAMAIS être lisible depuis le contexte de
 * l'établissement A (anti-IDOR cross-tenant). Comble le manque de test
 * data-scope relevé par l'audit. Intégration : tourne contre la base configurée,
 * dans une transaction annulée (aucune donnée persistée) ; se skippe si la base
 * n'est pas disponible (ex. CI sans MySQL).
 */
final class TenantIsolationTest extends TestCase
{
    private ?\PDO $pdo = null;
    private int $etabA = 1;
    private int $etabB = 0;
    private int $docB = 0;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $envFile = $root . '/.env';
        if (!is_file($envFile)) {
            $this->markTestSkipped('.env absent — test d\'intégration ignoré.');
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $l) {
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $l, $m)) {
                putenv($m[1] . '=' . trim($m[2], " \"'"));
            }
        }
        try {
            $this->pdo = new \PDO(
                'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT')
                    . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
                getenv('DB_USER') ?: '', getenv('DB_PASS') ?: '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('Base indisponible — test d\'intégration ignoré.');
        }
        require_once $root . '/modules/documents/includes/DocumentService.php';
        try {
            $this->pdo->beginTransaction();
            $this->pdo->prepare(
                "INSERT INTO etablissements (nom, code, type, slug, annee_scolaire, actif, status)
                 VALUES ('Tenant B Test', 'TESTB_ISO', 'college', 'tenant-b-iso', '2025-2026', 1, 'active')"
            )->execute();
            $this->etabB = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare(
                "INSERT INTO documents (etablissement_id, titre, description, categorie, fichier_nom,
                    fichier_chemin, fichier_taille, fichier_type, visibilite, auteur_id, auteur_type, date_creation)
                 VALUES (?, 'Doc secret B', '', 'autre', 'x.pdf', 'uploads/x.pdf', 1, 'application/pdf', '[]', 1, 'administrateur', NOW())"
            )->execute([$this->etabB]);
            $this->docB = (int) $this->pdo->lastInsertId();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            $this->markTestSkipped('Schéma incompatible pour le test d\'intégration : ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->resetContext();
    }

    private function resetContext(): void
    {
        $ref = new \ReflectionClass(EstablishmentContext::class);
        foreach (['id', 'resolvedDefault'] as $p) {
            if ($ref->hasProperty($p)) {
                $prop = $ref->getProperty($p);
                $prop->setAccessible(true);
                $prop->setValue(null, null);
            }
        }
    }

    public function testCrossTenantDocumentIsInvisible(): void
    {
        $svc = new \DocumentService($this->pdo);

        // Contexte = établissement A → le document de B doit être INTROUVABLE.
        EstablishmentContext::set($this->etabA);
        $this->assertNull(
            $svc->getDocument($this->docB),
            'Fuite cross-tenant : un document d\'un autre établissement est lisible depuis l\'établissement A.'
        );

        // Contexte = établissement B → le document redevient lisible dans son tenant.
        EstablishmentContext::set($this->etabB);
        $doc = $svc->getDocument($this->docB);
        $this->assertNotNull($doc, 'Le document doit rester lisible dans son propre établissement.');
        $this->assertSame('Doc secret B', $doc['titre']);
    }

    public function testCrossTenantDocumentAbsentFromList(): void
    {
        $svc = new \DocumentService($this->pdo);

        // Liste depuis l'établissement A : le document de B ne doit PAS y figurer.
        EstablishmentContext::set($this->etabA);
        $idsA = array_column($svc->getDocuments('administrateur'), 'id');
        $this->assertNotContains($this->docB, $idsA, 'Fuite cross-tenant : le document de B apparaît dans la liste de A.');

        // Liste depuis l'établissement B : le document doit y figurer.
        EstablishmentContext::set($this->etabB);
        $idsB = array_column($svc->getDocuments('administrateur'), 'id');
        $this->assertContains($this->docB, $idsB, 'Le document doit apparaître dans la liste de son propre établissement.');
    }
}
