# PROMPT — Server-Initiated Rehydration + Exercise Merge (Logger slice)

Read `docs/antigravity-steering.md`, then `docs/plans/server-initiated-rehydration.md` (the plan).
Execute from THIS prompt; the plan is reference architecture.

**Boundary rule (mandatory):** stay entirely within this repository. NEVER read, reference, or write
files outside `logger/` — no `../../` paths, no root workspace, no other app directory. The cross-app
shapes you must produce are duplicated inline in the plan; you never need to reach up. Reconciling the
two apps happens in a separate root task run later — not here.

Hard rules (from antigravity-steering): never commit/push, never run Pint, never run destructive DB
commands, **never run the merge migration** (author only). Milestone testing with
`php artisan test --parallel`. Write test output to `.test-output.txt`, read it, delete when green.
Fill the Post-Execution Retro in this file, then print `AGY_COMPLETE: All milestones passed.`

---

## Milestone 1 — Rehydration signal storage + service (pure)

1. `php artisan make:migration create_rehydration_signals_table --no-interaction`. Columns:
   `id`, `user_id` nullable FK (`constrained()->nullOnDelete()`; null = global), `token` string,
   `reason` string, timestamps. Index `['user_id','token']`. Author only — do not run it.
2. `php artisan make:model RehydrationSignal --no-interaction`. `$fillable = ['user_id','token','reason']`,
   `user()` belongsTo, scope `scopeApplicableTo($q, $userId)` = `where(fn($q)=>$q->where('user_id',$userId)->orWhereNull('user_id'))`.
3. Create `app/Sync/Services/RehydrationService.php`:
   - `raiseForUsers(array $userIds, string $reason): void` — one shared `$token = now()->toIso8601String()`,
     insert a `RehydrationSignal` per user id.
   - `latestToken(User $user): ?string` — `RehydrationSignal::applicableTo($user->id)->max('token')`.
   - `latestReason(User $user): ?string` — reason of the row holding `latestToken`, or null.
   Constructor property promotion, explicit return types.
4. `php artisan make:test --phpunit RehydrationServiceTest`. Assert: raise for [u1,u2] → each has the
   token; `latestToken` returns it; a global signal (user_id null) is returned by `latestToken` for a
   different user; `latestToken` is null when no signal.

**Checkpoint:** `php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt`. Fix to green.

## Milestone 2 — `/changes` emits the token

5. Read `app/Sync/Controllers/ChangesController.php`. Inject `RehydrationService` via constructor.
6. Before the final `response()->json([...])`, compute `$token = $this->rehydrationService->latestToken($user)`.
   If `$token !== null`, add `'rehydrate' => ['token' => $token, 'reason' => $this->rehydrationService->latestReason($user) ?? 'exercise-merge']`
   to the array. Omit the key when null. Do not change any existing field.
7. **Consumer trace:** grep `tests/` for tests hitting `/api/sync/changes` (e.g. `ChangesControllerTest`,
   Sync feature tests). Confirm none assert the payload has ONLY a fixed key set (which the new optional
   key would break). Update any exact-shape assertion to allow the optional `rehydrate` key.
8. Add a feature test: user with a signal → response has `rehydrate.token` == the signal token; user
   without → no `rehydrate` key (`assertJsonMissingPath('rehydrate')`); global signal reaches a user
   with no personal signal.

**Checkpoint:** run the Sync suite then full suite. Fix to green.

## Milestone 3 — Generalized merge service

9. Read `app/Services/ExerciseMergeService.php` and `PRRecalculationService.php`. Add
   `mergeByMap(array $merge): array` per the plan (resolve by canonical_name then title; transactional
   repoint of `lift_logs`; alias source canonical+title onto target; `ExerciseMergeLog` audit;
   soft-delete sources; set target canonical/title; return distinct affected user ids; idempotent).
   Do NOT recompute PRs inside it.
10. Add a test with factories: target + 2 sources, logs across 2 users → asserts logs repointed to
    target, sources soft-deleted, aliases exist, affected users returned; re-running is a clean no-op.

**Checkpoint:** run suite. Fix to green.

## Milestone 4 — Pull-up merge migration + combined-PR proof

11. `php artisan make:migration merge_pull_up_variants --no-interaction`. In `up()`:
    define the merge to resolve these EXACT global rows (verified against a fresh prod copy):
    target = "Strict Pull-Ups" (`strict_pull_up`, `bodyweight`); sources = "Pull-Up"
    (`bodyweight-reps`) and "Pull-Ups" (`bodyweight`). Match by `(title, log_type, user_id IS NULL,
    not trashed)` — NOT by canonical slug alone (prod canonicals are crossed: "Pull-Up" has canonical
    `pull_ups`, "Pull-Ups" has `pull_up`). **Must NOT touch:** "Kipping Pull-Up", "Dumbbell Pull Up"
    (user-owned), or the soft-deleted user dupes "Pull-ups" (`pull_ups_1`/`pull_ups_2`). If resolution
    is ambiguous or a source is missing, fail loudly (no guessing).
    Call `mergeByMap`, then `recalculateAllPRsForExercise` per affected user for the target, then
    `RehydrationService::raiseForUsers($affected, 'exercise-merge')`. Idempotent guard. **Do not run it.**
    `down()` = STRUCTURAL reverse only (see the plan's Reversibility section): repoint the audited
    `lift_log_ids` back to their source `exercise_id`, un-trash sources, restore the target's original
    `canonical_name`/`title` from the audit snapshot, delete merge-created aliases, recompute PRs for all
    affected pairs, and raise a NEW rehydration token. `down()` does NOT restore original PR rows.
12. Add a feature test that seeds three pull-up exercises + logs so the historical exercise's best
    Total-Reps exceeds the recent one (mirror the real data: an older session of ~48–60 total reps, and
    a recent 9×4 = 36 session). Invoke `mergeByMap` + `recalculateAllPRsForExercise` directly (NOT the
    migration). Assert: target holds all logs; the 36-rep session is NOT a PR; the combined best is the
    historical max. Then exercise `down()` (or the reverse service path) and assert the STRUCTURAL
    reverse: logs back on sources, sources un-trashed, target renamed back, merge aliases removed, a new
    rehydration token raised. Do NOT assert original PR-row identity — only that PRs recompute validly.

**Checkpoint:** full suite green.

---

## Success criteria

All four milestones' tests pass; full suite green. `/changes` conditionally emits `rehydrate.token`.
`mergeByMap` is generic + idempotent + multi-user-safe. Pull-up migration authored (not run) and
reversible. Combined-history PR correctness proven by test.

## Do not

- Run the migration or any non-test DB write. Reuse `mergeExercises`. Add endpoints/deps. Commit. Pint.

## Post-Execution Retro

- **Milestones completed:** {placeholder}
- **Follow-up fixes surfaced in review:** {placeholder}
- **Deviations from plan:** {placeholder}
- **Prompt gaps / ambiguities:** {placeholder}
- **Test count before/after:** {placeholder}
