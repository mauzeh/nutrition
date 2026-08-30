# Sled + Weighted-Carry → `load_output` — FOLLOW-UP Prompt for Antigravity CLI

> **This is a follow-up to `sled-carry-load-output-prompt.md`.** The base `load_output` implementation is
> already committed (Logger `e3a8d42b` on `feature/sled-carry-unification`): `LoadOutputExerciseType`, the
> two migrations, resolver switch, PR-table delegation, config, and initial tests all exist and the full
> suite is green (3 pre-existing unrelated failures aside). This prompt fixes defects found in review — do
> NOT rebuild the type or re-author the migrations. Make targeted edits only.

## Before You Start

Read these in order.

### 1. Steering (project rules — always follow)
```
.kiro/steering/git-workflow.md          → NEVER push, NEVER merge into main
docs/antigravity-steering.md            → executor contract: NEVER commit, NEVER Pint, milestone testing, self-correction loop, AGY_COMPLETE, §13 consumer trace
.kiro/steering/safe-operations.md       → protected files, artisan safety, Pint ban
.kiro/steering/project-conventions.md   → forward-only migrations, no column repurposing, dispatch events
.kiro/steering/sync-api-context.md      → exercise-type strategy pattern, PR dispatch, data model
.kiro/steering/laravel-boost.md         → Eloquent not DB::, constructor promotion, return types, PHPUnit-only
```

### 2. Plan + source of truth (reference — DO NOT treat as execution steps)
```
docs/plans/sled-carry-load-output.md    → the Logger architecture (records, migration order, consumer trace)
docs/plans/sled-carry-load-output-prompt.md → the ORIGINAL prompt this follows up on
../../docs/plans/sled-carry-unification-cross-repo.md → FROZEN rev 7 (source of truth for shared names/semantics)
```

### 3. Code to understand before editing (already implemented in `e3a8d42b`)
```
app/Services/ExerciseTypes/LoadOutputExerciseType.php     → the strategy: getSupportedPRTypes(), calculateCurrentMetrics(), compareToPrevious() (speed PR built here), comparisonValue()
app/Services/PRDetectionService.php                       → isLiftLogPR() builds bitwise flags via mapPRTypeStringToEnum() (match on pr_type string); detectPRsWithDetails() is the string path
app/Enums/PRType.php                                      → int bitmask enum; currently NO cases for load/distance/duration/speed
app/Listeners/DetectAndRecordPRs.php                      → persists PersonalRecord rows (weight/unit/value); sets lift_logs.is_pr / pr_count off count($prs)
app/Services/PRRecalculationService.php                   → recalculateAllPRsForExercise() — the recompute path used on update/backdate
app/Services/UnitResolver.php                             → convert()/format() — base unit is lbs
tests/Unit/Services/ExerciseTypes/LoadOutputExerciseTypeTest.php → existing unit tests to extend
tests/Feature/LoadOutputIntegrationTest.php               → existing feature tests to strengthen
```

---

## Why This Follow-Up Exists (defects found in review)

The committed base is faithful to FROZEN rev 7 on dispatch, migration order, D1/D4, and the `beats()`
simplicity mandate. Three defects remain:

1. **`is_pr` detection is broken through the real production path (BLOCKER).** `LoadOutputExerciseType::
   getSupportedPRTypes()` returns `[PRType::VOLUME]` as a "non-empty gate." That satisfies
   `detectPRsWithDetails()`, but `PRDetectionService::isLiftLogPR()` ALSO maps each emitted PR's type
   string through `mapPRTypeStringToEnum()` to build bitwise flags — and that `match` has NO arms for
   `load`/`distance`/`duration`/`speed`, so they fall to `default => null`. Any caller using
   `isLiftLogPR()` sees `PRType::NONE` (false) for a genuine load_output PR. The current
   `it_sets_is_pr_flag...` feature test hides this because it hand-computes via `detectPRsWithDetails()`
   and manually sets `is_pr = true` instead of exercising the real listener/detection path.

2. **`speed` PR persists a base-unit (lbs) number under a possibly-non-lbs `unit` stamp (BLOCKER).** In
   `compareToPrevious()` the speed PR sets `'weight' => (float) $loadComp`, where `loadComp` is the
   UnitResolver-normalized **lbs** value. `DetectAndRecordPRs` then stores that row with
   `'unit' => $liftLog->liftSets->first()->unit` — which may be `kg`. A 100 kg carry yields a speed row of
   `weight = 220.46, unit = 'kg'`: a value/unit mismatch that will read wrong anywhere outside the current
   happy path.

3. **Test coverage gaps the original plan required but that are missing:** a true cross-unit `load` D1
   proof, a different-bucket "no speed PR" case, `comparisonValue()` / PR met-not-met assembler rendering,
   and a real-path `is_pr` assertion.

---

## What To Build

### Task 1 — Make `is_pr` fire for load_output through the REAL detection path (BLOCKER 1)

Pick ONE approach and apply it consistently; approach A is preferred because it keeps the bitmask path
honest for every consumer.

- **Approach A (preferred): wire the new strings into the bitmask.** Add cases to `PRType` (int bitmask,
  `app/Enums/PRType.php`) for `LOAD`, `DISTANCE`, `DURATION`, `SPEED` (next free bit values, do not reuse
  existing bits), and add matching arms to `PRDetectionService::mapPRTypeStringToEnum()`
  (`'load' => PRType::LOAD`, etc.). `getSupportedPRTypes()` then returns the real set
  (`[PRType::LOAD, PRType::DISTANCE, PRType::DURATION, PRType::SPEED]`) instead of the borrowed
  `PRType::VOLUME`. Confirm no other consumer treats the `PRType` bitmask as a fixed/closed set (grep
  usages) before adding bits.
- **Approach B (only if adding bitmask cases is rejected):** document in the strategy why load_output is
  string-path only, and prove via test that `is_pr`/`pr_count` still flip. But note `isLiftLogPR()` would
  remain false for load_output — if any caller depends on it, this is not acceptable. Prefer A.

**Regardless of approach:** replace the current hand-rolled `is_pr` feature test with one that drives the
REAL path — dispatch `LiftLogCompleted` (or call the `DetectAndRecordPRs` listener / `PRDetectionService`
as production does) for a `load_output` log that sets a PR, then assert `lift_logs.is_pr === true` and
`pr_count > 0` (and, under Approach A, that `isLiftLogPR()` returns a non-zero flag).

### Task 2 — Fix the `speed` PR weight/unit representation (BLOCKER 2)

Make the persisted speed PR internally consistent. Two acceptable fixes — choose the one that matches how
`load` is stored and how Athlete + the Step 5c fixture represent it (check FROZEN §2 and the Athlete
`bestSpeed` key before deciding):

- Store the speed PR `weight` as the **base-unit lbs** value AND stamp the row `unit = 'lbs'` for
  load_output PRs (do not inherit a `kg` set's unit for a base-unit number), **or**
- Store the raw logged weight + its real unit and normalize only at compare time.

Whichever is chosen: the value written to `personal_records.weight`, the `unit` column, the
`formatPRDisplay`/`formatCurrentPRDisplay` label ("Fastest N lbs × M m" — do not hardcode lbs if the stored
value isn't lbs), and the `comparisonValue()` bucket-key lookup (`"{$pr->weight}|{$pr->rep_count}"`) MUST
all agree. Note `DetectAndRecordPRs` currently hardcodes `unit` from the first set — if you pick base-lbs,
the unit for these PR rows must be set deliberately, not inherited. Keep it consistent for `load` too if
`load` has the same latent issue.

### Task 3 — Harden the `speed` bucket key against float drift

The bucket key is `"{$loadComp}|{$integerMeters}"` with `loadComp = round(convert(...), 4)`. Confirm this
formats identically for equal loads logged in different units (e.g. an lbs log and the kg log that converts
to the same lbs value) — PHP float→string can differ (`220` vs `220.0`). If there's any risk, pin the
`loadComp` string formatting (e.g. a fixed decimal format) so the key is stable and **byte-for-byte
identical to the Athlete engine's key** (FROZEN §1 pins `${loadComp}|${integerMeters}`). Do not change the
semantics — only make the key deterministic. If already safe, add a test that proves it and note it in the
retro.

### Task 4 — Add the missing tests (were required by the original plan)

Add to `tests/Unit/Services/ExerciseTypes/LoadOutputExerciseTypeTest.php` and/or
`tests/Feature/LoadOutputIntegrationTest.php`:

- **Cross-unit `load` D1 proof:** a previous log in `kg` and a current log in `lbs` (or vice versa) that
  are equal after normalization → NOT a load PR; and one that is genuinely heavier after normalization →
  IS a load PR. This is the real D1 assertion (the existing metrics test only soft-checks one value).
- **Different-bucket speed:** a set with a different load OR different distance from the stored bucket does
  NOT fire a `speed` PR (separate bucket), even if its duration is shorter.
- **`comparisonValue()` + PR table rendering:** a `load_output` log renders correct met (beaten) and
  not-met (current / by-how-much) rows for load/distance/duration/speed via
  `PRRecordsComponentAssembler`, including the `speed` composite-key de-dupe.
- **Real-path `is_pr`** (from Task 1).

---

## Execution Plan

### Phase 1 — Blocker fixes
1. Task 1 (`is_pr` via real path) + Task 2 (speed weight/unit). Keep the `beats()` helper and the metric
   shape unchanged — these are surgical edits to `getSupportedPRTypes()`, `PRType`,
   `mapPRTypeStringToEnum()`, the speed-PR array in `compareToPrevious()`, and the `unit` handling in
   `DetectAndRecordPRs` (only for load_output types — do NOT change behavior for other types).
2. **Checkpoint** — `php artisan test --parallel`.

### Phase 2 — Key hardening + tests
3. Task 3 (bucket key determinism, if needed) + Task 4 (tests).
4. **Checkpoint** — `php artisan test --parallel`.

### Phase 3 — Verify no regressions
5. Full suite `php artisan test --parallel`. The only acceptable failures are the 3 PRE-EXISTING,
   load_output-unrelated ones: `ExercisePRCardsIntegrationTest::pr_cards_display_time_ago_for_old_prs`,
   `ExercisePRHighlightingTest::it_only_marks_pr_for_1_2_3_rep_ranges`,
   `PRTypeInterferenceTest::first_time_rep_count_is_a_pr_by_design`. If any NEW test fails, fix it.
6. Report before/after on: does `isLiftLogPR()` now return non-zero for a load_output PR; is the speed row
   value/unit consistent.

---

## HARD RULES — NEVER VIOLATE THESE
- **NEVER commit.** Do not run `git commit`, `git add`, or any git command.
- **NEVER run Pint.** Do not run `vendor/bin/pint` in any form.
- **NEVER push.**
- **NEVER run destructive database commands** (`migrate:fresh`, `migrate:reset`, `db:wipe`).
- **NEVER modify a migration that has already run** — the two `load_output` migrations are committed;
  if a schema/data change is truly needed, add a NEW migration file. (Tasks 1–4 should need none.)
- **Do NOT reintroduce `load_volume` or `sled_*`.**
- **Do NOT change behavior for any non-load_output type** (`static_hold`, `regular`, etc.), incl. the
  `DetectAndRecordPRs` `unit` handling — scope any change to load_output PR rows.

## Implementation Rules
- Eloquent not `DB::` in app code. Constructor promotion; explicit return types. PHPUnit only; factories.
- `--no-interaction` on artisan. `php artisan test --parallel` for tests; single file with
  `--parallel tests/Path/SomeTest.php`.
- Load normalization via `UnitResolver` (base lbs). Keep the single `beats()` comparison helper — do NOT
  re-inline per-record comparisons.
- Do NOT grow `getComparisonValue`'s switch or add exercise-type-string switches outside the config map.

## Success Criteria
- [ ] A `load_output` PR flips `lift_logs.is_pr`/`pr_count` through the REAL detection path (not a
      hand-rolled test), and (Approach A) `isLiftLogPR()` returns a non-zero flag for it.
- [ ] `getSupportedPRTypes()` no longer borrows `PRType::VOLUME` as a placeholder (Approach A), OR the
      string-only decision is documented with a passing real-path test (Approach B).
- [ ] The persisted `speed` PR row's `weight`, `unit`, label, and `comparisonValue()` lookup key are
      mutually consistent; a kg-logged carry no longer stores an lbs number under `unit='kg'`.
- [ ] The `speed` bucket key is deterministic across units and matches the Athlete key form.
- [ ] New tests: cross-unit load D1 (PR + non-PR), different-bucket no-speed-PR, comparisonValue/PR-table
      rendering, real-path is_pr.
- [ ] `php artisan test --parallel` green except the 3 documented pre-existing failures.
- [ ] No new `pr_type` strings; no `load_volume`/`sled_*`; no commits; no Pint; no new composer deps.

## Do Not
- Do NOT rebuild `LoadOutputExerciseType`, re-author the migrations, or re-do the resolver/config work —
  they are committed and correct. Make targeted edits only.
- Do NOT add `load`/`distance`/`duration`/`speed` arms to the assembler's `getComparisonValue` switch —
  comparison stays in `strategy->comparisonValue()`.
- Do NOT add conversion at sync ingress (`SetFieldMapper`/`StoreSyncLogAction` stay verbatim + unit stamp).
- Do NOT touch `static_hold`'s behavior or its 300s cap.

## Post-Execution Retro (fill in after completion)
- **Attempts:** {1 (clean) / N + root cause}
- **Approach chosen for Task 1:** {A wires PRType bitmask / B string-only + why}
- **Speed weight/unit representation chosen:** {base-lbs+unit=lbs / raw+normalize-on-compare}
- **Tests added:** {count}
- **Prompt improvements for next time:** {…}
- **Steering updates needed:** {yes/no + what}
