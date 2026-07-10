<?php
declare(strict_types=1);

namespace API\Core;

/**
 * Encryption — Chiffrement AES-256-GCM pour données sensibles at-rest.
 *
 * Clé maître = APP_KEY (repli JWT_SECRET). La clé de chiffrement effective est dérivée
 * PAR VERSION, ce qui permet la rotation :
 *   - v1 (legacy) : SHA-256 du matériel de clé (données chiffrées avant l'ajout de HKDF).
 *   - v2+         : HKDF-SHA256 (RFC 5869) avec un `info` versionné.
 * Le déchiffrement lit la version dans le payload et applique la bonne dérivation, donc
 * les données v1 existantes restent lisibles pendant/après la rotation. Écriture = version
 * courante (KEY_VERSION). Pour tourner la clé : bumper KEY_VERSION + ré-encoder à la volée.
 *
 * Usage :
 *   $enc = new Encryption();
 *   $cipher = $enc->encrypt('secret data');
 *   $plain  = $enc->decrypt($cipher);
 */
class Encryption
{
    private string $keyMaterial;
    private const CIPHER = 'aes-256-gcm';
    private const KEY_VERSION = 2;              // version d'écriture courante (HKDF)
    private const HKDF_INFO = 'fronote-at-rest';

    public function __construct(?string $masterKey = null)
    {
        $key = $masterKey ?? self::keyMaterial();
        if ($key === null || $key === '') {
            throw new \RuntimeException('Aucune clé applicative (APP_KEY ou JWT_SECRET) configurée pour le chiffrement.');
        }
        $this->keyMaterial = $key;
    }

    /**
     * Clé de chiffrement 256 bits pour une VERSION donnée (support rotation).
     * v1 = SHA-256 du matériel (compat des données déjà chiffrées) ; v2+ = HKDF-SHA256.
     */
    private function keyForVersion(int $version): string
    {
        if ($version <= 1) {
            return hash('sha256', $this->keyMaterial, true); // legacy — NE PAS modifier
        }
        return hash_hkdf('sha256', $this->keyMaterial, 32, self::HKDF_INFO . '-v' . $version, '');
    }

    /**
     * Matériel de clé maître : APP_KEY en priorité, repli sur JWT_SECRET
     * (toujours généré par install.php). Permet au chiffrement de fonctionner
     * sur les installations existantes sans APP_KEY dédié.
     */
    public static function keyMaterial(): ?string
    {
        $appKey = getenv('APP_KEY');
        if ($appKey !== false && $appKey !== '') {
            return $appKey;
        }
        $jwt = getenv('JWT_SECRET');
        if ($jwt !== false && $jwt !== '') {
            return $jwt;
        }
        return null;
    }

    /** Le chiffrement at-rest est-il disponible (une clé est-elle configurée) ? */
    public static function available(): bool
    {
        return self::keyMaterial() !== null;
    }

    /**
     * Chiffre une valeur.
     * Format de sortie : version:nonce_b64:ciphertext_b64:tag_b64
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(12); // 96 bits pour GCM
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->keyForVersion(self::KEY_VERSION),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '', // AAD vide
            16  // Tag de 128 bits
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return self::KEY_VERSION . ':'
            . base64_encode($nonce) . ':'
            . base64_encode($ciphertext) . ':'
            . base64_encode($tag);
    }

    /**
     * Déchiffre une valeur.
     */
    public function decrypt(string $payload): string
    {
        $parts = explode(':', $payload, 4);
        if (count($parts) !== 4) {
            throw new \RuntimeException('Invalid encrypted payload format.');
        }

        [$version, $nonceB64, $ciphertextB64, $tagB64] = $parts;

        $nonce = base64_decode($nonceB64, true);
        $ciphertext = base64_decode($ciphertextB64, true);
        $tag = base64_decode($tagB64, true);

        if ($nonce === false || $ciphertext === false || $tag === false) {
            throw new \RuntimeException('Invalid base64 in encrypted payload.');
        }

        // Sélection de la clé selon la version inscrite dans le payload (rotation).
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->keyForVersion((int) $version),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed — data may be corrupted or key mismatch.');
        }

        return $plaintext;
    }

    /**
     * Chiffre si la valeur n'est pas déjà chiffrée.
     */
    public function encryptIfPlain(string $value): string
    {
        if ($this->isEncrypted($value)) {
            return $value;
        }
        return $this->encrypt($value);
    }

    /**
     * Vérifie si une valeur a le format chiffré.
     */
    public function isEncrypted(string $value): bool
    {
        return (bool) preg_match('/^\d+:[A-Za-z0-9+\/=]+:[A-Za-z0-9+\/=]+:[A-Za-z0-9+\/=]+$/', $value);
    }

    /**
     * Hash une valeur (one-way, pour comparaison).
     */
    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
