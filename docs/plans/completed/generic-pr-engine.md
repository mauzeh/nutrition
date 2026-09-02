# Generic PR Engine (Logger side) — Plan

> **Reference architecture, NOT execution steps.** Execute from `docs/plans/generic-pr-engine-prompt.md`
> (authored from `docs/plans/template-prompt.md`). Format per `docs/antigravity-steering.md` §9.
>
> **Cross-repo:** This is the Logger slice of a change owned by the root plan
> `../../docs/plans/generic-pr-engine-cross-repo.md`. That root doc's **FROZEN spec + primitive tables**
> are the source of truth for all shared names, descriptor fields, directions, tolerances, and family
> membership. Do NOT re-decide them here. Land in lockstep with the cross-app contract tests — never
> ahead of them. Execution order across repos is **Logger first, then Athlete, then fixtures.**

---

## Before You Start (read in order)

```
docs/antigravity-steering.md                  → executor contract: git (NEVER commit), Pint ban, §6 DB rules, §13 consumer trace, §15 decomposition, milestones, AGY_COMPLETE
.kiro/steering/safe-operations.md             → files never to edit, artisan/bash safety, Pint ban wins
.kiro/steering/project-conventions.md         → forward-only migrations, no column repurposing, domain folders, dispatch events, one-source-of-truth
.kiro/steering/sync-api-context.md            → exercise-type strategy pattern, PR dispatch, data model
.kiro/steering/laravel-boost.md               → Eloquent not DB::, constructor promotion, explicit return types, PHPUnit-only
```
Cross-repo source of truth (read, do NOT edit): `../../docs/plans/generic-pr-engine-cross-repo.md`
(FROZEN spec + primitive-coverage table).

---

## What You're Building

Replace Logger's per-exercise-type imperative PR logic with **one generic, config-driven PR engine**
whose behavior is declared as data in a single new config file, structurally identical to the Athlete
app's `prDescriptors.js`. After this change, adding a PR type is a config edit in two files (this app's
`config/pr_families.php` + Athlete's `prDescriptors.js`) plus a contract fixture — **never a migration,
never a new PHP method, never a new enum case.**

Three things change together:

1. **Storage de-enumeration (the last PR migration ever).** `personal_records.pr_type` is today a MySQL
   `ENUM`; a new value structurally requires an `ALTER TABLE`. Migrate it to a `VARCHAR`, validated in
   the app against the config. Delete the `PRType` **bitmask** enum (`app/Enums/PRType.php`) — a bitmask
   caps the type universe and re-introduces a hardcoded list. Detection results become the list of type
   strings, not an int flag.
2. **Generic interpreter.** A new `app/Services/PR/` engine reads `config/pr_families.php` and computes
   metrics, detects PRs, and explains non-PRs (`reasons`) via two closed primitive tables
   (`REDUCTIONS`, `COMPARATORS`) — no `match($pr->pr_type)`, no per-type `if/elseif`, no per-type
   methods on the exercise-type strategies.
3. **Generic display.** The per-log PR table (`PRRecordsComponentAssembler`) and any formatter render
   from the descriptor's `label`/`format` config, not from `switch ($pr->pr_type)`.

**Zero debt.** No backward-compat: no ENUM kept "just in case," no bitmask shim, no dual code path, no
per-type strategy method left behind, no dead formatter branch. The exercise-type strategy classes keep
their non-PR responsibilities (validation, form fields, chart type) and LOSE all PR responsibilities.

**End state PR types (FROZEN spec, verbatim `pr_type` strings):** `one_rm`, `rep_specific`, `volume`,
`density`, `hypertrophy`, `time`, `consistency`, `endurance`, `load`, `distance`, `duration`, `speed`.
Family membership + `logTypeToFamily` per the FROZEN spec.

---

## Existing Code to Understand (read before modifying)

```
app/Enums/PRType.php                                   → the bitmask enum being DELETED; note every consumer of ::getTypes/::toArray/::combine/::isIn
app/Services/PRDetectionService.php                    → orchestrator: isLiftLogPR(), mapPRTypeStringToEnum() (deleted), enrichPRsWithPreviousPRIds(), snapshot builders
app/Services/PRRecalculationService.php                → recompute path (writes pr_type verbatim — mostly generic already)
app/Listeners/DetectAndRecordPRs.php                   → event listener that persists PRs (writes pr_type verbatim)
app/Services/ExerciseTypes/*ExerciseType.php           → getSupportedPRTypes()/calculateCurrentMetrics()/compareToPrevious()/formatPRDisplay()/comparisonValue() — all PR methods MOVE OUT to config+engine
app/Services/ExerciseTypes/BaseExerciseType.php        → the empty PR-method defaults — deleted along with the interface's PR methods
app/Services/ExerciseTypes/ExerciseTypeInterface.php   → the "PR DETECTION METHODS" section removed from the interface
app/Services/LiftLogTableRowBuilder/PRRecordsComponentAssembler.php → per-pr_type keying + getComparisonValue() switch → becomes descriptor-driven
config/exercise_types.php                              → per-type config; PR families do NOT go here (separate file), but logTypeToFamily lookup added
database/migrations/2026_01_22_113921_create_personal_records_table.php → original ENUM definition (context for the VARCHAR migration)
app/Models/PersonalRecord.php                          → pr_type cast/fillable; rep_count/weight key columns
```

---

## Shared Architecture (from the FROZEN root spec)

Two closed primitive tables + one writer. Both are pure and mirror the Athlete engine 1:1.

**Set-reduction primitives (Stage A)** — pure `(sets, descriptor, unitResolver) → metric`:
`maxOf`, `minOf`, `sumOf`, `sumProduct`, `estimated1RM`, `perKey`.

**Comparison primitives (Stage B)** — pure `(metric, storedBest, descriptor) → { isPR, delta }`:
`scalarBest(direction, tolerance)`, `keyedBest(direction, tolerance)`.

**Descriptor** (identical field names to Athlete — see FROZEN schema). Modifiers `suppressDominated`
and `minGroupSize` are declarative fields handled by one shared post-step each — NOT per-type code.

**Engine entry points (mirror Athlete's three functions):**
```
computeMetrics(LiftLog $log, string $family): array          // one loop over descriptors → REDUCTIONS[$d['reduce']]
detectPRs(array $metrics, ?array $history, string $family): array  // → { prs, reasons }; reasons ARE the "why not"
applyPRs(...)                                                 // persist by $d['store']; writes pr_type string verbatim
```

`reasons` shape (generic, no per-type labels in the engine):
`{ type, current, best, direction, tolerance, deltaToBeat }`. Labels come from `label`/`format` at
display time.

**Unit safety:** load/distance comparisons normalize via the existing `UnitResolver` (the sled/carry
plan already routed load through it). Distances integer-normalized to meters; durations integer seconds.
This must match the Athlete `toComparable` boundary — proven by the contract fixtures.

---

## Consumer Impact Trace (MANDATORY — this is a data-shape + column-semantics change, executor §13)

`pr_type` changes from ENUM to VARCHAR, and the detection return type changes from an int bitmask to a
list of type strings. Trace every reader before the change:

**Readers of `personal_records.pr_type` (column):**
- `PersonalRecord` model — `$fillable`, `casts()` (currently string-ish); no cast change needed for VARCHAR but confirm.
- `PRRecordsComponentAssembler` — beaten/current keying `if ($pr->pr_type === ...)` chains + `getComparisonValue()` switch → rewrite to descriptor lookup.
- `PRDetectionService` — `mapPRTypeStringToEnum()` (DELETE), `enrichPRsWithPreviousPRIds()` type-specific key filters → descriptor `keyFields`.
- `RestoreController` (sync) — emits `pr_type` verbatim (opaque pass-through — safe, but add a test asserting a non-legacy type round-trips).
- Any Blade/view rendering PR labels — grep `pr_type` across `resources/views`.
- Migrations `2026_*_*_pr_type*` — historical, DO NOT modify; the new migration supersedes the ENUM.

**Readers of the bitmask (`PRType: int`):**
- `LiftLog` PR flag column (if persisted) + `CreateLiftLogAction`/`UpdateLiftLogAction` using `PRType::toArray($flags)`.
- `PRType::getBestLabel()`/`getLabel()` consumers (celebration/label display) → move to config `label`.
- `isLiftLogPR()` return type + every caller checking `> 0` / `PRType::isPR()`.

**Tests asserting the old shape:** every test referencing `PRType::`, bitmask math, `ENUM`, or
`match($pr->pr_type)`. List and update in the same milestone that changes each producer. The prompt
enumerates these explicitly; the executor must not discover them.

---

## Migration (forward-only; the LAST PR migration — executor §6 + architect-workflow gate)

- **New migration** `ALTER`s `personal_records.pr_type` from `ENUM(...)` to `VARCHAR(32) NULL`
  (MySQL branch: `ALTER TABLE ... MODIFY COLUMN pr_type VARCHAR(32) NULL`; SQLite branch: drop/recreate
  as string, mirroring the pattern in `2026_08_30_142524_retype_sled_and_carry_exercises_to_load_output.php`).
  No data re-typing needed — existing string values are already valid VARCHAR content.
- Preserve the existing index `['user_id','exercise_id','pr_type']`.
- **Down migration** restores the ENUM with the exact current value list (from the sled/carry migration).
- **The migration MUST be RUN and verified** — `php artisan migrate` then `php artisan migrate:status`
  shows it **Ran**, plus a MySQL-side check that a novel `pr_type` string inserts without error. A green
  `RefreshDatabase` SQLite test does NOT prove the MySQL ENUM was actually dropped (rev-18 lesson).
- No app-facing data migration: PR records are recomputable via `PRRecalculationService` if ever needed,
  but this change does not alter stored values.

---

## Implementation Phases

1. **Config + primitives + engine (pure, unit-tested).** New `config/pr_families.php` (all 12 descriptors
   per FROZEN spec). New `app/Services/PR/` — `Reductions.php`, `Comparators.php`, `PrEngine.php`
   (computeMetrics/detectPRs/applyPRs). Full unit tests per primitive + per descriptor. Zero wiring.
2. **The ENUM→VARCHAR migration.** Author, RUN, verify `migrate:status` Ran + MySQL novel-string insert.
3. **Converge detection + delete the bitmask.** Point `PRDetectionService`/`PRRecalculationService`/
   `DetectAndRecordPRs` at `PrEngine`; delete `PRType` enum, `mapPRTypeStringToEnum`, and every
   `getSupportedPRTypes/calculateCurrentMetrics/compareToPrevious/formatPRDisplay/comparisonValue` on the
   strategies + their interface/base declarations. Behavioral-equivalence fixture set must match
   pre-refactor PR outputs before deletion.
4. **Generic display.** Rewrite `PRRecordsComponentAssembler` keying + comparison to descriptor-driven
   (`label`/`format`, `keyFields`). Delete the `switch`/if-elseif chains.
5. **Dead-code sweep + full suite.** Grep gates: zero `match ($pr->pr_type)`, zero `PRType::`, zero
   `getSupportedPRTypes`, zero ENUM reference outside the new migration. `php artisan test --parallel` green.

> Decomposed per executor §15 (touches 4+ consumers): Phase 1 is pure logic; Phases 3–4 are the
> integration sweep with the trace above. If Phase 3's equivalence gate can't go green, STOP — do not
> delete the strategies.

---

## Files Changed

New:
```
config/pr_families.php                         (12 descriptors + logTypeToFamily; mirrors athlete/src/shared/logging/prDescriptors.js)
app/Services/PR/Reductions.php                 (maxOf, minOf, sumOf, sumProduct, estimated1RM, perKey — pure)
app/Services/PR/Comparators.php                (scalarBest, keyedBest — pure)
app/Services/PR/PrEngine.php                   (computeMetrics, detectPRs, applyPRs — the one engine)
database/migrations/{ts}_change_pr_type_to_varchar.php
tests/Unit/PR/*                                (per-primitive, per-descriptor)
```
Modified:
```
app/Services/PRDetectionService.php            (delegate to PrEngine; delete mapPRTypeStringToEnum + bitmask assembly; return type strings)
app/Services/PRRecalculationService.php        (delegate to PrEngine)
app/Listeners/DetectAndRecordPRs.php           (persist string list)
app/Services/LiftLogTableRowBuilder/PRRecordsComponentAssembler.php (descriptor-driven; delete switch/if-elseif)
app/Services/ExerciseTypes/ExerciseTypeInterface.php (remove PR-detection method declarations)
app/Services/ExerciseTypes/BaseExerciseType.php (remove empty PR defaults)
app/Services/ExerciseTypes/*ExerciseType.php   (delete all PR methods)
app/Models/PersonalRecord.php                  (confirm cast/fillable for VARCHAR)
config/exercise_types.php                      (add logTypeToFamily lookup if not sourced from pr_families.php)
```
Deleted (after the equivalence gate is green):
```
app/Enums/PRType.php                           (bitmask enum — gone entirely)
```

---

## Success Criteria / Constraints

- One engine (`PrEngine`) driven by `config/pr_families.php`; NO `match($pr->pr_type)`, NO per-type
  `if/elseif`, NO per-type strategy PR methods anywhere (grep-verified).
- `pr_type` is `VARCHAR`; `PRType` bitmask enum deleted; detection returns type strings. Migration RUN
  and `migrate:status` shows Ran; a novel `pr_type` string inserts on MySQL.
- Adding a PR type requires ONLY a `config/pr_families.php` descriptor (+ Athlete parity + fixture) —
  demonstrated by a doc example, not built.
- Descriptor shape/field-names/order byte-for-semantic-byte identical to Athlete's `prDescriptors.js`.
- Behavioral equivalence: `PrEngine` detects the same PRs/values/reasons as the legacy strategies across
  a broad fixture set (incl. cross-unit + both density keyings) before any deletion.
- Zero dead code, zero backward-compat, zero commented blocks. `php artisan test --parallel` green.
- No new composer deps. No git commits. No Pint.

---

## Risks

- **R1 — Re-expressing 6 strategies' PR logic risks drift.** Mitigation: Phase-3 behavioral-equivalence
  fixture gate blocks deletion; existing suite is the second net.
- **R2 — The ENUM→VARCHAR migration is MySQL-vs-SQLite asymmetric.** Mitigation: dual-branch migration
  mirroring the sled/carry precedent; RUN on MySQL + verify a novel string inserts (SQLite green ≠ done).
- **R3 — Bitmask deletion has many callers** (`toArray`, `getBestLabel`, `isPR`, LiftLog flag column).
  Mitigation: the Consumer Impact Trace above enumerates them; update all in the converge milestone.
- **R4 — `consistency`/`suppressDominated` modifiers are subtle.** Mitigation: port as declarative
  descriptor fields with one shared implementation each; assert against captured legacy output.

---

## Prompt

Execution steps: `docs/plans/generic-pr-engine-prompt.md`.

---

## Post-Execution Retro

_(Reviewer-owned — leave placeholders until post-execution review.)_
- **Attempts:** {1 (clean) / N + root cause}
- **Tests added:** {count}
- **Migration verified Ran on MySQL:** {yes/no + evidence}
- **Prompt gap:** {what was missing}
- **Steering updates needed:** {yes/no + detail}
