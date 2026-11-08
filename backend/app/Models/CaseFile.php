<?php

namespace App\Models;

use App\Casts\Encrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CaseFile extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['id', 'case_id', 'uploaded_by', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'storage_path', 'wrapped_key'];

    protected $hidden = ['wrapped_key', 'storage_path'];

    protected function casts(): array
    {
        return [
            'original_name' => Encrypted::class . ':file.original_name',
            'size_bytes' => 'integer',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(CaseRecord::class, 'case_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function permittedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'file_permissions', 'case_file_id', 'user_id')->withPivot('granted_by')->withTimestamps();
    }

    public function isPermittedFor(User $user): bool
    {
        return $this->permittedUsers()->where('users.id', $user->id)->exists();
    }
}
