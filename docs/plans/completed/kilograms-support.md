# Feature Plan: Support Kilograms (kg) Weight Unit — v2

## Overview

Currently, the application is configured globally with a default unit of Pounds (`lbs`), and multiple components and strategies hardcode this unit in display layouts.

One of our users requested support for Kilograms (`kg`). To support this in a robust and extensible way without mutating any historical database values, we will implement a **UnitResolver** service layer. This layer acts as the single source of truth for converting, formatting, and resolving units between the raw database values and the UI.

### Design Principles

1. **Store the unit alongside the value** — every weight value in the DB gets a `unit` column recording what unit it was logged in.
2. **Never mutate historical data** — old rows default to `'lbs'` via the column default.
3. **Convert at boundaries only** — conversion happens at input (form → DB) and output (DB → display). Internal calculations normalize to a common unit before comparing.
4. **Single responsibility** — all conversion/formatting logic lives in `UnitResolver`. No other class should do math on unit conversion.

---

## 1. UnitResolver Service (`App\Services\UnitResolver`)

### 1.1 Precision Standards
- **Pounds (`lbs`)**: Rounded to nearest **1 lb** — `round($weight)`
- **Kilograms (`kg`)**: Rounded to nearest **0.5 kg** — `round($weight * 2) / 2`

### 1.2 Known Limitation: Round-Trip Drift
Not all values survive lbs → kg → lbs cleanly. Example: 135 lbs → 61.5 kg → 136 lbs. This is acceptable — precision loss is bounded to ±1 lb / ±0.5 kg, and values are only converted for display, never written back.

### 1.3 Interface

```php
namespace App\Services;

use App\Models\User;

class UnitResolver
{
    public const LBS_TO_KG = 0.45359237;
    public const KG_TO_LBS = 2.20462262;

    /**
     * Convert value from one unit to another with target-specific rounding.
     */
    public function convert(float $value, string $from, string $to): float;

    /**
     * Format a value for display with its unit label.
     * lbs: 0 decimals for whole numbers. kg: 0 or 1 decimal (for .5 values).
     */
    public function format(float $value, string $unit): string;

    /**
     * Resolve user's preferred weight unit. Falls back to config default.
     */
    public function getPreferredWeightUnit(?User $user = null): string;

    /**
     * Convert a raw weight to the user's preferred unit and format for display.
     */
    public function formatForUser(float $value, string $sourceUnit, ?User $user = null): string;

    /**
     * Get the weight input step/increment for the user's preferred unit.
     * lbs: 5.0, kg: 2.5
     */
    public function getWeightIncrement(?User $user = null): float;

    /**
     * Get the weight input step attribute for HTML inputs.
     * lbs: 1, kg: 0.5
     */
    public function getWeightStep(?User $user = null): float;
}
```

### 1.4 Dependency Injection
Inject `UnitResolver` via constructor where possible. For static methods or model accessors where DI isn't feasible, use `app(UnitResolver::class)`. Minimize these cases.

---

## 2. Database Changes

A single migration, applied atomically:

### 2.1 `users` table
```php
$table->string('weight_unit', 10)->default('lbs')->after('prefill_suggested_values');
```

### 2.2 `lift_sets` table
```php
$table->string('unit', 10)->default('lbs')->after('weight');
```

### 2.3 `personal_records` table
```php
$table->string('unit', 10)->default('lbs')->after('weight');
```

### 2.4 `body_logs` table
```php
$table->string('unit', 10)->nullable()->after('value');
```
Rationale: If a user changes their preference to kg, past body logs (which were in lbs) would display wrong without knowing their original unit. Nullable because existing rows have no explicit unit and the system should infer `measurement_types.default_unit` for those.

### 2.5 Model Updates
- `User`: add `weight_unit` to `$fillable`. No cast needed (it's a plain string).
- `LiftSet`: add `unit` to `$fillable`.
- `PersonalRecord`: add `unit` to `$fillable`.
- `BodyLog`: add `unit` to `$fillable`.

---

## 3. Input Boundaries (Form → DB)

### 3.1 Creating Lift Logs (`CreateLiftLogAction`)
When creating lift sets, store the user's current preferred unit:
```php
$unitResolver = app(UnitResolver::class);
$userUnit = $unitResolver->getPreferredWeightUnit($user);

$liftLog->liftSets()->create([
    'weight' => $liftData['weight'],
    'unit' => $userUnit,
    // ... other fields
]);
```

### 3.2 Updating Lift Logs (`UpdateLiftLogAction`)
Same pattern — delete old sets, recreate with current user unit.

### 3.3 Saving Body Logs (`BodyLogController::store`)
Set the `unit` field based on the user's measurement type default unit:
```php
BodyLog::create([
    // ... existing fields
    'unit' => $measurementType->default_unit,
]);
```

### 3.4 Import Commands (`ImportJsonLiftLog`, `ImportWodifyLiftLog`)
These commands import external data that is always in lbs (legacy format). They must explicitly set `'unit' => 'lbs'` on created LiftSets. If future imports support other units, the JSON schema should include a unit field.

### 3.5 PR Creation (`DetectAndRecordPRs` listener, `PRRecalculationService`)
When creating `PersonalRecord` entries, set `unit` from the lift log's first set:
```php
'unit' => $liftLog->liftSets->first()->unit ?? 'lbs',
```

### 3.6 Profile Preferences (`ProfileController`)
Add `weight_unit` to the preferences update with validation:
```php
'weight_unit' => ['required', 'in:lbs,kg'],
```

### 3.7 Form HTML Inputs
Numeric weight inputs must use a dynamic `step` attribute based on user preference:
- lbs: `step="1"` (whole pounds)
- kg: `step="0.5"` (half kilograms)

Increment/decrement buttons should use the dynamic increment (5 lbs / 2.5 kg).

---

## 4. Output Boundaries (DB → Display)

### 4.1 Exercise Strategy `formatWeightDisplay()`
Every exercise type strategy must convert the stored weight to the user's preferred unit:
```php
public function formatWeightDisplay(LiftLog $liftLog): string
{
    $weight = $liftLog->display_weight;
    $loggedUnit = $liftLog->liftSets->first()->unit ?? 'lbs';
    return $this->unitResolver()->formatForUser($weight, $loggedUnit, $liftLog->user);
}
```

### 4.2 Edit Form Pre-filling
When loading a lift log for editing, convert the stored weight to the user's current preferred unit:
```php
$preferredUnit = $unitResolver->getPreferredWeightUnit($user);
$weight = $unitResolver->convert($firstSet->weight, $firstSet->unit ?? 'lbs', $preferredUnit);
```
This handles the case where a user logged in lbs, then changed to kg, then edits the old log.

### 4.3 PR Records Table / Comparison Values
The `PRRecordsComponentAssembler` must convert all comparison values (current metrics, previous bests) to the viewing user's preferred unit before display.

### 4.4 PR Cards (Heaviest Lifts)
`ExercisePRService::getPRData()` must normalize weights when finding the best weight per rep range. `ExercisePageService` must pass the user's preferred unit to `prCardsBuilder->card()` instead of hardcoded `'lbs'`.

### 4.5 1RM Calculator Grid
`ExercisePRService::getCalculatorGrid()` must:
- Compute all percentage weights in the user's preferred unit.
- Keep the grid cells unit-free (display only raw values) to maintain high data density on mobile.

### 4.6 Charts (1RM Progression, Volume)
The `OneRepMaxChartGenerator` currently uses `$liftLog->best_one_rep_max`, which calculates from raw `$liftSet->weight` without conversion. Fix: the chart generator receives the user context and normalizes all data points to the viewer's preferred unit so mixed-unit logs appear on the same scale.

### 4.7 Progression Model Auto-Detection (`BaseExerciseType`)
The progression pattern detection compares `display_weight` between two logs:
```php
$weightChange = $newer->display_weight - $older->display_weight;
```
If logs are in different units, this comparison is invalid. Fix: convert both to a common unit before comparing.

### 4.8 Feed / Notifications
When displaying PR details in the feed or notifications, format weights using `UnitResolver::formatForUser()` with the PR creator's preferred unit (not the viewer's), since the PR belongs to the creator.

### 4.9 Body Log Display
`BodyLogController::showByType()` and `MobileEntry/BodyLogService` display body log values with their measurement type's default unit. After this change, they should use the body log's own `unit` field (falling back to `measurementType->default_unit` for legacy rows).

### 4.10 Form Field Labels
The config `exercise_types.php` has hardcoded labels like `'Weight (lbs):'`. The form builder must dynamically substitute the unit in these labels based on user preference.

---

## 5. Internal Calculations (Normalization)

### 5.1 PR Detection (`compareToPrevious` in exercise type strategies)
When comparing current metrics against previous logs, all weights must be normalized to a common unit before comparison. The natural choice is the **current log's unit** (the one being evaluated for PR status):

```php
$targetUnit = $currentLog->liftSets->first()->unit ?? 'lbs';

// When iterating previous logs:
$loggedUnit = $previousLog->liftSets->first()->unit ?? 'lbs';
$convertedWeight = $unitResolver->convert($set->weight, $loggedUnit, $targetUnit);
```

This applies to all PR type checks: 1RM, volume, rep-specific, hypertrophy, density.

### 5.2 Volume Calculation
Total volume = Σ(weight × reps). When comparing volumes across logs in different units, normalize all weights to the same unit first.

### 5.3 Progression Suggestions (`LinearProgression`, `DoubleProgression`)
Load the last session's weight and unit, convert to user's preferred unit, apply unit-appropriate increment (5 lbs / 2.5 kg), then suggest.

### 5.4 Tolerance Values
Floating point comparisons use a tolerance. Scale tolerance to unit:
- lbs: `0.1` tolerance
- kg: `0.05` tolerance

### 5.5 Progression Model Auto-Detection
When comparing two logs to determine the progression pattern, normalize both weights to the same unit before computing the delta.

---

## 6. Implementation Phases

Each phase is a separate commit. Do NOT run Laravel Pint or any code formatter at any point during implementation. Do not reformat files you touch — only make functional changes. The developer handles formatting independently.

### Phase 1: Migration + Models
- [ ] Create migration adding columns to `users`, `lift_sets`, `personal_records`, `body_logs`
- [ ] Update model `$fillable` arrays: `User`, `LiftSet`, `PersonalRecord`, `BodyLog`
- [ ] Update `UserFactory` to include `weight_unit` (default `'lbs'`)
- [ ] Run migration, verify schema

### Phase 2: UnitResolver Service + Unit Tests
- [ ] Create `App\Services\UnitResolver`
- [ ] Write `tests/Unit/Services/UnitResolverTest.php` (see Test Strategy section for details)

### Phase 3: Preferences UI
- [ ] Add `weight_unit` select in `ProfileFormService` (options: Pounds/Kilograms)
- [ ] Add validation rule in `ProfileController::updatePreferences()`: `'weight_unit' => ['required', 'in:lbs,kg']`
- [ ] Write test: user can save preference, invalid value rejected

### Phase 4: Input Boundary — Storing Unit on Create/Update
- [ ] `CreateLiftLogAction::createLiftSets()` — store user's preferred unit
- [ ] `UpdateLiftLogAction::updateLiftSets()` — store user's preferred unit
- [ ] `BodyLogController::store()` — store measurement type's default unit
- [ ] `ImportJsonLiftLog` — explicitly set `'unit' => 'lbs'`
- [ ] `ImportWodifyLiftLog` — explicitly set `'unit' => 'lbs'`

### Phase 5: Output Boundary — Display Formatting
- [ ] Refactor `RegularExerciseType::formatWeightDisplay()` to use UnitResolver
- [ ] Refactor `BodyweightExerciseType::formatWeightDisplay()` to use UnitResolver
- [ ] Refactor `StaticHoldExerciseType::formatWeightDisplay()` to use UnitResolver
- [ ] Refactor edit form pre-fill (`LiftLogService::prepareEditDefaults()`) to convert to preferred unit
- [ ] Refactor `PRRecordsComponentAssembler::getComparisonValue()` to convert units
- [ ] Refactor `ExercisePRService::getPRData()` to normalize weights per user preference
- [ ] Refactor `ExercisePRService::getCalculatorGrid()` to pass unit label to component
- [ ] Fix `ExercisePageService` PR cards — pass dynamic unit instead of hardcoded `'lbs'`
- [ ] Fix `BodyweightExerciseType::formatProgressionSuggestion()` hardcoded strings
- [ ] Fix `BodyweightExerciseType::formatSuccessMessageDescription()` hardcoded `'lbs'`
- [ ] Fix `StaticHoldExerciseType::formatProgressionSuggestion()` hardcoded `'lbs'` (two occurrences)
- [ ] Fix `LiftLogService` progression suggestion message hardcoded `'lbs'`
- [ ] Fix `pr-feed-list.blade.php` hardcoded `lbs` string
- [ ] Make form field labels dynamic (replace `'Weight (lbs):'` with user's unit)
- [ ] Update form input `step` attributes to be dynamic based on user's unit
- [ ] Write unit tests: `UnitConversionInDisplayTest` — strategy formatting with different stored units

### Phase 6: Internal Calculations — PR Detection
- [ ] Refactor `RegularExerciseType::compareToPrevious()` to normalize previous log weights to current log's unit
- [ ] Refactor `RegularExerciseType::getBest1RM()` to convert weights
- [ ] Refactor `RegularExerciseType::getBestVolume()` to convert weights
- [ ] Refactor `RegularExerciseType::getBestWeightForReps()` to convert weights
- [ ] Refactor `RegularExerciseType::getBestRepsAtWeight()` to convert weights
- [ ] Refactor `RegularExerciseType::getBestSetsAtWeight()` to convert weights
- [ ] Refactor `BodyweightExerciseType::compareToPrevious()` similarly
- [ ] Update `DetectAndRecordPRs` listener to store unit on PersonalRecord creation
- [ ] Update `PRRecalculationService` to store unit on PersonalRecord creation
- [ ] Write unit tests: `UnitConversionInPRDetectionTest` — mixed unit PR comparison (in-memory models, no DB)

### Phase 7: Internal Calculations — Progression & Charts
- [ ] Refactor `LinearProgression::suggest()` to convert last session weight to user's unit
- [ ] Refactor `LinearProgression::suggestNextWeight()` to normalize all weights before 1RM estimation
- [ ] Refactor `DoubleProgression::suggest()` to convert last session weight to user's unit
- [ ] Use unit-specific resolution (5 lbs / 2.5 kg) for weight snapping in both models
- [ ] Refactor `BaseExerciseType` progression detection to normalize weights before comparing
- [ ] Refactor chart generators to normalize weights to viewer's preferred unit
- [ ] Refactor `ExercisePRService::getEstimated1RM()` to normalize
- [ ] Write unit tests: `UnitConversionInProgressionTest` — suggestion logic with unit conversion (in-memory models, no DB)

### Phase 8: Remaining Integrations
- [ ] Feed/notifications: format weights using PR creator's preferred unit
- [ ] `PRInformationService`: normalize weights when computing heaviest lifts and rep maxes
- [ ] `AnalyzeLiftLogs` command: respect unit columns in analytics
- [ ] Body log display: use `body_log.unit` when available, fall back to measurement type default
- [ ] Write integration test: `KilogramsSupportIntegrationTest` — full end-to-end workflow with unit switching (see Test Strategy section)

### Phase 9: Verification
- [ ] Verify all tests pass: `vendor/bin/phpunit`
- [ ] Do NOT run Pint or any code formatter — the developer will handle formatting separately

---

## 7. Acceptance Criteria

Each criterion must be verified by a test.

| # | Scenario | Expected |
|---|----------|----------|
| 1 | User saves preference to kg | `users.weight_unit` = 'kg' |
| 2 | Invalid unit value submitted | Validation error, preference unchanged |
| 3 | kg user creates a lift log | `lift_sets.unit` = 'kg', weight stored as entered |
| 4 | kg user views historical lbs log | Weight displayed converted to kg |
| 5 | kg user edits a log originally in lbs | Form pre-fills with kg-converted value |
| 6 | kg user logs 100kg after previous 200lbs best | PR detected (100kg > 200lbs) |
| 7 | kg user logs 80kg after previous 200lbs best | Not a PR (80kg < 200lbs) |
| 8 | PR records table shows comparison in user's unit | All values in kg for kg user |
| 9 | Feed shows PR weight in creator's preferred unit | Creator sees kg, even if viewer prefers lbs |
| 10 | Chart plots mixed-unit history on common scale | All points converted to viewer's unit |
| 11 | Progression suggests using 2.5kg increments for kg user | Suggestion ends in 2.5/5.0/7.5 etc |
| 12 | Progression detects pattern correctly across units | Weight delta normalized before comparison |
| 13 | Calculator grid shows unit label | "100 kg" not just "100" |
| 14 | Bodyweight suggestion uses correct unit string | "Consider adding 2-5 kg" for kg users |
| 15 | Import command sets unit=lbs on all imported sets | Imported data correctly tagged |
| 16 | User switches lbs→kg→lbs, all historical data intact | No values mutated, display adapts |

---

## 8. Exhaustive UI Locations Where "lbs" Appears

These are ALL places where the string `'lbs'` is rendered to users and MUST be replaced with dynamic unit resolution:

### Blade Templates

| File | Line | Current Output |
|------|------|----------------|
| `resources/views/mobile-entry/components/pr-feed-list.blade.php` | ~330 | `{{ number_format($weight, 0) }} lbs` |
| `resources/views/mobile-entry/components/calculator-grid.blade.php` | weight cells | Leave unit-free as-is (do not add unit suffix to maintain high density on mobile) |

### PHP Services / Controllers (user-facing output)

| File | Method | Current Output |
|------|--------|----------------|
| `app/Services/ExerciseTypes/BodyweightExerciseType.php` | `formatProgressionSuggestion()` | `'Consider adding 5-10 lbs extra weight'` |
| `app/Services/ExerciseTypes/BodyweightExerciseType.php` | `formatProgressionSuggestion()` | `"Try {$nextWeight} lbs extra weight"` |
| `app/Services/ExerciseTypes/BodyweightExerciseType.php` | `formatSuccessMessageDescription()` | `'+'.$weight.' lbs × '.$reps.' reps × '.$rounds.' sets'` |
| `app/Services/ExercisePageService.php` | PR cards section | Passes hardcoded `'lbs'` as unit arg to `prCardsBuilder->card()` |
| `app/Http/Controllers/FeedController.php` | Feed PR display | `number_format($bestSet->weight).' '.$unit` — uses raw `$bestSet->unit ?? 'lbs'` without converting to creator's preferred unit |
| `app/Services/MobileEntry/LiftLogService.php` | Progression suggestion message | `$suggestion->suggestedWeight . ' lbs × ' . $suggestion->reps . ' reps × '...` |
| `app/Services/ExerciseTypes/StaticHoldExerciseType.php` | `formatProgressionSuggestion()` | `"Try {$durationDisplay} +5 lbs × {$sets} sets"` (two occurrences) |

### Config (form labels shown to users)

| File | Key | Current Value |
|------|-----|---------------|
| `config/exercise_types.php` | `regular.field_labels.weight` | `'Weight (lbs):'` |
| `config/exercise_types.php` | `bodyweight.field_labels.weight` | `'Added Weight (lbs):'` |
| `config/exercise_types.php` | `static_hold.field_labels.weight` | `'Added Weight (lbs):'` |

### Internal/Debug (acceptable to leave as-is)

| File | Method | Note |
|------|--------|------|
| `app/Services/PRDetectionService.php` | `buildPRReason()`, `buildWhyNotPRReason()` | Debug snapshot messages — log the raw calculation unit, not user-facing |

---

## 9. Files to Touch (Comprehensive Audit)

### Models
- `app/Models/User.php` — add `weight_unit` to fillable
- `app/Models/LiftSet.php` — add `unit` to fillable
- `app/Models/PersonalRecord.php` — add `unit` to fillable
- `app/Models/BodyLog.php` — add `unit` to fillable

### Actions
- `app/Actions/LiftLogs/CreateLiftLogAction.php` — store unit on lift set creation
- `app/Actions/LiftLogs/UpdateLiftLogAction.php` — store unit on lift set creation

### Controllers
- `app/Http/Controllers/ProfileController.php` — validate and save `weight_unit`
- `app/Http/Controllers/BodyLogController.php` — store unit on body log creation
- `app/Http/Controllers/FeedController.php` — format PR weights using creator's preferred unit

### Listeners / Services (PR)
- `app/Listeners/DetectAndRecordPRs.php` — store unit on PersonalRecord
- `app/Services/PRRecalculationService.php` — store unit on PersonalRecord

### Exercise Type Strategies
- `app/Services/ExerciseTypes/RegularExerciseType.php` — unit conversion in compareToPrevious, getBest* methods, formatWeightDisplay
- `app/Services/ExerciseTypes/BodyweightExerciseType.php` — same + fix hardcoded strings in formatProgressionSuggestion and formatSuccessMessageDescription
- `app/Services/ExerciseTypes/StaticHoldExerciseType.php` — formatWeightDisplay conversion + fix hardcoded `'lbs'` in formatProgressionSuggestion
- `app/Services/ExerciseTypes/BaseExerciseType.php` — progression detection weight normalization, formatSuccessMessageDescription already dynamic (verify)
- `app/Services/ExerciseTypes/CardioExerciseType.php` — no weight, likely no changes
- `app/Services/ExerciseTypes/BandedExerciseType.php` — no weight, no changes
- `app/Services/ExerciseTypes/BandedResistanceExerciseType.php` — no weight, no changes
- `app/Services/ExerciseTypes/BandedAssistanceExerciseType.php` — no weight, no changes

### Progression
- `app/Services/ProgressionModels/LinearProgression.php` — convert weights, use unit-specific resolution
- `app/Services/ProgressionModels/DoubleProgression.php` — convert weights, use unit-specific resolution

### Display / Components
- `app/Services/MobileEntry/LiftLogService.php` — edit form pre-fill conversion + fix hardcoded `'lbs'` in progression suggestion message
- `app/Services/LiftLogTableRowBuilder/PRRecordsComponentAssembler.php` — convert comparison values
- `app/Services/ExercisePRService.php` — normalize in getPRData, getCalculatorGrid, getEstimated1RM
- `app/Services/ExercisePageService.php` — pass dynamic unit to PR cards builder
- `app/Services/MobileEntry/PRInformationService.php` — normalize heaviest lifts display
- `app/Presenters/LiftLogTablePresenter.php` — convert weights for table display
- `app/Services/ProfileFormService.php` — add weight_unit select field

### Charts
- `app/Services/Charts/OneRepMaxChartGenerator.php` — normalize to viewer's unit
- `app/Services/ChartService.php` — pass user context to generators

### Commands (Imports)
- `app/Console/Commands/ImportJsonLiftLog.php` — add `'unit' => 'lbs'` to LiftSet creation
- `app/Console/Commands/ImportWodifyLiftLog.php` — add `'unit' => 'lbs'` to LiftSet creation

### Views
- `resources/views/mobile-entry/components/pr-feed-list.blade.php` — replace hardcoded `lbs`
- `resources/views/mobile-entry/components/calculator-grid.blade.php` — no changes needed (leave unit-free to keep cells compact)

### Form / Frontend
- `public/js/mobile-entry.js` — dynamic step/increment on weight inputs
- `config/exercise_types.php` — field labels must be made dynamic or overridden at form build time

### Factories
- `database/factories/UserFactory.php` — add `weight_unit` default

---

## 10. Test Strategy

### Philosophy
**Maximize unit tests, minimize integration tests.** The architecture must be designed so that each component (UnitResolver, strategies, progression models, PR detection) is independently testable with plain unit tests — no HTTP requests, no database. Integration tests exist only to verify the wiring between components in a real request lifecycle.

### Framework & Conventions
- All tests use **PHPUnit** (not Pest). Create tests with `php artisan make:test --phpunit {name}` (pass `--unit` for unit tests).
- Use `RefreshDatabase` trait only in integration/feature tests.
- Use model factories in integration tests. Check existing factory states before manually setting up models.
- Follow `fake()` or `$this->faker` convention from existing tests (check siblings).

### Architecture for Testability
To enable unit testing without DB or HTTP:
- `UnitResolver` is a pure service with no dependencies — instantiate directly in tests.
- Exercise type strategies accept a `UnitResolver` instance (via constructor or method) — pass a real instance in unit tests (it has no side effects).
- Progression models accept injected services — test by calling `suggest()` with mocked data.
- PR comparison methods in strategies operate on Collections of models — build in-memory model instances with `new LiftLog()` / `new LiftSet()` and set attributes directly.

### Test File Organization

| File | Type | What It Tests |
|------|------|---------------|
| `tests/Unit/Services/UnitResolverTest.php` | Unit | Conversion math, formatting, rounding, increments, edge cases |
| `tests/Unit/Services/UnitConversionInPRDetectionTest.php` | Unit | PR comparison logic with mixed units (strategy methods, in-memory models) |
| `tests/Unit/Services/UnitConversionInProgressionTest.php` | Unit | Progression suggestion logic with unit conversion |
| `tests/Unit/Services/UnitConversionInDisplayTest.php` | Unit | Strategy `formatWeightDisplay()` methods with different stored units |
| `tests/Feature/KilogramsSupportIntegrationTest.php` | Feature | End-to-end workflow (see below) |

### Unit Tests (bulk of coverage)

**UnitResolver** (Phase 2):
- Identity conversion (same unit returns same value)
- lbs→kg rounding to nearest 0.5
- kg→lbs rounding to nearest 1
- Round-trip drift documentation (135 lbs → 61.5 kg → 136 lbs — assert and document)
- Format output: decimals, unit label, edge values (0, 0.5, whole numbers)
- Increment values (5.0 for lbs, 2.5 for kg)
- Step values (1 for lbs, 0.5 for kg)
- Case insensitivity ('LBS' treated same as 'lbs')
- Unknown unit passthrough (returns value unchanged)

**PR Detection with mixed units** (Phase 6):
- Build in-memory LiftLog + LiftSet collections (no DB)
- Call `compareToPrevious()` directly on the strategy
- Test: previous log 200 lbs, current log 100 kg → PR detected (100 kg ≈ 220 lbs > 200 lbs)
- Test: previous log 200 lbs, current log 80 kg → NOT a PR (80 kg ≈ 176 lbs < 200 lbs)
- Test: previous log 100 kg, current log 225 lbs → PR detected
- Test: all logs same unit → works as before (regression)
- Test: volume PR across units (normalize before summing)
- Test: rep-specific PR across units

**Display formatting** (Phase 5):
- Build in-memory LiftLog with unit='lbs', user with weight_unit='kg' → assert formatted output is converted
- Static hold with added weight conversion
- Bodyweight with extra weight conversion
- Zero weight edge case

**Progression suggestions** (Phase 7):
- Mock last session in lbs, user prefers kg → suggestion in kg with 2.5 increment
- Mock last session in kg, user prefers lbs → suggestion in lbs with 5.0 increment
- Progression model detection: compare two logs in different units, assert correct model selected

### Integration Test (minimal — one comprehensive test)

**File**: `tests/Feature/KilogramsSupportIntegrationTest.php`

**Single end-to-end scenario** that exercises the full wiring:

1. User starts with default `weight_unit = 'lbs'`
2. User logs a lift (200 lbs × 5 reps) → assert `lift_sets.unit = 'lbs'` in DB
3. User switches preference to `'kg'` via profile update
4. User views the previous lift log → assert display shows `~91 kg` (converted)
5. User logs a new lift (100 kg × 5 reps) → assert `lift_sets.unit = 'kg'` in DB
6. Assert PR detected (100 kg > 200 lbs when normalized)
7. User views exercise page → assert chart/calculator values are in kg
8. User switches preference back to `'lbs'`
9. User views the kg lift log → assert display shows `220 lbs` (converted back)
10. User views the original lbs lift log → assert display shows `200 lbs` (unchanged)
11. Assert progression suggestion is in lbs with 5 lb increment

This single integration test covers: preference saving, unit persistence on create, display conversion in both directions, PR detection across units, and preference switching without data loss.

### Running Tests
- Use `vendor/bin/phpunit` to run tests. Do NOT use `php artisan test`.
- After each phase, run the relevant unit test file(s): `vendor/bin/phpunit tests/Unit/Services/UnitResolverTest.php`
- To run a specific test method: `vendor/bin/phpunit --filter=testMethodName`
- At the end (Phase 9), run the full suite: `vendor/bin/phpunit`
- If existing tests break due to the new `unit` column, update their factories/assertions to account for the default `'lbs'` value.

---

## 11. What NOT to Do

- **Do NOT run Laravel Pint, `vendor/bin/pint`, or any code formatter.** Do not format, lint, or style any files — not even the ones you modify. The developer will handle formatting separately. This is non-negotiable.
- **Do NOT reformat or restyle existing code** that you are not functionally changing. Touch only what is necessary for the feature.
- **Do NOT store converted values.** The DB always holds the value as entered by the user, in their unit at that time.
- **Do NOT change the `display_weight` accessor to do conversion.** It returns raw values. Conversion happens at the call site where user context is available.
- **Do NOT use `env()` for unit defaults.** Use `config('exercise_types.display.weight_unit')`.
- **Do NOT add a `weight_unit` column to tables other than `users`.** The unit is tracked per-value (on `lift_sets`, `personal_records`, `body_logs`), not per-table.
- **Do NOT combine unrelated changes into a single commit.** Each commit maps to one phase.
