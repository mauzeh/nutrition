# Exercise Types Usage Guide

This guide provides comprehensive documentation for working with the exercise type system, including examples for adding new exercise types and using the existing API.

## Table of Contents

1. [Overview](#overview)
2. [Using Existing Exercise Types](#using-existing-exercise-types)
3. [Adding New Exercise Types](#adding-new-exercise-types)
4. [Configuration Reference](#configuration-reference)
5. [API Reference](#api-reference)
6. [Best Practices](#best-practices)
7. [Troubleshooting](#troubleshooting)

## Overview

The exercise type system uses the Strategy Pattern to handle different types of exercises with type-specific behavior. This eliminates conditional logic throughout the application and provides a clean, extensible architecture.

### Available Exercise Types

- **Regular**: Traditional weight-based exercises (barbells, dumbbells, machines)
- **Banded**: Exercises using resistance or assistance bands
- **Bodyweight**: Exercises using body weight as primary resistance

### Key Components

- `ExerciseTypeInterface`: Defines the contract for all exercise types
- `BaseExerciseType`: Abstract base class with common functionality
- `ExerciseTypeFactory`: Creates appropriate strategy instances
- Configuration-driven type definitions in `config/exercise_types.php`

## Using Existing Exercise Types

### Basic Usage with Factory

```php
use App\Services\ExerciseTypes\ExerciseTypeFactory;

// Create strategy for an exercise
$strategy = ExerciseTypeFactory::create($exercise);

// Get validation rules
$rules = $strategy->getValidationRules($user);

// Process lift data
$processedData = $strategy->processLiftData($inputData);

// Format display
$weightDisplay = $strategy->formatWeightDisplay($liftLog);
$oneRmDisplay = $strategy->format1RMDisplay($liftLog);
```

### Safe Creation (Never Throws Exceptions)

```php
// Use createSafe() for guaranteed success
$strategy = ExerciseTypeFactory::createSafe($exercise);
// Always returns a valid strategy, falls back to RegularExerciseType if needed
```

### Validation Helper

```php
// Get validation rules for any exercise
$rules = ExerciseTypeFactory::validateExerciseData($exercise, $data, $user);
```

### Working with Specific Types

#### Regular Exercises

```php
$strategy = new RegularExerciseType();

// Process lift data
$data = [
    'weight' => '135',
    'reps' => '8',
    'band_color' => 'red' // Will be nullified
];
$processed = $strategy->processLiftData($data);
// Result: ['weight' => 135, 'reps' => 8, 'band_color' => null]

// Format display
echo $strategy->formatWeightDisplay($liftLog); // "135 lbs"
```

#### Banded Exercises

```php
$strategy = new BandedExerciseType();

// Process lift data
$data = [
    'weight' => '50', // Will be set to 0
    'band_color' => 'blue',
    'reps' => '10'
];
$processed = $strategy->processLiftData($data);
// Result: ['weight' => 0, 'band_color' => 'blue', 'reps' => 10]

// Format display
echo $strategy->formatWeightDisplay($liftLog); // "Band: Blue"

// Get progression suggestion
$suggestion = $strategy->formatProgressionSuggestion($liftLog);
// Might return: "Try green band with 8 reps"
```

#### Bodyweight Exercises

```php
$strategy = new BodyweightExerciseType();

// Process lift data (extra weight)
$data = [
    'weight' => '25', // Extra weight (weighted vest, etc.)
    'reps' => '8'
];
$processed = $strategy->processLiftData($data);
// Result: ['weight' => 25, 'reps' => 8, 'band_color' => null]

// Format display
echo $strategy->formatWeightDisplay($liftLog); // "Bodyweight +25 lbs"
echo $strategy->format1RMDisplay($liftLog); // "200 lbs (est. incl. BW)"
```

### Using in Controllers

```php
class LiftLogController extends Controller
{
    public function store(Request $request)
    {
        $exercise = Exercise::findOrFail($request->exercise_id);
        
        // Get validation rules from strategy
        $strategy = ExerciseTypeFactory::create($exercise);
        $rules = $strategy->getValidationRules(auth()->user());
        
        // Validate request
        $validated = $request->validate($rules);
        
        // Process data through strategy
        $processedData = $strategy->processLiftData($validated);
        
        // Create lift log
        $liftLog = LiftLog::create($processedData);
        
        return redirect()->route('lift-logs.index');
    }
}
```

### Using in View Components

```php
class LiftLogFormComponent extends Component
{
    public function __construct(LiftLog $liftLog = null)
    {
        $this->liftLog = $liftLog ?? new LiftLog();
        
        if ($this->liftLog->exists && $this->liftLog->exercise) {
            $strategy = $this->liftLog->exercise->getTypeStrategy();
            $this->formFields = $strategy->getFormFields();
            $this->validationRules = $strategy->getValidationRules();
        }
    }
    
    public function shouldShowField(string $field): bool
    {
        return in_array($field, $this->formFields);
    }
}
```

## Adding New Exercise Types

### Step 1: Create the Strategy Class

Create a new class that extends `BaseExerciseType`:

```php
<?php

namespace App\Services\ExerciseTypes;

use App\Models\LiftLog;
use App\Services\ExerciseTypes\Exceptions\InvalidExerciseDataException;

/**
 * Cable Exercise Type Strategy
 * 
 * Handles exercises using cable machines with adjustable weight stacks.
 * Similar to regular exercises but with cable-specific features.
 */
class CableExerciseType extends BaseExerciseType
{
    /**
     * Get the type name identifier
     */
    public function getTypeName(): string
    {
        return 'cable';
    }
    
    /**
     * Process lift data according to cable exercise rules
     */
    public function processLiftData(array $data): array
    {
        $processedData = $data;
        
        // Validate weight is present and numeric
        if (!isset($processedData['weight'])) {
            throw InvalidExerciseDataException::missingField('weight', $this->getTypeName());
        }
        
        if (!is_numeric($processedData['weight'])) {
            throw InvalidExerciseDataException::invalidWeight($processedData['weight'], $this->getTypeName());
        }
        
        // Cable exercises use weight in increments (usually 5-10 lbs)
        $increment = config('exercise_types.types.cable.weight_increment', 5);
        $processedData['weight'] = round($processedData['weight'] / $increment) * $increment;
        
        // Nullify band_color for cable exercises
        $processedData['band_color'] = null;
        
        return $processedData;
    }
    
    /**
     * Format weight display for cable exercises
     */
    public function formatWeightDisplay(LiftLog $liftLog): string
    {
        $weight = $liftLog->display_weight;
        
        if (!is_numeric($weight) || $weight <= 0) {
            return '0 lbs (cable)';
        }
        
        $unit = config('exercise_types.display.weight_unit', 'lbs');
        $formattedWeight = number_format($weight, 0); // Cable weights are usually whole numbers
        
        return $formattedWeight . ' ' . $unit . ' (cable)';
    }
    
    /**
     * Process exercise data according to cable exercise rules
     */
    public function processExerciseData(array $data): array
    {
        $processedData = $data;
        
        // Cable exercises are regular exercises
        $processedData['exercise_type'] = 'regular';
        
        // Set cable-specific flag
        $processedData['is_cable'] = true;
        
        return $processedData;
    }
}
```

### Step 2: Add Configuration

Add the new exercise type to `config/exercise_types.php`:

```php
'types' => [
    // ... existing types ...
    
    /**
     * Cable Exercise Type
     * 
     * Exercises using cable machines with weight stacks.
     * Similar to regular exercises but with cable-specific features.
     */
    'cable' => [
        'class' => \App\Services\ExerciseTypes\CableExerciseType::class,
        'validation' => [
            'weight' => 'required|numeric|min:0',
            'reps' => 'required|integer|min:1|max:100',
        ],
        'chart_type' => 'one_rep_max',
        'supports_1rm' => true,
        'form_fields' => ['weight', 'reps'],
        'progression_types' => ['linear', 'double_progression'],
        'display_format' => 'weight_lbs_cable',
        'weight_increment' => 5, // Cable machines typically use 5lb increments
    ],
],
```

### Step 3: Update Factory Logic (if needed)

If your exercise type requires custom detection logic, update the factory:

```php
// In ExerciseTypeFactory::determineExerciseType()
private static function determineExerciseType(Exercise $exercise): string
{
    // Check for cable exercise
    if ($exercise->is_cable) {
        return 'cable';
    }
    
    // Check exercise type directly
    if (str_contains($exercise->exercise_type, 'banded')) {
        return 'banded';
    }
    
    // Check for bodyweight exercise
    if ($exercise->exercise_type === 'bodyweight') {
        return 'bodyweight';
    }
    
    // Default to regular exercise
    return 'regular';
}
```

### Step 4: Add Database Migration (if needed)

If your exercise type requires new database fields:

```php
Schema::table('exercises', function (Blueprint $table) {
    $table->boolean('is_cable')->default(false);
});
```

### Step 5: Write Tests

Create comprehensive tests for your new exercise type:

```php
<?php

namespace Tests\Unit\Services\ExerciseTypes;

use App\Services\ExerciseTypes\CableExerciseType;
use Tests\TestCase;

class CableExerciseTypeTest extends TestCase
{
    private CableExerciseType $strategy;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new CableExerciseType();
    }
    
    public function test_get_type_name()
    {
        $this->assertEquals('cable', $this->strategy->getTypeName());
    }
    
    public function test_process_lift_data_rounds_to_increment()
    {
        $data = ['weight' => '137', 'reps' => '8'];
        $processed = $this->strategy->processLiftData($data);
        
        $this->assertEquals(135, $processed['weight']); // Rounded to nearest 5
        $this->assertEquals(8, $processed['reps']);
        $this->assertNull($processed['band_color']);
    }
    
    public function test_format_weight_display()
    {
        $liftLog = $this->createLiftLog(['weight' => 100]);
        $display = $this->strategy->formatWeightDisplay($liftLog);
        
        $this->assertEquals('100 lbs (cable)', $display);
    }
    
    // ... more tests
}
```

### Step 6: Update Documentation

Update this guide and any other relevant documentation to include your new exercise type.

## Configuration Reference

### Exercise Type Configuration Structure

```php
'type_name' => [
    'class' => 'Full\\Class\\Name',           // Required: Strategy class
    'validation' => [...],                    // Required: Laravel validation rules
    'chart_type' => 'chart_identifier',       // Required: Chart type for progress tracking
    'supports_1rm' => true|false,             // Required: Whether 1RM calculation is supported
    'form_fields' => [...],                   // Required: Fields to show in forms
    'progression_types' => [...],             // Required: Supported progression models
    'display_format' => 'format_identifier',  // Required: Display format identifier
    
    // Optional type-specific configuration
    'weight_increment' => 5,                  // Custom configuration for this type
    'subtypes' => [...],                      // Sub-type definitions if applicable
],
```

### Global Configuration Options

```php
'default_type' => 'regular',                  // Fallback when type can't be determined
'factory' => [
    'cache_strategies' => true,               // Enable strategy instance caching
    'fallback_type' => 'regular',             // Type to use when creation fails
],
'validation' => [
    'common_rules' => [...],                  // Rules applied to all types
],
'display' => [
    'weight_unit' => 'lbs',                   // Global weight unit
    'precision' => 1,                         // Decimal places for weight display
    'show_1rm_when_supported' => true,       // Show 1RM when available
],
```

## API Reference

### ExerciseTypeInterface Methods

#### `getValidationRules(?User $user = null): array`
Returns Laravel validation rules for this exercise type.

**Parameters:**
- `$user` (User|null): User context for user-specific rules

**Returns:** Array of Laravel validation rules

#### `getFormFields(): array`
Returns array of field names to display in forms.

**Returns:** Array of field names

#### `processLiftData(array $data): array`
Processes and validates lift log data according to exercise type rules.

**Parameters:**
- `$data` (array): Raw lift log data

**Returns:** Processed lift log data
**Throws:** `InvalidExerciseDataException` for invalid data

#### `processExerciseData(array $data): array`
Processes exercise creation/update data.

**Parameters:**
- `$data` (array): Raw exercise data

**Returns:** Processed exercise data

#### `canCalculate1RM(): bool`
Checks if this exercise type supports 1RM calculation.

**Returns:** Boolean indicating 1RM support

#### `getChartType(): string`
Gets the chart type identifier for progress tracking.

**Returns:** Chart type identifier string

#### `getSupportedProgressionTypes(): array`
Gets supported progression model identifiers.

**Returns:** Array of progression type identifiers

#### `formatWeightDisplay(LiftLog $liftLog): string`
Formats weight/resistance for display.

**Parameters:**
- `$liftLog` (LiftLog): Lift log to format

**Returns:** Formatted display string

#### `format1RMDisplay(LiftLog $liftLog): string`
Formats 1RM value for display.

**Parameters:**
- `$liftLog` (LiftLog): Lift log to format

**Returns:** Formatted 1RM display string
**Throws:** `UnsupportedOperationException` if 1RM not supported

#### `formatProgressionSuggestion(LiftLog $liftLog): ?string`
Provides progression suggestion based on lift log data.

**Parameters:**
- `$liftLog` (LiftLog): Lift log to analyze

**Returns:** Progression suggestion string or null

#### `getTypeName(): string`
Gets the unique type identifier.

**Returns:** Type name string

#### `getTypeConfig(): array`
Gets the full configuration array for this exercise type.

**Returns:** Configuration array

### ExerciseTypeFactory Methods

#### `create(Exercise $exercise): ExerciseTypeInterface`
Creates appropriate strategy for an exercise.

**Parameters:**
- `$exercise` (Exercise): Exercise to create strategy for

**Returns:** Exercise type strategy instance
**Throws:** Various exceptions for creation failures

#### `createSafe(Exercise $exercise): ExerciseTypeInterface`
Creates strategy with guaranteed success (never throws).

**Parameters:**
- `$exercise` (Exercise): Exercise to create strategy for

**Returns:** Exercise type strategy instance (falls back to RegularExerciseType)

#### `validateExerciseData(Exercise $exercise, array $data, $user = null): array`
Gets validation rules for an exercise with fallback.

**Parameters:**
- `$exercise` (Exercise): Exercise to validate for
- `$data` (array): Data to validate
- `$user` (User|null): User context

**Returns:** Laravel validation rules array

#### `getAvailableTypes(): array`
Gets all configured exercise type names.

**Returns:** Array of type name strings

#### `isTypeSupported(string $typeName): bool`
Checks if a type name is supported.

**Parameters:**
- `$typeName` (string): Type name to check

**Returns:** Boolean indicating support

#### `clearCache(): void`
Clears the strategy instance cache.

## Best Practices

### 1. Use the Factory Pattern

Always use `ExerciseTypeFactory::create()` instead of instantiating strategies directly:

```php
// Good
$strategy = ExerciseTypeFactory::create($exercise);

// Avoid
$strategy = new RegularExerciseType();
```

### 2. Handle Exceptions Gracefully

Use `createSafe()` when you need guaranteed success:

```php
// For critical paths where failure is not acceptable
$strategy = ExerciseTypeFactory::createSafe($exercise);

// For normal operations where you can handle exceptions
try {
    $strategy = ExerciseTypeFactory::create($exercise);
} catch (Exception $e) {
    // Handle the error appropriately
    Log::error('Strategy creation failed', ['exercise' => $exercise->id, 'error' => $e->getMessage()]);
    $strategy = new RegularExerciseType(); // Fallback
}
```

### 3. Validate Data Through Strategies

Always process data through the appropriate strategy:

```php
// Get validation rules from strategy
$rules = $strategy->getValidationRules($user);
$validated = $request->validate($rules);

// Process data through strategy
$processedData = $strategy->processLiftData($validated);
```

### 4. Configuration-Driven Development

Keep exercise type behavior configurable rather than hard-coded:

```php
// Good - configurable
$increment = config('exercise_types.types.cable.weight_increment', 5);

// Avoid - hard-coded
$increment = 5;
```

### 5. Comprehensive Testing

Test all aspects of your exercise type:

```php
// Test basic functionality
public function test_process_lift_data()
public function test_format_weight_display()
public function test_validation_rules()

// Test edge cases
public function test_invalid_data_throws_exception()
public function test_zero_weight_handling()

// Test integration
public function test_factory_creates_correct_strategy()
```

### 6. Clear Documentation

Document your exercise types thoroughly:

```php
/**
 * Cable Exercise Type Strategy
 * 
 * Handles exercises using cable machines with adjustable weight stacks.
 * 
 * Characteristics:
 * - Rounds weights to machine increments (typically 5 lbs)
 * - Supports 1RM calculation
 * - Uses cable-specific display formatting
 * - Compatible with linear progression models
 */
class CableExerciseType extends BaseExerciseType
```

## Troubleshooting

### Common Issues

#### Strategy Creation Fails

**Problem:** `ExerciseTypeFactory::create()` throws exceptions

**Solutions:**
1. Use `createSafe()` for guaranteed success
2. Check that the exercise type is properly configured
3. Verify the strategy class exists and implements the interface
4. Check logs for detailed error information

#### Validation Rules Not Applied

**Problem:** Form validation doesn't use exercise type rules

**Solutions:**
1. Ensure you're getting rules from the strategy: `$strategy->getValidationRules()`
2. Check that the exercise has the correct type properties set
3. Verify the strategy is being created correctly

#### Display Formatting Issues

**Problem:** Weight/resistance not displaying correctly

**Solutions:**
1. Check that `formatWeightDisplay()` is implemented correctly
2. Verify the lift log has the expected data structure
3. Check configuration for display settings

#### 1RM Calculation Errors

**Problem:** `UnsupportedOperationException` when trying to display 1RM

**Solutions:**
1. Check `canCalculate1RM()` before calling `format1RMDisplay()`
2. Verify the exercise type configuration has `supports_1rm: true`
3. Implement proper 1RM formatting in the strategy

### Debugging Tips

#### Enable Detailed Logging

Add logging to track strategy creation and usage:

```php
Log::info('Creating strategy for exercise', [
    'exercise_id' => $exercise->id,
    'exercise_type' => $exercise->getTypeStrategy()->getTypeName(),
]);
```

#### Use Factory Helper Methods

Check available types and support:

```php
$availableTypes = ExerciseTypeFactory::getAvailableTypes();
$isSupported = ExerciseTypeFactory::isTypeSupported('cable');
```

#### Inspect Configuration

Verify your configuration is loaded correctly:

```php
$config = config('exercise_types.types.cable');
dd($config); // Debug configuration
```

### Performance Considerations

#### Strategy Caching

The factory caches strategy instances by default. Clear cache when needed:

```php
ExerciseTypeFactory::clearCache();
```

#### Disable Caching in Tests

```php
// In test setup
config(['exercise_types.factory.cache_strategies' => false]);
```

## Migration Guide

### From Conditional Logic to Strategy Pattern

If you're migrating from conditional logic, follow this pattern:

**Before:**
```php
if ($exercise->exercise_type === 'bodyweight') {
    // Bodyweight logic
} elseif (str_contains($exercise->exercise_type, 'banded')) {
    // Banded logic
} else {
    // Regular logic
}
```

**After:**
```php
$strategy = ExerciseTypeFactory::create($exercise);
$result = $strategy->processLiftData($data);
```

### Updating Existing Code

1. Replace conditional logic with factory calls
2. Move type-specific behavior into strategy classes
3. Update tests to use strategies
4. Add configuration for new exercise types
5. Update documentation and examples

This completes the comprehensive usage guide for the exercise type system.