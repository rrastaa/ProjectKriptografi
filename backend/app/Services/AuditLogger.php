<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Crypto\HashChain;
use Illuminate\Support\Facades\DB;

/**
 * Mencatat aktivitas ke audit log berantai (SHA-256 hash chain).
 * Penulisan diserialisasi dengan advisory lock PostgreSQL supaya urutan seq & prev_hash selalu konsisten.
 */
class AuditLogger
{
    private const LOCK_KEY = 728301;

    public function record(
        ?User $user,
        string $action,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        ?int $caseId = null,
        array $details = []
    ): AuditLog {
        $details['ip'] = request()?->ip();

        return DB::transaction(function () use ($user, $action, $subjectType, $subjectId, $caseId, $details) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
            }

            $last = AuditLog::orderByDesc('seq')->first();
            $seq = $last ? $last->seq + 1 : 1;
            $prev = $last ? $last->hash : HashChain::GENESIS;
            $loggedAt = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
            $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $subjectId = $subjectId === null ? null : (string) $subjectId;

            $hash = HashChain::hash($prev, $seq, $user?->id, $action, $subjectType, $subjectId, $caseId, $detailsJson, $loggedAt);

            return AuditLog::create([
                'seq' => $seq,
                'user_id' => $user?->id,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'case_id' => $caseId,
                'details' => $detailsJson,
                'prev_hash' => $prev,
                'hash' => $hash,
                'logged_at' => $loggedAt,
            ]);
        });
    }
}
