<?php

namespace App\Casts;

use App\Support\Crypto\CryptoService;
use App\Support\Crypto\DecryptionException;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Cast Eloquent: kolom disimpan sebagai AES-256-GCM, dibaca sebagai plaintext.
 * Pemakaian: 'title' => Encrypted::class . ':case.title'   (bagian setelah ":" = konteks kunci)
 */
class Encrypted implements CastsAttributes
{
    public function __construct(private readonly string $context = 'default')
    {
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }
        try {
            return app(CryptoService::class)->decryptField((string) $value, $this->context);
        } catch (DecryptionException $e) {
            Log::warning('CASELOCK: field gagal didekripsi', ['model' => $model::class, 'key' => $key, 'id' => $model->getKey()]);
            return '[DATA RUSAK / TIDAK DAPAT DIDEKRIPSI]';
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }
        return app(CryptoService::class)->encryptField((string) $value, $this->context);
    }
}
