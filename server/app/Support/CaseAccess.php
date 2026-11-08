<?php

namespace App\Support;

use App\Models\CaseRecord;
use App\Models\User;

/** Aturan akses kasus. Hak membuka isi berkas diatur terpisah (file_permissions). */
final class CaseAccess
{
    public static function memberRole(User $user, CaseRecord $case): ?string
    {
        $m = $case->members()->where('users.id', $user->id)->first();
        return $m?->pivot->role;
    }

    /** Boleh melihat kasus (metadata, daftar berkas terenkripsi, pesan terenkripsi). */
    public static function canView(User $user, CaseRecord $case): bool
    {
        return $user->isAdmin() || self::memberRole($user, $case) !== null;
    }

    /** Boleh menambah berkas / mengirim pesan. */
    public static function canContribute(User $user, CaseRecord $case): bool
    {
        return in_array(self::memberRole($user, $case), ['owner', 'investigator'], true);
    }

    /** Boleh mengelola anggota, status, dan izin berkas. */
    public static function canManage(User $user, CaseRecord $case): bool
    {
        return $user->isAdmin() || self::memberRole($user, $case) === 'owner';
    }
}
