<?php
declare(strict_types=1);

namespace API\Services;

use PDO;

/**
 * SignatureService — TAMPON D'INTÉGRITÉ de documents (PAS une signature qualifiée).
 *
 * Permet de recueillir un tracé manuscrit (canvas base64) pour bulletins, conventions
 * de stage, autorisations, etc., et d'y associer un hash SHA-256 + horodatage.
 *
 * ⚠️ PORTÉE JURIDIQUE : il s'agit d'un simple TAMPON D'INTÉGRITÉ. Le hash est un
 * SHA-256 non chiffré de données publiques/recalculables — il prouve qu'une ligne
 * n'a pas été modifiée après coup, mais N'APPORTE NI authenticité, NI non-répudiation,
 * NI valeur probante eIDAS (aucune clé de signataire, aucune PKI). Quiconque a accès
 * en écriture à la base peut forger un tracé et recalculer un hash valide.
 * Pour une valeur légale, intégrer une vraie signature électronique qualifiée (PKI).
 */
class SignatureService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Enregistre une signature électronique.
     *
     * @param string $documentType Type de document (bulletin, convention, autorisation)
     * @param int    $documentId   ID du document
     * @param int    $signerId     ID du signataire
     * @param string $signerType   Type du signataire (parent, professeur, etc.)
     * @param string $signatureData Données de la signature (base64 du canvas PNG)
     */
    public function sign(string $documentType, int $documentId, int $signerId, string $signerType, string $signatureData): array
    {
        // Vérifier qu'il n'est pas déjà signé par cette personne
        if ($this->hasSigned($documentType, $documentId, $signerId, $signerType)) {
            return ['success' => false, 'error' => 'Document déjà signé.'];
        }

        // Hash d'intégrité recalculable : uniquement des champs persistés (pas de time()
        // non stocké, sinon verify() ne pourrait jamais recalculer le hash).
        $hash = $this->computeHash($signatureData, $documentType, $documentId, $signerId, $signerType);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        try {
            $this->pdo->prepare(
                "INSERT INTO signatures (document_type, document_id, signer_id, signer_type, signature_hash, signature_data, ip_address, signed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
            )->execute([
                $documentType, $documentId, $signerId, $signerType,
                $hash, $signatureData, $ip,
            ]);

            return ['success' => true, 'hash' => $hash, 'signed_at' => date('c')];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Erreur lors de la signature.'];
        }
    }

    /**
     * Vérifie si un document a été signé par une personne.
     */
    public function hasSigned(string $documentType, int $documentId, int $signerId, string $signerType): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM signatures
             WHERE document_type = ? AND document_id = ? AND signer_id = ? AND signer_type = ?"
        );
        $stmt->execute([$documentType, $documentId, $signerId, $signerType]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Retourne les signatures d'un document.
     */
    public function getSignatures(string $documentType, int $documentId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.*, COALESCE(
                (SELECT CONCAT(p.prenom, ' ', p.nom) FROM parents p WHERE p.id = s.signer_id AND s.signer_type = 'parent'),
                (SELECT CONCAT(pr.prenom, ' ', pr.nom) FROM professeurs pr WHERE pr.id = s.signer_id AND s.signer_type = 'professeur'),
                'Signataire'
             ) AS signer_name
             FROM signatures s
             WHERE s.document_type = ? AND s.document_id = ?
             ORDER BY s.signed_at"
        );
        $stmt->execute([$documentType, $documentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Vérifie l'intégrité d'une signature (le hash correspond).
     */
    public function verify(int $signatureId): bool
    {
        $stmt = $this->pdo->prepare("SELECT signature_hash, signature_data, document_type, document_id, signer_id, signer_type FROM signatures WHERE id = ?");
        $stmt->execute([$signatureId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['signature_hash'])) {
            return false;
        }

        // Recalcule le hash depuis les données persistées et compare en temps constant.
        $expected = $this->computeHash(
            (string) $row['signature_data'],
            (string) $row['document_type'],
            (int) $row['document_id'],
            (int) $row['signer_id'],
            (string) $row['signer_type']
        );
        return hash_equals($expected, (string) $row['signature_hash']);
    }

    /**
     * Hash d'intégrité déterministe sur les champs persistés d'une signature.
     */
    private function computeHash(string $signatureData, string $documentType, int $documentId, int $signerId, string $signerType): string
    {
        return hash('sha256', $signatureData . ':' . $documentType . ':' . $documentId . ':' . $signerId . ':' . $signerType);
    }
}
