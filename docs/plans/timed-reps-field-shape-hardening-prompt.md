# Timed-Reps Field-Shape Hardening — Prompt for Antigravity CLI (Logger slice)

## Global Execution Rules
1. Execute sequentially; test ONLY at the Milestone checkpoints.
2. SELF-CORRECTION LOOP: run the checkpoint; if tests fail, read the error, fix, re-run within the turn.
   Do NOT yield with a failing milestone; do NOT ask for help — fix it.
3. When ALL milestones pass, leave the Post-Execution Retro `{placeholder}` values UNTOUCHED, run the
   End-of-Run Cleanup Sweep, then print exactly:
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
.kiro/steering/project-conventions.md    → Actions pattern, Form Request validation, dispatch events
.kiro/steering/sync-api-context.md       → sync ingress (LogController/StoreSyncLogAction), strategy pattern
.kiro/steering/laravel-boost.md          → Eloquent not DB::, constructor promotion, explicit return types, PHPUnit-only
```
### 2. Plan (reference — execute from THIS prompt)
```
docs/plans/timed-reps-field-shape-hardening.md                          → the Logger slice plan (WHAT/WHY, FROZEN §3/§4, consumer trace)
../../docs/plans/timed-reps-field-shape-hardening-cross-repo.md         → spine; FROZEN §1–§6 (read, do NOT edit)
```
### 3. Existing code (read before modifying)
```
config/exercise_types.php (timed_output entry) · app/Actions/LiftLogs/{Create,Update}LiftLogAction.php (getValidationRules)
app/Sync/Controllers/LogController.php (imperative timed-reps guard — REMOVE) · app/Sync/Actions/StoreSyncLogAction.php
app/Services/Factories/LiftLogFormFactory.php (buildFields ariaLabels) · resources/views/mobile-entry/components/form-field.blade.php
app/Services/ExerciseTypes/{BaseExerciseType,TimedOutputExerciseType}.php
```

---

## What You're Building
Two root-cause fixes: (1) ONE declarative `group_rule` validation for `timed_output`, enforced by a SHARED
validator on BOTH the web and sync paths (delete the imperative `LogController` guard); (2) the web edit
form can never 500 on a missing `ariaLabels` — the factory attaches it for every field type AND the Blade
partial reads it defensively. "Logged" = `> 0` (matches Athlete's null-for-not-logged payload). No new
column/enum/migration. Only `timed_output` validation changes; the form-factory/template hardening is
type-agnostic and must be behavior-identical for all existing types. FROZEN §3/§4 authoritative.

**Ordering:** Athlete (Slice A) has already landed (sends `null` for not-logged). Do NOT edit `athlete/`
or `contracts/`.

## Milestone 1 — group_rule config + shared validator on both paths
- `config/exercise_types.php` `timed_output`: add `'optional_fields' => ['time','reps']` and
  `'group_rule' => ['kind' => 'require_one_of', 'fields' => ['time','reps']]`; KEEP the `required_without`
  `validation` pair (it is the web expression of the rule).
- Add a shared helper (a small `SetGroupRuleValidator` class or a strategy/base method) that enforces a
  `group_rule` against a set's data using the `> 0` "logged" definition.
  - Web path: `getValidationRules()` already yields the `required_without` pair → no change needed (verify).
  - Sync path: in `LogController::store` (or `StoreSyncLogAction`), resolve the exercise strategy's
    `group_rule` and validate each set via the shared helper; throw a 422 `ValidationException` when
    unsatisfied. DELETE the existing imperative `timed-reps` guard (and its dead `$set['time']` key).
- Tests: web create/update rejects all-null + both-`0`, accepts either-one; sync POST rejects all-null +
  all-`0`, accepts either-one and persists `null` for the not-logged field; other types' validation
  unchanged.
### Checkpoint
```bash
php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt
```

## Milestone 2 — form factory ariaLabels for every field type + defensive template
- `LiftLogFormFactory::buildFields`: attach an `ariaLabels` array for EVERY emitted field type (text, date,
  textarea, file, select, numeric, …), default derived from the field label; numeric keeps
  `decrease`/`increase`, and add `field` where the template reads it. No field def leaves without it.
- `resources/views/mobile-entry/components/form-field.blade.php`: null-coalesce EVERY `ariaLabels` access on
  every branch — `{{ $field['ariaLabels']['field'] ?? '' }}` (and `decrease`/`increase`).
- Feature test: `GET /lift-logs/{id}/edit` for a synced `timed-reps` log → 200, renders time + reps, no
  `Undefined array key`. Defensive test: a field def WITHOUT `ariaLabels` renders (empty aria-label, no
  exception).
### Checkpoint (same command)

## Milestone 3 — Verification + cleanup sweep
- Full suite green (existing types' web forms + validation unchanged). §14 sweep: no dead guard residue, no
  unused `use`, delete temp scripts + `.test-output.txt`.
### Checkpoint (same command) — zero failures bar pre-existing unrelated.

---

## HARD RULES
- NEVER commit/push. NEVER Pint. NEVER destructive DB. Never modify a run migration. Never edit `athlete/`
  or `contracts/`. Do NOT keep the imperative sync guard alongside the shared validator. Do NOT change
  validation for any type other than `timed_output`.

## Implementation Rules
- Eloquent not `DB::`. Constructor promotion; explicit return types. PHPUnit; factories. `--no-interaction`.
  Config-driven validation, not inline ad-hoc guards.

## Success Criteria
- [ ] ONE `group_rule` drives `timed_output` validation on BOTH web and sync; imperative guard removed;
      all-null/all-0 rejected + either-one accepted identically on both paths; null-for-not-logged persists.
- [ ] `GET /lift-logs/{id}/edit` renders a timed-reps log with no exception; every field def has
      `ariaLabels`; partial null-safe on every branch.
- [ ] Existing types' web forms + validation behavior-identical. `php artisan test --parallel` green; sweep
      clean. No column/enum/migration, no deps, no commits.

## Do Not
- Do NOT keep two validation dialects. Do NOT change other types' validation. Do NOT add a column/migration.
- Do NOT edit historical migrations, `athlete/`, or `contracts/`.

---

## Post-Execution Retro (reviewer-authored — leave placeholders untouched)
- **Attempts:** {1 (clean) / N — root cause if N}
- **Tests added:** {count}
- **Prompt improvements for next time:** {…}
- **Steering updates needed:** {yes/no + what}
