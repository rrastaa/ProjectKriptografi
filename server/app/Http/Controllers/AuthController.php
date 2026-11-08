<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\WebAuthnService;
use App\Support\WebAuthn\WebAuthnException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(
        private readonly WebAuthnService $webauthn,
        private readonly AuditLogger $audit
    ) {
    }

    /** Langkah 1: email + password (Argon2id). Jika akun punya passkey, lanjut ke langkah 2. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string|max:512']);
        $user = User::where('email', Str::lower($data['email']))->first();

        $hasher = Hash::driver('argon2id');
        $valid = $user
            ? $hasher->check($data['password'], $user->password)
            : $hasher->check($data['password'], $hasher->make('dummy-password')) && false; // waktu proses disamakan

        // Verify USB security key if configured
        $usbPath = env('USB_KEY_PATH');
        $usbHash = env('USB_KEY_HASH');
        if ($usbPath && $usbHash) {
            if (!file_exists($usbPath) || !hash_equals($usbHash, hash('sha256', file_get_contents($usbPath)))) {
                $this->audit->record($user, 'auth.usb_key_missing', 'user', $user ? $user->id : null);
                return response()->json(['message' => 'USB security key not detected or invalid.'], 403);
            }
        }

        if (!$valid || !$user->is_active) {
            $this->audit->record($user, 'auth.login_failed', 'user', $user?->id, null, ['email_sha256' => hash('sha256', Str::lower($data['email']))]);
            return response()->json(['message' => 'Email atau password salah.'], 422);
        }

        if ($user->passkeys()->exists()) {
            $ticket = Str::random(48);
            Cache::put("caselock:login-ticket:{$ticket}", $user->id, 300);
            return response()->json([
                'passkey_required' => true,
                'ticket' => $ticket,
                'options' => $this->webauthn->requestOptions($user, 'login'),
            ]);
        }

        return $this->issueSession($user, 'password');
    }

    /** Langkah 2: verifikasi passkey (Face ID / fingerprint / USB FIDO2 key) setelah password benar. */
    public function loginSecondFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['ticket' => 'required|string', 'assertion' => 'required|array']);
        $userId = Cache::get("caselock:login-ticket:{$data['ticket']}");
        $user = $userId ? User::find($userId) : null;
        if (!$user || !$user->is_active) {
            return response()->json(['message' => 'Tiket login tidak valid atau kedaluwarsa. Ulangi login.'], 422);
        }

        try {
            $this->webauthn->verifyAssertion($user, 'login', $data['assertion'], true);
        } catch (WebAuthnException $e) {
            $this->audit->record($user, 'auth.passkey_failed', 'user', $user->id, null, ['reason' => $e->getMessage()]);
            return response()->json(['message' => 'Verifikasi passkey gagal: ' . $e->getMessage()], 422);
        }

        Cache::forget("caselock:login-ticket:{$data['ticket']}");
        return $this->issueSession($user, 'password+passkey');
    }

    /** Login tanpa password: hanya passkey (dengan user verification). */
    public function passkeyOptions(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email']);
        $user = User::where('email', Str::lower($data['email']))->where('is_active', true)->first();

        try {
            if (!$user) {
                throw new WebAuthnException('Passkey tidak tersedia untuk akun ini.');
            }
            return response()->json(['options' => $this->webauthn->requestOptions($user, 'login')]);
        } catch (WebAuthnException $e) {
            return response()->json(['message' => 'Passkey tidak tersedia untuk akun ini.'], 422);
        }
    }

    public function passkeyLogin(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'assertion' => 'required|array']);
        $user = User::where('email', Str::lower($data['email']))->where('is_active', true)->first();
        if (!$user) {
            return response()->json(['message' => 'Verifikasi passkey gagal.'], 422);
        }

        try {
            $this->webauthn->verifyAssertion($user, 'login', $data['assertion'], true);
        } catch (WebAuthnException $e) {
            $this->audit->record($user, 'auth.passkey_failed', 'user', $user->id, null, ['reason' => $e->getMessage()]);
            return response()->json(['message' => 'Verifikasi passkey gagal: ' . $e->getMessage()], 422);
        }

        return $this->issueSession($user, 'passkey');
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()->toApi()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('api_token')?->delete();
        $this->audit->record($request->user(), 'auth.logout', 'user', $request->user()->id);
        return response()->json(['ok' => true]);
    }

    private function issueSession(User $user, string $method): JsonResponse
    {
        $token = ApiToken::issue($user, request()->ip());
        $this->audit->record($user, 'auth.login', 'user', $user->id, null, ['method' => $method]);
        return response()->json(['token' => $token, 'user' => $user->toApi()]);
    }
}
