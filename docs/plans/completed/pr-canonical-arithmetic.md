# PR Engine — Canonical Arithmetic (Logger slice, Phase 2)

> **Scope:** Logger-only slice of a coordinated three-repo Phase 2. Reference architecture; execution steps
> in `pr-canonical-arithmetic-prompt.md`. The shared FROZEN spec (§7–§14) lives at the root
> (`pr-load-role-token-cross-repo.md`); this slice keeps Logger byte-shape-aligned with Athlete on the
> canonical frame + factor. Do not re-decide the shared numbers.
>
> **Directional isolation:** Stay entirely inside `logger/`. No `../athlete`, `../contracts`, `../docs`, or
> sibling apps. The shared shape is duplicated inline from the FROZEN spec.
>
> **Builds on Phase 1** (the `load` role token, already landed in `config/pr_families.php` +
> `Reductions::resolveField`).

---

## What You're Building

Two things, zero user-facing behavior change beyond a hair-precision PR value shift:

1. **Unify the kg→lbs factor to full precision `2.2046226218`** and collapse Logger's TWO current values
   into ONE. Today `UnitResolver::KG_TO_LBS = 2.20462262` (truncated, used in the display/convert path)
   while `Reductions::extractValue` and `PRDetectionService` inline `2.2046226218` (full, the comparison
   path). Athlete is unifying to `2.2046226218`; Logger must match so the comparison INPUT is byte-identical
   across apps (the cross-app requirement).

2. **Canonical arithmetic shape parity + no intermediate rounding.** Logger already converts to the
   lbs-comparable frame in `Reductions::extractValue` and re-derives history from raw logs (its history is
   transient bare numbers — already the canonical model). The one inconsistency to fix: `extractValue`'s
   `$roundMass` flag rounds the converted mass to 2 dp for scalar/keyed reads but full precision for volume.
   The FROZEN rule is **no intermediate rounding** of the comparable value. Remove the 2-dp rounding so
   every mass read is full precision (quantization stays only at the speed-bucket key's whole-pound round,
   which is a KEY, not the comparable value).

3. **A one-time recalc MIGRATION** so production `personal_records` is rebuilt under the unified factor on
   deploy (values shift slightly; existing rows must be regenerated to be consistent day one).

## Why Logger already has the right model (context)

Logger's PR source of truth is the full `lift_logs` + `lift_sets` history. `buildHistoryFromPreviousLogs`
re-derives comparable (lbs) bests from raw logs every calculation; `personal_records` is a rebuildable
DISPLAY cache (`PRRecalculationService::recalculateAllPRsForExercise` deletes-then-rebuilds it from logs).
So Logger never round-trips a stored unit through a conversion — it has no persisted-blob frame bug. This
slice only (a) unifies the factor and (b) removes the one intermediate-rounding inconsistency, so Logger's
comparison INPUT matches Athlete's byte-for-byte. The `weight` column and storage are unchanged.

## Architecture

### The single factor
- Put the full-precision factor in ONE place. Set `UnitResolver::KG_TO_LBS = 2.2046226218` (and keep
  `LBS_TO_KG = 0.45359237`). Then make `Reductions::extractValue` and `PRDetectionService` reference that
  constant instead of inlining `2.2046226218` — so there is exactly ONE definition of the factor in the
  app. (They already use the full-precision value; this is a de-duplication to a single source, plus the
  `UnitResolver` bump from the truncated value.)
- Grep after: `2.20462262` → ZERO; `2.2046226218` → exactly one definition site (+ test assertions).

### No intermediate rounding
- In `Reductions::extractValue`, the `$convertMass` closure currently does
  `return $roundMass ? round($lbs, 2) : $lbs;`. Per FROZEN §9, the comparable value is NOT rounded. Make
  every mass conversion full precision (drop the 2-dp branch). `extractValueRaw` (already full precision)
  and `extractValue` converge on the same full-precision result. Verify the volume path (which relied on
  `roundMass: false`) is unchanged, and the scalar/keyed paths now also full-precision.
- The speed-bucket whole-pound round in `perKey` (`$kf === 'weight'` → `(int) round(...)`) STAYS — that is a
  KEY quantization (FROZEN §9), not the comparable value.

> **Consumer note:** removing the 2-dp round changes the exact comparable numbers slightly (e.g. a kg log's
> comparable goes from 2-dp to full precision). This can flip a borderline PR that the 2-dp rounding had
> masked, and shifts stored `personal_records.value`. That is WHY the recalc migration exists — see below.

### The recalc migration (the deploy-time rebuild)
- A forward-only Laravel migration whose `up()` rebuilds ALL `personal_records` from raw logs under the new
  factor, so production is consistent immediately on deploy.
- DO NOT hand-roll enumeration. `app/Console/Commands/CalculateHistoricalPRs.php` already enumerates every
  distinct `(user_id, exercise_id)` from BOTH `lift_logs` AND `personal_records` (the latter to clear
  orphans) and calls `PRRecalculationService::recalculateAllPRsForExercise` per pair. The migration should
  reuse that logic — either invoke the command (`Artisan::call('...')`) or replicate its two distinct-pair
  queries + the per-pair `recalculateAllPRsForExercise` loop inside the migration.
- Precedent: `database/migrations/2026_09_01_070639_merge_pull_up_variants.php` calls
  `PRRecalculationService` from a migration `up()` — mirror that structure (resolve service via the
  container, loop, no `down()` data-restore needed since it's a derived cache).
- **Operating rule (FROZEN §12, after a real Pending-migration bug):** the migration is NOT done until it
  has actually been RUN locally and `php artisan migrate:status` shows it **Ran**. A committed-but-Pending
  migration silently leaves prod on the old factor. The prompt's final milestone runs it + verifies status.

## Consumer Impact Trace (§13 Logger antigravity-steering — factor + comparable-precision change)

1. **`Reductions::extractValue`/`extractValueRaw`** — the mass-conversion chokepoint. Factor + rounding
   change here. Every reduction funnels through it. Updated in the same milestone.
2. **`UnitResolver::convert`/`formatForUser`** — display/convert path; factor bump. Display rounds to
   preference (nearest 1 lb / 0.5 kg) — that display rounding STAYS; only the raw factor changes.
3. **`PRDetectionService`** — de-normalizes PR values back to the log unit with the factor; point at the
   single constant.
4. **`personal_records` rows** — `value`/`weight` shift slightly with the unified factor; the recalc
   migration rebuilds them. No schema change, no column change.
5. **Display/label assemblers** (`PRRecordsComponentAssembler`) — read the stored value/unit; unchanged in
   shape, values refreshed by the recalc.
6. **Tests** — anything asserting `UnitResolver::KG_TO_LBS === 2.20462262`, any PR test with a hardcoded
   2-dp-rounded kg comparable, or a converted expected value. Recompute to full precision.

## Files Changed (Logger)

```
app/Services/UnitResolver.php                        [modified] KG_TO_LBS 2.20462262 → 2.2046226218 (single factor source)
app/Services/PR/Reductions.php                       [modified] extractValue: reference the single factor; remove 2-dp intermediate rounding (full precision)
app/Services/PRDetectionService.php                  [modified] reference the single factor (drop inlined literal)
database/migrations/XXXX_recalculate_prs_canonical_factor.php  [new] forward-only; rebuild all personal_records via recalculateAllPRsForExercise over all (user,exercise) pairs
tests/**                                             [modified] unified factor + full-precision comparable expectations
```

## Success Criteria / Constraints

- ONE kg→lbs factor (`2.2046226218`) in the app; `2.20462262` grep-clean.
- `Reductions::extractValue` does NO intermediate rounding of the comparable (full precision); the
  speed-bucket key keeps whole-pound rounding.
- The recalc migration exists, is forward-only, reuses `CalculateHistoricalPRs`/`recalculateAllPRsForExercise`,
  has been RUN, and `php artisan migrate:status` shows it Ran.
- `php artisan test --parallel` green; `personal_records` schema unchanged; no destructive DB commands.
- Descriptor shapes stay mirrored with Athlete; comparison input matches Athlete byte-for-byte (proven by
  the root contract slice's byte-exact parity test).

## Risks

- **A borderline PR flip.** Full precision can flip a PR the 2-dp rounding masked (correctly — 2-dp was the
  bug). The recalc migration rebuilds history consistently, so the stored state matches the new logic.
- **Migration left Pending.** The single biggest risk (see the operating rule). Run it, verify status.
- **Factor de-duplication.** Ensure `Reductions`/`PRDetectionService` reference the ONE constant, not a
  re-inlined literal — otherwise a future edit could re-diverge.

## Changelog
- rev 1 (2026-09-01) — Logger Phase-2 slice authored: unify kg→lbs to full precision, remove intermediate
  rounding, recalc migration. Aligns Logger's comparison input byte-for-byte with Athlete's canonical frame.
