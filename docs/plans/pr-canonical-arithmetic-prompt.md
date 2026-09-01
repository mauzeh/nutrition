# PR Engine Canonical Arithmetic — Prompt for Antigravity CLI (Logger, Phase 2)

## Before You Start

Read these in order.

### 1. Steering (project rules — always follow)
```
.kiro/steering/safe-operations.md       → files never to touch, bash/artisan safety, Pint ban, no destructive DB
.kiro/steering/project-conventions.md   → data integrity, Actions, events, migrations forward-only
.kiro/steering/laravel-boost.md         → PHP style, Eloquent, PHPUnit-only
docs/antigravity-steering.md            → §3 NEVER git, §4 --parallel testing + retro-to-file, §6 DB integrity, §13 consumer trace
```

### 2. Feature Spec (what to build)
```
docs/plans/pr-canonical-arithmetic.md                → architecture, the single-factor + no-rounding change, the recalc migration, consumer trace
../docs/plans/pr-load-role-token-cross-repo.md       → FROZEN §7–§14 (canonical frame, factor 2.2046226218, no intermediate rounding) — shared source of truth
```

### 3. Existing Code to Understand (read before modifying)
```
app/Services/UnitResolver.php                        → KG_TO_LBS (truncated 2.20462262 — bump to full precision); convert/formatForUser
app/Services/PR/Reductions.php                       → extractValue/extractValueRaw $convertMass (factor + $roundMass 2-dp); perKey speed-bucket key round
app/Services/PRDetectionService.php                  → de-normalizes PR values with inlined 2.2046226218; buildHistoryFromPreviousLogs
app/Services/PRRecalculationService.php              → recalculateAllPRsForExercise (delete-then-rebuild from raw logs)
app/Console/Commands/CalculateHistoricalPRs.php      → enumerates distinct (user_id, exercise_id) from lift_logs AND personal_records; loops recalculateAllPRsForExercise
database/migrations/2026_09_01_070639_merge_pull_up_variants.php → PRECEDENT: a migration up() that calls PRRecalculationService
```

---

## What You're Building

Unify Logger's kg→lbs factor to full precision `2.2046226218` (it currently has TWO: `UnitResolver` uses
the truncated `2.20462262`; `Reductions`/`PRDetectionService` inline the full `2.2046226218`), collapse to
ONE definition, and remove the one intermediate-rounding inconsistency in `Reductions::extractValue` (the
`$roundMass` 2-dp round) so the comparable value is full precision — matching Athlete's canonical frame
byte-for-byte. Then author a forward-only MIGRATION that rebuilds all `personal_records` from raw logs under
the unified factor, so production is consistent on deploy.

Logger already re-derives history from raw logs (no persisted-blob frame bug) — so this is a factor + rounding
alignment + a data recalc, NOT a structural rewrite. The `weight` column and storage are unchanged.

---

## Execution Plan

### Phase order
1. **Single factor** (Milestone 1) — `UnitResolver::KG_TO_LBS = 2.2046226218`; `Reductions`/`PRDetectionService`
   reference that ONE constant (no inlined literal).
2. **No intermediate rounding** (Milestone 1) — remove the 2-dp round in `extractValue`'s `$convertMass`
   (full precision); keep the speed-bucket key's whole-pound round.
3. **Checkpoint** — `php artisan test --parallel`; recompute factor/precision test expectations.
4. **Recalc migration** (Milestone 2) — forward-only; rebuild all `personal_records` via
   `recalculateAllPRsForExercise` over every (user, exercise) pair (reuse `CalculateHistoricalPRs` logic).
5. **Run it + verify** (Milestone 3) — `php artisan migrate` then `php artisan migrate:status` shows Ran;
   `php artisan test --parallel` green.

---

## Milestone 1: Single full-precision factor + no intermediate rounding

### Step 1 — one factor
- `app/Services/UnitResolver.php`: `const KG_TO_LBS = 2.2046226218;` (keep `LBS_TO_KG = 0.45359237`).
- `app/Services/PR/Reductions.php` + `app/Services/PRDetectionService.php`: replace the inlined
  `2.2046226218` with a reference to `UnitResolver::KG_TO_LBS` so the factor is defined in exactly ONE place.
- Grep gate: `grep -rn "2.20462262" app/` → zero; `grep -rn "2.2046226218" app/` → one definition site.

### Step 2 — full precision comparable (remove 2-dp round)
In `Reductions::extractValue`, the `$convertMass` closure returns `round($lbs, 2)` when `$roundMass` is
true. Per FROZEN §9 the comparable value is NOT rounded — make the mass conversion full precision in ALL
paths (drop the 2-dp branch; `$roundMass` can be removed or made a no-op). Verify:
- volume (`extractValueRaw`, already full precision) is unchanged;
- scalar/keyed reads are now full precision;
- the `perKey` speed-bucket key round (`$kf === 'weight'` → `(int) round(...)`) STAYS (it is a key, not the
  comparable value).

### Milestone 1 Checkpoint
```bash
php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt
```
Update any test asserting `KG_TO_LBS === 2.20462262` or a 2-dp-rounded kg comparable to the full-precision
value. A borderline PR that the 2-dp rounding masked may now correctly fire — update the expectation, do
not re-add rounding. Read `.test-output.txt`; delete when green.

## Milestone 2: The recalc migration (forward-only, reuses existing recalc)

### Step 3 — author the migration
```bash
php artisan make:migration recalculate_prs_canonical_factor --no-interaction
```
In `up()`: rebuild all `personal_records` from raw logs under the new factor. Reuse the existing
enumeration — either:
- (preferred) `Artisan::call('prs:calculate-historical', ['--force' => true])` — the command enumerates all
  distinct (user, exercise) pairs from lift_logs AND personal_records and recalcs each. `--force` skips its
  interactive confirmation prompt (a migration has no TTY — MUST pass it), OR
- replicate its two distinct-pair queries (from `lift_logs` and from `personal_records`, unioned/deduped)
  and loop `app(PRRecalculationService::class)->recalculateAllPRsForExercise($userId, $exerciseId)` per pair.
Mirror the `merge_pull_up_variants` migration's structure (resolve services via the container). No schema
change. `down()` is a no-op / comment (this rebuilds a derived cache; there is nothing to reverse).

> This is the Logger equivalent of Athlete's schema-version rehydrate: the deploy pipeline runs `migrate`,
> which regenerates every PR under the unified factor. Do NOT gate it behind an env check — it must run in
> production automatically.

### Milestone 2 Checkpoint
```bash
php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt
```
Add/confirm a test that the recalc rebuilds `personal_records` correctly for a mixed-unit exercise (kg + lbs
logs) under the full-precision factor. Green. Delete `.test-output.txt`.

## Milestone 3: RUN the migration + verify + final run

### Step 4 — run it (MANDATORY — operating rule)
```bash
php artisan migrate --no-interaction
php artisan migrate:status
```
Confirm the new migration shows **Ran** in `migrate:status`. A committed-but-Pending migration silently
leaves the data on the old factor — the milestone is NOT complete until status shows Ran.

### Step 5 — final run
```bash
php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt
```
All green (note any PRE-EXISTING unrelated failures; do not fix them). Delete `.test-output.txt`.

### Post-Execution Retro
Write the retro into THIS file's `## Post-Execution Retro` section (str_replace the placeholders) per
antigravity-steering §4, THEN print:
```
AGY_COMPLETE: All milestones passed.
```

---

## HARD RULES — NEVER VIOLATE
- **NEVER commit / add / push / touch git.**
- **NEVER run Pint.**
- **NEVER run destructive DB** (`migrate:fresh`/`reset`/`db:wipe`). `migrate` (forward-only) is REQUIRED here.

## Implementation Rules
- `php artisan test --parallel` always. PHP 8 constructor promotion, explicit return types, Eloquent over `DB::`.
- The `weight` column, `personal_records` schema, and set/column mappers are UNCHANGED — only the factor,
  the comparable precision, and the (rebuilt) row VALUES change.
- The factor must be defined in exactly ONE place and referenced elsewhere.

## Success Criteria
- [ ] ONE kg→lbs factor (`2.2046226218`); `2.20462262` grep-clean.
- [ ] `extractValue` full precision (no 2-dp intermediate round); speed-bucket key round retained.
- [ ] Forward-only recalc migration reusing `recalculateAllPRsForExercise`, authored, RUN, and `migrate:status` = Ran.
- [ ] `php artisan test --parallel` green; no schema change; no destructive DB; no Pint; no git.
- [ ] Comparison input matches Athlete byte-for-byte (verified by the root contract slice).

## Do Not
- Do NOT commit, push, or run Pint.
- Do NOT touch `../athlete`, `../contracts`, `../docs`, or any sibling app.
- Do NOT keep/reintroduce the truncated `2.20462262`.
- Do NOT re-add intermediate rounding of the comparable value.
- Do NOT change the `weight` column or `personal_records` schema.
- Do NOT gate the recalc migration behind an env flag — it must run automatically on deploy.
- Do NOT leave the migration Pending — run it and verify.

## Post-Execution Retro (added after completion)
- **Attempts:** {1 (clean) / N — root cause}
- **Tests added:** {count}
- **Prompt improvements for next time:** {what to add/change}
- **Steering updates needed:** {yes/no, what}
