<?php

namespace App\Services\PR;

/**
 * PrReasons — the "why not" explainer (Logger-only).
 *
 * Reason generation is a Logger concern; the Athlete engine emits `prs` only. To keep
 * PrEngine::detectPRs structurally identical to the Athlete detection loop, the detect loop
 * NEVER builds reason records inline — at each non-PR branch it delegates here with a single
 * call. All reason-record shaping lives in this one class.
 *
 * Policy: a reason is emitted ONLY for a genuinely CONTESTED miss — a prior best existed at
 * this metric/key and the current attempt did not beat it. The "no previous data yet"
 * (requirePrevious with empty history) and "needs 2+ sets" (minGroupSize) cases are NOT
 * reasons; they are absences, not misses. `forMiss()` returns null for those so the caller's
 * one-liner (`if ($reason) $reasons[] = $reason;`) stays trivial.
 */
final class PrReasons
{
    /**
     * Build a "why not" reason for a non-PR comparison result, or null when the miss is not
     * a contested one (no prior best to beat).
     *
     * @param array $descriptor The PR family descriptor.
     * @param array $comparison The comparator result: { isPR, current, best, delta }.
     * @param string|int|null $key Optional key for keyed types (rep count / weight / bucket).
     */
    public static function forMiss(array $descriptor, array $comparison, string|int|null $key = null): ?array
    {
        // Contested-miss only: a prior best must have existed. First-occurrence / requirePrevious
        // absences carry a null best and produce no reason.
        if (($comparison['best'] ?? null) === null) {
            return null;
        }

        $reason = [
            'type' => $descriptor['type'],
            'current' => $comparison['current'],
            'best' => $comparison['best'],
            'direction' => $descriptor['direction'] ?? 'max',
            'tolerance' => $descriptor['tolerance'] ?? 'none',
            'deltaToBeat' => $comparison['best'] - $comparison['current'],
        ];

        if ($key !== null) {
            $reason['key'] = $key;
        }

        return $reason;
    }
}
