# Sled + Weighted-Carry → `load_output` — FOLLOW-UP 2 (post-review fixes) — Prompt for Antigravity CLI

> **Second follow-up.** The `load_output` type + the first follow-up are already committed (Logger
> `e3a8d42b` + `90d53075` on `feature/sled-carry-unification`). A behavioral review then found two BLOCKING
> defects in the re-typing migration plus two smaller gaps. This prompt fixes them. Do NOT rebuild the
> strategy or re-author migration A — targeted edits only. The two migrations are committed but NOT yet run
> against any shared DB, so migration B may be edited in place (it is not a "already-run" migration in any
> shared environment); confirm locally with a fresh migrate.

## Before You Start

Read in order.

### Steering
```
.kiro/steering/git-workflow.md          → NEVER push, NEVER merge into main
docs/antigravity-steering.md            → executor contract: NEVER commit, NEVER Pint, milestone testing, AGY_COMPLETE
.kiro/steering/safe-operations.md       → protected files, artisan safety, Pint ban, NEVER destructive DB (migrate:fresh/reset/wipe)
.kiro/steering/project-conventions.md   → forward-only migrations, no column repurposing
.kiro/steering/sync-api-context.md      → sync download path: log_type is the field-mapping key
```

### Plan + source of truth
```
docs/plans/sled-carry-load-output.md    → the load_output architecture + the frozen shared names/semantics (source of truth for this repo)
```
Key frozen facts you need (restated so you never leave this repo): `sled` and `weighted-carry` stay
DISTINCT logTypes — ONLY `exercise_type` unifies to `load_output`; `log_type` is preserved. `dual-kettlebell`
+ `static-hold` stay `static_hold`.

> **Repository containment (hard boundary):** Do ALL work inside this `logger/` repository only. Do NOT
> read, edit, run, or `cd` into any path outside `logger/` — including the parent `squirby/` workspace, the
> root `contracts/` suite, or the sibling `athlete/` repo. The cross-app contract suite is run by the
> reviewer from the root AFTER you finish; it is NOT your step.

### Code to understand before editing
```
database/migrations/2026_08_30_142524_retype_sled_and_carry_exercises_to_load_output.php → migration B (the file to fix)
app/Sync/Services/SetFieldMapper.php          → mapFromColumns($logType, $set): the download mapper. Has cases for 'sled' and 'weighted-carry'; NO 'load_output' case (by design).
app/Sync/Controllers/RestoreController.php    → reads $liftLog->log_type first, passes to mapFromColumns
app/Sync/Controllers/ChangesController.php    → same log_type usage
app/Enums/PRType.php                          → getLabel() / getBestLabel() (no arms for load/distance/duration/speed)
app/Services/PRDetectionService.php           → enrichPRsWithPreviousPRIds (type-specific filters for rep_specific/hypertrophy; none for speed)
app/Services/ExerciseTypes/LoadOutputExerciseType.php → the strategy (reference; do not rebuild)
```

---

## Why This Follow-Up Exists (review findings)

1. **BLOCKER — migration B overwrites `lift_logs.log_type`, corrupting the sync download path.** The
   migration correctly re-types `exercises.exercise_type` to `load_output`, but it ALSO rewrites
   `lift_logs.log_type` from `sled`/`weighted-carry` to `load_output`. `log_type` is the field-mapping key:
   `RestoreController`/`ChangesController` read `$liftLog->log_type` and pass it to
   `SetFieldMapper::mapFromColumns($logType, $set)`, which has NO `load_output` case (and neither does
   Athlete — the frozen design keeps `sled` + `weighted-carry` as distinct logTypes). So every re-typed
   historical set would restore to Athlete with an EMPTY body (no weight/distance/duration).

2. **BLOCKER — migration B `down()` filters a non-existent column.** `down()` runs
   `DB::table('lift_logs')->where('exercise_type', ...)`, but `lift_logs` has no `exercise_type` column
   (only `log_type`), so rollback throws "unknown column." It is also conceptually unrecoverable once both
   types collapse to one value.

3. **LOW — `PRType` labels not extended.** `getLabel()`/`getBestLabel()` have no arms for
   `LOAD`/`DISTANCE`/`DURATION`/`SPEED`, so if the emoji-label path is used for these records it falls
   through to a generic label. The strategy's `formatPRDisplay`/`formatCurrentPRDisplay` own the real
   user-facing copy.

4. **LOW — `speed` `previous_pr_id` not disambiguated by bucket.** `enrichPRsWithPreviousPRIds` filters by
   `rep_count` for `rep_specific` and `weight` for `hypertrophy`, but nothing for `speed`; a prior log with
   multiple speed buckets yields multiple `pr_type='speed'` rows and `->first()` picks arbitrarily.

---

## What To Build

### Task 1 — Stop migration B from re-typing `lift_logs.log_type` (BLOCKER)
Remove the `lift_logs.log_type` UPDATE from migration B's `up()`. Re-type ONLY `exercises.exercise_type`
(scoped by `log_type IN ('sled','weighted-carry')` — unchanged). Leave every `lift_logs.log_type` value as
`sled` / `weighted-carry` so the download mapper keeps producing correct set bodies. (The exercise's own
`log_type` is likewise left untouched.) The PR recompute + `COUNT(sled_*)=0` assert + `sled_*` drop and the
correct ordering all stay exactly as they are.

### Task 2 — Fix migration B `down()` (BLOCKER)
Since `up()` no longer touches `lift_logs.log_type`, remove the `lift_logs` restore statements from `down()`
entirely (they reference a non-existent `exercise_type` column and there is nothing to restore). Keep the
`down()` steps that re-add the `sled_*` enum values and restore `exercises.exercise_type`
(`sled`→`sled`, `weighted-carry`→`static_hold`) scoped by `log_type`. Keep documenting that recomputed PR
rows aren't perfectly reversible.

### Task 3 — Verify the migration end-to-end with a seeded sled/carry log (BLOCKER-adjacent test gap)
The current tests never run migration B against seeded data, which is why the defect slipped through. Add a
migration/feature test that: seeds a `sled` exercise + `weighted-carry` exercise with lift_logs + sets and
some `sled_*` personal_records; runs the migration; then asserts (a) `exercises.exercise_type` became
`load_output`, (b) **`lift_logs.log_type` is STILL `sled`/`weighted-carry`** (not `load_output`), (c) no
`personal_records.pr_type` references `sled_*`, and (d) genuine static-hold / dual-kettlebell rows are
untouched. Optionally assert `mapFromColumns($liftLog->log_type, $set)` still returns a non-empty body for a
re-typed log (the concrete regression this prevents). Do NOT use `migrate:fresh`/`reset`/`wipe`.

### Task 4 — Extend `PRType` labels (LOW)
Add `getLabel()` arms for `LOAD`/`DISTANCE`/`DURATION`/`SPEED` (short human labels consistent with the
strategy copy — "Heaviest Load" / "Farthest Distance" / "Longest Duration" / "Fastest Pace" or their emoji
equivalents), and add them to the `getBestLabel` priority list in a sensible rank. If you determine the
emoji-label path is genuinely never used for load_output (strategy owns all display), instead add a
one-line comment documenting that and leave a safe default — but prefer adding the arms.

### Task 5 — Disambiguate `speed` `previous_pr_id` (LOW)
In `enrichPRsWithPreviousPRIds`, add a `speed` filter mirroring the existing ones: match the previous
`PersonalRecord` on `lift_log_id` + `pr_type='speed'` AND the bucket identity (`weight` + `rep_count`,
which hold the loadComp and integer-meters for speed rows). So the back-reference points at the correct
bucket's record.

---

## Execution Plan (checkpoints use `php artisan test --parallel`)

### Phase 1 — Migration fixes + verification test (Tasks 1–3)
- Edit migration B up()/down(); add the seeded migration test.
- **Checkpoint.**

### Phase 2 — Label + back-ref gaps (Tasks 4–5)
- Extend `PRType`; add the `speed` enrichment filter.
- **Checkpoint.**

### Phase 3 — Full verification
- **Checkpoint:** `php artisan test --parallel`. Green except the 3 documented PRE-EXISTING unrelated
  failures (`PRTypeInterferenceTest::first_time_rep_count_is_a_pr_by_design`,
  `ExercisePRCardsIntegrationTest::pr_cards_display_time_ago_for_old_prs`,
  `ExercisePRHighlightingTest::it_only_marks_pr_for_1_2_3_rep_ranges`). Any NEW failure must be fixed.
- Do NOT run the root contract suite yourself — it lives outside this repo. The reviewer runs it from the
  squirby root after you finish and print `AGY_COMPLETE`.

## HARD RULES
- **Stay inside `logger/`.** Do NOT read, edit, run, or `cd` into anything outside this repo — no parent
  `squirby/` workspace, no root `contracts/`, no `athlete/`. All commands run from the `logger/` root.
- **NEVER commit / push / run Pint.** **NEVER** `migrate:fresh`/`migrate:reset`/`db:wipe`.
- Do NOT re-type `lift_logs.log_type`. Do NOT add a `load_output` case to `SetFieldMapper` (the logTypes
  stay `sled`/`weighted-carry` by design).
- Do NOT change `static_hold` behavior or its 300s cap. Do NOT rebuild the strategy or migration A.
- Eloquent not `DB::` in app code (migration raw enum statements are the documented exception).

## Success Criteria
- [ ] Migration B re-types ONLY `exercises.exercise_type`; `lift_logs.log_type` stays `sled`/`weighted-carry`.
- [ ] Migration B `down()` runs without error (no `lift_logs.exercise_type` reference).
- [ ] A seeded migration test proves exercise_type flips, log_type is preserved, no `sled_*` rows remain,
      and genuine static-holds/dual-kettlebells are untouched.
- [ ] `PRType::getLabel`/`getBestLabel` handle the four new types (or documented as unused with a safe default).
- [ ] `speed` `previous_pr_id` is disambiguated by bucket.
- [ ] `php artisan test --parallel` green bar the 3 pre-existing failures; root contract suite green.
- [ ] No commits, no Pint, no destructive DB, no new composer deps.

## Do Not
- Do NOT read, edit, run, or `cd` into anything outside the `logger/` repo (no parent workspace, no root
  `contracts/`, no `athlete/`).
- Do NOT commit, push, or run Pint.
- Do NOT re-type or add a mapper case for `log_type = load_output`.
- Do NOT introduce `load_volume` or keep `sled_*` (beyond the transitional enum window migration A already handles).
- Do NOT change static_hold behavior / 300s cap.

## Post-Execution Retro (filled after completion; reviewer authors)
- **Attempts:** 2. Attempt 1 (this prompt) shipped the migration fixes and passed the migration test on
  in-memory SQLite, but a real defect survived: the executor kept a blanket `DELETE FROM personal_records
  WHERE pr_type IN (sled_*)` and relied on the recompute's Eloquent `->delete()` to clear the rest. Attempt
  2 (fix commit `45bf2303`) was forced when the migration was actually RUN against the MySQL dev DB during
  manual verification and threw an FK integrity violation, then (once the blanket delete was removed) left
  soft-deleted rows behind.
- **Migration test:** original seeds a sled exercise + carry exercise + logs + a legacy `sled_weight` PR,
  runs migration B, asserts exercise_type→load_output, log_type preserved, sled_* count 0, static-hold
  untouched, mapFromColumns non-empty. **Added in attempt 2:** a second case seeding two legacy sled_* rows
  where one references the other via `previous_pr_id` (reproduces the self-FK) — asserts the migration does
  not throw and leaves zero sled_* rows (catches both the FK and soft-delete traps the SQLite happy-path missed).
- **PRType labels:** added (LOAD/DISTANCE/DURATION/SPEED getLabel arms + getBestLabel priority).
- **Tests added:** 1 (the self-referential/soft-delete migration regression case), on top of the original.
- **Prompt improvements for next time:**
  1. **A migration is not verified until it has RUN against a real (MySQL) DB, not just the in-memory SQLite
     test.** The SQLite migration test passed while the migration was broken on MySQL. Prompts that add a
     data migration must include a "run `php artisan migrate` against the dev DB and confirm `migrate:status`
     = Ran" step, not only a `RefreshDatabase` feature test.
  2. **Never bulk-`DELETE` rows on a table with a self-referential FK** (`personal_records.previous_pr_id`,
     `NO ACTION`) — null inbound references first, or delete the whole connected set together.
  3. **`PersonalRecord` uses SoftDeletes** — an Eloquent `->delete()` (as in `PRRecalculationService`) does
     NOT physically remove rows; a migration that must eliminate rows by pr_type has to hard-delete via the
     query builder (which also bypasses the soft-delete scope). Raw `DB::table()->count()` sees soft-deleted
     rows, so an assert built on it will trip on them.
  4. The blanket delete I flagged in the follow-up-2 review as "redundant but safe" was in fact the source
     of the FK violation — trust the "why is this here?" review instinct and remove genuinely redundant
     data operations rather than leaving them as belt-and-suspenders.
- **Steering updates needed:** yes — see the two additions made to `logger/.kiro/steering/conventions.md`
  (migration safety: SoftDeletes + self-FK deletes) and the run-it rule woven into the master plan (rev 18).
