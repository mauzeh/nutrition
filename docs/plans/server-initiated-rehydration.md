# Server-Initiated Rehydration + Generalized Exercise Merge (Logger slice)

## Before You Start

Read first, in order:
- `docs/antigravity-steering.md` (this repo) — hard rules, no-git, no-Pint, milestone testing.
- `.kiro/steering/project-conventions.md`, `.kiro/steering/sync-api-context.md` — sync architecture,
  Actions pattern, event dispatch, soft deletes.
> **Boundary rule:** this is a self-contained Logger task. Do NOT read, reference, or write anything
> outside this repository (no `../../` paths, no root workspace, no other app). The cross-app contract
> shapes this slice must produce are duplicated INLINE below so you never need to reach up. A separate
> root-level contract task (run after both apps are implemented) reconciles the two apps — that is not
> your concern here.

### Cross-app shapes this slice must produce (inline — the contract, duplicated here)

`/changes` `rehydrate` field (additive, optional; omit when nothing to signal):

```jsonc
{ "rehydrate": { "token": "2026-09-01T12:00:00Z", "reason": "exercise-merge" } }
```
- `token`: monotonic per user; an ISO-8601 UTC string so plain string comparison orders it.
- `reason`: advisory label only.

Exercise merge map (drives `mergeByMap`; pull-ups is the first entry):

```jsonc
{ "target": "strict_pull_up", "title": "Strict Pull-Ups", "sources": ["pull_up", "pull_ups"] }
```
- `target`/`sources` resolve to `Exercise` rows by `canonical_name`, then title (case-insensitive).

## What You're Building

Two capabilities, one producing the other:

1. **A rehydration-signal channel** on the durable backend. Logger can raise a per-user "your local
   derived state is stale, rebuild it" signal, delivered as a monotonic `token` on the existing
   `GET /api/sync/changes` response. The Athlete honors each token once.

2. **A generalized exercise-merge** — fold multiple exercise records that are the same real-world
   movement into one canonical exercise, repoint their logs, recompute PRs, alias the old canonicals,
   and raise a rehydration signal for every affected user. Driven by a declarative merge map so future
   merges are config + a thin migration, not new logic. First application: fold "Pull-Up" (id 9) and
   "Pull-Ups" (id 258) into "Strict Pull-Ups" (id 263) — canonical `strict_pull_up`.

End state: `/changes` emits a `rehydrate` token to affected users after a merge; the pull-up variants
are one exercise with correct combined-history PRs; the merge is reusable via a map.

## Existing Code to Understand

- `app/Sync/Controllers/ChangesController.php` — the `/changes` endpoint you extend with `rehydrate`.
- `app/Services/ExerciseMergeService.php` — existing admin merge (user→global). You generalize a
  global→global, map-driven path from it. Note: current `mergeExercises` requires a user-owned source
  and an admin User; the pull-up sources are all global, so you need a new method, not the existing one.
- `app/Services/PRRecalculationService.php` — `recalculateAllPRsForExercise($userId, $exerciseId)`;
  idempotent delete-then-rebuild + chain relink. Call it per affected pair after repointing logs.
- `app/Models/Exercise.php`, `app/Models/ExerciseAlias.php`, `app/Models/LiftLog.php`,
  `app/Models/PersonalRecord.php` — soft deletes; `canonical_name`, `title`, `log_type`, `exercise_type`.
- `database/migrations/` — the reconciliation/merge migration precedent
  (`2026_08_30_142524_retype_sled_and_carry_exercises_to_load_output.php`) shows how to repoint +
  recompute inside a migration safely (self-FK, soft-delete safety).
- `app/Sync/Services/ExerciseReconciler.php` (if present) — the changeset/reconciler convention for
  reviewable, reversible data changes (in-repo reference only).

## Execution Plan

### Phase 1 — Rehydration signal storage + model (pure, no integration)

1. Migration: create `rehydration_signals` table — `id`, `user_id` (nullable FK; null = global signal
   applying to all users), `token` (string, monotonic — use an ISO-8601 UTC timestamp string so string
   compare orders correctly), `reason` (string), timestamps. Index `(user_id, token)`.
2. Model `App\Models\RehydrationSignal` — `$fillable`, casts, `user()` relation. Add a query scope
   `latestForUser($userId)` returning the max token applicable to the user (their own signals ∪ global
   signals). New columns nullable per DB rules.
3. A tiny service `App\Sync\Services\RehydrationService` with:
   - `raiseForUsers(array $userIds, string $reason): void` — inserts a signal row per user with
     `token = now()->toIso8601String()` (all sharing one timestamp for one event is fine).
   - `latestToken(User $user): ?string` — max token across the user's own + global signals, or null.
4. **Milestone test:** unit tests for the model scope + service (`tests/Unit/Sync/RehydrationServiceTest.php`
   or feature test with factories). Run `php artisan test --parallel`.

### Phase 2 — `/changes` emits the token (single integration point)

5. Inject `RehydrationService` into `ChangesController`. After building the payload, compute
   `$token = $rehydrationService->latestToken($user)`. If non-null, add
   `'rehydrate' => ['token' => $token, 'reason' => 'exercise-merge']` (reason from the latest signal
   row) to the JSON. Omit the key entirely when null.
6. **Consumer trace (payload shape change):** the only consumer of `/changes` is the Athlete
   `pullChanges.js`. The new field is additive and optional; existing tests asserting the payload must
   still pass (they should not assert absence of extra keys — verify). List and update any Sync feature
   test that snapshots the full `/changes` body.
7. **Milestone test:** feature test — a user with a signal gets `rehydrate.token`; a user without one
   gets no `rehydrate` key; a global signal reaches all users. Run the Sync suite, then full suite.

### Phase 3 — Generalized merge (pure logic + service)

8. Add `App\Services\ExerciseMergeService::mergeByMap(array $merge): array` (or a dedicated
   `ExerciseMergeReconciler`) where `$merge = ['target' => 'strict_pull_up', 'title' => 'Strict Pull-Ups',
   'sources' => ['pull_up','pull_ups', ... resolved to ids]]`. It must:
   - Resolve target + sources to `Exercise` rows by `canonical_name`, then by title (case-insensitive)
     as fallback. **Scope resolution to GLOBAL exercises only** (`whereNull('user_id')`) and exclude
     soft-deleted rows — this prevents matching user-owned or trashed duplicates. Skip
     already-merged/absent sources (idempotent). If a source resolves to more than one candidate, do NOT
     guess: fail the merge with a clear error (a merge must be unambiguous). Prefer resolving the
     real-world rows by the DATA the migration knows (see migration step) rather than trusting canonical
     slugs alone — in prod the pull-up canonicals are crossed (id 9 "Pull-Up" has canonical `pull_ups`;
     id 258 "Pull-Ups" has canonical `pull_up`) and there are trashed user dupes `pull_ups_1`/`pull_ups_2`.
   - Inside a transaction, for each source: repoint `lift_logs.exercise_id` → target; transfer/collapse
     `exercise_intelligence`; create an `ExerciseAlias` for the source `canonical_name` and `title` on
     the target; write an `ExerciseMergeLog` audit row; soft-delete the source exercise.
   - The audit row MUST capture everything `down()` needs for the structural reverse: source id +
     original `canonical_name`/`title`/`log_type`, the exact `lift_log_ids` moved, the aliases created,
     and the target's ORIGINAL `canonical_name`/`title` (before this merge overwrote them). If
     `ExerciseMergeLog` lacks columns for the target's original naming, add a nullable JSON `snapshot`
     column (new migration, nullable, `$fillable`, cast to array).
   - Set the target's `canonical_name`/`title` to the map's canonical/title (naming authority).
   - Return the set of affected `user_id`s (distinct across all repointed logs).
   - Do NOT recompute PRs here (keep this pure-ish); the caller (migration) does that + raises signals.
9. **Milestone test:** unit/feature test of `mergeByMap` with factories — 2 sources + target, logs on
   multiple users; asserts logs repointed, sources soft-deleted, aliases created, affected users
   returned, idempotent on re-run. Run suite.

### Phase 4 — The pull-up merge migration (integration + data)

10. Migration `..._merge_pull_up_variants.php`:
    - Define the merge map inline (or read from a committed changeset JSON per the reconciliation doc).
    - **Exact prod rows (from a fresh prod copy) — resolve to these GLOBAL exercises, nothing else:**
      target = id 263 "Strict Pull-Ups" (`canonical=strict_pull_up`, `bodyweight`); sources = id 9
      "Pull-Up" (`canonical=pull_ups`, `bodyweight-reps`, 39 logs across users 1/34/20) and id 258
      "Pull-Ups" (`canonical=pull_up`, `bodyweight`, 6 logs user 1). **Do NOT touch:** id 179 "Kipping
      Pull-Up" (distinct movement), id 63 "Dumbbell Pull Up" (user-owned, distinct), ids 150/156
      (soft-deleted user dupes, 0 logs). Because prod canonicals are crossed and dupes exist, the
      migration should match sources by the specific `(title, log_type, user_id IS NULL)` tuples above,
      not by canonical slug alone — keep resolution unambiguous. Note the merge crosses `log_type`
      (`bodyweight-reps` sources into a `bodyweight` target); that is intentional — they are the same
      movement and both resolve to the `bodyweight` PR family. Set the target `log_type` to the
      canonical value the Athlete uses for `strict_pull_up`.
    - Call `mergeByMap($merge)` → get affected user ids.
    - For each affected `user_id`, call `PRRecalculationService::recalculateAllPRsForExercise($userId,
      $targetExerciseId)`.
    - Call `RehydrationService::raiseForUsers($affectedUserIds, 'exercise-merge')`.
    - Guard idempotency: if sources already soft-deleted/absent, no-op cleanly.
    - `down()`: **structural reverse only** (see "Reversibility" below) — repoint the logs named in the
      `ExerciseMergeLog` rows back to their original source `exercise_id`, un-trash the source exercises,
      restore the target's original `canonical_name`/`title` (captured in the audit row), remove the
      aliases the merge added, then `recalculateAllPRsForExercise` for every affected `(user, exercise)`
      pair (target AND restored sources), and **raise a NEW rehydration signal** for the affected users
      so their clients re-converge on the reverted data. `down()` does NOT restore original PR rows — it
      recomputes them (see Reversibility).
    - **Do NOT run the migration** (the user runs migrations). Just author it.
11. **Milestone test:** a feature test that seeds the three pull-up exercises + logs (mirroring the
    real data: id-9-style history reaching a higher Total-Reps best than the recent id-258 sessions),
    runs the merge + recalc path (invoke the service/recalc directly, not the migration), and asserts:
    the target holds all logs, PRs reflect combined history, and the previously-PR recent session is no
    longer a PR. Run full suite.

## Hard Rules

- Never commit, push, or run Pint. Never run destructive DB commands; do NOT run the merge migration.
- New columns nullable, in `$fillable`, casts where needed. Forward-only migrations.
- Use Eloquent, constructor property promotion, explicit return types, Form Requests where validating.
- Soft-delete scoping everywhere; the merge must not resurrect trashed logs.
- Dispatch/consume events per convention; do not inline PR detection — reuse `PRRecalculationService`.

## Implementation Rules

- Namespace sync-only code under `app/Sync/`; the merge service is shared domain (stays in `app/Services/`).
- Match sibling controller/service/test conventions. PHPUnit only, `--parallel`.
- The `rehydrate` field shape must match the inline contract above (`token`, `reason`).

## Reversibility (be honest about the boundary)

The migration is **structurally reversible but not byte-identically reversible.** State the boundary
plainly; do not claim a perfect rollback.

**`down()` CAN restore (from the `ExerciseMergeLog` audit + snapshot):**
- Log ownership — repoint the recorded `lift_log_ids` back to their original source `exercise_id`.
- Source exercises — un-trash (they were soft-deleted, the rows still exist).
- Target naming — restore the target's original `canonical_name`/`title` from the snapshot.
- Merge-created aliases — delete them.

**`down()` CANNOT restore (inherent, not fixable in `down()`):**
- **Original PR rows.** `up()` regenerates PRs via `PRRecalculationService` (destructive
  delete-and-rebuild). The original `personal_records` rows — their ids, `previous_pr_id` chains,
  `achieved_at`, and anything referencing those PR ids (`PRHighFive`, `PRComment`,
  `PersonalRecordRead`) — are gone. `down()` can only RECOMPUTE PRs for the un-merged exercises,
  producing NEW rows. Social interactions tied to the old PR ids are lost. This mirrors the existing
  recalc model (same property as the sled/carry Migration B) and is accepted.
- **Client-side rehydration.** Once `up()` raises a signal, athletes' clients poll, rehydrate, and
  re-pull merged data locally. Server `down()` cannot un-ring that. Therefore `down()` MUST raise a
  NEW (higher) rehydration token after reverting, so clients rehydrate again against the reverted data
  and re-converge. Without this, clients would keep the merged local state after a server rollback.

This matches the platform's exercise-reconciliation convention, which classifies `merge` as "not
reversible without backup — the changeset stores original state." The audit row + snapshot ARE that
backup for the structural layer; the PR/social layer is re-derived, not restored.

**Test the reverse:** the migration feature test must also exercise `down()` and assert the structural
reverse (logs back on sources, sources un-trashed, target renamed back, merge aliases gone, a new
rehydration token raised). It should NOT assert PR-row identity equality — only that PRs recompute to a
valid state for the un-merged exercises.

## Success Criteria

- `rehydration_signals` table + model + service exist and are tested.
- `/changes` returns `rehydrate.token` iff the user has an unacknowledged signal; omitted otherwise.
- `mergeByMap` folds sources → target transactionally, idempotently, multi-user-safe, with audit +
  aliases + affected-user return.
- The pull-up merge migration is authored (not run), **structurally reversible** (per the Reversibility
  section — `down()` restores logs/exercises/naming/aliases and recomputes PRs + re-signals clients; it
  does NOT restore original PR rows or client state byte-for-byte), and raises signals for affected users.
- Feature test proves the pull-up combined-history PRs are correct (recent 36-rep session no longer a PR).
- Full suite green.

## Do Not

- Do not run the migration or any DB write outside tests.
- Do not reuse `mergeExercises` (user→global/admin path) for the global→global merge — add `mergeByMap`.
- Do not add a new endpoint or push channel — the signal rides on `/changes`.
- Do not hardcode pull-ups in the service; it takes the map. Only the migration names pull-ups.

## Post-Execution Retro

- **Milestones completed:** {placeholder}
- **Follow-up fixes surfaced in review:** {placeholder}
- **Deviations from plan:** {placeholder}
- **Prompt gaps / ambiguities:** {placeholder}
