# Fix: added weight dropped for `bodyweight-reps` sync mapping (Logger)

## Before You Start

Read first: `docs/antigravity-steering.md` (hard rules, no-git, no-Pint, milestone testing),
`.kiro/steering/sync-api-context.md` (sync data model, SetFieldMapper role).

> **Boundary rule:** self-contained Logger task. Do NOT read/reference/write anything outside this
> repository (no `../../`, no root workspace, no other app).

## What You're Building

A correctness fix in `App\Sync\Services\SetFieldMapper` so that **added weight is preserved for the
`bodyweight-reps` logType** on both sync directions. Today the `bodyweight-reps` case maps reps only and
silently drops the `weight` column, so an athlete who logs *added-weight* pull-ups/dips on a
`bodyweight-reps`-typed exercise loses that weight on every sync (both inbound writes and outbound
`/changes` + `/restore` payloads).

End state: `bodyweight-reps` round-trips weight exactly like `bodyweight` / `added-weight` — weight + reps
both preserved; zero-weight (true bodyweight) sessions still store weight `0` and behave unchanged.

## Existing Code to Understand

- `app/Sync/Services/SetFieldMapper.php`:
  - `mapToColumns(string $logType, array $setData, string $weightUnit)` — front-end → DB columns. The
    `bodyweight` / `added-weight` case does `weight = addedWeight ?? weight ?? 0`; the `bodyweight-reps`
    case sets reps only (drops weight). **This is the inbound half of the bug.**
  - `mapFromColumns(string $logType, LiftSet $set)` — DB columns → wire format for the Athlete. The
    `bodyweight` / `added-weight` case includes `weight`; the `bodyweight-reps` case emits reps only.
    **This is the outbound half of the bug.**
- Callers: `LogController` / batch upsert (inbound `mapToColumns`), `ChangesController` +
  `RestoreController` (outbound `mapFromColumns`).
- `tests/Feature/Sync/` — existing SetFieldMapper / sync round-trip tests to mirror.

## Execution Plan

### Phase 1 — Make `bodyweight-reps` preserve weight (both directions)

1. In `mapToColumns`, change the `bodyweight-reps` case to set `weight` the same way `bodyweight` does:
   `$columns['weight'] = $setData['addedWeight'] ?? $setData['weight'] ?? 0;` plus the existing
   `reps`. (Zero-weight bodyweight stays `0`.) Confirm the `bodyweight-reps` and `bodyweight` cases are
   now weight-equivalent; if they are byte-identical, collapse them into one shared case to avoid drift.
2. In `mapFromColumns`, add `$data['weight'] = $set->weight;` to the `bodyweight-reps` case (or fold it
   into the `bodyweight` case). The Athlete side already maps inbound `weight` → `addedWeight` for
   bodyweight logTypes, so no Athlete change is needed for weight to land in the right field.
3. **Consumer trace (payload shape):** the outbound change adds a `weight` key to `bodyweight-reps` set
   payloads. Confirm no Athlete-facing contract test asserts its ABSENCE, and that the Athlete's
   `mapSetsFromApi` maps `weight` → `addedWeight` for these logTypes (it does — verify the logType names
   line up: Athlete treats `bodyweight` / `added-weight` as added-weight; ensure `bodyweight-reps` logs
   also carry weight through — if the Athlete needs a logType-name alignment, that is a SEPARATE Athlete
   task, NOT part of this Logger slice — flag it, do not reach across).

### Phase 2 — Tests

4. Add/extend SetFieldMapper tests:
   - `mapToColumns('bodyweight-reps', ['addedWeight' => 15, 'reps' => 5], 'lbs')` → `weight = 15`,
     `reps = 5`.
   - `mapToColumns('bodyweight-reps', ['reps' => 5], 'lbs')` → `weight = 0` (true bodyweight unchanged).
   - `mapFromColumns('bodyweight-reps', <set weight=15 reps=5>)` → `['weight' => 15, 'reps' => 5]`.
   - A round-trip test: columns → wire → (Athlete would map to addedWeight) → back to columns preserves
     weight 15.
5. **Milestone test:** `php artisan test --parallel`. Fix to green.

## Hard Rules

- Never commit, push, or Pint. No destructive DB. Forward-only if any migration is needed (none expected
  — this is a mapping fix, no schema change).
- Match sibling case structure; explicit return types; constructor property promotion where relevant.
- Zero behavioral change for true (zero-weight) bodyweight sessions.

## Success Criteria

- `bodyweight-reps` preserves weight in both `mapToColumns` and `mapFromColumns`.
- True bodyweight (no added weight) still stores/sends weight `0`.
- New tests cover both directions + round-trip; full suite green.

## Do Not

- Do not change any Athlete file (separate repo). If a logType-name alignment is needed on the Athlete,
  flag it as a follow-up — do not reach across the boundary.
- Do not alter the `bodyweight` / `added-weight` behavior (already correct) beyond optional case-merging.
- Do not commit.

## Post-Execution Retro

- **Milestones completed:** {placeholder}
- **Follow-up fixes surfaced in review:** {placeholder}
- **Deviations from plan:** {placeholder}
