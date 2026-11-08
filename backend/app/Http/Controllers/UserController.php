<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** Daftar ringkas pengguna aktif (untuk memilih anggota kasus) — admin & investigator. */
    public function directory(): JsonResponse
    {
        $users = User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'email', 'role']);
        return response()->json(['users' => $users]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['users' => User::orderBy('id')->get()->map->toApi()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => 'required|string|min:12|max:512',
            'role' => ['required', Rule::in(User::ROLES)],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'password' => Hash::driver('argon2id')->make($data['password']),
            'role' => $data['role'],
        ]);

        $this->audit->record($request->user(), 'user.create', 'user', $user->id, null, ['role' => $user->role]);
        return response()->json(['user' => $user->toApi()], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(User::ROLES)],
            'is_active' => 'sometimes|boolean',
            'password' => 'sometimes|string|min:12|max:512',
        ]);

        if ($user->id === $request->user()->id && (isset($data['role']) || (isset($data['is_active']) && !$data['is_active']))) {
            return response()->json(['message' => 'Anda tidak dapat mengubah peran atau menonaktifkan akun sendiri.'], 422);
        }

        $changes = [];
        if (isset($data['role'])) {
            $user->role = $data['role'];
            $changes[] = 'role';
        }
        if (isset($data['is_active'])) {
            $user->is_active = $data['is_active'];
            $changes[] = 'is_active';
        }
        if (isset($data['password'])) {
            $user->password = Hash::driver('argon2id')->make($data['password']);
            $changes[] = 'password';
        }
        $user->save();

        if (isset($data['is_active']) && !$data['is_active']) {
            \App\Models\ApiToken::where('user_id', $user->id)->delete(); // cabut semua sesi
        }

        $this->audit->record($request->user(), 'user.update', 'user', $user->id, null, ['changed' => $changes]);
        return response()->json(['user' => $user->toApi()]);
    }
}
