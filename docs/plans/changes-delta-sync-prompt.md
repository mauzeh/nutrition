# Global Execution Rules

1. Execute this plan sequentially, pausing to test ONLY at the designated Milestone checkpoints.
2. CRITICAL SELF-CORRECTION LOOP: at a testing step, run the test command; if it fails, read the errors,
   fix, and re-run within this turn until green. Do not yield with a failing milestone; do not ask for help.
3. Do not finish your turn until the current milestone's tests pass completely.
4. When ALL milestones pass, write the Post-Execution Retro into this file, run the End-of-Run Cleanup
   Sweep (`docs/antigravity-steering.md`), then print exactly:
   ```
   AGY_COMPLETE: All milestones passed.
   ```
5. CONTEXT BUDGET: grep/line-range reads; no whole-file reads for small edits. See antigravity-steering §13.

---

# /changes Delta Sync — Logger Implementation Prompt

## Feature Classification

- [x] **API behavior change (additive + filtered), no schema change.** `GET /api/sync/changes` becomes a
  delta when the client sends `?since=<ISO-8601>`: return only logs/tombstones changed at/after that
  instant, plus a new `cursor`. `since` absent → full dump exactly as today. No migration (LiftLog already
  has id/timestamps/deleted_at).

---

## Directional Isolation
Stay inside `logger/`. Do NOT read or modify `../athlete`, `../contracts`, `../docs`.

---

## What You're Building
See `docs/plans/changes-delta-sync.md`. In short: parse `?since=`, filter the live-logs query
(`updated_at >= since`) and the tombstone query (`deleted_at >= since`) INCLUSIVELY when present, add a
`cursor` (max high-water mark) to the response, and leave the `since`-absent path as the current full dump.
This retires the "deleted_ids=97 forever" churn (verified live: old tombstones re-announced every poll).

## Read These Files (in order, before writing any code)
```
docs/antigravity-steering.md                       → §2 tool/bash safety, §3 no git, §4 verification + End-of-Run Cleanup Sweep, §13 consumer trace
.kiro/steering/sync-api-context.md                 → sync API architecture
docs/plans/changes-delta-sync.md                   → protocol (inline), execution phases, boundary/timezone/GREATEST risks
app/Sync/Controllers/ChangesController.php          → index(): live-logs query, onlyTrashed() tombstones, payload assembly
app/Models/LiftLog.php                              → confirm SoftDeletes + timestamps
tests/Feature/Sync/ (or the changes feature test)   → sibling test conventions (factories, actingAs, assertJson)
```

## Milestone 1: `since` parsing + filtered queries + cursor

### Step 1 — parse `since`
In `ChangesController@index`, read `?since=`. If present, validate as an ISO-8601 datetime (422 on
malformed, matching sibling validation style); parse to a Carbon instant. If absent/empty → `$since = null`.

### Step 2 — filter live logs
When `$since !== null`, add `->where('updated_at', '>=', $since)` to the live `LiftLog` query (a live row's
high-water is its `updated_at`; trashed rows are already excluded from the live query). Preserve eager
loading and ordering. When null, leave the query unfiltered (current behavior).

### Step 3 — filter tombstones
Change the tombstone query to: `LiftLog::onlyTrashed()->where('user_id', $user->id)` PLUS, when
`$since !== null`, `->where('deleted_at', '>=', $since)`, then `->pluck('id')`. When null, unfiltered.

### Step 4 — compute `cursor`
Compute `$cursor` = the max `GREATEST(updated_at, deleted_at)` across the user's logs INCLUDING trashed.
For portability (MySQL prod + SQLite tests) do NOT rely on a raw `GREATEST` SQL function — instead take the
max of two cheap queries (max `updated_at` over all incl. trashed, and max `deleted_at` over trashed) and
pick the later in PHP. Emit as `->toIso8601String()`. If the user has no rows, use `$since ?? now()`. Add
`'cursor' => $cursor` to the response payload.

### Milestone 1 Checkpoint
```bash
php artisan test tests/Feature/Sync/ > .test-output.txt 2>&1; tail -30 .test-output.txt
```
(Adjust the path to the changes feature test dir.) Existing changes tests still green with the additive
`cursor`. Read `.test-output.txt`; delete when green.

---

## Milestone 2: Feature tests for the delta

### Step 5 — add tests
Cover:
- `since` omitted → full logs + full tombstones (unchanged), and `cursor` present.
- `since = T` → only logs with `updated_at >= T`; only tombstones with `deleted_at >= T`.
- **inclusive boundary:** a log with `updated_at == T` IS included (guards against `>` regressions).
- `cursor` equals the max high-water mark; equals `since` when nothing is newer.
- **the bug fix:** a log soft-deleted before `T` is NOT in `deleted_ids` when `since = T` (this is the
  "97 forever" fix — assert the old tombstone is gone).

### Milestone 2 Checkpoint
```bash
php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt
```
Full suite green. Read `.test-output.txt`; delete when green.

---

## Milestone 3: Retro + cleanup sweep + completion

### Step 6 — write the Post-Execution Retro into this file (per antigravity-steering §4).
### Step 7 — End-of-Run Cleanup Sweep (MANDATORY, per antigravity-steering): no orphaned code, no unused
`use` imports, no fallback shims, no temp scripts/`dd()`/`.test-output.txt`. (Pint is banned — sweep by
inspection + grep, do not run a formatter.)
### Step 8 — final run:
```bash
php artisan test --parallel > .test-output.txt 2>&1; tail -20 .test-output.txt
```
Green, zero failures. Delete `.test-output.txt`. Print:
```
AGY_COMPLETE: All milestones passed.
```

## Success Criteria
- [ ] `?since=T` filters live logs by `updated_at >= T` and tombstones by `deleted_at >= T`, INCLUSIVE.
- [ ] Response includes a correct `cursor` (max high-water; `since` when nothing newer; never null).
- [ ] `since` absent → full dump, behavior identical to before.
- [ ] The old-tombstone-forever bug is fixed (asserted by a test).
- [ ] `php artisan test --parallel` green. No `../athlete`/`../contracts`/`../docs`. No git. No Pint. No deps. No migration.

## Do Not
- Do NOT use an exclusive `>` boundary — inclusive `>=` only (silent-skip data-loss trap otherwise).
- Do NOT rely on a raw `GREATEST()` SQL function (SQLite tests) — compute the max in PHP from portable queries.
- Do NOT strip the offset from the emitted `cursor` (`toIso8601String()`) — the client normalizes it.
- Do NOT change the `since`-absent full-dump behavior.
- Do NOT add a migration or schema change. Do NOT commit/push. Do NOT run Pint. Do NOT add composer deps.

## Post-Execution Retro (fill in after completion, then print AGY_COMPLETE)
- **Attempts:** 1 (clean)
- **Boundary inclusivity test:** `test_since_inclusive_boundary` in `ChangesDeltaSyncTest.php` asserting that live log with `updated_at == T` and tombstone with `deleted_at == T` are included when `since = T`.
- **Old-tombstone-forever fix verified:** `test_old_tombstone_forever_fix` in `ChangesDeltaSyncTest.php` asserting that soft-deleted log before `since` is excluded from `deleted_ids`.
- **cursor computed via:** Max of `orderBy('updated_at', 'desc')` and `orderBy('deleted_at', 'desc')` Eloquent queries evaluated in PHP, taking `since` when nothing is newer.
- **Cleanup sweep:** Clean; verified imports, no orphaned code or temp files, deleted `.test-output.txt`.
- **Prompt gap:** None.
