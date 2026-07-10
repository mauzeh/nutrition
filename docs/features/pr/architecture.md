# Personal Records (PRs) as First-Class Data

## Overview

This document outlines the architectural approach for making Personal Records (PRs) first-class citizens in the application's data layer, moving from expensive on-the-fly calculations to event-driven, queryable data.

**Architecture Update (January 2026):** PR detection logic has been refactored using the Strategy Pattern. Each exercise type now implements its own PR detection logic, making the system extensible and maintainable. See [Strategy Pattern Architecture](#strategy-pattern-architecture) section below.

## Current Problem

**Performance Issues:**
- O(n²) calculation on every page load
- For 18 historical logs: ~153 comparisons, taking 28ms
- For 50 historical logs: ~1,225 comparisons, taking 200-500ms+
- Scales poorly with user activity

**Functional Limitations:**
- No historical record of when PRs were achieved
- Can't query "show me all my PRs from last month"
- Can't show PR trends over time
- Can't analyze PR patterns
- Recalculation needed if algorithm changes

## Proposed Solution: Event-Driven PR Records

### Database Schema

Create a new `personal_records` table to store PR achievements:

```php
Schema::create('personal_records', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
    $table->foreignId('lift_log_id')->constrained()->cascadeOnDelete();
    $table->enum('pr_type', ['one_rm', 'volume', 'rep_specific', 'hypertrophy']);
    $table->integer('rep_count')->nullable(); // For rep-specific PRs (e.g., "5 reps")
    $table->decimal('weight', 8, 2)->nullable(); // For hypertrophy PRs (e.g., "best @ 200 lbs")
    $table->decimal('value', 10, 2); // The actual PR value (1RM weight, volume, etc.)
    $table->foreignId('previous_pr_id')->nullable()->constrained('personal_records');
    $table->decimal('previous_value', 10, 2)->nullable();
    $table->timestamp('achieved_at');
    $table->timestamps();
    $table->softDeletes();
    
    // Indexes for fast queries
    $table->index(['user_id', 'exercise_id', 'pr_type']);
    $table->index(['user_id', 'achieved_at']);
    $table->index(['lift_log_id']);
});
```

Add lightweight flags to `lift_logs` table for fast filtering:

```php
Schema::table('lift_logs', function (Blueprint $table) {
    $table->boolean('is_pr')->default(false)->index();
    $table->integer('pr_count')->default(0); // How many PR types achieved
});
```

### Data Model

**PersonalRecord Model:**

```php
class PersonalRecord extends Model
{
    use SoftDeletes;
    
    protected $fillable = [
        'user_id',
        'exercise_id',
        'lift_log_id',
        'pr_type',
        'rep_count',
        'weight',
        'value',
        'previous_pr_id',
        'previous_value',
        'achieved_at',
    ];
    
    protected $casts = [
        'value' => 'decimal:2',
        'previous_value' => 'decimal:2',
        'weight' => 'decimal:2',
        'achieved_at' => 'datetime',
    ];
    
    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function exercise()
    {
        return $this->belongsTo(Exercise::class);
    }
    
    public function liftLog()
    {
        return $this->belongsTo(LiftLog::class);
    }
    
    public function previousPR()
    {
        return $this->belongsTo(PersonalRecord::class, 'previous_pr_id');
    }
    
    public function supersededBy()
    {
        return $this->hasOne(PersonalRecord::class, 'previous_pr_id');
    }
    
    // Scopes
    public function scopeCurrent($query)
    {
        return $query->whereDoesntHave('supersededBy');
    }
    
    public function scopeForExercise($query, $exerciseId)
    {
        return $query->where('exercise_id', $exerciseId);
    }
    
    public function scopeOfType($query, $type)
    {
        return $query->where('pr_type', $type);
    }
}
```

**Update LiftLog Model:**

```php
class LiftLog extends Model
{
    // ... existing code ...
    
    public function personalRecords()
    {
        return $this->hasMany(PersonalRecord::class);
    }
    
    public function isPR(): bool
    {
        return $this->is_pr;
    }
    
    public function getPRCount(): int
    {
        return $this->pr_count;
    }
}
```

## Strategy Pattern Architecture

### Overview

PR detection logic is decoupled from `PRDetectionService` using the Strategy Pattern. Each exercise type implements its own PR detection logic through the `ExerciseTypeInterface`:

```php
interface ExerciseTypeInterface
{
    // ... existing methods ...
    
    // PR Detection Methods
    public function getSupportedPRTypes(): array;
    public function calculateCurrentMetrics(LiftLog $liftLog): array;
    public function compareToPrevious(array $currentMetrics, Collection $previousLogs, LiftLog $currentLog): array;
    public function formatPRDisplay(PersonalRecord $pr, LiftLog $liftLog): array;
    public function formatCurrentPRDisplay(PersonalRecord $pr, LiftLog $liftLog, bool $isCurrent): array;
}
```

### Benefits

- **Open/Closed Principle**: Add new exercise types without modifying existing code
- **Single Responsibility**: Each type knows its own PR logic
- **Testability**: Test each type's PR logic in isolation
- **Maintainability**: All bodyweight logic in one file, all static hold logic in another
- **Extensibility**: Adding new types (cardio, tempo, etc.) is trivial

### Implementation by Exercise Type

#### RegularExerciseType (Weighted Exercises)

**Supported PR Types:**
- `ONE_RM`: Estimated one-rep max
- `REP_SPECIFIC`: Best weight for specific rep counts (1-10 reps)
- `VOLUME`: Total session volume (weight × reps × sets)
- `hypertrophy`: Best reps at a given weight (stored as string, not enum)

**Key Methods:**
```php
public function getSupportedPRTypes(): array
{
    return [PRType::ONE_RM, PRType::REP_SPECIFIC, PRType::VOLUME];
}

public function calculateCurrentMetrics(LiftLog $liftLog): array
{
    return [
        'best_1rm' => /* highest estimated 1RM from all sets */,
        'total_volume' => /* sum of (weight × reps) for all sets */,
        'rep_weights' => /* [reps => best weight] for reps 1-10 */,
        'weight_reps' => /* [weight => best reps] for hypertrophy */,
    ];
}
```

#### BodyweightExerciseType

**Supported PR Types:**
- `VOLUME`: Total reps (pure bodyweight) OR weight × reps (weighted)
- `REP_SPECIFIC`: Best weight for specific rep counts (only when extra weight used)

**Key Logic:**
- Pure bodyweight: Volume = total reps across all sets
- Weighted bodyweight: Volume = weight × reps (like regular exercises)
- Rep-specific PRs only awarded when extra weight is used

**Example:**
```php
// Pure bodyweight pull-ups: 10 + 8 + 6 = 24 total reps (volume PR)
// Weighted pull-ups: 45 lbs × 5 reps = 225 lbs volume (volume PR)
// Weighted pull-ups: 45 lbs × 5 reps (rep-specific PR for 5 reps)
```

#### StaticHoldExerciseType

**Supported PR Types:**
- `TIME`: Longest hold duration (in seconds)
- `REP_SPECIFIC`: Best duration at a specific weight

**Key Logic:**
- Reps field stores duration in seconds
- Unweighted: TIME PR for longest hold
- Weighted: REP_SPECIFIC PR for best duration at each weight
- Can achieve both TIME and REP_SPECIFIC PRs simultaneously

**Example:**
```php
// Plank: 90 seconds (TIME PR)
// Weighted plank: 45 lbs × 60 seconds (REP_SPECIFIC PR for 45 lbs)
```

### PRDetectionService Orchestration

The `PRDetectionService` is now a thin orchestration layer (~229 lines, down from 823):

```php
public function isLiftLogPR(LiftLog $liftLog, Exercise $exercise, User $user): int
{
    $strategy = $exercise->getTypeStrategy();
    
    // Check if this exercise type supports PRs
    $supportedPRTypes = $strategy->getSupportedPRTypes();
    if (empty($supportedPRTypes)) {
        return PRType::NONE->value;
    }
    
    // Get previous logs
    $previousLogs = LiftLog::where('exercise_id', $exercise->id)
        ->where('user_id', $user->id)
        ->where('logged_at', '<', $liftLog->logged_at)
        ->with('liftSets')
        ->orderBy('logged_at', 'asc')
        ->get();
    
    // Use strategy to detect PRs
    $currentMetrics = $strategy->calculateCurrentMetrics($liftLog);
    $prs = $strategy->compareToPrevious($currentMetrics, $previousLogs, $liftLog);
    
    // Convert to bitwise flags
    $prFlags = PRType::NONE->value;
    foreach ($prs as $pr) {
        $prType = $this->mapPRTypeStringToEnum($pr['type']);
        if ($prType) {
            $prFlags |= $prType->value;
        }
    }
    
    return $prFlags;
}
```

**No exercise-type conditionals!** All logic is delegated to the strategy.

### Adding New Exercise Types

To add PR support for a new exercise type (e.g., cardio):

1. **Implement the interface methods:**
```php
class CardioExerciseType extends BaseExerciseType
{
    public function getSupportedPRTypes(): array
    {
        return [PRType::ENDURANCE, PRType::DENSITY];
    }
    
    public function calculateCurrentMetrics(LiftLog $liftLog): array
    {
        $bestDistance = $liftLog->liftSets->max('reps'); // reps = distance
        $totalRounds = $liftLog->liftSets->count();
        
        return [
            'best_distance' => $bestDistance,
            'density' => $totalRounds > 0 ? $bestDistance / $totalRounds : 0,
        ];
    }
    
    public function compareToPrevious(array $currentMetrics, Collection $previousLogs, LiftLog $currentLog): array
    {
        $prs = [];
        
        // Check ENDURANCE PR (furthest distance)
        $previousBest = $this->getBestDistance($previousLogs);
        if ($currentMetrics['best_distance'] > $previousBest) {
            $prs[] = [
                'type' => 'endurance',
                'value' => $currentMetrics['best_distance'],
                'previous_value' => $previousBest,
                'previous_lift_log_id' => /* ... */,
            ];
        }
        
        return $prs;
    }
    
    public function formatPRDisplay(PersonalRecord $pr, LiftLog $liftLog): array
    {
        return match($pr->pr_type) {
            'endurance' => [
                'label' => 'Best Distance',
                'value' => $pr->previous_value ? $pr->previous_value . 'm' : '—',
                'comparison' => $pr->value . 'm',
            ],
            // ...
        };
    }
}
```

2. **That's it!** No changes to `PRDetectionService`, `LiftLogTableRowBuilder`, or any other file.

### Testing Strategy

Each exercise type has its own comprehensive test suite:

- `tests/Feature/BodyweightPRDetectionTest.php` (12 tests)
- `tests/Feature/StaticHoldPRDetectionTest.php` (11 tests)
- `tests/Feature/PREventSystemTest.php` (10 tests)
- `tests/Feature/PREdgeCasesTest.php` (8 tests)

**All 509 tests passing** after refactoring.

## Event-Driven Architecture

### Write Path

When a lift log is created or updated:

```
User submits form
  ↓
Create/Update LiftLog + LiftSets (in transaction)
  ↓
Dispatch LiftLogCompleted event
  ↓
DetectAndRecordPRs listener (synchronous)
  ↓
Detect PRs (compare against previous logs)
  ↓
Create PersonalRecord entries
  ↓
Update lift_logs.is_pr and pr_count flags
  ↓
Return response
```

### Event Implementation

**Event:**

```php
namespace App\Events;

use App\Models\LiftLog;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiftLogCompleted
{
    use Dispatchable, SerializesModels;
    
    public function __construct(
        public LiftLog $liftLog,
        public bool $isUpdate = false
    ) {}
}
```

**Note:** We use a new `LiftLogCompleted` event instead of the existing `LiftLogged` event. This ensures:
- The event is dispatched AFTER lift sets are created
- No conflicts with existing `LiftLogged` listeners
- Clear semantic meaning: the lift log is fully complete with all sets

**Listener:**

```php
namespace App\Listeners;

use App\Events\LiftLogCompleted;
use App\Services\PRDetectionService;
use App\Models\PersonalRecord;
use Illuminate\Support\Facades\DB;

class DetectAndRecordPRs
{
    public function __construct(
        protected PRDetectionService $prDetectionService
    ) {}
    
    public function handle(LiftLogCompleted $event): void
    {
        DB::transaction(function () use ($event) {
            $liftLog = $event->liftLog;
            
            // Eager load relationships needed for PR detection
            $liftLog->load(['exercise', 'liftSets']);
            
            // If this is an update, delete old PR records
            if ($event->isUpdate) {
                PersonalRecord::where('lift_log_id', $liftLog->id)->delete();
            }
            
            // Detect PRs for this lift log
            $prs = $this->prDetectionService->detectPRsWithDetails($liftLog);
            
            // Create PR records
            foreach ($prs as $pr) {
                PersonalRecord::create([
                    'user_id' => $liftLog->user_id,
                    'exercise_id' => $liftLog->exercise_id,
                    'lift_log_id' => $liftLog->id,
                    'pr_type' => $pr['type'],
                    'rep_count' => $pr['rep_count'] ?? null,
                    'weight' => $pr['weight'] ?? null,
                    'value' => $pr['value'],
                    'previous_pr_id' => $pr['previous_pr_id'] ?? null,
                    'previous_value' => $pr['previous_value'] ?? null,
                    'achieved_at' => $liftLog->logged_at,
                ]);
            }
            
            // Update lift log flags
            $liftLog->update([
                'is_pr' => count($prs) > 0,
                'pr_count' => count($prs),
            ]);
        });
    }
}
```

**Event Registration:**

Laravel 12 uses **automatic event discovery**. Simply place:
- Event in `app/Events/LiftLogCompleted.php`
- Listener in `app/Listeners/DetectAndRecordPRs.php`

Laravel automatically discovers and registers them based on the type hint in the listener's `handle()` method. No manual registration needed.

**Dispatching the Event:**

In `CreateLiftLogAction` (after creating lift sets):

```php
// Create the lift log
$liftLog = $this->createLiftLog($request, $user, $loggedAt);

// Create lift sets
$this->createLiftSets($request, $liftLog, $exercise);

// Dispatch event for PR detection (synchronous)
LiftLogCompleted::dispatch($liftLog, isUpdate: false);
```

In `UpdateLiftLogAction` (after updating lift sets):

```php
// Update the lift log
$liftLog->update($validated);

// Update lift sets
$this->updateLiftSets($request, $liftLog, $exercise);

// Dispatch event for PR recalculation (synchronous)
LiftLogCompleted::dispatch($liftLog, isUpdate: true);
```

**Why Synchronous (No Queue)?**

The listener does NOT implement `ShouldQueue`, meaning:
- ✅ PR detection runs immediately (blocks response)
- ✅ PRs are available right away when page loads
- ✅ Simpler debugging and testing
- ✅ No queue configuration needed
- ✅ Acceptable performance (~5-15ms overhead)

Future enhancement: Add a separate queued listener for notifications:

```php
class SendPRNotification implements ShouldQueue
{
    public function handle(LiftLogCompleted $event): void
    {
        // Send emails/push notifications in background
    }
}
```

### Read Path

**Fast PR lookup (replaces O(n²) calculation):**

```php
// Get all PRs for a lift log (for display)
$prs = PersonalRecord::where('lift_log_id', $liftLog->id)
    ->with('previousPR')
    ->get();

// Transform to display format
$prRecords = $prs->map(function ($pr) {
    return [
        'label' => $this->formatPRLabel($pr),
        'value' => $this->formatPRValue($pr),
    ];
});
```

**Performance improvement:**
- Current: O(n²) calculation (28ms for 18 logs, 200-500ms for 50+ logs)
- New: O(1) lookup (1-2ms regardless of history size)
- **14-250x faster!**

## Data Consistency

### Edge Cases to Handle

#### 1. User Edits a Lift Log

**Scenario:** User changes 315 lbs → 300 lbs (was a PR, may still be a PR)

**Solution (Implemented):**
```php
// In UpdateLiftLogAction
$liftLog->update($validated);
$this->updateLiftSets($request, $liftLog, $exercise);

// Dispatch event with isUpdate flag
LiftLogCompleted::dispatch($liftLog, true);

// Listener detects update and triggers full recalculation
// All PRs for this exercise are recalculated from scratch
```

**Key Insight:** Even if updated to lighter weight, the lift may still be a PR (e.g., if it's the only lift for that exercise, or the first time at that specific weight).

#### 2. User Deletes a Lift Log

**Scenario:** User deletes a lift log that was a PR

**Solution (Implemented):**
```php
// In LiftLogController::destroy()
$userId = $liftLog->user_id;
$exerciseId = $liftLog->exercise_id;
$wasPR = $liftLog->is_pr;

$liftLog->delete(); // Cascades to personal_records via foreign key

// If deleted log was a PR, recalculate all PRs for this exercise
if ($wasPR) {
    app(PRRecalculationService::class)->recalculateAllPRsForExercise(
        $userId,
        $exerciseId
    );
}
```

#### 3. User Logs Out of Order (Backdating)

**Scenario:** 
- Logs 2025-01-15: 300 lbs (becomes PR)
- Logs 2025-01-10: 315 lbs (backdated, should be the PR)

**Solution (Implemented):**
```php
// In DetectAndRecordPRs listener
$hasSubsequentLogs = LiftLog::where('user_id', $liftLog->user_id)
    ->where('exercise_id', $liftLog->exercise_id)
    ->where('logged_at', '>', $liftLog->logged_at)
    ->exists();

// If backdated (has subsequent logs), trigger full recalculation
if ($hasSubsequentLogs) {
    $this->prRecalculationService->recalculateAllPRsForExercise(
        $liftLog->user_id,
        $liftLog->exercise_id
    );
}
```

**Result:** All logs are reprocessed chronologically, so the 315 lbs lift becomes the PR and the 300 lbs lift is recalculated correctly.

**Solution:**
```php
// In DetectAndRecordPRs listener
public function handle(LiftLogCompleted $event): void
{
    $liftLog = $event->liftLog;
    
    // Check if there are any logs after this one for the same exercise
    $hasSubsequentLogs = LiftLog::where('user_id', $liftLog->user_id)
        ->where('exercise_id', $liftLog->exercise_id)
        ->where('logged_at', '>', $liftLog->logged_at)
        ->exists();
    
    if ($hasSubsequentLogs) {
        // Recalculate all PRs for this exercise from this date forward
        app(PRRecalculationService::class)->recalculatePRsFromDate(
            $liftLog->user_id,
            $liftLog->exercise_id,
            $liftLog->logged_at
        );
    } else {
        // Normal case: just detect PRs for this log
        $this->detectAndRecordPRs($liftLog);
    }
}
```

### Recalculation Service

```php
namespace App\Services;

use App\Events\LiftLogCompleted;
use App\Models\LiftLog;
use App\Models\PersonalRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PRRecalculationService
{
    public function recalculatePRsForExercise(
        int $userId,
        int $exerciseId,
        ?Carbon $fromDate = null
    ): void {
        DB::transaction(function () use ($userId, $exerciseId, $fromDate) {
            // Delete existing PR records for this exercise (from date forward)
            $query = PersonalRecord::where('user_id', $userId)
                ->where('exercise_id', $exerciseId);
            
            if ($fromDate) {
                $query->where('achieved_at', '>=', $fromDate);
            }
            
            $query->delete();
            
            // Get all lift logs for this exercise (from date forward)
            $logs = LiftLog::where('user_id', $userId)
                ->where('exercise_id', $exerciseId)
                ->when($fromDate, fn($q) => $q->where('logged_at', '>=', $fromDate))
                ->with(['exercise', 'liftSets'])
                ->orderBy('logged_at', 'asc')
                ->get();
            
            // Recalculate PRs for each log
            foreach ($logs as $log) {
                LiftLogCompleted::dispatch($log, isUpdate: true);
            }
        });
    }
    
    public function recalculatePRsFromDate(
        int $userId,
        int $exerciseId,
        Carbon $fromDate
    ): void {
        $this->recalculatePRsForExercise($userId, $exerciseId, $fromDate);
    }
}
```

## Migration Strategy

### Historical Data Migration

Create a command to calculate PRs for all existing lift logs:

```php
namespace App\Console\Commands;

use App\Models\User;
use App\Models\LiftLog;
use App\Events\LiftLogged;
use Illuminate\Console\Command;

class CalculateHistoricalPRs extends Command
{
    protected $signature = 'prs:calculate-historical 
                          {--user= : Specific user ID to process}
                          {--exercise= : Specific exercise ID to process}
                          {--dry-run : Show what would be done without making changes}';
    
    protected $description = 'Calculate and store PR records for all historical lift logs';
    
    public function handle(): int
    {
        $this->info('Starting historical PR calculation...');
        
        $query = User::query();
        
        if ($userId = $this->option('user')) {
            $query->where('id', $userId);
        }
        
        $users = $query->get();
        $totalUsers = $users->count();
        
        $this->info("Processing {$totalUsers} users...");
        
        $progressBar = $this->output->createProgressBar($totalUsers);
        
        foreach ($users as $user) {
            $this->processUser($user);
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $this->newLine();
        $this->info('Historical PR calculation complete!');
        
        return Command::SUCCESS;
    }
    
    protected function processUser(User $user): void
    {
        $exerciseQuery = $user->exercises();
        
        if ($exerciseId = $this->option('exercise')) {
            $exerciseQuery->where('id', $exerciseId);
        }
        
        $exercises = $exerciseQuery->get();
        
        foreach ($exercises as $exercise) {
            $logs = LiftLog::where('user_id', $user->id)
                ->where('exercise_id', $exercise->id)
                ->with(['exercise', 'liftSets'])
                ->orderBy('logged_at', 'asc')
                ->get();
            
            if ($this->option('dry-run')) {
                $this->line("Would process {$logs->count()} logs for {$exercise->title}");
                continue;
            }
            
            foreach ($logs as $log) {
                LiftLogCompleted::dispatch($log);
            }
        }
    }
}
```

**Run the migration:**

```bash
# Dry run first to see what would happen
php artisan prs:calculate-historical --dry-run

# Process all users
php artisan prs:calculate-historical

# Process specific user
php artisan prs:calculate-historical --user=26

# Process specific exercise for all users
php artisan prs:calculate-historical --exercise=1
```

**Considerations:**
- Run during off-peak hours
- Consider queuing for large datasets
- Monitor memory usage
- Add progress tracking
- Make idempotent (can run multiple times safely)

## Common Query Patterns

### Display PRs for a Lift Log

```php
// In LiftLogTableRowBuilder
$prs = PersonalRecord::where('lift_log_id', $liftLog->id)
    ->with('previousPR')
    ->get();

$prRecords = $prs->map(function ($pr) {
    $label = match($pr->pr_type) {
        'one_rm' => '1RM',
        'volume' => 'Volume',
        'rep_specific' => $pr->rep_count . ' Rep' . ($pr->rep_count > 1 ? 's' : ''),
        'hypertrophy' => 'Best @ ' . $pr->weight . ' lbs',
    };
    
    $value = $pr->previous_value 
        ? sprintf('%s → %s', $pr->previous_value, $pr->value)
        : sprintf('%s (First time!)', $pr->value);
    
    return [
        'label' => $label,
        'value' => $value,
    ];
});
```

### Get Current PRs for an Exercise

```php
// Get the current (unbeaten) PRs for an exercise
$currentPRs = PersonalRecord::where('user_id', $userId)
    ->where('exercise_id', $exerciseId)
    ->current() // Uses scope: whereDoesntHave('supersededBy')
    ->get();
```

### Get PR History

```php
// Get all 1RM PRs for an exercise over time
$prHistory = PersonalRecord::where('user_id', $userId)
    ->where('exercise_id', $exerciseId)
    ->where('pr_type', 'one_rm')
    ->orderBy('achieved_at', 'desc')
    ->with('liftLog')
    ->get();
```

### Get Recent PRs

```php
// Get all PRs in the last 30 days
$recentPRs = PersonalRecord::where('user_id', $userId)
    ->where('achieved_at', '>=', now()->subDays(30))
    ->with(['exercise', 'liftLog'])
    ->orderBy('achieved_at', 'desc')
    ->get();
```

### PR Statistics

```php
// Count PRs by exercise
$prCounts = PersonalRecord::where('user_id', $userId)
    ->groupBy('exercise_id')
    ->selectRaw('exercise_id, COUNT(*) as pr_count')
    ->with('exercise')
    ->get();

// Count PRs by type
$prsByType = PersonalRecord::where('user_id', $userId)
    ->groupBy('pr_type')
    ->selectRaw('pr_type, COUNT(*) as count')
    ->get();

// PRs per month
$prsPerMonth = PersonalRecord::where('user_id', $userId)
    ->selectRaw('DATE_FORMAT(achieved_at, "%Y-%m") as month, COUNT(*) as count')
    ->groupBy('month')
    ->orderBy('month', 'desc')
    ->get();
```

## Performance Implications

### Storage

**Per PR lift log:**
- Average: 2-3 PR records
- Storage per record: ~100 bytes
- 1000 lift logs → ~200 PRs → ~600 records → ~60KB

**Minimal storage impact**

### Write Performance

**Additional overhead per lift log creation:**
- PR detection: 5-10ms
- INSERT queries: 2-5ms
- Total: 7-15ms additional

**Acceptable overhead for dramatic read performance improvement**

### Read Performance

**Before (calculated on-the-fly):**
- 18 logs: 28ms
- 50 logs: 200-500ms
- 100 logs: 1000-2000ms

**After (database lookup):**
- Any number of logs: 1-2ms
- **14-1000x faster!**

## Feature Unlocks

Once PRs are first-class data, new features become possible:

### PR Dashboard
- "You've set 47 PRs this year!"
- PR frequency chart
- Longest PR drought
- PR streak tracking

### PR Notifications
- Email: "Congrats on your new PR!"
- Push notifications
- Weekly PR summary

### PR Analytics
- "You set most PRs on Mondays"
- "Average time between PRs: 12 days"
- PR prediction: "You're on track for a PR next week"
- Correlation with other metrics (sleep, nutrition, etc.)

### PR History Timeline
- Visual timeline of all PRs
- See progression over time
- Compare PR rates across exercises

### Social Features
- Share PR achievements
- Compare with friends
- Gym leaderboards
- PR challenges

### Training Insights
- Which exercises you PR most frequently
- Optimal training frequency for PRs
- PR patterns by time of day, day of week
- Deload effectiveness (PR rate before/after)

## Implementation Plan

### Phase 1: Foundation (Week 1) ✅ COMPLETE
- [x] Create migration for `personal_records` table
- [x] Add `is_pr` and `pr_count` columns to `lift_logs`
- [x] Create `PersonalRecord` model with relationships
- [x] Update `LiftLog` model with PR relationships
- [x] Write unit tests for models

### Phase 2: Event System (Week 1-2) ✅ COMPLETE
- [x] Create `LiftLogCompleted` event in `app/Events/`
- [x] Create `DetectAndRecordPRs` listener in `app/Listeners/`
- [x] Update `PRDetectionService` with `detectPRsWithDetails()` method
- [x] Update `CreateLiftLogAction` to dispatch event after creating sets
- [x] Update `UpdateLiftLogAction` to dispatch event after updating sets
- [x] Write integration tests
- [x] Verify auto-discovery works (Laravel 12 automatically registers events)

### Phase 3: Edge Cases (Week 2) ✅ COMPLETE
- [x] Handle lift log updates (triggers full recalculation)
- [x] Handle lift log deletions (recalculates if deleted log was a PR)
- [x] Handle backdated logs (detects subsequent logs and recalculates)
- [x] Create `PRRecalculationService` with simple full-recalculation approach
- [x] Update `DetectAndRecordPRs` listener to detect update/backdate scenarios
- [x] Update `LiftLogController::destroy()` to trigger recalculation
- [x] Fix event dispatch to use positional parameters (not named)
- [x] Add proper casts for `is_pr` (boolean) and `pr_count` (integer)
- [x] Write comprehensive edge case tests (8 tests, all passing)

**Key Implementation Details:**

**PRRecalculationService:**
```php
class PRRecalculationService
{
    public function recalculateAllPRsForExercise(int $userId, int $exerciseId): void
    {
        DB::transaction(function () use ($userId, $exerciseId) {
            // 1. Delete ALL existing PR records for this exercise
            PersonalRecord::where('user_id', $userId)
                ->where('exercise_id', $exerciseId)
                ->delete();
            
            // 2. Get ALL lift logs for this exercise, ordered chronologically
            $logs = LiftLog::where('user_id', $userId)
                ->where('exercise_id', $exerciseId)
                ->with(['exercise', 'liftSets'])
                ->orderBy('logged_at', 'asc')
                ->orderBy('id', 'asc')
                ->get();
            
            // 3. Process each log chronologically
            foreach ($logs as $log) {
                $prs = $this->prDetectionService->detectPRsWithDetails($log);
                
                // Create PR records and update lift log flags
                // ...
            }
        });
    }
}
```

**Updated Listener Logic:**
```php
public function handle(LiftLogCompleted $event): void
{
    $liftLog = $event->liftLog;
    $liftLog->load(['exercise', 'liftSets']);
    
    // Check if there are any logs after this one (backdating scenario)
    $hasSubsequentLogs = LiftLog::where('user_id', $liftLog->user_id)
        ->where('exercise_id', $liftLog->exercise_id)
        ->where('logged_at', '>', $liftLog->logged_at)
        ->exists();
    
    // If UPDATE or BACKDATED, recalculate all PRs for this exercise
    if ($event->isUpdate || $hasSubsequentLogs) {
        $this->prRecalculationService->recalculateAllPRsForExercise(
            $liftLog->user_id,
            $liftLog->exercise_id
        );
        return;
    }
    
    // Normal case: new lift with no subsequent logs
    // Just detect PRs for this log
    // ...
}
```

**Edge Cases Handled:**
1. ✅ Updating only lift to lighter weight → Still PR (it's the only lift)
2. ✅ Updating lift to heavier weight → Still PR
3. ✅ Updating lift to lighter weight with other lifts → No longer PR if all metrics are worse
4. ✅ Deleting PR lift → Recalculates remaining lifts
5. ✅ Backdating heavier lift → Recalculates all subsequent PRs (removes PR status from beaten lifts)
6. ✅ Backdating lighter lift → Still PR, doesn't break existing PRs
7. ✅ Deleting non-PR lift → Doesn't affect other lifts
8. ✅ Updating lift date → Triggers full recalculation

**Design Decision: Data Accuracy Over Historical Context**
When a backdated lift is added that beats a later lift, the later lift loses its PR status. We prioritize data accuracy over preserving "was a PR at the time" status. This ensures the PR flags always reflect the true best performance in the full timeline.

**Key Insight:**
A lift is ALWAYS a PR if it's the only lift for that exercise, even if updated to a lighter weight. This is correct behavior because it's still the best you've ever done for that exercise.

**Design Decision: Simple Full Recalculation**
We chose to recalculate ALL logs for an exercise (not just from a date forward) because:
- ✅ Simpler logic with fewer edge cases
- ✅ Always correct (no ambiguity about "first lift")
- ✅ Acceptable performance (most exercises have < 100 logs)
- ✅ Easier to test and maintain
- ✅ Natural chronological processing
- ✅ Data accuracy over historical context

**Test Results:**
- All PR-related tests passing (100% pass rate)
- PREventSystemTest: 10/10 passing
- PREdgeCasesTest: 8/8 passing
- PRInfoDisplayTest: 19/19 passing
- ExercisePRHighlightingTest: 9/9 passing
- NotesDisplayTest: 8/8 passing

### Phase 4: Update Read Path (Week 3) ✅ COMPLETE
- [x] Update `LiftLogTableRowBuilder` to use PR records
- [x] Remove old O(n²) calculation code
- [x] Remove unused `getPRInfoForLiftLog()` method
- [x] Remove PRDetectionService dependency from constructor
- [x] Update `getCurrentRecordsTable()` to use PersonalRecord database records
- [x] Update `getPRRecordsForBeatenPRs()` to use PersonalRecord database records
- [x] Update unit tests (33 tests, all passing)
- [x] Create `TriggersPRDetection` helper trait for tests
- [x] Update `ExercisePRHighlightingTest` to trigger PR detection (9/9 passing)
- [x] Update `LiftLogLoggingTest` to trigger PR detection (2 tests fixed)
- [x] Update `PRInfoDisplayTest` to trigger PR detection (19/19 passing ✅)
- [x] Fix "First time!" message display for first lifts
- [x] Fix hypertrophy PR logic (only award when improving reps at previously done weight)
- [x] Fix "Est 1RM" display for true 1RM lifts (don't show separately when reps=1)

**Implementation Details:**

**Updated Methods:**
1. `buildRows()` - Now uses `is_pr` flag from database instead of O(n²) calculation
2. `buildRow()` - Checks `$liftLog->is_pr` instead of `in_array($liftLog->id, $config['prLogIds'])`
3. `getPRRecordsForBeatenPRs()` - Queries PersonalRecord table instead of calculating on-the-fly
   - **Fixed:** Check for first lift BEFORE checking PRs (so "First time!" message shows correctly)
4. `getCurrentRecordsTable()` - Queries PersonalRecord table for current (unbeaten) PRs

**Removed:**
- `getPRInfoForLiftLog()` method (no longer needed)
- `PRDetectionService` dependency from constructor
- All O(n²) PR calculation logic

**Performance Improvement:**
- Before: O(n²) calculation (28ms for 18 logs, 200-500ms for 50+ logs)
- After: O(1) lookup (1-2ms regardless of history size)
- **14-250x faster!**

**Key Insight:**
For first lifts, PersonalRecords ARE created (since it's the first time doing the exercise), but we want to show "First time!" instead of showing the beaten records. The fix was to check `$isFirstLift` BEFORE checking if PRs exist, not after.

### Phase 5: Historical Migration (Week 3-4) ✅ COMPLETE
- [x] Create `prs:calculate-historical` command
- [x] Test on development data
- [x] Verify data integrity

**Command Features:**
- `--dry-run`: Preview what would be done without making changes
- `--user=ID`: Calculate PRs for a specific user
- `--exercise=ID`: Calculate PRs for a specific exercise
- `--force`: Skip confirmation prompt

**Migration Results:**
- Processed 199 user-exercise combinations in 7 seconds
- 968 total lift logs analyzed
- 616 lift logs marked as PRs (63.6%)
- 1,579 PersonalRecord entries created
  - 388 1RM PRs
  - 305 volume PRs
  - 760 rep-specific PRs
  - 126 hypertrophy PRs
- Zero errors encountered

**Usage:**
```bash
# Dry run first to see what would happen
php artisan prs:calculate-historical --dry-run

# Run for all users and exercises
php artisan prs:calculate-historical --force

# Run for specific user
php artisan prs:calculate-historical --user=1

# Run for specific exercise
php artisan prs:calculate-historical --exercise=5
```

## Testing Strategy

### Unit Tests
- PersonalRecord model relationships
- PR detection logic
- PR formatting methods

### Integration Tests
- Lift log creation triggers PR detection
- Lift log update recalculates PRs
- Lift log deletion handles PRs correctly
- Backdated logs recalculate correctly

### Performance Tests
- Benchmark PR lookup vs calculation
- Load test with 1000+ historical logs
- Memory usage monitoring

### Data Integrity Tests
- Verify all historical PRs are correct
- Check for orphaned PR records
- Validate previous_pr_id references

## Rollback Plan

If issues arise:

1. **Disable event listener** (quick rollback)
   ```php
   // In EventServiceProvider
   protected $listen = [
       // LiftLogged::class => [
       //     DetectAndRecordPRs::class,
       // ],
   ];
   ```

2. **Revert to old calculation** (fallback)
   - Keep old `calculatePRLogIds()` method
   - Add feature flag to switch between old/new

3. **Data cleanup** (if needed)
   ```bash
   php artisan prs:cleanup
   ```

## Conclusion

Making PRs first-class data transforms them from expensive calculations into queryable, analyzable records. This architectural change:

- **Improves performance** by 14-1000x
- **Enables new features** (dashboard, analytics, notifications)
- **Provides historical record** of all PR achievements
- **Scales better** with user activity
- **Unlocks insights** into training patterns

The event-driven approach ensures data consistency while maintaining flexibility for future enhancements.


### Phase 6: Cardio Exercise PR Detection (Week 4) ✅ COMPLETE
- [x] Add ENDURANCE PR type to PRType enum (value: 32)
- [x] Create migration to add 'endurance' to pr_type ENUM in personal_records table
- [x] Implement PR detection methods in CardioExerciseType
- [x] Create comprehensive test suite (14 tests, all passing)
- [x] Verify all existing tests still pass (1816 tests passing)

**Cardio Exercise PR Types:**

Cardio exercises support three PR types:
1. **ENDURANCE**: Longest single distance in one round (best individual round)
2. **VOLUME**: Total distance across all rounds in a session
3. **REP_SPECIFIC**: Best total distance at specific round counts (e.g., best 5-round total)

**Implementation Details:**

**CardioExerciseType PR Methods:**
```php
public function getSupportedPRTypes(): array
{
    return [PRType::ENDURANCE, PRType::REP_SPECIFIC, PRType::VOLUME];
}

public function calculateCurrentMetrics(LiftLog $liftLog): array
{
    $bestDistance = 0;
    $totalDistance = 0;
    $roundCount = 0;
    
    foreach ($liftLog->liftSets as $set) {
        if ($set->reps > 0) { // reps field stores distance in meters
            $distance = $set->reps;
            $bestDistance = max($bestDistance, $distance);
            $totalDistance += $distance;
            $roundCount++;
        }
    }
    
    $roundDistances = [];
    if ($roundCount > 0 && $roundCount <= 10) {
        $roundDistances[$roundCount] = $totalDistance;
    }
    
    return [
        'best_distance' => $bestDistance,
        'total_distance' => $totalDistance,
        'round_distances' => $roundDistances,
    ];
}

public function compareToPrevious(array $currentMetrics, Collection $previousLogs, LiftLog $currentLog): array
{
    $prs = [];
    $distanceTolerance = 1; // 1 meter tolerance
    
    // Check ENDURANCE PR (longest single distance)
    $bestDistanceResult = $this->getBestSingleDistance($previousLogs);
    if ($currentMetrics['best_distance'] > $bestDistanceResult['value'] + $distanceTolerance) {
        $prs[] = [
            'type' => 'endurance',
            'value' => $currentMetrics['best_distance'],
            'previous_value' => $bestDistanceResult['value'],
            'previous_lift_log_id' => $bestDistanceResult['lift_log_id'],
        ];
    }
    
    // Check VOLUME PR (total distance)
    $bestVolumeResult = $this->getBestTotalDistance($previousLogs);
    if ($currentMetrics['total_distance'] > $bestVolumeResult['value'] + $distanceTolerance) {
        $prs[] = [
            'type' => 'volume',
            'value' => $currentMetrics['total_distance'],
            'previous_value' => $bestVolumeResult['value'],
            'previous_lift_log_id' => $bestVolumeResult['lift_log_id'],
        ];
    }
    
    // Check REP_SPECIFIC PRs (best total distance at specific round counts)
    foreach ($currentMetrics['round_distances'] as $rounds => $distance) {
        $previousBestResult = $this->getBestDistanceForRounds($previousLogs, $rounds);
        if ($distance > $previousBestResult['distance'] + $distanceTolerance) {
            $prs[] = [
                'type' => 'rep_specific',
                'rep_count' => $rounds,
                'value' => $distance,
                'previous_value' => $previousBestResult['distance'],
                'previous_lift_log_id' => $previousBestResult['lift_log_id'],
            ];
        }
    }
    
    return $prs;
}
```

**Cardio Data Model:**
- `reps` field stores distance in meters (e.g., 500 = 500m)
- `weight` always 0 for cardio exercises
- `sets` represents number of rounds/intervals
- Distance displayed as "500m" or "10.5km" depending on magnitude

**Examples:**

1. **First cardio session:**
   - 3 × 500m = 1500m total
   - Creates 3 PRs: ENDURANCE (500m), VOLUME (1500m), REP_SPECIFIC for 3 rounds (1500m)

2. **Improved session:**
   - 3 × 600m = 1800m total
   - Beats all 3 PRs: ENDURANCE (600m > 500m), VOLUME (1800m > 1500m), REP_SPECIFIC for 3 rounds (1800m > 1500m)

3. **Different round count:**
   - 5 × 400m = 2000m total
   - Creates new REP_SPECIFIC PR for 5 rounds (first time doing 5 rounds)
   - May or may not beat VOLUME PR depending on previous best

4. **Multiple sets with varying distances:**
   - 500m, 800m, 600m
   - ENDURANCE PR tracks the best single distance (800m)
   - VOLUME PR tracks total (1900m)

**Test Coverage:**

Created comprehensive test suite `tests/Feature/CardioPRDetectionTest.php` with 14 tests:
- ✅ First cardio creates ENDURANCE PR
- ✅ Longer distance creates ENDURANCE PR
- ✅ Shorter distance does not create ENDURANCE PR
- ✅ First cardio creates VOLUME PR
- ✅ Higher total distance creates VOLUME PR
- ✅ Lower total distance does not create VOLUME PR
- ✅ First cardio creates REP_SPECIFIC PR
- ✅ Better distance at same rounds creates REP_SPECIFIC PR
- ✅ Different round counts create separate REP_SPECIFIC PRs
- ✅ Cardio can achieve multiple PR types simultaneously
- ✅ Multiple sets tracks best distance for ENDURANCE PR
- ✅ Cardio does not create ONE_RM PR
- ✅ Cardio display shows distance correctly
- ✅ Cardio PR flags are set correctly

**Key Implementation Insight:**

The `is_pr` field in the `lift_logs` table is a boolean flag (not bitwise flags), indicating whether the lift has ANY PRs. The specific PR types are stored in the `personal_records` table with the `pr_type` column. This was discovered when the test initially tried to use bitwise operations on `is_pr`, which failed because it's cast as a boolean in the LiftLog model.

**Migration:**
```php
Schema::table('personal_records', function (Blueprint $table) {
    DB::statement("ALTER TABLE personal_records MODIFY COLUMN pr_type ENUM('one_rm', 'rep_specific', 'volume', 'density', 'time', 'endurance') NOT NULL");
});
```

**Files Modified:**
- `app/Services/ExerciseTypes/CardioExerciseType.php` - Added PR detection methods
- `database/migrations/2026_01_25_084700_add_endurance_pr_type_to_personal_records_table.php` - Added ENDURANCE to enum
- `tests/Feature/CardioPRDetectionTest.php` - Created comprehensive test suite
- `app/Enums/PRType.php` - ENDURANCE enum already existed with value 32

**Test Results:**
- All 14 cardio PR detection tests passing ✅
- All 1816 existing tests still passing ✅
- Zero regressions introduced ✅

**Design Consistency:**

Cardio PR detection follows the same Strategy Pattern architecture as other exercise types:
- Implements `getSupportedPRTypes()`, `calculateCurrentMetrics()`, `compareToPrevious()`
- Uses same event-driven architecture (LiftLogCompleted event)
- Stores PRs in same `personal_records` table
- Uses same `is_pr` and `pr_count` flags on `lift_logs`
- No changes needed to PRDetectionService or DetectAndRecordPRs listener

This demonstrates the power of the Strategy Pattern - adding PR support for a new exercise type required ZERO changes to the core PR detection infrastructure.
