<?php

namespace App\Support\Crypto;

use RuntimeException;

/**
 * Seluruh operasi kriptografi sisi server CASELOCK.
 *
 *  - Field database sensitif : AES-256-GCM (OpenSSL), kunci turunan HKDF per konteks
 *  - Berkas bukti            : XChaCha20-Poly1305 (libsodium), kunci acak PER BERKAS (DEK)
 *  - DEK                     : dibungkus (wrap) dengan AES-256-GCM memakai kunci turunan master key
 *
 * Karena setiap berkas punya DEK sendiri, mendekripsi satu berkas tidak membuka berkas lain
 * (Selective Decryption).
 */
final class CryptoService
{
    private const VERSION = 'v1';

    public function __construct(private readonly string $masterKey)
    {
        if (strlen($masterKey) !== 32) {
            throw new RuntimeException('CASELOCK_MASTER_KEY harus tepat 32 byte (base64).');
        }
    }

    /** Membaca master key dari konfigurasi (format base64, boleh berawalan "base64:"). */
    public static function fromConfig(?string $configured): self
    {
        if ($configured === null || trim($configured) === '') {
            throw new RuntimeException(
                'CASELOCK_MASTER_KEY belum diatur. Jalankan: php artisan caselock:generate-key --write'
            );
        }
        $raw = base64_decode(preg_replace('/^base64:/', '', trim($configured)), true);
        if ($raw === false) {
            throw new RuntimeException('CASELOCK_MASTER_KEY bukan base64 yang valid.');
        }
        return new self($raw);
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    public function deriveKey(string $context): string
    {
        return hash_hkdf('sha256', $this->masterKey, 32, 'caselock:' . $context);
    }

    /* --------------------------------------------------------------------
     * AES-256-GCM
     * ------------------------------------------------------------------ */

    public function aesGcmEncrypt(string $plaintext, string $key, string $aad = ''): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($ciphertext === false) {
            throw new RuntimeException('Enkripsi AES-256-GCM gagal.');
        }
        return self::VERSION . ':' . base64_encode($iv . $tag . $ciphertext);
    }

    public function aesGcmDecrypt(string $payload, string $key, string $aad = ''): string
    {
        [$version, $encoded] = array_pad(explode(':', $payload, 2), 2, '');
        $raw = base64_decode($encoded, true);
        if ($version !== self::VERSION || $raw === false || strlen($raw) < 28) {
            throw new DecryptionException('Format ciphertext tidak dikenali.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($plain === false) {
            throw new DecryptionException('Dekripsi gagal: data rusak atau telah dimanipulasi.');
        }
        return $plain;
    }

    /** Enkripsi field database sensitif. */
    public function encryptField(string $plaintext, string $context, string $aad = ''): string
    {
        return $this->aesGcmEncrypt($plaintext, $this->deriveKey('field:' . $context), $aad);
    }

    public function decryptField(string $payload, string $context, string $aad = ''): string
    {
        return $this->aesGcmDecrypt($payload, $this->deriveKey('field:' . $context), $aad);
    }

    /* --------------------------------------------------------------------
     * Berkas bukti: XChaCha20-Poly1305 + DEK per berkas
     * ------------------------------------------------------------------ */

    public static function fileAad(string $fileId, int|string $caseId): string
    {
        return 'file:' . $fileId . ':case:' . $caseId;
    }

    /**
     * @return array{blob: string, wrapped_key: string}
     *   blob        = nonce(24) || ciphertext+tag  (disimpan di disk)
     *   wrapped_key = DEK terbungkus AES-256-GCM    (disimpan di database)
     */
    public function encryptFile(string $plaintext, string $fileId, int|string $caseId): array
    {
        $aad = self::fileAad($fileId, $caseId);
        $dek = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $dek);
        $wrapped = $this->aesGcmEncrypt($dek, $this->deriveKey('file-key-wrap'), $aad);
        sodium_memzero($dek);

        return ['blob' => $nonce . $ciphertext, 'wrapped_key' => $wrapped];
    }

    public function decryptFile(string $blob, string $wrappedKey, string $fileId, int|string $caseId): string
    {
        $aad = self::fileAad($fileId, $caseId);
        $nonceLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($blob) < $nonceLen + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new DecryptionException('Berkas terenkripsi tidak valid.');
        }

        $dek = $this->aesGcmDecrypt($wrappedKey, $this->deriveKey('file-key-wrap'), $aad);
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($blob, $nonceLen),
            $aad,
            substr($blob, 0, $nonceLen),
            $dek
        );
        sodium_memzero($dek);

        if ($plain === false) {
            throw new DecryptionException('Dekripsi berkas gagal: data rusak atau telah dimanipulasi.');
        }
        return $plain;
    }
}
