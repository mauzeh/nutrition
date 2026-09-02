# Global Execution Rules

1. Execute sequentially, testing ONLY at Milestone checkpoints.
2. SELF-CORRECTION LOOP: run the test command; if it fails, read errors, fix, re-run within this turn until
   green. Never yield on a failing milestone; never ask for help with a failure.
3. Do not finish your turn until the current milestone's tests pass completely.
4. When ALL milestones pass, write the Post-Execution Retro into THIS file (§4 of antigravity-steering),
   run the End-of-Run Cleanup Sweep, then print:
   ```
   AGY_COMPLETE: All milestones passed.
   ```
5. CONTEXT BUDGET: grep + line-range reads; temp `.php` script for >5-file mechanical edits (§11).
6. Run artisan with `--no-interaction`. NEVER run Pint, `migrate:fresh`, `migrate:reset`, or `db:wipe`.

---

# Bodyweight Volume Split — Logger Implementation Prompt

## Feature Classification

- [x] **PR-engine correctness change (config-driven, cross-app-mirrored).** Split the `bodyweight` family's
  single `volume` record into two mutually-exclusive `pr_type` tracks: weighted `volume` and bodyweight
  `bodyweight_volume`. Scope is the `bodyweight` family ONLY. `pr_type` is already VARCHAR(32) → NO migration.

## Directional Isolation

Stay inside `logger/`. Do NOT read or modify `../athlete`, `../contracts`, `../docs`. The Athlete engine is
mirrored SEPARATELY from an identical frozen spec; the shapes you need are inline below — do not reach up.

## FROZEN Spec (inline — single source of truth for this slice)

A `bodyweight`-family log is EITHER weighted (some set weight > 0) OR pure-bodyweight (all sets weight = 0).
Mutually exclusive → exactly one track fires per log.

| | Weighted (keep) | Bodyweight (new) |
|---|---|---|
| pr_type | `volume` | `bodyweight_volume` |
| factors | `['load','reps']` | `['reps']` |
| fires when | some set weight > 0 | all sets weight = 0 |
| value | Σ(weight×reps) | Σ(reps) |
| tolerance | `percent` | `none` |
| label / format | `Volume` / `volume` | `Total Reps` / `reps` |
| kg-converted? | yes (mass) | NO (rep count) |

`bodyweight` family descriptors (three total — keep `rep_specific` unchanged):
```php
[ 'type' => 'volume',            'reduce' => 'sumProduct', 'compare' => 'scalarBest',
  'factors' => ['load','reps'], 'mode' => 'weighted',   'unitField' => 'load',
  'direction' => 'max', 'tolerance' => 'percent', 'store' => 'scalar',
  'label' => 'Volume',     'format' => 'volume' ],
[ 'type' => 'bodyweight_volume', 'reduce' => 'sumProduct', 'compare' => 'scalarBest',
  'factors' => ['reps'],        'mode' => 'bodyweight',
  'direction' => 'max', 'tolerance' => 'none',    'store' => 'scalar',
  'label' => 'Total Reps', 'format' => 'reps' ],
```
Every OTHER family (weightlifting, cardio, static_hold, load_output) is UNTOUCHED. `mode` is bodyweight-only.

## Read These Files (in order, before writing code)
```
docs/antigravity-steering.md                                          → §2 safety, §4 verification+retro+sweep, §6 DB rules, §11 budget, §13 consumer trace
docs/plans/bodyweight-volume-split.md                                 → the plan (architecture, consumer trace, success criteria)
config/pr_families.php                                                → the bodyweight family (the config to split)
app/Services/PR/Reductions.php                                        → sumProduct + the allZeroWeight branch to remove
app/Services/PRDetectionService.php                                   → buildHistoryFromPreviousLogs, detectPRsWithDetails (the kg de-normalization in_array whitelist)
app/Services/PRRecalculationService.php                              → recalculateAllPRsForExercise + chainKey (confirm scalar chains by pr_type)
app/Services/LiftLogTableRowBuilder/PRRecordsComponentAssembler.php   → the display-time isPureBodyweight heuristic + resolveLabel + format (the "600 reps" site)
tests/Unit/PR/PrEngineTest.php                                        → where the engine anchors live
```
Grep (search tool) for `isPureBodyweight`, `bodyweightLabel`, and `'volume'` to enumerate every consumer
before editing.

---

## Milestone 1: Config + mode-aware reduction

### Step 1 — descriptors
In `config/pr_families.php`, in the `bodyweight` family: add `'mode' => 'weighted'` to the existing `volume`
descriptor and add the new `bodyweight_volume` descriptor per the frozen spec. Touch NO other family.

### Step 2 — mode-aware sumProduct
In `Reductions::sumProduct`, honor `$descriptor['mode']` (pass the descriptor/mode in if the signature
doesn't already carry it — match how other reducers receive the descriptor):
- `mode === 'weighted'` and all sets zero-weight → return null/skip (no metric).
- `mode === 'bodyweight'` and any set weight > 0 → return null/skip.
- The `bodyweight` descriptor's factors are `['reps']` → straight rep sum, no weight factor.
- REMOVE the `$allZeroWeight`→substitute-1 branch (it only served the merged slot). Descriptors with NO
  `mode` key behave exactly as before.

### Milestone 1 Checkpoint
```bash
php artisan test --parallel tests/Unit/PR 2>&1 > .test-output.txt; tail -40 .test-output.txt
```
Fix fallout from removing the substitute branch. Read `.test-output.txt`.

---

## Milestone 2: History fold + kg exclusion + display

### Step 3 — detection + kg whitelist
- `buildHistoryFromPreviousLogs` and `detectPRsWithDetails` dispatch off `compare`/`type` already — confirm
  the two scalar tracks fold into independent `$history['volume']` and `$history['bodyweight_volume']`.
- In `detectPRsWithDetails`, the kg de-normalization `in_array($pr['type'], [...])` list must NOT include
  `bodyweight_volume` (keep `one_rm, rep_specific, volume, load`; `bodyweight_volume` is a rep count).

### Step 4 — display from stored pr_type, not current weight
In `PRRecordsComponentAssembler`: replace the `$isPureBodyweight` heuristic (derived from the current log's
`exercise_type` + row `weight`) with a decision on the stored `$pr->pr_type`:
- `pr_type === 'volume'` → label "Volume", weight/volume format.
- `pr_type === 'bodyweight_volume'` → label "Total Reps", reps format.
A stored weighted `volume` (600) always renders "Volume", never "600 reps". Update `resolveLabel`/format
routing accordingly. Grep-confirm no other display path still uses the old heuristic.

### Consumer trace (per §13)
Update every reader surfaced by the grep: the kg whitelist, the assembler label/format, chart generators if
they special-case bodyweight volume, and any test asserting `pr_type='volume'` for a pure-bodyweight log.

### Milestone 2 Checkpoint
```bash
php artisan test --parallel 2>&1 > .test-output.txt; tail -40 .test-output.txt
```
Full suite green (web UI display tests included).

---

## Milestone 3: Correctness anchors + chain isolation (this app owns correctness)

### Step 5 — engine + persistence tests
In `tests/Unit/PR/PrEngineTest.php` (and a persistence/recalc feature test where appropriate) add anchors
asserting INTENDED outcomes (not cross-engine agreement — that is the root contract's job):
- **Dips anchor:** replay [weighted 25×6×4] then [pure-BW 5,7,8,8] with a prior bodyweight best of 24 → the
  second log fires a `bodyweight_volume` PR (value 28, previous 24); NO `volume` PR fires on it.
- **Weighted-only:** only `volume` records; never `bodyweight_volume`.
- **Bodyweight-only:** only `bodyweight_volume`; never `volume`.
- **Chain isolation:** after `recalculateAllPRsForExercise` on a history with both weighted and bodyweight
  sessions, the `volume` chain and the `bodyweight_volume` chain are independent (each has its own current
  row; neither supersedes the other).
- **Display:** `PRRecordsComponentAssembler` renders "Total Reps" for a `bodyweight_volume` row and "Volume"
  for a `volume` row; a bodyweight day never shows "600 reps".
- **kg pure-bodyweight:** a kg-unit pure-bodyweight log's `bodyweight_volume` value is NOT divided by the kg
  factor.

### Milestone 3 Checkpoint
```bash
php artisan test --parallel 2>&1 > .test-output.txt; tail -40 .test-output.txt
```

---

## Milestone 4: Retro + cleanup + completion

### Step 6 — Post-Execution Retro
Fill the `## Post-Execution Retro` placeholders at the bottom of THIS file via `str_replace` (§4).

### Step 7 — End-of-Run Cleanup Sweep (MANDATORY, §4 — inspection + grep, NO Pint)
Grep-confirm the `$allZeroWeight`→substitute-1 branch and the display-time `isPureBodyweight` heuristic are
fully removed (no stub, no dual-read). No unused `use` in touched files. Delete `.test-output.txt`.

### Step 8 — final run
```bash
php artisan test --parallel 2>&1 > .test-output.txt; tail -20 .test-output.txt
```
Green. Delete `.test-output.txt`. Print:
```
AGY_COMPLETE: All milestones passed.
```

## Success Criteria
- [ ] `bodyweight` family has two volume tracks: `volume` (weighted, Σ weight×reps, "Volume") and
      `bodyweight_volume` (Σ reps, "Total Reps"), mutually exclusive per log via `mode`.
- [ ] Dips anchor passes; weighted 600 renders "Volume", never "600 reps"; pure-BW 28 fires a
      `bodyweight_volume` PR (prev 24).
- [ ] `bodyweight_volume` excluded from kg de-normalization; substitute-1 branch and isPureBodyweight
      heuristic removed.
- [ ] `volume` and `bodyweight_volume` form independent supersession chains.
- [ ] Every other family byte-identical. `php artisan test --parallel` green. NO migration added (pr_type is
      varchar). No `../athlete`/`../contracts`/`../docs`. No git. No Pint. No new deps.

## Do Not
- Do NOT add a migration — `pr_type` is already VARCHAR(32); the new token needs none.
- Do NOT touch any family other than `bodyweight`.
- Do NOT keep the `allZeroWeight`→substitute-1 branch or the display-time `isPureBodyweight` heuristic.
- Do NOT kg-convert `bodyweight_volume`.
- Do NOT run `prs:calculate-historical` yourself (the user runs the data refresh post-deploy) — but DO make
  sure recalc WOULD split correctly (proven by the chain-isolation test).
- Do NOT touch `../athlete`/`../contracts`/`../docs`. Do NOT commit/push. Do NOT run Pint or destructive DB.

## Post-Execution Retro (fill after completion, per §4)
- **Attempts:** 1 (clean)
- **Display heuristic replaced by pr_type:** yes
- **Substitute-1 branch removed cleanly:** yes — grep returned 0 matches in app/
- **Chain isolation verified:** test_chain_isolation
- **Follow-up fixes needed:** 0
- **Prompt gap:** None
