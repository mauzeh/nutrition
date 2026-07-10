# PR Types System

## Overview

The PR (Personal Record) detection system now supports multiple types of PRs using a modern PHP 8.2+ enum-based bitwise flag system. This allows a single lift to be recognized for multiple PR achievements simultaneously.

## PR Types

### Currently Implemented

1. **ONE_RM (1)** - Estimated one-rep max PR
   - Label: "🎉 NEW PR!"
   - Triggered when the estimated 1RM exceeds previous best

2. **REP_SPECIFIC (2)** - Rep-specific PR (1-10 reps)
   - Label: "🏆 Rep PR!"
   - Triggered when weight for a specific rep count exceeds previous best
   - Only applies to 1-10 rep ranges

3. **VOLUME (4)** - Total volume PR
   - Label: "💪 Volume PR!"
   - Triggered when total session volume (weight × reps × sets) exceeds previous best
   - Calculated as sum of all sets in a single lift log

4. **DENSITY (8)** - Work density PR
   - Label: "⚡ Density PR!"
   - Triggered when you complete more sets at a specific weight/duration than ever before
   - Weight-specific for regular exercises, duration-specific for static holds

5. **TIME (16)** - Time-based PR
   - Label: "⏱️ Time PR!"
   - Triggered when you achieve the longest single hold duration
   - Primarily used for static hold exercises (L-sits, planks, etc.)

6. **ENDURANCE (32)** - Endurance PR
   - Label: "🔥 Endurance PR!"
   - Triggered for cardio exercises when you achieve the longest total duration

7. **CONSISTENCY (64)** - Consistency PR (NEW!)
   - Label: "🎯 Consistency PR!"
   - Triggered when you maintain a higher minimum across all sets in a session
   - **Example**: 5 rounds of L-sit with times [20s, 18s, 15s, 17s, 15s] = 15s minimum
   - This is a PR if you've never maintained at least 15s across all 5 sets before
   - Only applies to multi-set sessions (2+ sets)
   - Compares sessions with same or more sets

### Future PR Types (Placeholders)

None currently - all planned PR types have been implemented!

## Technical Implementation

### Enum Structure

```php
enum PRType: int
{
    case NONE = 0;
    case ONE_RM = 1;
    case REP_SPECIFIC = 2;
    case VOLUME = 4;
    // ... more types
}
```

### Bitwise Operations

Multiple PR types can be combined using bitwise OR:
```php
$prFlags = PRType::ONE_RM->value | PRType::VOLUME->value; // = 5
```

Check for specific PR type:
```php
if (PRType::VOLUME->isIn($prFlags)) {
    // Has volume PR
}
```

### Backward Compatibility

The system maintains backward compatibility with existing code:

- Returns `int` instead of `bool` from `PRDetectionService::isLiftLogPR()`
- `0` = no PR (falsy in boolean context)
- `>0` = has PR (truthy in boolean context)
- Existing `if ($isPR)` checks continue to work
- Session storage works with integer flags

### Display Priority

When multiple PR types are achieved, the label shown follows this priority:
1. ONE_RM
2. REP_SPECIFIC
3. VOLUME
4. CONSISTENCY
5. DENSITY
6. TIME
7. ENDURANCE

## Usage Examples

### Checking for Any PR

```php
$prFlags = $prDetectionService->isLiftLogPR($liftLog, $exercise, $user);

if ($prFlags) {
    // This is a PR!
    $label = PRType::getBestLabel($prFlags);
}
```

### Checking for Specific PR Type

```php
if (PRType::VOLUME->isIn($prFlags)) {
    // This is a volume PR
}
```

### Getting All PR Types

```php
$types = PRType::getTypes($prFlags);
// Returns array of PRType enums
```

### Combining PR Types

```php
$flags = PRType::combine(PRType::ONE_RM, PRType::VOLUME);
// Returns combined bitwise flags
```

## Volume PR Calculation

Volume is calculated as:
```
Total Volume = Σ (weight × reps) for all sets in a lift log
```

Example:
- Set 1: 100 lbs × 5 reps = 500 lbs
- Set 2: 100 lbs × 5 reps = 500 lbs  
- Set 3: 100 lbs × 5 reps = 500 lbs
- **Total Volume: 1500 lbs**

A volume PR is achieved when the current session's total volume exceeds all previous sessions for that exercise.

## Consistency PR Calculation (Static Holds)

Consistency PR tracks the highest minimum hold duration maintained across all sets in a session. This is particularly useful for static hold exercises like L-sits, planks, handstands, etc.

**How it works:**
1. Find the shortest (minimum) hold duration across all sets in the current session
2. Compare to the best minimum from previous sessions with the same or more sets
3. Award PR if current minimum exceeds previous best minimum

**Example Progression:**

**Week 1:** L-sit holds: [15s, 12s, 10s, 14s, 13s]
- Minimum: 10s across 5 sets

**Week 2:** L-sit holds: [20s, 18s, 15s, 17s, 15s]
- Minimum: 15s across 5 sets
- **🎯 Consistency PR!** (15s > 10s)

**Week 3:** L-sit holds: [22s, 15s, 19s]
- Minimum: 15s across 3 sets
- **🎯 Consistency PR!** (First time maintaining 15s across 3 sets)

**Why this matters:**
- Measures your ability to maintain quality across all sets
- Prevents "one good set" from masking overall fatigue
- Encourages sustainable progression
- Particularly valuable for skill-based holds where consistency matters

**Key Rules:**
- Only applies to sessions with 2+ sets (single sets can't measure consistency)
- Compares to sessions with same or more sets
- Independent of TIME PR (you can get both simultaneously)

## Badge Display

PR badges are displayed in lift log tables when a log contains any PR type. The badge shows "🏆 PR" regardless of which specific PR type(s) were achieved.

## Testing

Comprehensive test coverage includes:
- Individual PR type detection
- Multiple PR types in single lift
- Volume PR specific scenarios
- Backward compatibility with existing tests
- All 1677 tests passing

See `tests/Feature/VolumePRDetectionTest.php` for volume PR examples.
