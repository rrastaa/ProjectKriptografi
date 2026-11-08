<?php

namespace App\Http\Controllers;

use App\Models\CaseFile;
use App\Models\CaseRecord;
use App\Services\AuditLogger;
use App\Services\StepUpAuth;
use App\Services\WebAuthnService;
use App\Support\CaseAccess;
use App\Support\Crypto\CryptoService;
use App\Support\Crypto\DecryptionException;
use App\Support\StepUpFailed;
use App\Support\WebAuthn\WebAuthnException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileController extends Controller
{
    public function __construct(
        private readonly CryptoService $crypto,
        private readonly AuditLogger $audit,
        private readonly StepUpAuth $stepUp,
        private readonly WebAuthnService $webauthn
    ) {
    }

    /** Daftar berkas: hanya metadata. Isi berkas TIDAK dibaca / didekripsi di sini. */
    public function index(Request $request, CaseRecord $case): JsonResponse
    {
        $user = $request->user();
        abort_unless(CaseAccess::canView($user, $case), 403, 'Anda bukan anggota kasus ini.');

        $permitted = DB::table('file_permissions')->where('user_id', $user->id)->pluck('case_file_id')->all();
        $files = $case->files()->with('uploader:id,name')->orderByDesc('created_at')->get()->map(fn (CaseFile $f) => [
            'id' => $f->id,
            'name' => $f->original_name,
            'mime_type' => $f->mime_type,
            'size_bytes' => $f->size_bytes,
            'sha256' => $f->sha256,
            'uploaded_by' => $f->uploader?->name,
            'created_at' => $f->created_at,
            'can_decrypt' => in_array($f->id, $permitted, true),
        ]);

        return response()->json(['files' => $files]);
    }

    public function store(Request $request, CaseRecord $case): JsonResponse
    {
        $user = $request->user();
        abort_unless(CaseAccess::canContribute($user, $case), 403, 'Anda tidak memiliki hak menambah berkas pada kasus ini.');

        $maxKb = (int) config('caselock.max_file_mb') * 1024;
        $request->validate(['file' => "required|file|max:{$maxKb}"]);

        $upload = $request->file('file');
        $plaintext = file_get_contents($upload->getRealPath());
        if ($plaintext === false) {
            return response()->json(['message' => 'Berkas tidak dapat dibaca.'], 422);
        }

        $id = (string) Str::uuid();
        $enc = $this->crypto->encryptFile($plaintext, $id, $case->id);
        $path = trim(config('caselock.evidence_dir'), '/') . "/{$case->id}/{$id}.enc";

        Storage::disk(config('caselock.evidence_disk'))->put($path, $enc['blob']);

        $file = DB::transaction(function () use ($id, $case, $user, $upload, $plaintext, $enc, $path) {
            $file = CaseFile::create([
                'id' => $id,
                'case_id' => $case->id,
                'uploaded_by' => $user->id,
                'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
                'mime_type' => $upload->getClientMimeType() ?: 'application/octet-stream',
                'size_bytes' => strlen($plaintext),
                'sha256' => hash('sha256', $plaintext),
                'storage_path' => $path,
                'wrapped_key' => $enc['wrapped_key'],
            ]);
            // Pengunggah otomatis berhak mendekripsi berkasnya sendiri.
            $file->permittedUsers()->attach($user->id, ['granted_by' => $user->id]);
            return $file;
        });

        $this->audit->record($user, 'file.upload', 'file', $file->id, $case->id, ['size' => $file->size_bytes, 'sha256' => $file->sha256]);

        return response()->json(['file' => ['id' => $file->id, 'name' => $file->original_name, 'size_bytes' => $file->size_bytes]], 201);
    }

    /** Opsi challenge passkey untuk step-up sebelum dekripsi satu berkas. */
    public function stepUpOptions(Request $request, CaseFile $file): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->mayDecrypt($request, $file), 403, 'Anda tidak memiliki izin untuk berkas ini.');

        try {
            return response()->json(['options' => $this->webauthn->requestOptions($user, "decrypt:{$file->id}")]);
        } catch (WebAuthnException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * SELECTIVE DECRYPTION: hanya berkas ini yang didekripsi (DEK miliknya sendiri), di memori,
     * setelah (1) anggota kasus, (2) punya izin berkas, (3) re-autentikasi. Setiap percobaan dicatat.
     */
    public function decrypt(Request $request, CaseFile $file): Response|JsonResponse
    {
        $user = $request->user();
        $case = $file->case;

        if (!CaseAccess::canView($user, $case)) {
            $this->audit->record($user, 'file.decrypt_denied', 'file', $file->id, $case->id, ['reason' => 'bukan anggota kasus']);
            return response()->json(['message' => 'Anda bukan anggota kasus ini.'], 403);
        }
        if (!$file->isPermittedFor($user)) {
            $this->audit->record($user, 'file.decrypt_denied', 'file', $file->id, $case->id, ['reason' => 'tidak punya izin berkas']);
            return response()->json(['message' => 'Anda tidak memiliki izin untuk membuka berkas ini.'], 403);
        }

        $data = $request->validate(['auth' => 'required|array']);
        try {
            $method = $this->stepUp->verify($user, $data['auth'], "decrypt:{$file->id}");
        } catch (StepUpFailed $e) {
            $this->audit->record($user, 'file.decrypt_denied', 'file', $file->id, $case->id, ['reason' => 're-autentikasi gagal']);
            return response()->json(['message' => $e->getMessage()], 403);
        }

        try {
            $blob = Storage::disk(config('caselock.evidence_disk'))->get($file->storage_path);
            if ($blob === null) {
                throw new DecryptionException('Berkas terenkripsi tidak ditemukan di penyimpanan.');
            }
            $plain = $this->crypto->decryptFile($blob, $file->wrapped_key, $file->id, $case->id);
        } catch (DecryptionException $e) {
            $this->audit->record($user, 'file.integrity_failed', 'file', $file->id, $case->id, ['error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], 409);
        }

        if (!hash_equals($file->sha256, hash('sha256', $plain))) {
            $this->audit->record($user, 'file.integrity_failed', 'file', $file->id, $case->id, ['error' => 'sha256 plaintext tidak cocok']);
            return response()->json(['message' => 'Verifikasi integritas gagal (SHA-256 tidak cocok).'], 409);
        }

        $this->audit->record($user, 'file.decrypt', 'file', $file->id, $case->id, ['auth' => $method]);

        $safeName = preg_replace('/[^\w.\- ]+/u', '_', $file->original_name) ?: 'berkas';
        return response($plain, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . addslashes($safeName) . '"',
            'Content-Length' => (string) strlen($plain),
            'X-CaseLock-Mime' => $file->mime_type,
            'X-CaseLock-Name' => rawurlencode($file->original_name),
            'X-CaseLock-SHA256' => $file->sha256,
            'Access-Control-Expose-Headers' => 'X-CaseLock-Mime, X-CaseLock-Name, X-CaseLock-SHA256',
        ]);
    }

    /** Daftar anggota kasus + status izin berkas (pemilik kasus / admin). */
    public function permissions(Request $request, CaseFile $file): JsonResponse
    {
        $case = $file->case;
        abort_unless(CaseAccess::canManage($request->user(), $case), 403, 'Hanya pemilik kasus atau admin yang dapat mengatur izin.');

        $granted = $file->permittedUsers()->pluck('users.id')->all();
        $members = $case->members()->get()->map(fn ($m) => [
            'id' => $m->id, 'name' => $m->name, 'role' => $m->pivot->role, 'granted' => in_array($m->id, $granted, true),
        ]);
        // Admin non-anggota yang sudah diberi izin tetap tampil.
        return response()->json(['members' => $members]);
    }

    public function updatePermissions(Request $request, CaseFile $file): JsonResponse
    {
        $actor = $request->user();
        $case = $file->case;
        abort_unless(CaseAccess::canManage($actor, $case), 403, 'Hanya pemilik kasus atau admin yang dapat mengatur izin.');

        $data = $request->validate(['user_ids' => 'present|array', 'user_ids.*' => 'integer']);
        $memberIds = $case->members()->pluck('users.id')->all();
        $wanted = array_values(array_unique(array_intersect($data['user_ids'], $memberIds)));

        $current = $file->permittedUsers()->pluck('users.id')->all();
        $toGrant = array_values(array_diff($wanted, $current));
        $toRevoke = array_values(array_diff($current, $wanted));

        DB::transaction(function () use ($file, $toGrant, $toRevoke, $actor) {
            foreach ($toGrant as $uid) {
                $file->permittedUsers()->attach($uid, ['granted_by' => $actor->id]);
            }
            if ($toRevoke) {
                $file->permittedUsers()->detach($toRevoke);
            }
        });

        $this->audit->record($actor, 'file.permission.update', 'file', $file->id, $case->id, ['granted' => $toGrant, 'revoked' => $toRevoke]);
        return response()->json(['ok' => true, 'granted' => $toGrant, 'revoked' => $toRevoke]);
    }

    private function mayDecrypt(Request $request, CaseFile $file): bool
    {
        return CaseAccess::canView($request->user(), $file->case) && $file->isPermittedFor($request->user());
    }
}
