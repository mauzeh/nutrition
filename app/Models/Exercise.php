<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use App\Services\ExerciseTypes\ExerciseTypeFactory;
use App\Services\ExerciseTypes\ExerciseTypeInterface;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Exercise extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'description', 'canonical_name', 'user_id', 'exercise_type'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'title',
        'description',
        'canonical_name',
        'user_id',
        'exercise_type',
        'log_type',
        'show_in_feed',
    ];

    protected $casts = [
        'exercise_type' => 'string',
        'log_type' => 'string',
        'show_in_feed' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($exercise) {
            if (empty($exercise->canonical_name) && !empty($exercise->title)) {
                $exercise->canonical_name = static::generateUniqueCanonicalName($exercise->title);
            }
            
            // Set default exercise_type if not provided
            if (empty($exercise->exercise_type)) {
                $exercise->exercise_type = 'regular';
            }
        });

        static::deleting(function ($exercise) {
            // Soft delete all associated ExerciseAlias records
            $exercise->aliases()->each(function ($alias) {
                $alias->delete();
            });

            // Soft delete associated ExerciseIntelligence record
            if ($exercise->intelligence) {
                $exercise->intelligence->delete();
            }
        });
    }

    public function liftLogs()
    {
        return $this->hasMany(LiftLog::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function intelligence()
    {
        return $this->hasOne(ExerciseIntelligence::class);
    }

    public function aliases()
    {
        return $this->hasMany(ExerciseAlias::class);
    }

    // Scopes for querying
    public function scopeGlobal($query)
    {
        return $query->whereNull('user_id');
    }

    public function scopeUserSpecific($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope that applies user-specific exercise filtering based on preferences
     * 
     * Global exercises are shown if:
     * 1. User has show_global_exercises enabled, OR
     * 2. User has lift logs for that exercise (even if promoted to global)
     */
    public function scopeAvailableToUser($query, $userId = null, $showGlobal = null)
    {
        $userId = $userId ?? Auth::id();
        
        if (!$userId) {
            // If no user, show no exercises at all
            return $query->whereRaw('1 = 0'); // This will return no results
        }
        
        $user = User::find($userId);
        
        if (!$user) {
            // If user doesn't exist, show no exercises
            return $query->whereRaw('1 = 0'); // This will return no results
        }
        
        // Admin users see all exercises regardless of preference
        if ($user->hasRole('Admin')) {
            return $query->orderByRaw('user_id IS NULL ASC');
        }
        
        // Use provided showGlobal parameter or fall back to user preference
        $shouldShowGlobal = $showGlobal !== null ? $showGlobal : $user->shouldShowGlobalExercises();
        
        if ($shouldShowGlobal) {
            // Show global + own exercises
            return $query->where(function ($q) use ($userId) {
                $q->whereNull('user_id')        // Global exercises
                  ->orWhere('user_id', $userId); // User's own exercises
            })->orderByRaw('user_id IS NULL ASC');
        } else {
            // Show only user's own exercises + global exercises they have lift logs for
            // This ensures exercises that were promoted to global remain visible
            return $query->where(function ($q) use ($userId) {
                $q->where('user_id', $userId) // User's own exercises
                  ->orWhere(function ($subQuery) use ($userId) {
                      // Global exercises that user has lift logs for
                      $subQuery->whereNull('user_id')
                               ->whereHas('liftLogs', function ($logQuery) use ($userId) {
                                   $logQuery->where('user_id', $userId);
                               });
                  });
            })->orderBy('title', 'asc');
        }
    }

    /**
     * Scope to show all exercises without any filtering (for admin operations)
     */
    public function scopeWithoutUserFiltering($query)
    {
        return $query;
    }

    /**
     * Scope to force showing only global exercises
     */
    public function scopeOnlyGlobal($query)
    {
        return $query->whereNull('user_id');
    }

    /**
     * Scope to force showing only user-specific exercises for a given user
     */
    public function scopeOnlyUserSpecific($query, $userId = null)
    {
        $userId = $userId ?? Auth::id();
        return $query->where('user_id', $userId);
    }

    public function scopeWithIntelligence($query)
    {
        return $query->whereHas('intelligence');
    }

    /**
     * Scope to filter exercises by specific type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('exercise_type', $type);
    }

    /**
     * Scope to filter banded exercises (both resistance and assistance)
     */
    public function scopeBanded($query)
    {
        return $query->whereIn('exercise_type', ['banded_resistance', 'banded_assistance']);
    }

    /**
     * Scope to filter bodyweight exercises
     */
    public function scopeBodyweight($query)
    {
        return $query->where('exercise_type', 'bodyweight');
    }

    /**
     * Scope to filter regular exercises
     */
    public function scopeRegular($query)
    {
        return $query->where('exercise_type', 'regular');
    }

    /**
     * Scope to filter cardio exercises
     */
    public function scopeCardio($query)
    {
        return $query->where('exercise_type', 'cardio');
    }

    /**
     * Scope to filter non-cardio exercises
     */
    public function scopeNonCardio($query)
    {
        return $query->where('exercise_type', '!=', 'cardio')
                     ->orWhereNull('exercise_type');
    }

    /**
     * Scope to filter static hold exercises
     */
    public function scopeStaticHold($query)
    {
        return $query->where('exercise_type', 'static_hold');
    }

    // Helper methods
    public function isGlobal(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Check if this exercise is of a specific type
     */
    public function isType(string $type): bool
    {
        return $this->exercise_type === $type;
    }

    public function isBandedResistance(): bool
    {
        return $this->exercise_type === 'banded_resistance';
    }

    public function isBandedAssistance(): bool
    {
        return $this->exercise_type === 'banded_assistance';
    }

    /**
     * Check if this is a banded exercise (either resistance or assistance)
     */
    public function isBanded(): bool
    {
        return in_array($this->exercise_type, ['banded_resistance', 'banded_assistance']);
    }

    /**
     * Check if this is a cardio exercise
     */
    public function isCardio(): bool
    {
        return $this->exercise_type === 'cardio';
    }

    /**
     * Check if this is a static hold exercise
     */
    public function isStaticHold(): bool
    {
        return $this->exercise_type === 'static_hold';
    }



    /**
     * Check if this exercise supports 1RM calculation
     * Uses the strategy pattern to determine capability
     */
    public function supports1RM(): bool
    {
        return $this->getTypeStrategy()->canCalculate1RM();
    }

    /**
     * Get the chart type for this exercise
     * Uses the strategy pattern to determine chart type
     */
    public function getChartType(): string
    {
        return $this->getTypeStrategy()->getChartType();
    }

    /**
     * Get the display format for this exercise type
     * Uses the strategy pattern for consistent formatting
     */
    public function getDisplayFormat(): string
    {
        $config = $this->getTypeStrategy()->getTypeConfig();
        return $config['display_format'] ?? 'weight_lbs';
    }

    public function canBeEditedBy(User $user): bool
    {
        // Admin users can edit all exercises
        if ($user->hasRole('Admin')) {
            return true;
        }
        
        // Regular users can only edit global exercises (if admin) or their own exercises
        if ($this->isGlobal()) {
            return false; // Regular users cannot edit global exercises
        }
        return $this->user_id === $user->id;
    }

    public function canBeDeletedBy(User $user): bool
    {
        // Use preloaded lift_logs_count if available, otherwise fall back to query
        $hasLiftLogs = isset($this->lift_logs_count) 
            ? $this->lift_logs_count > 0 
            : $this->liftLogs()->exists();
            
        if ($hasLiftLogs) {
            return false; // Cannot delete if has lift logs
        }
        
        // Admin users can delete all exercises (if no lift logs)
        if ($user->hasRole('Admin')) {
            return true;
        }
        
        // Regular users can only delete global exercises (if admin) or their own exercises
        if ($this->isGlobal()) {
            return false; // Regular users cannot delete global exercises
        }
        return $this->user_id === $user->id;
    }

    public function hasIntelligence(): bool
    {
        return $this->intelligence !== null;
    }

    /**
     * Generate a unique canonical name from a title
     */
    protected static function generateUniqueCanonicalName(string $title, ?int $excludeId = null): string
    {
        $baseCanonicalName = Str::slug($title, '_');
        $canonicalName = $baseCanonicalName;
        $counter = 1;

        // Keep checking until we find a unique canonical name
        while (static::canonicalNameExists($canonicalName, $excludeId)) {
            $canonicalName = $baseCanonicalName . '_' . $counter;
            $counter++;
        }

        return $canonicalName;
    }

    /**
     * Check if a canonical name already exists
     */
    protected static function canonicalNameExists(string $canonicalName, ?int $excludeId = null): bool
    {
        $query = static::where('canonical_name', $canonicalName);
        
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * Check if this exercise can be merged by an admin
     * Requirements: 2.1, 5.1, 5.5
     */
    public function canBeMergedByAdmin(): bool
    {
        // Only user exercises can be merged (not global exercises)
        if ($this->isGlobal()) {
            return false;
        }

        // Check if there are any compatible global target exercises
        $potentialTargets = static::onlyGlobal()
            ->where('id', '!=', $this->id)
            ->get()
            ->filter(function ($exercise) {
                return $this->isCompatibleForMerge($exercise);
            });

        return $potentialTargets->isNotEmpty();
    }

    /**
     * Check if this exercise is compatible for merging with another exercise
     * Requirements: 5.1, 5.2, 5.3
     */
    public function isCompatibleForMerge(Exercise $target): bool
    {
        // Cannot merge with itself
        if ($this->id === $target->id) {
            return false;
        }

        // For merge compatibility, we need to determine which is source and which is target
        // Only user exercises can be merged into global exercises
        $source = $this->isGlobal() ? $target : $this;
        $globalTarget = $this->isGlobal() ? $this : $target;

        // Source must be user exercise and target must be global
        if ($source->isGlobal() || !$globalTarget->isGlobal()) {
            return false;
        }

        // Exercise types must be compatible for merging
        $sourceType = $source->exercise_type;
        $targetType = $globalTarget->exercise_type;
        
        // Exact match is always compatible
        if ($sourceType === $targetType) {
            return true;
        }
        
        // Special compatibility rules:
        // - Bodyweight exercises can only merge with other bodyweight exercises
        // - Different banded types (resistance vs assistance) are NOT compatible
        // - Regular exercises can merge with banded exercises (flexible band assignment)
        // - Same banded types can merge with each other
        
        if ($sourceType === 'bodyweight' || $targetType === 'bodyweight') {
            // Bodyweight exercises can only merge with other bodyweight exercises
            return $sourceType === 'bodyweight' && $targetType === 'bodyweight';
        }
        
        // Different specific banded types are not compatible
        if (($sourceType === 'banded_resistance' && $targetType === 'banded_assistance') ||
            ($sourceType === 'banded_assistance' && $targetType === 'banded_resistance')) {
            return false;
        }
        
        // Regular exercises can merge with any banded type
        // Same banded types can merge with each other
        $compatibleTypes = ['regular', 'banded_resistance', 'banded_assistance'];
        return in_array($sourceType, $compatibleTypes) && in_array($targetType, $compatibleTypes);

        return true;
    }

    /**
     * Check if the owner of this exercise has global visibility disabled
     * Only applicable for user exercises (not global exercises)
     * Requirements: 5.4
     */
    public function hasOwnerWithGlobalVisibilityDisabled(): bool
    {
        // Global exercises don't have owners
        if ($this->isGlobal()) {
            return false;
        }

        // Load the user relationship if not already loaded
        if (!$this->relationLoaded('user')) {
            $this->load('user');
        }

        // Check if the user has global exercise visibility disabled
        return $this->user && !$this->user->shouldShowGlobalExercises();
    }

    /**
     * Get the exercise type strategy for this exercise
     * Uses safe creation to ensure it never fails
     */
    public function getTypeStrategy(): ExerciseTypeInterface
    {
        return ExerciseTypeFactory::createSafe($this);
    }

    /**
     * Get the display name for this exercise for a specific user
     * Returns the user's alias if one exists, otherwise returns the exercise title
     * Requirements: 2.1, 2.2, 3.1, 3.2, 4.1, 4.2
     */
    public function getDisplayNameForUser(User $user): string
    {
        return app(\App\Services\ExerciseAliasService::class)->getDisplayName($this, $user);
    }

    public function hasAliasForUser(User $user): bool
    {
        return app(\App\Services\ExerciseAliasService::class)->hasAlias($user, $this);
    }

    public function getLogTypeAttribute($value): string
    {
        if ($value) {
            return $value;
        }

        return match ($this->exercise_type) {
            'bodyweight' => 'bodyweight',
            'cardio' => 'cardio',
            'static_hold' => 'static_hold',
            default => 'regular',
        };
    }
}