<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'ip', 'expires_at', 'last_used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Membuat token baru; hanya hash SHA-256 yang disimpan. @return string token plaintext (sekali tampil) */
    public static function issue(User $user, ?string $ip): string
    {
        $plain = Str::random(64);
        static::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'ip' => $ip,
            'expires_at' => now()->addMinutes((int) config('caselock.token_ttl_minutes')),
        ]);
        return $plain;
    }
}
