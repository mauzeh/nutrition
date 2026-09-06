# Timed-Reps LogType — Prompt for Antigravity CLI (Logger slice)

## Global Execution Rules
1. Execute sequentially; test ONLY at the Milestone checkpoints.
2. SELF-CORRECTION LOOP: run the checkpoint; if tests fail, read the error, fix, re-run within the turn.
   Do NOT yield with a failing milestone; do NOT ask for help — fix it.
3. When ALL milestones pass, leave the Post-Execution Retro `{placeholder}` values UNTOUCHED (the reviewer
   writes it), run the End-of-Run Cleanup Sweep, then print exactly:
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
docs/plans/timed-reps-logtype.md                         → the Logger slice plan (WHAT/WHY, diagram, FROZEN §3/§4/§5, consumer trace)
../../docs/plans/timed-reps-logtype-cross-repo.md        → cross-repo spine; FROZEN §1–§6 (shared source of truth; read, do NOT edit)
```
### 3. Existing code (read before modifying — grep/line-ranges)
```
config/pr_families.php · config/exercise_types.php · app/Sync/Services/ExerciseResolverService.php
app/Sync/Services/SetFieldMapper.php · app/Services/PR/PrEngine.php
app/Services/ExerciseTypes/{BaseExerciseType,StaticHoldExerciseType,LoadOutputExerciseType,ExerciseTypeFactory}.php
app/Models/{LiftLog,LiftSet,PersonalRecord}.php
```
### 4. Reference (study, don't rebuild)
```
docs/plans/carry-logtype-system.md   → sibling logType slice pattern
```

---

## What You're Building
Wire **`timed-reps`** through Logger's sync + display + PR layers. Dual-optional-metric: duration and/or
reps, either may be null (null = "not logged", never 0). NEW `timed_output` exercise_type + display
strategy + PR family. `pr_type` is `VARCHAR(32)` → `max_reps` needs NO enum migration. No new column, no
data migration. FROZEN §1/§3/§4/§5 in the spine + plan are authoritative.

---

## Milestone 1 — SetFieldMapper + resolver + family map + unit tests
- `SetFieldMapper::mapToColumns()`: add `case 'timed-reps':` →
  `$columns['time'] = $setData['duration'] ?? null; $columns['reps'] = $setData['reps'] ?? null; break;`
- `SetFieldMapper::mapFromColumns()`: add `case 'timed-reps':` →
  `$data['duration'] = $set->time; $data['reps'] = $set->reps; break;`
- `ExerciseResolverService::deriveExerciseType()`: add `'timed-reps' => 'timed_output',`.
- `config/pr_families.php`:
  - `logTypeToFamily`: add `'timed-reps' => 'timed_output',`.
  - `families`: add a `timed_output` block — 4 descriptors, EACH `'compare' => 'scalarBest', 'direction' =>
    'max', 'tolerance' => 'none', 'store' => 'scalar'`:
    1. `type=time, reduce=maxOf, field=time, label='Longest Duration', format=seconds`
    2. `type=volume, reduce=sumOf, field=time, label='Total Time', format=seconds`
    3. `type=max_reps, reduce=maxOf, field=reps, label='Most Reps', format=reps`
    4. `type=bodyweight_volume, reduce=sumOf, field=reps, label='Total Reps', format=reps`
- CONFIRM `Reductions::maxOf`/`sumOf` read descriptor `field` generically and skip non-positive values. If
  they do NOT, STOP and flag — do not hack around it.
- Unit tests: derive → `timed_output`; `resolveFamily` → `timed_output`; mapper round-trip for both-present,
  reps-only (duration null), duration-only (reps null) — assert nulls preserved (NOT 0).
### Checkpoint
```bash
php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt
```

## Milestone 2 — TimedOutputExerciseType + config + display tests
- Create `app/Services/ExerciseTypes/TimedOutputExerciseType.php` extending `BaseExerciseType`:
  - `getTypeName(): string` → `'timed_output'`.
  - `formatSingleSetBadge(LiftSet $set, ?User $user = null): string` → `formatDuration((int)$set->time)`
    when `time > 0`, else `''`.
  - `getSetEffortValue(LiftSet $set): string` → inherit base (reps). Do NOT override to '1'.
  - `processLiftData(array $data): array` → `$data['band_color'] = null;` and RETURN preserving `reps`
    and `time` as given. Do NOT set `reps = 1`.
  - `processExerciseData` → set `exercise_type = 'timed_output'`.
  - `formatWeightDisplay` / `formatLoggedItemDisplay` / `formatTableCellDisplay` → duration + reps sensible
    strings (mirror StaticHold's shape but keep reps).
  - `canCalculate1RM(): bool` → false; `format1RMTableCellDisplay` → `'N/A'`.
  - private `formatDuration(int $seconds): string` → copy StaticHold's (`{n}s` / `{m}m` / `{m}m {s}s`),
    WITHOUT the " hold" suffix.
- `config/exercise_types.php`: add `'timed_output' => [ 'class' => TimedOutputExerciseType::class,
  'validation' => [ 'time' => 'nullable|integer|min:1|max:900|required_without:reps', 'reps' =>
  'nullable|integer|min:1|max:1000|required_without:time' ], 'supports_1rm' => false, 'form_fields' =>
  ['time','reps'], 'field_labels' => [...], ... ]`.
- Display unit tests (`formatMobileSummaryDisplay`): reps-only → repsSets non-zero, badge empty;
  duration-only → badge `40s`, sets shown; both → badge + reps effort. Assert reps NOT forced to 1.
### Checkpoint (same command)

## Milestone 3 — Sync feature tests
- POST `/api/squirby/logs` for a `timed-reps` exercise, three sets shapes: (a) `{duration:40, reps:12}`,
  (b) `{reps:12}` (duration null), (c) `{duration:40}` (reps null) → each auto-creates `timed_output`,
  persists `time`/`reps` with nulls intact, records the PRs it should (both → duration + reps; reps-only →
  reps only; duration-only → duration only).
- `GET /restore` returns `duration` + `reps` for a `timed-reps` set (nulls intact).
- Validation: a set with BOTH `time` and `reps` null is rejected.
### Checkpoint (same command)

## Milestone 4 — Verification + cleanup sweep
- §14 sweep (search tool, not bash): no unused `use`, no dead branch, no static_hold copy residue (grep
  the new class for `= 1` / ` hold`), delete temp scripts + `.test-output.txt`.
### Checkpoint (same command) — zero failures bar pre-existing unrelated.

---

## HARD RULES
- NEVER commit/push. NEVER Pint. NEVER destructive DB. Never modify a run migration. Never edit any
  `contracts/` file. Do NOT null reps in `processLiftData`. Do NOT coerce null→0 in the mapper.

## Implementation Rules
- Edits stay in `config/`, `app/Sync/`, `app/Services/ExerciseTypes/`, `tests/`. Eloquent not `DB::`.
  Constructor promotion; explicit return types. PHPUnit; factories. `--no-interaction`.

## Success Criteria
- [ ] Mapper persists + round-trips `timed-reps` duration & reps; nulls preserved (not 0).
- [ ] `deriveExerciseType('timed-reps') === 'timed_output'`; `resolveFamily` → `timed_output`.
- [ ] `TimedOutputExerciseType` renders badge=duration + effort=reps for all 3 states; reps NOT nulled.
- [ ] `timed_output` family records duration PRs and reps PRs independently (per §4).
- [ ] Validation rejects both-null; accepts either. `php artisan test --parallel` green; sweep clean.
- [ ] No new column, no enum migration, no deps, no commits.

## Do Not
- Do NOT route `timed-reps` to `static_hold`/`regular`. Do NOT null reps. Do NOT coerce null→0.
- Do NOT add a column/migration. Do NOT edit historical migrations or `contracts/`.

---

## Post-Execution Retro (reviewer-authored — leave placeholders untouched)
- **Attempts:** {1 (clean) / N — root cause if N}
- **Tests added:** {count}
- **Prompt improvements for next time:** {…}
- **Steering updates needed:** {yes/no + what}
