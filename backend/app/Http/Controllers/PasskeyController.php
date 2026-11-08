<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\WebAuthnService;
use App\Support\WebAuthn\WebAuthnException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasskeyController extends Controller
{
    public function __construct(
        private readonly WebAuthnService $webauthn,
        private readonly AuditLogger $audit
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $list = $request->user()->passkeys()->orderBy('id')->get()->map(fn ($c) => [
            'id' => $c->id,
            'label' => $c->label,
            'created_at' => $c->created_at,
            'last_used_at' => $c->last_used_at,
        ]);
        return response()->json(['passkeys' => $list]);
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json(['options' => $this->webauthn->creationOptions($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['label' => 'nullable|string|max:60', 'credential' => 'required|array']);

        try {
            $credential = $this->webauthn->register($request->user(), $data['credential'], $data['label'] ?? 'Passkey');
        } catch (WebAuthnException $e) {
            return response()->json(['message' => 'Registrasi passkey gagal: ' . $e->getMessage()], 422);
        }

        $this->audit->record($request->user(), 'passkey.register', 'passkey', $credential->id, null, ['label' => $credential->label]);
        return response()->json(['ok' => true, 'id' => $credential->id], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $credential = $request->user()->passkeys()->findOrFail($id);
        $credential->delete();
        $this->audit->record($request->user(), 'passkey.remove', 'passkey', $id);
        return response()->json(['ok' => true]);
    }
}
