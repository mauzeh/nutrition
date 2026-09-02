# /changes Delta Sync (Logger)

> **Scope:** Logger-only. Reference architecture; execution steps live in `changes-delta-sync-prompt.md`.
> Implements the Logger portion of a cross-repo change; the FROZEN protocol lives at the workspace root
> but is duplicated inline below (directional isolation — do NOT read the root doc from here).
>
> **Directional isolation:** Stay inside `logger/`. Do NOT touch `../athlete`, `../contracts`, `../docs`.

---

## Before You Start

```
.kiro/steering/safe-operations.md        → protected files, artisan safety, Pint ban
.kiro/steering/sync-api-context.md        → sync API architecture, app/Sync/ layout
docs/antigravity-steering.md              → §2 tool safety, §4 verification + cleanup sweep, §9 plan format, §13 consumer trace
```

## What You're Building

`GET /api/sync/changes` currently returns the user's ENTIRE state every poll — all live logs plus every
soft-deleted log id, forever, with no watermark (`LiftLog::onlyTrashed()->pluck('id')` unconditionally).
This makes the payload fully redundant on every poll and re-announces old deletions permanently.

This slice makes `/changes` a **delta**: when the client sends `?since=<ISO-8601>`, return only the logs
and tombstones changed at/after that instant, plus a `cursor` (new high-water mark). When `since` is
absent, return the full state exactly as today (first-ever pull / transitional client).

**No schema change, no migration.** `LiftLog` already has `id`, `created_at`/`updated_at`, and
`deleted_at` (SoftDeletes) — everything the cursor needs.

## Delta protocol (duplicated inline from the FROZEN spine)

1. **Cursor = ISO-8601 timestamp**, high-water mark of "changed."
2. **Changed since `since`** = `GREATEST(updated_at, COALESCE(deleted_at, updated_at)) >= since`
   (INCLUSIVE `>=`). Live edits bump `updated_at`; soft-deletes bump `deleted_at`.
3. **Inclusive boundary** — `>=`, never `>`. Over-fetching boundary rows is safe (client de-dups
   idempotently); under-fetching would skip changes. NEVER use exclusive `>`.
4. **`cursor` in response** = the MAX `GREATEST(updated_at, deleted_at)` across the user's rows (the "you
   are current as of" instant). When nothing changed, it equals the request `since` (or the current max);
   never null.
5. **`since` absent/empty → full dump** (today's behavior; the filter is simply skipped).

## Existing Code to Understand

```
app/Sync/Controllers/ChangesController.php   → the index() method: live-logs query + onlyTrashed() tombstones + payload assembly
routes/sync.php                              → GET /changes (auth-protected) — no route change needed
app/Models/LiftLog.php                       → SoftDeletes; id/timestamps/deleted_at available
```

## Execution Plan (phases)

### Phase 1 — parse and validate `since`
Read `?since=` from the request. If present, parse as an ISO-8601 datetime (reject malformed with a 422 via
a Form Request or inline validation matching the sibling convention); if absent/empty, `since = null`
(full-dump path).

### Phase 2 — filter the live-logs query
When `since !== null`, constrain the live `LiftLog` query so only rows with
`GREATEST(updated_at, deleted_at) >= since` are returned. Since the live query already excludes trashed
rows, in practice this is `where('updated_at', '>=', $since)` for the live set (a live row's high-water is
its `updated_at`). Keep eager loading (`with(['exercise','liftSets'])`) and ordering. When `since === null`,
run the query unfiltered (today's behavior).

### Phase 3 — filter the tombstones
Replace `LiftLog::onlyTrashed()->where('user_id',...)->pluck('id')` with: when `since !== null`, add
`->where('deleted_at', '>=', $since)`; when null, unfiltered (full tombstone list). This is what retires
the "97 forever" churn.

### Phase 4 — compute and return `cursor`
Compute the response `cursor` = the max of `GREATEST(updated_at, deleted_at)` across the user's logs
(including trashed), as an ISO-8601 string via `->toIso8601String()` (consistent with the existing
`updated_at` serialization). If the user has zero rows, return the request `since` (or `now()` if none).
Add `'cursor' => $cursor` to the payload array.

### Phase 5 — feature tests
Add tests to a `ChangesController`/sync feature test covering:
- `since` omitted → full logs + full tombstones (unchanged behavior).
- `since = T` → only logs with `updated_at >= T`; only tombstones with `deleted_at >= T`.
- inclusive boundary: a row whose `updated_at == T` IS included.
- `cursor` returned equals the max high-water mark; equals `since` when nothing newer.
- a soft-deleted-long-ago log is NOT returned when `since` is after its `deleted_at` (the bug fix).

## Hard Rules
- Never commit/push. Never run Pint. No destructive DB commands. No new composer deps.
- Existing web UI + the full-dump (`since` absent) path must behave identically.

## Consumer Impact Trace (per antigravity-steering §13)
This changes the `/changes` RESPONSE (adds `cursor`, conditionally filters `logs`/`deleted_ids`). Readers:
- **Athlete `pullChanges`** — the sole consumer; it will send `since` and read `cursor` (its own slice).
  The added `cursor` field is additive; an old client ignoring it + never sending `since` still gets the
  full dump. No breakage during rollout.
- **Existing `/changes` contract test / feature tests** — update any that assert the exact payload keys to
  allow the additive `cursor`.

## Success Criteria / Constraints
- `?since=T` filters live logs (`updated_at >= T`) and tombstones (`deleted_at >= T`), inclusive.
- `cursor` present and correct; `since` absent → full dump unchanged.
- `php artisan test --parallel` green. No `../athlete`/`../contracts`/`../docs`. No git. No Pint. No deps.

## Risks
- **Exclusive vs inclusive boundary.** Must be `>=`. `>` silently skips boundary-timestamp rows — the core
  data-loss trap. Assert inclusivity in a test.
- **Server timezone.** `updated_at`/`deleted_at` and the `since` comparison must be in the same frame. The
  DB stores in the app timezone; compare `since` (parsed) against the columns directly (same frame). The
  emitted `cursor` uses `toIso8601String()` (carries offset) so the client normalizes it — do not strip the
  offset.
- **`GREATEST` portability.** MySQL supports `GREATEST`; ensure the query builder expression is written so
  it runs under both MySQL (prod) and the SQLite used in tests, or split into the equivalent
  `updated_at >= since OR deleted_at >= since` conditions (preferred — portable and clearer).

## Changelog
- rev 1 (2026-09-01) — authored. `/changes` becomes a timestamp-cursor delta: `since` filters live logs by
  `updated_at` and tombstones by `deleted_at` (inclusive `>=`), response gains `cursor`; `since` absent =
  full dump. No schema change. Retires the unbounded-tombstone churn.
