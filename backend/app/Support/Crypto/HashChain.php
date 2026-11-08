<?php

namespace App\Support\Crypto;

/**
 * Hash chain SHA-256 sederhana (mirip blockchain) untuk audit log.
 * hash_n = SHA256( JSON[ hash_(n-1), seq, user_id, action, subject_type, subject_id, case_id, details, logged_at ] )
 * Mengubah / menghapus / menyisipkan satu baris akan memutus rantai pada baris tersebut.
 */
final class HashChain
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public static function hash(
        string $prevHash,
        int $seq,
        int|string|null $userId,
        string $action,
        ?string $subjectType,
        int|string|null $subjectId,
        int|string|null $caseId,
        string $details,
        string $loggedAt
    ): string {
        $payload = json_encode([
            $prevHash,
            $seq,
            $userId === null ? null : (int) $userId,
            $action,
            $subjectType,
            $subjectId === null ? null : (string) $subjectId,
            $caseId === null ? null : (int) $caseId,
            $details,
            $loggedAt,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $payload);
    }

    /**
     * Memverifikasi rangkaian baris (urut seq naik).
     *
     * @param iterable<object|array> $rows
     * @return array{valid: bool, checked: int, broken_at_seq: int|null, reason: string|null, head_hash: string}
     */
    public static function verify(iterable $rows, ?string $expectedPrev = null, ?int $expectedSeq = null): array
    {
        $prev = $expectedPrev ?? self::GENESIS;
        $nextSeq = $expectedSeq ?? 1;
        $checked = 0;

        foreach ($rows as $row) {
            $r = (array) $row;
            $seq = (int) $r['seq'];

            if ($seq !== $nextSeq) {
                return self::fail($checked, $nextSeq, 'Urutan seq terputus (baris hilang/disisipkan).', $prev);
            }
            if (!hash_equals($prev, (string) $r['prev_hash'])) {
                return self::fail($checked, $seq, 'prev_hash tidak cocok dengan hash sebelumnya.', $prev);
            }

            $expected = self::hash(
                $prev,
                $seq,
                $r['user_id'],
                (string) $r['action'],
                $r['subject_type'],
                $r['subject_id'],
                $r['case_id'],
                (string) $r['details'],
                (string) $r['logged_at']
            );
            if (!hash_equals($expected, (string) $r['hash'])) {
                return self::fail($checked, $seq, 'Isi baris telah diubah (hash tidak cocok).', $prev);
            }

            $prev = $expected;
            $nextSeq = $seq + 1;
            $checked++;
        }

        return ['valid' => true, 'checked' => $checked, 'broken_at_seq' => null, 'reason' => null, 'head_hash' => $prev];
    }

    private static function fail(int $checked, int $seq, string $reason, string $head): array
    {
        return ['valid' => false, 'checked' => $checked, 'broken_at_seq' => $seq, 'reason' => $reason, 'head_hash' => $head];
    }
}
