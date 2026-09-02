# Global Execution Rules

1. Execute this plan sequentially, pausing to test ONLY at the designated Milestone checkpoints.
2. CRITICAL SELF-CORRECTION LOOP: at a testing step, run the test command; if it fails, read the errors,
   fix, and re-run within this turn until green. Do not yield with a failing milestone; do not ask for
   help with a test failure — fix it.
3. Do not finish your turn until the current milestone's tests pass completely.
4. When ALL milestones pass and all success criteria are met, write the Post-Execution Retro into THIS
   file (per `docs/antigravity-steering.md` §4), then print exactly:
   ```
   AGY_COMPLETE: All milestones passed.
   ```
5. CONTEXT BUDGET: never read a large file to change a few lines — use grep/line-range reads. See
   `docs/antigravity-steering.md` §11.

---

# PR Test Reduction — Logger Implementation Prompt

## Feature Classification

- [x] **Test-suite reduction** — no production behavior change. Deletes redundant per-family PR-detection
  calculation feature suites (the cross-app contract equivalence suite owns that correctness) and thins
  the pure engine spec to a bare-minimum anchor set, while keeping the HTTP+DB persistence/recalculation
  lifecycle and reason/event/display tests. No schema change, no migration, no new stored entity.

---

## Directional Isolation (hard boundary)

Stay entirely inside `logger/`. Do NOT read or modify `../athlete`, `../contracts`, or `../docs`. No test
may import the root contract generator. Everything you need is inside this repo.

---

## What You're Building

The cross-app PR-engine equivalence suite (at the workspace root — you do NOT read it) is now the
authority for exhaustive PR-calculation correctness, replaying the real `App\Services\PR\PrEngine` over
~600 generated scenarios across all five families. This slice reduces the Logger in-repo PR tests:

1. DELETE the redundant per-family PR-detection calculation feature suites.
2. THIN `tests/Unit/PR/PrEngineTest.php` to a ~7-anchor smoke set + one-assertion-per-primitive + the
   reason test.
3. KEEP everything the contract does not exercise: the HTTP+DB persistence/recalc lifecycle
   (`PREdgeCasesTest` + persistence/event/display/command/integration tests).

**Zero production behavior change.** Only tests change.

---

## Read These Files (in order, before writing any code)

### Steering & safety (READ FIRST)
```
docs/antigravity-steering.md             → §2 tool/bash safety, §3 no git, §4 verification (retro + AGY_COMPLETE), §11 context budget
.kiro/steering/safe-operations.md        → protected files, no Pint
```

### The plan (reference — DO NOT treat as steps)
```
docs/plans/pr-test-reduction.md          → what is kept vs deleted, exact Files Changed, the persistence-lift rule, risks
```

### Files to read before modifying
```
tests/Unit/PR/PrEngineTest.php           → identify anchors + one-per-primitive to keep vs permutations to drop
tests/Feature/PREdgeCasesTest.php        → the persistence lifecycle home (destination for any lifted case)
tests/Feature/BodyweightPRDetectionTest.php, CardioPRDetectionTest.php, ConsistencyPRDetectionTest.php,
tests/Feature/DensityPRDetectionTest.php, StaticHoldPRDetectionTest.php, VolumePRDetectionTest.php
                                         → scan each for unique HTTP+DB persistence assertions before deleting
```

---

## Milestone 1: Baseline

### Step 1 — capture the current green suite
```bash
php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt
```
Confirm the PR suites are green BEFORE changes so any later failure is attributable to this slice. Read
`.test-output.txt`; delete when confirmed.

---

## Milestone 2: Thin the pure engine spec to anchors

### Step 2 — reduce `tests/Unit/PR/PrEngineTest.php`
KEEP:
- The ~7 detection anchors: `test_weightlifting_family_computes_and_detects_core_types`,
  `test_static_hold_*` (one hold + one density-by-time), `test_cardio_family_endurance_and_volume`,
  `test_load_output_speed_fires_only_after_a_prior_bucket`, a bodyweight detect (add a minimal one if
  none exists), `test_mixed_unit_weight_comparison_is_correct` (mixed-unit anchor), and
  `test_non_pr_emits_a_structured_reason` (no-PR anchor + reason).
- ONE assertion per primitive: `maxOf`, `minOf`, `sumOf`, `sumProduct` (incl. the pure-bodyweight
  zero-weight case), `estimated1RM`, `perKey` (count / sumReps / maxValue / minValue), `scalarBest`
  (unit + percent tolerance + min-direction), `keyedBest` (requirePrevious + suppressDominated), and the
  load-role-token resolution + ft→m normalization anchors.

DELETE: any additional multi-permutation calculation cases beyond one anchor per behavior above
(e.g. repeated rep_specific/1RM/volume permutation variants). These are contract-owned.

### Milestone 2 Checkpoint
```bash
php artisan test --parallel tests/Unit/PR/PrEngineTest.php > .test-output.txt 2>&1; tail -30 .test-output.txt
```
Thinned spec green. Read `.test-output.txt`; delete when green.

---

## Milestone 3: Delete the redundant per-family feature suites

### Step 3 — scan-then-delete
For each of `Bodyweight`, `Cardio`, `Consistency`, `Density`, `StaticHold`, `Volume` `PRDetectionTest.php`:
scan for any HTTP+DB PERSISTENCE assertion (a `PersonalRecord` row / `is_pr` lifecycle unique to that
family) NOT covered by `PREdgeCasesTest`. If found, LIFT that single case into `PREdgeCasesTest.php`
(matching its factory/route style). Pure "detect these PRs from this log" cases are dropped. Then delete
the file.

### Milestone 3 Checkpoint
```bash
php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt
```
Full suite green with the per-family calculation suites removed (any lifted persistence case passing in
`PREdgeCasesTest`). Read `.test-output.txt`; delete when green.

---

## Milestone 4: Final verification

### Step 4 — full suite
```bash
php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt
```
Green, zero failures. Delete `.test-output.txt`.

### Step 5 — write the retro (per antigravity-steering §4), then print:
```
AGY_COMPLETE: All milestones passed.
```

---

## Success Criteria

- [ ] The six per-family `*PRDetectionTest.php` calculation suites deleted; any unique persistence case
      lifted into `PREdgeCasesTest.php`.
- [ ] `PrEngineTest.php` thinned to the ~7 anchors + one-per-primitive + the reason test.
- [ ] `PREdgeCasesTest` + persistence/event/display/command/integration tests intact.
- [ ] `php artisan test --parallel` green, zero failures.
- [ ] No `../athlete` / `../contracts` / `../docs` access; no git; no Pint; no new composer deps; no
      destructive DB commands.

## Do Not

- Do NOT delete or weaken `PREdgeCasesTest`, the persistence/model tests, event/display tests, the recalc
  command test, or the kg/load-output INTEGRATION tests — those are NOT redundant with the contract.
- Do NOT drop a family-specific PERSISTENCE assertion without lifting it into `PREdgeCasesTest`.
- Do NOT change production PR/engine code — only tests.
- Do NOT touch `../athlete`, `../contracts`, `../docs`, or import the root contract generator.
- Do NOT commit or push. Do NOT run Pint. Do NOT add composer deps. Do NOT run destructive DB commands.

## Post-Execution Retro (fill in after completion, then print AGY_COMPLETE)
- **Attempts:** 1 (clean)
- **Suites deleted:** BodyweightPRDetectionTest.php, CardioPRDetectionTest.php, ConsistencyPRDetectionTest.php, DensityPRDetectionTest.php, StaticHoldPRDetectionTest.php, VolumePRDetectionTest.php
- **Persistence cases lifted into PREdgeCasesTest:** 0 (all HTTP+DB persistence behaviors were already fully covered by PREdgeCasesTest, model tests, event system tests, and display tests)
- **PrEngineTest anchors/primitives kept:** 32 test methods (7 detection anchors across all 5 families + primitives + reason test)
- **Follow-up fixes needed:** 0
- **Prompt gap:** None
