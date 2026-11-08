<?php

namespace App\Services;

use App\Models\User;
use App\Models\WebauthnCredential;
use App\Support\WebAuthn\WebAuthnException;
use App\Support\WebAuthn\WebAuthnVerifier;
use Illuminate\Support\Facades\Cache;

/** Lapisan Laravel di atas WebAuthnVerifier: pembuatan challenge (sekali pakai, 5 menit) dan penyimpanan kredensial. */
class WebAuthnService
{
    private const CHALLENGE_TTL = 300;

    public function __construct(private readonly WebAuthnVerifier $verifier)
    {
    }

    public function creationOptions(User $user): array
    {
        $challenge = $this->newChallenge('reg', $user->id, 'register');

        return [
            'challenge' => $challenge,
            'rp' => ['id' => config('caselock.rp_id'), 'name' => config('caselock.rp_name')],
            'user' => [
                'id' => WebAuthnVerifier::b64uEncode('caselock-user-' . $user->id),
                'name' => $user->email,
                'displayName' => $user->name,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => WebAuthnVerifier::ALG_ES256],
                ['type' => 'public-key', 'alg' => WebAuthnVerifier::ALG_RS256],
            ],
            'authenticatorSelection' => [
                'residentKey' => 'preferred',
                'userVerification' => 'preferred',
            ],
            'excludeCredentials' => $user->passkeys->map(fn ($c) => ['type' => 'public-key', 'id' => $c->credential_id])->values()->all(),
            'attestation' => 'none',
            'timeout' => 60000,
        ];
    }

    public function register(User $user, array $payload, string $label): WebauthnCredential
    {
        $challenge = $this->pullChallenge('reg', $user->id, 'register');
        $parsed = $this->verifier->parseRegistration($payload, $challenge);

        if (WebauthnCredential::where('credential_id', $parsed['credential_id'])->exists()) {
            throw new WebAuthnException('Passkey ini sudah terdaftar.');
        }

        return WebauthnCredential::create([
            'user_id' => $user->id,
            'credential_id' => $parsed['credential_id'],
            'label' => $label,
            'public_key' => $parsed['public_key'],
            'alg' => $parsed['alg'],
            'sign_count' => $parsed['sign_count'],
        ]);
    }

    /** @param string $purpose "login" atau "decrypt:{fileId}" */
    public function requestOptions(User $user, string $purpose): array
    {
        $challenge = $this->newChallenge('auth', $user->id, $purpose);
        $credentials = $user->passkeys;
        if ($credentials->isEmpty()) {
            throw new WebAuthnException('Akun ini belum memiliki passkey.');
        }

        return [
            'challenge' => $challenge,
            'rpId' => config('caselock.rp_id'),
            'allowCredentials' => $credentials->map(fn ($c) => ['type' => 'public-key', 'id' => $c->credential_id])->values()->all(),
            'userVerification' => 'required',
            'timeout' => 60000,
        ];
    }

    public function verifyAssertion(User $user, string $purpose, array $payload, bool $requireUv = true): WebauthnCredential
    {
        $challenge = $this->pullChallenge('auth', $user->id, $purpose);

        $credentialId = (string) ($payload['rawId'] ?? $payload['id'] ?? '');
        $credential = $user->passkeys()->where('credential_id', $credentialId)->first();
        if (!$credential) {
            throw new WebAuthnException('Passkey tidak dikenali untuk akun ini.');
        }

        $newCount = $this->verifier->verifyAssertion($payload, $challenge, $credential->public_key, (int) $credential->sign_count, $requireUv);
        $credential->forceFill(['sign_count' => $newCount, 'last_used_at' => now()])->save();

        return $credential;
    }

    private function key(string $kind, int $userId, string $purpose): string
    {
        return "caselock:wa:{$kind}:{$userId}:" . hash('sha256', $purpose);
    }

    private function newChallenge(string $kind, int $userId, string $purpose): string
    {
        $challenge = WebAuthnVerifier::b64uEncode(random_bytes(32));
        Cache::put($this->key($kind, $userId, $purpose), $challenge, self::CHALLENGE_TTL);
        return $challenge;
    }

    /** Challenge hanya berlaku sekali: dihapus saat diambil. */
    private function pullChallenge(string $kind, int $userId, string $purpose): string
    {
        $challenge = Cache::pull($this->key($kind, $userId, $purpose));
        if (!$challenge) {
            throw new WebAuthnException('Challenge tidak ditemukan atau sudah kedaluwarsa. Ulangi proses verifikasi.');
        }
        return $challenge;
    }
}
