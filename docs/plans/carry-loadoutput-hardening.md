# Carry / LoadOutput Field-Shape Hardening — Plan (Logger side)

> **Reference architecture, NOT execution steps.** Execute from
> `docs/plans/carry-loadoutput-hardening-prompt.md`. Format per `docs/antigravity-steering.md` §9.
>
> **Cross-repo:** Logger slice (Slice B) of the effort owned by
> `../../docs/plans/carry-loadoutput-hardening-cross-repo.md` (FROZEN §4 = source of truth). Continues the
> hardening line on the EXISTING `feature/timed-reps-logtype` branch. No new branch. Small, self-contained.

---

## Before You Start (read in order)
```
docs/antigravity-steering.md                     → git (NEVER commit), Pint BAN, milestones, §13 trace, §14 sweep, AGY_COMPLETE
.kiro/steering/safe-operations.md                → protected files, artisan safety, Pint ban (wins)
.kiro/steering/project-conventions.md            → Actions pattern, Form Request validation
.kiro/steering/sync-api-context.md               → sync ingress (StoreSyncLogAction), strategy pattern
.kiro/steering/laravel-boost.md                  → Eloquent not DB::, explicit return types, PHPUnit-only
```
Cross-repo source of truth (read, do not edit): `../../docs/plans/carry-loadoutput-hardening-cross-repo.md`
(FROZEN §4). Prior art (read, do not edit): the shipped `timed_output` group_rule work —
`app/Sync/Services/SetGroupRuleValidator.php`, `app/Services/ExerciseTypes/BaseExerciseType.php`
(`mergeGroupRuleValidation`), `config/exercise_types.php` (`timed_output` entry).

---

## What You're Building

Add a `group_rule` to the `load_output` exercise type so a carry/sled set with NEITHER distance NOR
duration is rejected — on BOTH the web and sync paths — reusing the SAME shared machinery the
`timed_output` hardening built. This matches the Athlete client's carry gate (load AND
(distance OR duration)); the Athlete slice enforces the client half.

> **THIS IS NEW SERVER ENFORCEMENT, not a refactor (FROZEN §4).** Today Logger's `load_output` has no
> completeness validation (all `nullable`), so it accepts a carry set with only a load. After this, such a
> set is REJECTED with a 422. Real Athlete clients already gate this (they can't save/sync a carry with
> neither), so well-behaved clients are unaffected — but older clients or direct API callers that send a
> load-only carry will now get a 422. This is intentional (consistency with the client), and MUST be
> called out. It does NOT newly require the load field: `weight` stays `nullable`; the rule is the
> distance/time one-of only.

### FROZEN §4 — the addition
`config/exercise_types.php` `load_output`:
```php
'load_output' => [
    ...,
    // Reject a carry/sled set with neither distance nor duration (matches the Athlete carry gate).
    // NEW server enforcement — load-only carries previously synced OK. weight stays nullable.
    'group_rule' => ['kind' => 'require_one_of', 'fields' => ['distance', 'time']],
    'validation' => [ /* unchanged: weight/reps/distance/time all nullable */ ],
    ...,
],
```
- Field names are the DB column names: `distance` + `time` (the athlete `duration` maps to the `time`
  column; `SetGroupRuleValidator` already aliases `duration`→`time`, so a synced payload using either key
  is handled).
- Enforcement is entirely via the EXISTING shared path: `SetGroupRuleValidator::validateSets` (sync, called
  from `StoreSyncLogAction`) + `BaseExerciseType::mergeGroupRuleValidation`→`getValidationRules` (web,
  `Create/UpdateLiftLogAction`). No new validator, no new mechanism — just the config key.

## Existing Code to Understand (read before modifying)
```
config/exercise_types.php                              → load_output entry. ADD group_rule (distance/time one-of). validation UNCHANGED.
app/Sync/Services/SetGroupRuleValidator.php            → validateSets reads group_rule; already aliases duration->time. NO change.
app/Services/ExerciseTypes/BaseExerciseType.php        → mergeGroupRuleValidation derives required_without from group_rule. NO change (already generic).
app/Sync/Actions/StoreSyncLogAction.php                → already calls validateSets($exercise->exercise_type, sets). NO change.
app/Actions/LiftLogs/{Create,Update}LiftLogAction.php  → web path via getValidationRules(). NO change (derivation is automatic).
app/Services/ExerciseTypes/LoadOutputExerciseType.php  → display strategy. UNCHANGED.
```

## Key facts (do not re-discover)
1. `SetGroupRuleValidator` already handles `require_one_of` and aliases `duration`→`time`. Adding the
   config key is sufficient for the SYNC path — no validator change.
2. `BaseExerciseType::mergeGroupRuleValidation` already derives `required_without` from any
   `require_one_of` group_rule; the web path picks it up automatically via `getValidationRules`.
3. `load_output` `weight` is `nullable` today (BW/0-load carries allowed). Keep it nullable — the new rule
   is distance/time only, NOT a load requirement.
4. This changes ONLY `load_output`. All other types are untouched.

## Execution Plan
Checkpoints use `php artisan test --parallel`.

### Phase 1 — add group_rule + tests
- `config/exercise_types.php`: add `'group_rule' => ['kind' => 'require_one_of', 'fields' => ['distance',
  'time']]` to `load_output`. Leave `validation` (all nullable) as-is; the derivation adds the
  `required_without` cross-pair.
- Feature tests (sync): POST `/api/squirby/logs` for a carry (a `load_output` exercise) with (a) only a
  load → REJECTED 422; (b) load+distance → accepted; (c) load+duration(→time) → accepted. Assert the 422
  path and that a valid carry still persists.
- Feature test (web): `Create`/`Update` a load_output lift with neither distance nor time → validation
  error; with either → passes. (Mirror the `timed_output` web validation test.)
- Unit (optional): assert `getValidationRules()` for a `load_output` strategy now contains
  `required_without` on `distance` and `time`.
- **Checkpoint.**

### Phase 2 — verification + cleanup sweep
- Full suite green; existing `load_output`/carry/sled tests unaffected (they log distance or duration, so
  they still pass). §14 sweep; delete `.test-output.txt`.
> Note: the branch has pre-existing unrelated failures (exercise visibility/seeding). Confirm your changes
> add no NEW failures; do not attempt to fix the pre-existing ones (out of scope).

## Consumer Impact Trace (mandatory — §13)
| Structure changed | Reads / interprets it | Action |
|---|---|---|
| `load_output` config + `group_rule` | `SetGroupRuleValidator` (sync), `mergeGroupRuleValidation`→`getValidationRules` (web) | Add the key; both paths enforce automatically. |
| NEW rejection of load-only carries | sync ingress (`StoreSyncLogAction`), web `Create/UpdateLiftLogAction` | NEW 422 for a previously-accepted shape; call out in the plan; feature-test both paths. |
| `weight` nullability | `load_output` validation | UNCHANGED — do NOT newly require the load. |
| existing carry/sled logs + display | `LoadOutputExerciseType`, PR engine | UNCHANGED — no shape/PR/display change. |

**Tests to add:** sync feature (load-only rejected; load+distance / load+duration accepted), web feature
(neither rejected; either accepted), optional unit on the derived rules. Confirm existing load_output/carry
tests pass.

## Simplicity Criteria
- ONE config key. Zero new classes, zero validator/mechanism changes — the `timed_output` hardening
  already built the shared path. No migration, no display change.

## Hard Rules
- NEVER commit/push. NEVER Pint. NEVER destructive DB. NEVER create a new branch. NEVER edit `athlete/` or
  `contracts/`.
- Do NOT newly require the carry LOAD field (`weight` stays nullable). Do NOT change any type other than
  `load_output`. Do NOT hand-author `required_without` (it derives from `group_rule`).

## Success Criteria
- [ ] `load_output` has a `require_one_of [distance, time]` group_rule; a carry set with neither is
      rejected on BOTH web and sync; either-one accepted; `weight` stays nullable.
- [ ] No new validator/mechanism; existing types + carry/sled display/PR unchanged.
- [ ] `php artisan test --parallel` green (no NEW failures vs the pre-existing branch baseline); sweep
      clean. No Pint/commits/deps/branch.

## Do Not
- Do NOT require the load field. Do NOT change other types. Do NOT add a validator or migration.
- Do NOT edit `athlete/`/`contracts/`. Do NOT create a branch.

## Post-Execution Retro (filled after completion; then move plan+prompt to `completed/`)
- **Attempts:** {1 (clean) / N + root cause}
- **Tests added:** {count}
- **Prompt improvements for next time:** {…}
- **Steering updates needed:** {yes/no + what}
