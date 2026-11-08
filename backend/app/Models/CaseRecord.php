<?php

namespace App\Models;

use App\Casts\Encrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tabel `cases` (nama kelas "Case" tidak boleh dipakai di PHP karena kata cadangan). */
class CaseRecord extends Model
{
    protected $table = 'cases';

    protected $fillable = ['case_number', 'title', 'category', 'status', 'description', 'reporter', 'owner_id'];

    public const STATUSES = ['open', 'investigating', 'closed'];

    protected function casts(): array
    {
        return [
            'title' => Encrypted::class . ':case.title',
            'description' => Encrypted::class . ':case.description',
            'reporter' => Encrypted::class . ':case.reporter',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'case_user', 'case_id', 'user_id')->withPivot('role')->withTimestamps();
    }

    public function files(): HasMany
    {
        return $this->hasMany(CaseFile::class, 'case_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'case_id');
    }
}
