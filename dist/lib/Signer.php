<?php
declare(strict_types=1);

namespace Dist;

use RuntimeException;

/**
 * Signature détachée Ed25519 (libsodium) des payloads de distribution.
 *
 * La clé PRIVÉE reste sur le serveur (dist/data/signing.key, 0600). La clé PUBLIQUE
 * (hex) est embarquée dans le bootstrapper install.sh côté client, qui vérifie la
 * signature de chaque archive téléchargée → un MITM ne peut pas injecter de code.
 */
final class Signer
{
    private string $secretKey;
    private string $publicKey;

    public function __construct(string $secretKeyPath, string $publicKeyPath)
    {
        if (!function_exists('sodium_crypto_sign_detached')) {
            throw new RuntimeException("Extension libsodium requise (ext-sodium).");
        }
        if (!is_file($secretKeyPath) || !is_file($publicKeyPath)) {
            throw new RuntimeException("Clés de signature absentes — lancez d'abord : php dist/bin/init.php");
        }
        $this->secretKey = (string) file_get_contents($secretKeyPath);
        $this->publicKey = (string) file_get_contents($publicKeyPath);
        if (strlen($this->secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Clé privée de signature invalide.');
        }
    }

    /** Génère et écrit une paire de clés Ed25519 (idempotent : ne réécrit pas si présentes). */
    public static function ensureKeypair(string $secretKeyPath, string $publicKeyPath): array
    {
        if (is_file($secretKeyPath) && is_file($publicKeyPath)) {
            return ['created' => false, 'public_hex' => bin2hex((string) file_get_contents($publicKeyPath))];
        }
        $pair = sodium_crypto_sign_keypair();
        $sk = sodium_crypto_sign_secretkey($pair);
        $pk = sodium_crypto_sign_publickey($pair);
        $dir = dirname($secretKeyPath);
        if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
        file_put_contents($secretKeyPath, $sk);
        @chmod($secretKeyPath, 0600);
        file_put_contents($publicKeyPath, $pk);
        @chmod($publicKeyPath, 0644);
        return ['created' => true, 'public_hex' => bin2hex($pk)];
    }

    /** Signature détachée (hex) d'un contenu. */
    public function signData(string $data): string
    {
        return bin2hex(sodium_crypto_sign_detached($data, $this->secretKey));
    }

    /** Signature détachée (hex) d'un fichier. */
    public function signFile(string $path): string
    {
        $data = file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException("Fichier illisible pour signature : {$path}");
        }
        return $this->signData($data);
    }

    public function publicKeyHex(): string
    {
        return bin2hex($this->publicKey);
    }

    /** Vérification (utilitaire côté serveur/tests ; le client vérifie en shell avec openssl/sodium). */
    public static function verify(string $data, string $signatureHex, string $publicKeyBin): bool
    {
        $sig = @hex2bin($signatureHex);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($sig, $data, $publicKeyBin);
    }
}
