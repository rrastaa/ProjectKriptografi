<?php

namespace App\Http\Controllers;

use App\Models\CaseRecord;
use App\Models\Message;
use App\Services\AuditLogger;
use App\Support\CaseAccess;
use App\Support\Crypto\CryptoService;
use App\Support\Crypto\DecryptionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pesan teks berlapis:
 *   1. Browser menerapkan Vigenère Cipher dengan kata kunci rahasia pengirim (kunci tidak pernah dikirim ke server).
 *   2. Server membungkus hasilnya dengan AES-256-GCM (terikat ke ID kasus lewat AAD) sebelum disimpan.
 */
class MessageController extends Controller
{
    public function __construct(
        private readonly CryptoService $crypto,
        private readonly AuditLogger $audit
    ) {
    }

    public function index(Request $request, CaseRecord $case): JsonResponse
    {
        abort_unless(CaseAccess::canView($request->user(), $case), 403, 'Anda bukan anggota kasus ini.');

        $messages = $case->messages()->with('sender:id,name')->orderBy('id')->get()->map(fn (Message $m) => [
            'id' => $m->id,
            'sender' => $m->sender?->name,
            'sender_id' => $m->sender_id,
            'created_at' => $m->created_at,
        ]);

        return response()->json(['messages' => $messages]);
    }

    public function show(Request $request, CaseRecord $case, Message $message): JsonResponse
    {
        abort_unless(CaseAccess::canView($request->user(), $case) && $message->case_id === $case->id, 403, 'Akses ditolak.');

        try {
            $vigenereCipher = $this->crypto->decryptField($message->body, 'message.body', "case:{$case->id}");
        } catch (DecryptionException $e) {
            $this->audit->record($request->user(), 'message.integrity_failed', 'message', $message->id, $case->id);
            return response()->json(['message' => 'Pesan rusak atau telah dimanipulasi.'], 409);
        }

        $this->audit->record($request->user(), 'message.open', 'message', $message->id, $case->id);

        return response()->json(['message' => [
            'id' => $message->id,
            'sender' => $message->sender?->name,
            'created_at' => $message->created_at,
            'vigenere_ciphertext' => $vigenereCipher,
        ]]);
    }

    public function store(Request $request, CaseRecord $case): JsonResponse
    {
        $user = $request->user();
        abort_unless(CaseAccess::canContribute($user, $case), 403, 'Anda tidak memiliki hak mengirim pesan pada kasus ini.');

        $data = $request->validate(['vigenere_ciphertext' => 'required|string|max:20000']);

        $message = Message::create([
            'case_id' => $case->id,
            'sender_id' => $user->id,
            'body' => $this->crypto->encryptField($data['vigenere_ciphertext'], 'message.body', "case:{$case->id}"),
        ]);

        $this->audit->record($user, 'message.send', 'message', $message->id, $case->id, ['length' => mb_strlen($data['vigenere_ciphertext'])]);

        return response()->json(['id' => $message->id], 201);
    }
}
