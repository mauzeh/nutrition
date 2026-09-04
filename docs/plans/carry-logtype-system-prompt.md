# Carry LogType System — Prompt for Antigravity CLI (Logger slice)

## Global Execution Rules
1. Execute sequentially; test ONLY at the Milestone checkpoints.
2. SELF-CORRECTION LOOP: run the checkpoint; if tests fail, read the error, fix, re-run within the turn.
   Do NOT yield with a failing milestone; do NOT ask for help — fix it.
3. When ALL milestones pass, write the Post-Execution Retro into the bottom of THIS file (replace the
   `{placeholder}` values), run the End-of-Run Cleanup Sweep, then print exactly:
   ```
   AGY_COMPLETE: All milestones passed.
   ```
4. Test output: redirect to a project file, read it, delete it at the end. Never `/tmp/`. Never re-run to
   re-see output.
   ```bash
   php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt
   ```

---

## Before You Start (read in order)
### 1. Steering
```
docs/antigravity-steering.md             → git (NEVER commit), Pint BAN, DB rules, milestones, §13 trace, §14 sweep, AGY_COMPLETE
.kiro/steering/safe-operations.md        → protected files, artisan safety, Pint ban (wins)
.kiro/steering/project-conventions.md    → forward-only migrations, soft deletes, dispatch events
.kiro/steering/sync-api-context.md       → exercise-type strategy, PR dispatch, data model
.kiro/steering/laravel-boost.md          → Eloquent not DB::, constructor promotion, explicit return types, PHPUnit-only
```
### 2. Plan (reference — execute from THIS prompt)
```
docs/plans/carry-logtype-system.md                        → the Logger slice plan (WHAT/WHY, diagrams, consumer trace)
../../docs/plans/carry-logtype-system-cross-repo.md       → cross-repo spine; FROZEN §1–§5 (shared source of truth; read, do NOT edit)
```
### 3. Existing code (read before modifying — grep/line-ranges)
```
config/pr_families.php · app/Sync/Services/ExerciseResolverService.php · app/Sync/Services/SetFieldMapper.php
app/Services/PR/PrEngine.php · app/Services/ExerciseTypes/LoadOutputExerciseType.php
app/Models/{LiftLog,LiftSet,PersonalRecord}.php (SoftDeletes)
```
### 4. Reference (study, don't rebuild)
```
docs/plans/completed/sled-carry-load-output.md
database/migrations/2026_08_10_000605_backfill_exercise_log_types_from_athlete_library.php
database/migrations/2026_07_25_013942_update_sled_exercises_type_and_log_type.php
```

---

## What You're Building
Route **five carry logTypes** — `weighted-carry-{1,2}-kb`, `weighted-carry-{1,2}-db`, `weighted-carry-ball`
— into the EXISTING `load_output` family, and PURGE (soft-delete) the unmigratable historical carry data
in the same migration. Reuses `load_output` — NO new `pr_type` strings, NO enum migration. All five persist
the same columns (`weight`/`distance`/`distance_unit`/`time`); the implement distinction is Athlete-side.
`dual-kettlebell` is removed from the active vocabulary. Zero behavioral change to `static_hold`, sled, or
any other type. FROZEN §1/§4/§5 in the spine are authoritative.

---

## Milestone 1 — Family map + resolver + SetFieldMapper + unit tests
- `config/pr_families.php` `logTypeToFamily`: add `weighted-carry-1-kb`, `weighted-carry-2-kb`,
  `weighted-carry-1-db`, `weighted-carry-2-db`, `weighted-carry-ball` → `'load_output'`; remove
  `'dual-kettlebell'`.
- `ExerciseResolverService::deriveExerciseType()`: add all 5 to the `=> 'load_output'` arm; remove
  `'dual-kettlebell'` (keep `'static-hold' => 'static_hold'`).
- `SetFieldMapper::mapToColumns()`: ONE shared arm for all 5 →
  `weight = $setData['kbWeight'] ?? $setData['ballWeight'] ?? $setData['weight'] ?? null;
  distance = $setData['distance'] ?? null;
  distance_unit = $setData['distanceUnit'] ?? $setData['distance_unit'] ?? null;
  time = $setData['duration'] ?? null;`
- `SetFieldMapper::mapFromColumns()`: same shared arm → `['weight'=>$set->weight, 'distance'=>$set->distance,
  'distance_unit'=>$set->distance_unit, 'duration'=>$set->time]`.
- Unit tests: all 5 → `load_output` (`deriveExerciseType` + `PrEngine::resolveFamily`); round-trip a KB
  (kbWeight), DB (weight), ball (ballWeight) input through mapToColumns/mapFromColumns.
- Update tests referencing `dual-kettlebell`: `tests/Unit/Sync/SetFieldMapperTest.php`,
  `tests/Unit/Sync/ExerciseResolverServiceTest.php`, `tests/Feature/LoadOutputIntegrationTest.php`.

### Checkpoint
```bash
php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt
```

## Milestone 2 — Sync feature tests
- POST `/api/squirby/logs` for `-2-kb` (kbWeight+distance+duration), `-1-db` (weight+distance), `-ball`
  (ballWeight+duration) on new exercises → each auto-creates `load_output`, persists columns, records
  `load_output` PRs. `GET /restore` returns generic `weight`+`distance`+`distance_unit`+`duration`.
### Checkpoint (same command)

## Milestone 3 — Migration: re-type + create splits + PURGE (§5)
One migration (`php artisan make:migration --no-interaction`). Strict `up()` order:
1. **Re-type** existing carry defs scoped by `canonical_name` (FROZEN §4 non-split rows): `log_type` = new
   string, `exercise_type='load_output'`; update dependent `lift_logs.log_type` per the `2026_07_25_013942`
   precedent.
2. **Create** the six split defs — `mixed_rack_carry_kb`/`_db`, `single_arm_oh_carry_kb`/`_db`,
   `suitcase_march_kb`/`_db` — with `exercise_type='load_output'` + new `log_type`. (Or document that sync
   auto-creates them by name; pick one — no old history may survive under the retired originals.)
3. **PURGE (soft-delete)** all `lift_logs` + `lift_sets` + `personal_records` for every affected + retired
   `canonical_name`: the §4 rows PLUS the three retired originals `mixed_rack_carry`, `single_arm_oh_carry`,
   `suitcase_march`. Soft-delete only. NO PR recompute.
- Scope strictly by `canonical_name`; genuine static-holds untouched.
- `down()`: restore re-typed rows' `exercise_type`/`log_type`, un-soft-delete the purged rows, remove any
  created split defs; document best-effort. Never destructive.
### Checkpoint, then RUN it:
```bash
php artisan migrate --no-interaction
php artisan migrate:status
```
Confirm the new migration shows **Ran**.

## Milestone 4 — Grep gate + cleanup sweep
- Grep `dual-kettlebell` (search tool, not bash): only historical migrations + the updated tests may
  reference it; ZERO in `pr_families.php`/`ExerciseResolverService`/`SetFieldMapper`.
- §14 sweep: no dead arm, no unused `use`, no shims, delete temp scripts + `.test-output.txt`.
### Checkpoint (same command) — zero failures bar pre-existing unrelated.

---

## HARD RULES
- NEVER commit/push. NEVER Pint. NEVER destructive DB (`migrate:fresh`/`reset`/`db:wipe`). Never modify a
  run migration. **Purge is SOFT-delete only.** Never edit historical migrations or any `contracts/` file.

## Implementation Rules
- Edits stay in `config/`, `app/Sync/`, `database/migrations/`. Eloquent not `DB::` (except migration raw
  statements). Constructor promotion; explicit return types. PHPUnit; factories. `--no-interaction`. No
  ingress conversion.

## Success Criteria
- [ ] All 5 logTypes → `load_output` (`logTypeToFamily` + `deriveExerciseType`); `dual-kettlebell` removed.
- [ ] One shared `SetFieldMapper` arm persists + round-trips weight/distance/distance_unit/time for all 5.
- [ ] Migration re-types carry defs, creates the 6 split defs (or documents auto-create), soft-deletes all
      affected + retired history; static-holds untouched; migration RAN (`migrate:status` = Ran).
- [ ] No new `pr_type` values; no enum migration; `LoadOutputExerciseType` unchanged.
- [ ] `php artisan test --parallel` green; grep gate + cleanup sweep clean. No Pint/commits/deps.

## Do Not
- Do NOT add `pr_type` strings/enum values. Do NOT recompute PRs (history purged). Do NOT hard-delete.
- Do NOT change `static_hold` or move the three pure holds. Do NOT re-type by `exercise_type` alone.
- Do NOT edit historical migrations or `contracts/`.

---

## Post-Execution Retro (fill in after completion; then move plan + prompt to `completed/`)
- **Attempts:** {1 (clean) / N — root cause if N}
- **Tests added:** {count}
- **Prompt improvements for next time:** {…}
- **Steering updates needed:** {yes/no + what}
