<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebauthnCredential extends Model
{
    protected $fillable = ['user_id', 'credential_id', 'label', 'public_key', 'alg', 'sign_count', 'last_used_at'];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'sign_count' => 'integer', 'alg' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
