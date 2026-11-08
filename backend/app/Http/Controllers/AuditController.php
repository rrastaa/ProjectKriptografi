<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CaseRecord;
use App\Services\AuditLogger;
use App\Support\CaseAccess;
use App\Support\Crypto\HashChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** Admin: semua log. Pemilik kasus: log kasusnya (wajib case_id). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'case_id' => 'nullable|integer',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = AuditLog::query()->with('user:id,name')->orderByDesc('seq');

        if (!empty($data['case_id'])) {
            $case = CaseRecord::findOrFail($data['case_id']);
            abort_unless(CaseAccess::canManage($user, $case), 403, 'Hanya pemilik kasus atau admin yang dapat melihat audit log kasus.');
            $query->where('case_id', $case->id);
        } else {
            abort_unless($user->isAdmin(), 403, 'Hanya admin yang dapat melihat seluruh audit log.');
        }

        $page = $query->paginate($data['per_page'] ?? 25, ['*'], 'page', $data['page'] ?? 1);

        return response()->json([
            'logs' => collect($page->items())->map(fn (AuditLog $l) => [
                'seq' => $l->seq,
                'user' => $l->user?->name,
                'action' => $l->action,
                'subject_type' => $l->subject_type,
                'subject_id' => $l->subject_id,
                'case_id' => $l->case_id,
                'details' => json_decode($l->details, true),
                'prev_hash' => $l->prev_hash,
                'hash' => $l->hash,
                'logged_at' => $l->logged_at,
            ]),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Verifikasi integritas seluruh rantai hash (admin). */
    public function verify(Request $request): JsonResponse
    {
        $result = ['valid' => true, 'checked' => 0, 'broken_at_seq' => null, 'reason' => null, 'head_hash' => HashChain::GENESIS];
        $prev = HashChain::GENESIS;
        $nextSeq = 1;
        $checked = 0;

        AuditLog::query()->orderBy('seq')->chunk(500, function ($rows) use (&$result, &$prev, &$nextSeq, &$checked) {
            $r = HashChain::verify($rows->map->getAttributes(), $prev, $nextSeq);
            $checked += $r['checked'];
            $prev = $r['head_hash'];
            $nextSeq += $r['checked'];
            if (!$r['valid']) {
                $result = $r;
                return false; // hentikan chunking
            }
            $result = $r;
            return true;
        });

        $result['checked'] = $checked;
        $this->audit->record($request->user(), 'audit.verify', 'audit', null, null, ['valid' => $result['valid'], 'checked' => $checked]);

        return response()->json($result);
    }
}
