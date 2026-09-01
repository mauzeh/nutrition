# PROMPT — Fix `bodyweight-reps` added-weight mapping (Logger)

Read `docs/antigravity-steering.md`, then `docs/plans/bodyweight-added-weight-mapping.md`. Execute from
THIS prompt.

**Boundary rule (mandatory):** stay entirely within `logger/`. NEVER read/reference/write outside this
repo (no `../../`, no root workspace, no other app). If a change appears to require an Athlete edit, flag
it as a follow-up — do not reach across.

Hard rules: never commit/push, never Pint, no destructive DB. Milestone testing with
`php artisan test --parallel`; write output to `.test-output.txt`, read it, delete when green. Fill the
Post-Execution Retro in the plan file, then print `AGY_COMPLETE: All milestones passed.`

---

## Milestone 1 — Preserve weight for `bodyweight-reps` (both directions)

1. Read `app/Sync/Services/SetFieldMapper.php`.
2. In `mapToColumns`, change the `bodyweight-reps` case to also set weight:
   `$columns['weight'] = $setData['addedWeight'] ?? $setData['weight'] ?? 0;` (keep `reps`). This mirrors
   the `bodyweight` / `added-weight` case. If the two cases are now identical, merge them into one shared
   `case 'bodyweight': case 'added-weight': case 'bodyweight-reps':` block to prevent future drift.
3. In `mapFromColumns`, add `$data['weight'] = $set->weight;` to the `bodyweight-reps` case (or fold it
   into the shared bodyweight case, matching whatever you did in step 2).
4. Verify no other case regressed (true bodyweight with no added weight must still yield weight `0`).

**Checkpoint:** `php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt`. Fix to green.

## Milestone 2 — Tests

5. Add/extend a SetFieldMapper test (PHPUnit) asserting:
   - `mapToColumns('bodyweight-reps', ['addedWeight' => 15, 'reps' => 5], 'lbs')` → `weight == 15`, `reps == 5`.
   - `mapToColumns('bodyweight-reps', ['reps' => 5], 'lbs')` → `weight == 0`.
   - `mapFromColumns('bodyweight-reps', <LiftSet weight=15, reps=5>)` → `['weight' => 15, 'reps' => 5]`.
   - `mapFromColumns('bodyweight-reps', <LiftSet weight=0, reps=8>)` → weight `0`, reps `8`.
6. If a sync round-trip feature test exists for bodyweight, extend it with an added-weight `bodyweight-reps`
   case; otherwise add one asserting the weight survives inbound store + outbound `/changes` (or the
   mapper round-trip directly).

**Checkpoint:** full suite green. Fill the retro. Print the completion signal.

## Success criteria

`bodyweight-reps` preserves added weight both directions; true bodyweight unchanged (weight 0); tests
cover both directions + round-trip; suite green.

## Do not

- Do not modify Athlete files or reach outside `logger/`. Do not change `bodyweight`/`added-weight`
  behavior beyond optional case-merging. Do not commit. Do not Pint.

## Post-Execution Retro

(Write into the plan file, not here.)
