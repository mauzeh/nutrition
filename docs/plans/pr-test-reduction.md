# PR Test Reduction (Logger)

> **Scope:** Logger-only. Reference architecture; execution steps live in `pr-test-reduction-prompt.md`.
> This slice implements the Logger portion of a cross-repo reduction whose FROZEN policy lives at the
> workspace root — but per directional isolation you do NOT read the root doc from here; its policy is
> duplicated inline below.
>
> **Directional isolation:** Stay entirely inside `logger/`. Do NOT read or touch `../athlete`,
> `../contracts`, or `../docs`. No test may import the root contract generator.

---

## Before You Start

Read (project steering):
```
.kiro/steering/safe-operations.md        → files never to edit, bash safety, Pint ban
.kiro/steering/project-conventions.md    → conventions, testing
docs/antigravity-steering.md             → §2 tool safety, §4 verification, §9 plan format, §11 context budget
```

## What You're Building

The cross-app PR-engine equivalence suite (at the workspace root — not readable from here) now proves the
Logger (PHP) and Athlete (JS) PR engines produce identical PR sets over ~600 auto-generated log-sequence
scenarios across all five families. It is the authority for exhaustive PR-calculation correctness, and it
already replays the real Logger `App\Services\PR\PrEngine` over every scenario.

That makes Logger's hand-authored per-family PR-detection feature suites and the calculation-permutation
portions of the pure engine spec **redundant** — they re-assert calculation the contract owns more
thoroughly. This slice reduces them to a bare-minimum anchor set, keeping everything the contract does NOT
exercise: the HTTP+DB persistence/recalculation lifecycle and the reason/event/highlighting behavior.

> **Precedent:** `tests/Unit/PR/PrEngineTest.php` already absorbed the deleted
> `UnitConversionInPRDetectionTest` and `LoadOutputExerciseTypeTest`. This slice continues that same
> consolidation, now driven by the contract equivalence suite.

## Reduction policy (duplicated inline from the FROZEN spine)

1. **Contract equivalence suite is the correctness authority.** No Logger test needs to re-prove engine
   calculation correctness.
2. **Keep a ~7-anchor smoke set** in `tests/Unit/PR/PrEngineTest.php`: one per-family detection anchor
   (`weightlifting`, `bodyweight`, `cardio`, `static_hold`, `load_output`), one mixed-unit anchor
   (kg vs stored lbs), one no-PR/negative anchor. A fast, DB-free local gate.
3. **Delete** the redundant calculation coverage (see Files Changed).
4. **Keep** everything the contract does NOT exercise (see below).

## What is KEPT (NOT redundant with the contract)

The contract replays the PURE engine only (no DB, no HTTP, no persistence). These stay:
- `tests/Feature/PREdgeCasesTest.php` — full HTTP+DB lifecycle: store/update/delete/backdate → recalc,
  `is_pr` flag, `PersonalRecord` rows, chain recalculation. Persistence + recalc orchestration.
- `tests/Unit/Models/PersonalRecordTest.php`, `tests/Unit/Models/LiftLogPRTest.php` — model behavior.
- `tests/Feature/PRDetectionLoggingTest.php`, `tests/Feature/PREventSystemTest.php` — event dispatch +
  logging side effects.
- `tests/Feature/PRInfoDisplayTest.php`, `tests/Feature/ExercisePRHighlightingTest.php` — display/web UI.
- `tests/Feature/CalculateHistoricalPRsCommandTest.php` — the recalc command.
- `tests/Feature/KilogramsSupportIntegrationTest.php`, `tests/Feature/LoadOutputIntegrationTest.php` —
  these are INTEGRATION (HTTP+DB) tests. KEEP the integration/persistence assertions; only remove any
  block that is purely re-asserting engine calculation already covered by the contract (judgement per the
  Execution Plan). When in doubt, KEEP.
- The "why not" reason coverage in `PrEngineTest.php` (`test_non_pr_emits_a_structured_reason`) — KEEP;
  reasons are Logger-only and not asserted cross-app.

## Files Changed

Thin (keep the file, reduce to anchors):
```
tests/Unit/PR/PrEngineTest.php
  KEEP: the ~7 anchors (per-family detect + mixed-unit + no-PR) and test_non_pr_emits_a_structured_reason.
  KEEP: enough Reductions/Comparators primitive tests to anchor each primitive ONCE (maxOf, minOf, sumOf,
        sumProduct, estimated1RM, perKey count/sumReps/maxValue/minValue, scalarBest tolerances/min,
        keyedBest requirePrevious/suppressDominated) — these are the PHP analogue of Athlete's kept
        primitive tests; they are cheap and not permutation sweeps. KEEP one assertion per primitive.
  DELETE: redundant multi-permutation calculation cases beyond one anchor per behavior.
```

Delete (redundant per-family calculation feature suites — contract-owned):
```
tests/Feature/BodyweightPRDetectionTest.php
tests/Feature/CardioPRDetectionTest.php
tests/Feature/ConsistencyPRDetectionTest.php
tests/Feature/DensityPRDetectionTest.php
tests/Feature/StaticHoldPRDetectionTest.php
tests/Feature/VolumePRDetectionTest.php
```
> Before deleting each, scan it: if it contains any HTTP+DB PERSISTENCE assertion not covered elsewhere
> (e.g. a `PersonalRecord` row / `is_pr` flag lifecycle unique to that family), LIFT that one case into
> `PREdgeCasesTest.php` rather than losing it. Pure "detect these PRs from this log" cases are dropped.

## Success Criteria / Constraints

- Per-family calculation feature suites deleted (or their unique persistence cases lifted to
  `PREdgeCasesTest`).
- `PrEngineTest.php` thinned to the ~7 anchors + one-per-primitive + the reason test.
- `PREdgeCasesTest` + persistence/event/display/command tests intact.
- `php artisan test --parallel` green. No `../athlete` / `../contracts` / `../docs` access. No git. No Pint.
  No new composer deps. No destructive DB commands.

## Risks

- **Over-deletion of persistence coverage.** The per-family feature suites are mostly HTTP+DB. Only the
  ENGINE-CALCULATION assertions are contract-redundant; a family-specific PERSISTENCE/recalc assertion is
  not. Scan before deleting; lift unique persistence cases into `PREdgeCasesTest`.
- **Integration tests are not calculation tests.** `KilogramsSupportIntegrationTest` /
  `LoadOutputIntegrationTest` exercise the wire+DB path; keep them. When unsure, KEEP.

## Changelog
- rev 1 (2026-09-01) — authored. Reduce Logger PR-calculation tests to a ~7-anchor smoke set in
  `PrEngineTest.php`, delete the redundant per-family detection feature suites (lifting any unique
  persistence case into `PREdgeCasesTest`), keep the HTTP+DB lifecycle + reason/event/display tests.
  Continues the consolidation `PrEngineTest.php` began.
