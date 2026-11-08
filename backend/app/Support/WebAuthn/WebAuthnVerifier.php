<?php

namespace App\Support\WebAuthn;

/**
 * Verifikasi WebAuthn (registrasi & assertion) tanpa dependensi eksternal.
 *
 * Cakupan: attestation "none" (kita tidak meminta attestation), algoritma ES256 (-7) dan RS256 (-257).
 * Ini sudah memenuhi Passkey platform (Face ID / Touch ID / Windows Hello / Android) dan USB FIDO2 key.
 */
final class WebAuthnVerifier
{
    public const ALG_ES256 = -7;
    public const ALG_RS256 = -257;

    /** @param string[] $origins daftar origin yang diizinkan, mis. ["https://caselock.example.com"] */
    public function __construct(
        private readonly string $rpId,
        private readonly array $origins
    ) {
    }

    public static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $str): string
    {
        $padded = strtr($str, '-_', '+/') . str_repeat('=', (4 - strlen($str) % 4) % 4);
        $raw = base64_decode($padded, true);
        if ($raw === false) {
            throw new WebAuthnException('Base64url tidak valid.');
        }
        return $raw;
    }

    /**
     * @return array{credential_id: string, public_key: string, alg: int, sign_count: int}
     */
    public function parseRegistration(array $payload, string $expectedChallenge): array
    {
        $resp = $payload['response'] ?? null;
        if (!is_array($resp) || !isset($resp['clientDataJSON'], $resp['attestationObject'])) {
            throw new WebAuthnException('Payload registrasi tidak lengkap.');
        }

        $this->checkClientData(self::b64uDecode((string) $resp['clientDataJSON']), 'webauthn.create', $expectedChallenge);

        $att = Cbor::decode(self::b64uDecode((string) $resp['attestationObject']));
        if (!is_array($att) || !isset($att['authData']) || !is_string($att['authData'])) {
            throw new WebAuthnException('attestationObject tidak valid.');
        }

        $authData = $att['authData'];
        if (strlen($authData) < 55) {
            throw new WebAuthnException('authData terlalu pendek.');
        }
        $this->checkAuthData($authData, false);

        $flags = ord($authData[32]);
        if (!($flags & 0x40)) {
            throw new WebAuthnException('authData tidak memuat data kredensial.');
        }

        $signCount = unpack('N', substr($authData, 33, 4))[1];
        $idLen = unpack('n', substr($authData, 53, 2))[1];
        if (strlen($authData) < 55 + $idLen + 1) {
            throw new WebAuthnException('authData terpotong.');
        }
        $credentialId = substr($authData, 55, $idLen);

        $offset = 0;
        $cose = Cbor::decode(substr($authData, 55 + $idLen), $offset);
        if (!is_array($cose)) {
            throw new WebAuthnException('Public key COSE tidak valid.');
        }
        [$pem, $alg] = self::coseToPem($cose);

        if (isset($payload['id']) && !hash_equals(self::b64uEncode($credentialId), (string) $payload['id'])) {
            throw new WebAuthnException('ID kredensial tidak konsisten.');
        }

        return [
            'credential_id' => self::b64uEncode($credentialId),
            'public_key' => $pem,
            'alg' => $alg,
            'sign_count' => $signCount,
        ];
    }

    /**
     * @return int sign counter baru
     */
    public function verifyAssertion(
        array $payload,
        string $expectedChallenge,
        string $publicKeyPem,
        int $storedSignCount,
        bool $requireUserVerification
    ): int {
        $resp = $payload['response'] ?? null;
        if (!is_array($resp) || !isset($resp['clientDataJSON'], $resp['authenticatorData'], $resp['signature'])) {
            throw new WebAuthnException('Payload assertion tidak lengkap.');
        }

        $clientDataRaw = self::b64uDecode((string) $resp['clientDataJSON']);
        $this->checkClientData($clientDataRaw, 'webauthn.get', $expectedChallenge);

        $authData = self::b64uDecode((string) $resp['authenticatorData']);
        if (strlen($authData) < 37) {
            throw new WebAuthnException('authenticatorData terlalu pendek.');
        }
        $this->checkAuthData($authData, $requireUserVerification);

        $signature = self::b64uDecode((string) $resp['signature']);
        $signedData = $authData . hash('sha256', $clientDataRaw, true);

        $ok = openssl_verify($signedData, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new WebAuthnException('Tanda tangan passkey tidak valid.');
        }

        $signCount = unpack('N', substr($authData, 33, 4))[1];
        if (($signCount !== 0 || $storedSignCount !== 0) && $signCount <= $storedSignCount) {
            throw new WebAuthnException('Sign counter mundur: kemungkinan kredensial diklon.');
        }

        return $signCount;
    }

    private function checkClientData(string $raw, string $type, string $expectedChallenge): void
    {
        $cd = json_decode($raw, true);
        if (!is_array($cd)) {
            throw new WebAuthnException('clientDataJSON tidak valid.');
        }
        if (($cd['type'] ?? null) !== $type) {
            throw new WebAuthnException('Tipe operasi WebAuthn tidak sesuai.');
        }
        if (!hash_equals($expectedChallenge, (string) ($cd['challenge'] ?? ''))) {
            throw new WebAuthnException('Challenge tidak cocok atau sudah kedaluwarsa.');
        }
        if (!in_array($cd['origin'] ?? null, $this->origins, true)) {
            throw new WebAuthnException('Origin tidak diizinkan.');
        }
        if (!empty($cd['crossOrigin'])) {
            throw new WebAuthnException('Permintaan cross-origin ditolak.');
        }
    }

    private function checkAuthData(string $authData, bool $requireUv): void
    {
        $rpIdHash = substr($authData, 0, 32);
        if (!hash_equals(hash('sha256', $this->rpId, true), $rpIdHash)) {
            throw new WebAuthnException('RP ID tidak cocok.');
        }
        $flags = ord($authData[32]);
        if (!($flags & 0x01)) {
            throw new WebAuthnException('User presence tidak terpenuhi.');
        }
        if ($requireUv && !($flags & 0x04)) {
            throw new WebAuthnException('User verification (biometrik/PIN) diperlukan.');
        }
    }

    /**
     * Konversi COSE key -> PEM (SubjectPublicKeyInfo).
     *
     * @return array{0: string, 1: int} [pem, alg]
     */
    public static function coseToPem(array $cose): array
    {
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;

        if ($kty === 2 && ($cose[-1] ?? null) === 1) { // EC2, P-256
            $x = $cose[-2] ?? '';
            $y = $cose[-3] ?? '';
            if (strlen($x) !== 32 || strlen($y) !== 32) {
                throw new WebAuthnException('Koordinat EC tidak valid.');
            }
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
            return [self::pem($der), self::ALG_ES256];
        }

        if ($kty === 3) { // RSA
            $n = $cose[-1] ?? '';
            $e = $cose[-2] ?? '';
            if ($n === '' || $e === '') {
                throw new WebAuthnException('Parameter RSA tidak valid.');
            }
            $rsaKey = self::derSeq(self::derInt($n) . self::derInt($e));
            $bitString = "\x03" . self::derLen(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
            $algId = hex2bin('300d06092a864886f70d0101010500');
            return [self::pem(self::derSeq($algId . $bitString)), self::ALG_RS256];
        }

        throw new WebAuthnException('Algoritma kunci tidak didukung (gunakan ES256 / RS256).');
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derLen(int $len): string
    {
        if ($len < 128) {
            return chr($len);
        }
        $bytes = ltrim(pack('N', $len), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derSeq(string $content): string
    {
        return "\x30" . self::derLen(strlen($content)) . $content;
    }

    private static function derInt(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLen(strlen($bytes)) . $bytes;
    }
}
