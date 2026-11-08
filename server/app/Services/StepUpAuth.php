<?php

namespace App\Services;

use App\Models\User;
use App\Support\StepUpFailed;
use App\Support\WebAuthn\WebAuthnException;
use Illuminate\Support\Facades\Hash;

/**
 * Re-autentikasi tepat sebelum operasi sensitif (dekripsi berkas).
 * Token sesi saja TIDAK cukup untuk membuka bukti: pengguna harus membuktikan diri lagi
 * dengan password (Argon2id) atau passkey (WebAuthn dengan user verification: Face ID / sidik jari / PIN key).
 */
class StepUpAuth
{
    public function __construct(private readonly WebAuthnService $webauthn)
    {
    }

    /** @return string metode yang dipakai: "password" | "passkey" @throws StepUpFailed */
    public function verify(User $user, array $auth, string $purpose): string
    {
        $method = $auth['method'] ?? null;

        if ($method === 'password') {
            $password = (string) ($auth['password'] ?? '');
            if ($password === '' || !Hash::driver('argon2id')->check($password, $user->password)) {
                throw new StepUpFailed('Password salah.');
            }
            return 'password';
        }

        if ($method === 'passkey') {
            try {
                $this->webauthn->verifyAssertion($user, $purpose, (array) ($auth['assertion'] ?? []), true);
            } catch (WebAuthnException $e) {
                throw new StepUpFailed($e->getMessage());
            }
            return 'passkey';
        }

        throw new StepUpFailed('Metode verifikasi tidak valid.');
    }
}
