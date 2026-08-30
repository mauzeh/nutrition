# Sled + Weighted-Carry → `load_output` — Prompt for Antigravity CLI

## Before You Start

Read these files in order.

### 1. Steering (project rules — always follow)
```
.kiro/steering/git-workflow.md          → NEVER push, NEVER merge into main
docs/antigravity-steering.md            → executor contract: NEVER commit, NEVER Pint, milestone testing, self-correction loop, AGY_COMPLETE, §13 consumer trace, §15 decomposition
.kiro/steering/safe-operations.md       → protected files, artisan safety, Pint ban
.kiro/steering/project-conventions.md   → forward-only migrations, no column repurposing, dispatch events
.kiro/steering/sync-api-context.md      → exercise-type strategy pattern, PR dispatch, data model
.kiro/steering/laravel-boost.md         → Eloquent not DB::, constructor promotion, return types, PHPUnit-only
```

### 2. Plan (reference architecture — DO NOT treat as execution steps)
```
docs/plans/sled-carry-load-output.md    → full architecture, records, migration order, consumer trace
../../docs/plans/sled-carry-unification-cross-repo.md → FROZEN rev 7 (source of truth for shared names/semantics)
```

### 3. Existing code to understand (read before modifying)
```
config/exercise_types.php                                → mirror the `sled` entry for `load_output`; remove `sled` at the end
app/Services/ExerciseTypes/SledExerciseType.php          → generalize then DELETE (do NOT copy raw-int weight compare or inlined 0.3048)
app/Services/ExerciseTypes/StaticHoldExerciseType.php    → duration handling reference; leave it owning genuine holds
app/Services/ExerciseTypes/BaseExerciseType.php          → base class
app/Sync/Services/ExerciseResolverService.php            → deriveExerciseType() match block (lines ~131, ~135)
app/Services/UnitResolver.php                            → convert()/format() for load normalization (D1)
app/Listeners/DetectAndRecordPRs.php                     → writes PersonalRecord rows (pr_type must be valid enum)
app/Services/PRDetectionService.php                      → gates on getSupportedPRTypes(); calls metrics + compare
app/Services/PRRecalculationService.php                  → recalculateAllPRsForExercise() — the recompute used by Migration B
```

### 4. Reference (already implemented — study, don't rebuild)
```
database/migrations/2026_07_25_015633_add_sled_pr_types_to_personal_records.php   → enum migration template (up+down, sqlite+mysql)
database/migrations/2026_06_22_150458_fix_weighted_carry_and_dual_kettlebell_exercise_type.php → scoped-by-log_type data fix
database/migrations/2026_07_25_013942_update_sled_exercises_type_and_log_type.php → exercises + lift_logs.log_type update
```

---

## What You're Building

Replace the bespoke `SledExerciseType` and the `weighted-carry → static_hold` routing with ONE
`load_output` exercise type. It computes PRs on independent axes with per-record direction:
- `load` — heaviest weight (max), normalized via `UnitResolver`.
- `distance` — farthest single set (max), integer-normalized meters.
- `duration` — longest single set (max), integer seconds.
- `speed` — MIN duration at a matched (load, integer-distance) (min direction, composite key).

`weighted-carry` and `sled` resolve to `load_output`; `dual-kettlebell` + `static-hold` stay `static_hold`.
Duration cap 900s for load_output (static_hold's 300s unchanged). Add `load`/`distance`/`duration`/`speed`
to the `personal_records.pr_type` enum; re-type historical sled + carry (scoped by `log_type`); recompute
their PRs; then drop the old `sled_*` enum values; delete `SledExerciseType`.

Zero behavioral change to `static_hold` (genuine holds + dual-kettlebell) and all non-sled/carry types.

---

## Execution Plan

Follow the phases in `docs/plans/sled-carry-load-output.md` in order. Each numbered section is a phase.

### Phase order:
1. **Enum migration (additive) + LoadOutputExerciseType + config + unit tests** — add the 4 strings to the
   enum (keep sled_* for now); build the strategy. Route EVERY record's win/lose decision through ONE
   private helper `beats($current, $stored, $direction)` (max = `>`, min = `<`) — do NOT re-inline the
   comparison per record the way SledExerciseType does. `load` via UnitResolver (D1); `distance` integer
   meters via a centralized ft→m helper (D4); `duration` integer seconds; `speed` = `beats(...,'min')` on
   the PINNED bucket: key `"{loadComp}|{integerMeters}"`, value integer seconds, strictly-less-than at the
   exact bucket, first entry = baseline (not a PR). Register the `load_output` config key (form_fields
   weight/distance/distance_unit/time, duration cap 900). Unit tests incl. cross-unit load (D1), ft→m
   normalization, speed PR / non-PR (equal or longer) / different-bucket. Keep `LoadOutputExerciseType`
   LOC ≤ the deleted SledExerciseType.
2. **Checkpoint** — `php artisan test --parallel`.
3. **Resolver switch + feature test** — deriveExerciseType: `'weighted-carry','sled' => 'load_output'`;
   keep `'dual-kettlebell','static-hold' => 'static_hold'`. Feature test: sync a carry + a sled, assert the
   correct pr_type rows written.
4. **Checkpoint** — `php artisan test --parallel`.
5. **Re-typing migration + recompute + drop sled_*** — Migration B, strict order: (1) update exercises
   (+ dependent lift_logs) scoped by `log_type IN ('sled','weighted-carry')`; (2) recompute affected via
   `PRRecalculationService::recalculateAllPRsForExercise` (step 1 MUST precede — recompute resolves the
   strategy from the re-typed exercise_type); (3) ASSERT `SELECT COUNT(*) FROM personal_records WHERE
   pr_type IN ('sled_weight','sled_distance','sled_volume')` = 0 and ABORT the drop if non-zero; only then
   drop `sled_*` from the enum. Correct down() path.
6. **Checkpoint** — `php artisan test --parallel`.
7. **Delete SledExerciseType + sled config key + dead sled_* code.** Grep `sled` to confirm cleanup.
8. **Final checkpoint** — `php artisan test --parallel`.

---

## HARD RULES — NEVER VIOLATE THESE:
- **NEVER commit.** Do not run `git commit`, `git add`, or any git command.
- **NEVER run Pint.** Do not run `vendor/bin/pint` in any form.
- **NEVER push.**
- **NEVER run destructive database commands.** No `migrate:fresh`, `migrate:reset`, `db:wipe`.
- **NEVER modify a migration that has already run** — create new migration files.
- **Do NOT emit any new pr_type string before the enum migration is in place.**
- **Do NOT drop `sled_*` from the enum until the recompute leaves zero referencing rows.**

## Implementation Rules
- New strategy in `app/Services/ExerciseTypes/`. Routes go nowhere new (no routes change).
- `php artisan test --parallel` for tests; single file with `--parallel tests/Feature/Path/SomeTest.php`.
- Never use `DB::` facade in app code — use Eloquent. (Migration raw `enum`/`MODIFY` statements mirror the
  precedent and are the exception.)
- PHP 8 constructor promotion; explicit return types; PHPUnit (`make:test --phpunit`), factories.
- `--no-interaction` on all artisan commands.
- Load normalization via `UnitResolver` (D1). ft→m from ONE centralized constant/helper (D4).
- Re-type scoped by `log_type` only. Recompute via `PRRecalculationService`.

## Success Criteria
- [ ] **Simpler:** one comparison helper `beats()` used by all records (no per-record inlined compare);
      `LoadOutputExerciseType` LOC ≤ deleted `SledExerciseType`; net production LOC ≤ 0 (excluding
      tests/migrations); no `switch`/`match` on exercise-type string outside the config map. Report
      before/after LOC in the retro.
- [ ] `personal_records.pr_type` includes `load`/`distance`/`duration`/`speed`; `sled_*` removed post-recompute.
- [ ] `LoadOutputExerciseType`: load via UnitResolver; distance integer meters; duration integer seconds;
      speed = min duration at matched (load, integer-distance).
- [ ] `weighted-carry` + `sled` → `load_output`; `dual-kettlebell` + `static-hold` → `static_hold`.
- [ ] Duration cap 900s for load_output; static_hold 300s + behavior unchanged.
- [ ] Historical sled + carry re-typed by `log_type`; genuine holds/dual-kettlebells untouched; PRs recomputed.
- [ ] `SledExerciseType` + `sled` config key deleted; no dead `sled_*`.
- [ ] All tests pass (`php artisan test --parallel`).
- [ ] No git commits, no Pint, no new composer deps.

## Do Not
- Do NOT commit, push, or run Pint.
- Do NOT introduce `load_volume` or keep `sled_*` (superseded).
- Do NOT re-type by `exercise_type` alone (scope by `log_type`).
- Do NOT change `static_hold` behavior / 300s cap / hold+dual-kettlebell routing.
- Do NOT add conversion at sync ingress (`SetFieldMapper`/`StoreSyncLogAction` stay verbatim + unit stamp).
- Do NOT copy SledExerciseType's raw-int weight comparison (D1) or inlined 0.3048 (D4).
- Do NOT leave dead code after deleting SledExerciseType.

## Post-Execution Retro (added after completion)
> Fill in after antigravity finishes. Move both plan + prompt to `completed/` once all fields are set.
- **Attempts:** {1 (clean) / N + root cause}
- **Tests added:** {count}
- **Prompt improvements for next time:** {…}
- **Steering updates needed:** {yes/no + what}
