# Generic PR Engine (Logger) — Prompt for Antigravity CLI

## Before You Start

Read these in order. They contain everything needed to implement this correctly.

### 1. Steering (project rules — always follow)
```
docs/antigravity-steering.md            → executor contract: NEVER commit, NEVER Pint, §6 DB rules, §13 consumer trace, §15 decomposition, milestones, AGY_COMPLETE
.kiro/steering/safe-operations.md       → files never to touch, artisan/bash safety (Pint ban wins)
.kiro/steering/project-conventions.md   → forward-only migrations, no column repurposing, domain folders, event dispatch
.kiro/steering/laravel-boost.md         → Eloquent not DB::, constructor promotion, explicit return types, PHPUnit-only
```

### 2. Feature Spec (what to build)
```
docs/plans/generic-pr-engine.md                          → this app's plan (architecture, consumer trace, migration, phases)
../../docs/plans/generic-pr-engine-cross-repo.md         → FROZEN spec + primitive tables (READ, do not edit — source of truth)
```

### 3. Existing Code to Understand (read before modifying)
```
app/Enums/PRType.php                                     → bitmask enum being DELETED — note every caller
app/Services/PRDetectionService.php                      → orchestrator (isLiftLogPR, mapPRTypeStringToEnum→delete, enrich, snapshot)
app/Services/PRRecalculationService.php                  → recompute path
app/Listeners/DetectAndRecordPRs.php                     → persists PRs
app/Services/ExerciseTypes/*ExerciseType.php             → PR methods to DELETE (getSupportedPRTypes/calculateCurrentMetrics/compareToPrevious/formatPRDisplay/comparisonValue)
app/Services/ExerciseTypes/{BaseExerciseType,ExerciseTypeInterface}.php → PR method declarations to remove
app/Services/LiftLogTableRowBuilder/PRRecordsComponentAssembler.php → per-type keying + getComparisonValue switch
app/Models/PersonalRecord.php                            → pr_type fillable/cast, rep_count/weight
database/migrations/2026_08_30_142524_retype_sled_and_carry_exercises_to_load_output.php → the ENUM branch pattern to mirror in reverse
```

### 4. Reference (already implemented — study, don't rebuild)
```
../../athlete/src/shared/logging/prDescriptors.js        → the descriptor shape your config/pr_families.php MUST mirror (field names + order)
```

---

## What You're Building

Replace Logger's per-exercise-type imperative PR logic with one generic, config-driven engine. PR
behavior becomes data in a new `config/pr_families.php` (structurally identical to Athlete's
`prDescriptors.js`), interpreted by two closed primitive tables plus one writer. Simultaneously
de-enumerate storage: `personal_records.pr_type` ENUM → VARCHAR, and delete the `PRType` bitmask enum.

End state: adding a PR type is a config edit + a contract fixture — never a migration, never a new PHP
method, never an enum case. ZERO backward-compat: no kept ENUM, no bitmask shim, no dual path, no
leftover per-type strategy method, no dead formatter branch. The exercise-type strategies keep
validation/form/chart responsibilities and lose ALL PR responsibilities.

---

## Execution Plan

Follow the phases in `docs/plans/generic-pr-engine.md` in order.

### Phase order:
1. **Config + primitives + engine (pure)** — `config/pr_families.php`, `app/Services/PR/{Reductions,Comparators,PrEngine}.php` + unit tests. Zero wiring.
2. **Checkpoint** — `php artisan test --parallel`
3. **ENUM→VARCHAR migration** — author, RUN, verify `migrate:status` Ran + MySQL novel-string insert.
4. **Converge detection + delete bitmask** — delegate services to `PrEngine`; delete `PRType` + `mapPRTypeStringToEnum` + all strategy PR methods. Behavioral-equivalence fixtures green BEFORE deletion.
5. **Checkpoint** — `php artisan test --parallel`
6. **Generic display** — `PRRecordsComponentAssembler` descriptor-driven; delete switch/if-elseif.
7. **Dead-code sweep + final checkpoint** — grep gates + `php artisan test --parallel`

---

## Consumer Impact Trace (execute the trace in the plan before each producer change)

Before changing `pr_type` semantics or deleting the bitmask, update every reader listed in
`docs/plans/generic-pr-engine.md` → "Consumer Impact Trace" in the SAME milestone:
- `PersonalRecord` (fillable/cast), `PRRecordsComponentAssembler`, `PRDetectionService`,
  `RestoreController` (add a round-trip test for a non-legacy `pr_type`), any Blade rendering `pr_type`.
- Bitmask readers: `LiftLog` PR-flag column + `Create/UpdateLiftLogAction` (`PRType::toArray`),
  `PRType::getBestLabel/getLabel` consumers, `isLiftLogPR()` return-type callers (`> 0` checks).
- Update every test referencing `PRType::`, bitmask math, ENUM, or `match($pr->pr_type)` in the same
  milestone that changes its producer.

---

## HARD RULES — NEVER VIOLATE THESE:

- **NEVER commit / add / push.** No git commands.
- **NEVER run Pint** (`vendor/bin/pint` in any form).
- **NEVER run destructive DB commands** — no `migrate:fresh`, `migrate:reset`, `db:wipe`. Use `migrate`.
- **NEVER modify a migration that has already run.** The ENUM→VARCHAR change is a NEW migration.
- **NEVER leave the old ENUM, the bitmask, or any per-type PR method as a "fallback."** This refactor has
  zero backward-compat by mandate.

---

## Implementation Rules

- **New engine code in `app/Services/PR/`.** PR families config in `config/pr_families.php`.
- **Always `php artisan test --parallel`** (2000+ tests; parallel ~11s). Single file:
  `php artisan test tests/Unit/PR/PrEngineTest.php`. Filter: `--filter=testName`.
- **Never `DB::` facade** — Eloquent models/relationships.
- **PHP 8 constructor promotion** + **explicit return types** on all new methods.
- **PHPUnit only** (`make:test --phpunit`), never Pest.
- **The migration is dual-branch** (MySQL `ALTER ... MODIFY COLUMN pr_type VARCHAR(32) NULL`; SQLite
  drop/recreate as string), preserving the `['user_id','exercise_id','pr_type']` index; down restores the
  current ENUM value list. RUN it and verify `migrate:status` shows Ran + a novel string inserts on MySQL.
- **`config()` not `env()`.** Descriptor field names/order must match Athlete's `prDescriptors.js` exactly.

---

## Success Criteria

- [ ] `config/pr_families.php` holds all 12 descriptors per the FROZEN spec; field names + order mirror `prDescriptors.js`.
- [ ] `app/Services/PR/PrEngine.php` computes/detects/writes generically via `REDUCTIONS`/`COMPARATORS`; no per-type branching.
- [ ] `grep` shows ZERO `match ($pr->pr_type)`, ZERO `PRType::`, ZERO `getSupportedPRTypes`/`compareToPrevious`/`calculateCurrentMetrics` on strategies, ZERO `ENUM` reference outside the new migration.
- [ ] `pr_type` is VARCHAR; `PRType` enum deleted; migration RUN + `migrate:status` Ran + MySQL novel-string insert verified.
- [ ] Behavioral equivalence vs legacy strategies proven on the fixture set (incl. cross-unit + both density keyings) before deletion.
- [ ] `PRRecordsComponentAssembler` renders from descriptor `label`/`format`; switch/if-elseif deleted.
- [ ] All tests pass (`php artisan test --parallel`). No git commits. No Pint. No new composer deps.

---

## Do Not

- Do NOT commit or push. Do NOT run Pint. Do NOT run destructive DB commands.
- Do NOT keep the ENUM, the bitmask, or any per-type PR method as a fallback.
- Do NOT put PR families in `config/exercise_types.php` — they get their own `config/pr_families.php`.
- Do NOT re-decide any FROZEN spec name/direction/tolerance/family — read them from the root plan.
- Do NOT print `AGY_COMPLETE` if the equivalence gate is red or the migration is not verified Ran.

---

## Post-Execution Retro (authored by the REVIEWER, not the executor)

> Do NOT fill this in. The reviewer replaces the placeholders during post-execution review; the archival
> gate refuses to move plan+prompt to `completed/` until every field is filled.

- **Attempts:** {1 (clean) / N — root cause}
- **Follow-up fixes needed:** {0 / count — commit subjects}
- **Tests added:** {count}
- **Migration verified Ran on MySQL:** {yes/no + evidence (migrate:status line + novel-string insert)}
- **Prompt gap:** {file + what was missing}
- **Steering updates needed:** {yes/no — what rule would have prevented it}
