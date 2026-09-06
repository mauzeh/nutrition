# Carry / LoadOutput Field-Shape Hardening — Prompt for Antigravity CLI (Logger slice)

## Global Execution Rules
1. Execute sequentially; test ONLY at the Milestone checkpoints.
2. SELF-CORRECTION LOOP: run the checkpoint; if tests fail, read the error, fix, re-run within the turn.
   Do NOT yield with a failing milestone; do NOT ask for help — fix it.
3. When ALL milestones pass, leave the Post-Execution Retro `{placeholder}` values UNTOUCHED, run the
   End-of-Run Cleanup Sweep, then print exactly:
   ```
   AGY_COMPLETE: All milestones passed.
   ```
4. Test output → a project file; read it; delete it at the end. Never `/tmp/`. Never re-run to re-see.
   ```bash
   php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt
   ```

---

## Before You Start (read in order)
### 1. Steering
```
docs/antigravity-steering.md             → git (NEVER commit), Pint BAN, milestones, §13 trace, §14 sweep, AGY_COMPLETE
.kiro/steering/safe-operations.md        → protected files, artisan safety, Pint ban (wins)
.kiro/steering/project-conventions.md    → Actions pattern, Form Request validation
.kiro/steering/sync-api-context.md       → sync ingress (StoreSyncLogAction), strategy pattern
.kiro/steering/laravel-boost.md          → Eloquent not DB::, explicit return types, PHPUnit-only
```
### 2. Plan (reference — execute from THIS prompt)
```
docs/plans/carry-loadoutput-hardening.md                          → Logger slice plan (WHAT/WHY, FROZEN §4, NEW-enforcement note, consumer trace)
../../docs/plans/carry-loadoutput-hardening-cross-repo.md         → spine; FROZEN §1–§4 (read, do NOT edit)
```
### 3. Existing code (read before modifying)
```
config/exercise_types.php (load_output entry) · app/Sync/Services/SetGroupRuleValidator.php
app/Services/ExerciseTypes/BaseExerciseType.php (mergeGroupRuleValidation) · app/Sync/Actions/StoreSyncLogAction.php
app/Actions/LiftLogs/{Create,Update}LiftLogAction.php · config/exercise_types.php (timed_output entry, as the pattern)
```

---

## What You're Building
Add ONE config key — a `require_one_of [distance, time]` `group_rule` on `load_output` — so a carry/sled
set with neither distance nor duration is rejected on BOTH web and sync, via the SAME shared machinery the
`timed_output` hardening already built (`SetGroupRuleValidator` + `mergeGroupRuleValidation`). NEW server
enforcement (load-only carries previously synced OK); `weight` stays nullable (no new load requirement).
Only `load_output` changes. Land on the existing `feature/timed-reps-logtype` branch. Do NOT edit
`athlete/` or `contracts/`. FROZEN §4 authoritative.

## Milestone 1 — add group_rule + tests
- `config/exercise_types.php` `load_output`: add
  `'group_rule' => ['kind' => 'require_one_of', 'fields' => ['distance', 'time']]`. Leave `validation`
  (weight/reps/distance/time all `nullable`) UNCHANGED — the required_without pair derives automatically.
- Do NOT modify `SetGroupRuleValidator`, `BaseExerciseType`, `StoreSyncLogAction`, or the web actions —
  they already consume `group_rule` generically. (If a test proves otherwise, STOP and re-read; the
  timed_output path is already generic.)
- Tests:
  - Sync feature (POST `/api/squirby/logs`, a load_output carry exercise): (a) only a load → 422;
    (b) load+distance → accepted + persists; (c) load+duration → accepted.
  - Web feature (`Create`/`Update` load_output lift): neither distance nor time → validation error; either
    → passes. Mirror the timed_output web validation test.
  - (Optional) unit: `getValidationRules()` for load_output contains `required_without` on distance + time.
### Checkpoint
```bash
php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt
```

## Milestone 2 — verification + sweep
- Full suite: your changes add NO new failures. (The branch has PRE-EXISTING unrelated failures in
  exercise visibility/seeding — do NOT fix those; just confirm you added none.) Existing load_output/carry/
  sled tests pass (they log distance or duration).
- §14 sweep: no stray edits beyond the one config key + tests; delete `.test-output.txt`.
### Checkpoint (same command)

---

## HARD RULES
- NEVER commit/push. NEVER Pint. NEVER destructive DB. NEVER create a new branch. NEVER edit `athlete/` or
  `contracts/`.
- Do NOT newly require the carry LOAD (`weight` stays nullable). Do NOT change any type other than
  `load_output`. Do NOT hand-author `required_without` — it derives from `group_rule`. Do NOT modify the
  shared validator/base/actions (already generic).

## Success Criteria
- [ ] `load_output` has `require_one_of [distance, time]`; a carry with neither is rejected on web + sync;
      either accepted; `weight` nullable; other types unaffected.
- [ ] No new validator/mechanism/migration. `php artisan test --parallel` green with no NEW failures vs the
      pre-existing baseline; sweep clean. No Pint/commits/deps/branch.

## Do Not
- Do NOT require the load field. Do NOT change other types or the shared machinery. Do NOT add a
  validator/migration. Do NOT edit `athlete/`/`contracts/`. Do NOT create a branch.

---

## Post-Execution Retro (reviewer-authored — leave placeholders untouched)
- **Attempts:** {1 (clean) / N — root cause if N}
- **Tests added:** {count}
- **Prompt improvements for next time:** {…}
- **Steering updates needed:** {yes/no + what}
