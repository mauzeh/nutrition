<?php

namespace App\Services\ExerciseTypes;

use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\User;
use App\Services\ExerciseTypes\Exceptions\InvalidExerciseDataException;

/**
 * Static Hold Exercise Type Strategy
 * 
 * Handles static hold exercises commonly used in gymnastics and calisthenics training.
 * Examples include L-sits, planches, front levers, handstands, and hollow body holds.
 * 
 * Characteristics:
 * - Reps field stores hold duration in seconds (1-300 seconds / 5 minutes max)
 * - Weight field stores optional added weight (weighted vests, etc.)
 * - Sets field stores number of holds performed
 * - Does not support 1RM calculation (not applicable to isometric holds)
 * - Uses duration-based display formatting
 * - Progression focuses on increasing duration or adding weight
 * 
 * @package App\Services\ExerciseTypes
 * @since 1.0.0
 * 
 * @example
 * // Typical usage for exercises like "L-sit", "Planche Hold", "Front Lever"
 * $strategy = new StaticHoldExerciseType();
 * $processedData = $strategy->processLiftData([
 *     'reps' => '30',      // Hold duration in seconds
 *     'sets' => '3',       // Number of holds
 *     'weight' => '0',     // No added weight (bodyweight only)
 *     'band_color' => 'red' // Will be nullified
 * ]);
 * // Result: ['reps' => 30, 'sets' => 3, 'weight' => 0, 'band_color' => null]
 * 
 * @example
 * // Display formatting
 * $display = $strategy->formatWeightDisplay($liftLog);
 * // Result: "30s hold" or "30s hold +25 lbs" (if weighted)
 */
class StaticHoldExerciseType extends BaseExerciseType
{
    /**
     * Minimum hold duration in seconds (1 second)
     */
    private const MIN_DURATION = 1;
    
    /**
     * Maximum hold duration in seconds (5 minutes = 300 seconds)
     */
    private const MAX_DURATION = 300;
    
    /**
     * Get the type name identifier
     */
    public function getTypeName(): string
    {
        return 'static_hold';
    }
    
    /**
     * Process lift data according to static hold exercise rules
     * 
     * For static hold exercises:
     * - Time field stores hold duration in seconds and must be validated
     * - Weight field is optional (0 for bodyweight, or added weight)
     * - Band color is always nullified (not applicable)
     * - Duration must be between 1 second and 5 minutes
     * - Reps is always set to 1 (semantic: "1 hold performed")
     */
    public function processLiftData(array $data): array
    {
        $processedData = $data;
        
        // Nullify band_color for static hold exercises
        $processedData['band_color'] = null;
        
        // Validate duration (stored in time field)
        if (!isset($processedData['time'])) {
            throw InvalidExerciseDataException::missingField('time', $this->getTypeName());
        }
        
        if (!is_numeric($processedData['time'])) {
            throw InvalidExerciseDataException::forField('time', $this->getTypeName(), 'hold duration must be a number');
        }
        
        $duration = (int) $processedData['time'];
        
        if ($duration < self::MIN_DURATION) {
            throw InvalidExerciseDataException::forField('time', $this->getTypeName(), 'hold duration must be at least ' . self::MIN_DURATION . ' second');
        }
        
        if ($duration > self::MAX_DURATION) {
            throw InvalidExerciseDataException::forField('time', $this->getTypeName(), 'hold duration cannot exceed ' . self::MAX_DURATION . ' seconds');
        }
        
        $processedData['time'] = $duration;
        
        // Set reps to 1 (semantic: "1 hold performed")
        $processedData['reps'] = 1;
        
        // Validate weight if provided
        if (isset($processedData['weight'])) {
            if (!is_numeric($processedData['weight'])) {
                throw InvalidExerciseDataException::invalidWeight($processedData['weight'], $this->getTypeName());
            }
            
            if ($processedData['weight'] < 0) {
                throw InvalidExerciseDataException::forField('weight', $this->getTypeName(), 'weight cannot be negative');
            }
        } else {
            $processedData['weight'] = 0;
        }
        
        return $processedData;
    }
    
    /**
     * Process exercise data according to static hold exercise rules
     */
    public function processExerciseData(array $data): array
    {
        $processedData = $data;
        
        // For static hold exercises, ensure exercise_type is set correctly
        $processedData['exercise_type'] = 'static_hold';
        
        return $processedData;
    }
    
    /**
     * Format weight display for static hold exercises
     * 
     * For static hold exercises, we display duration and optional weight.
     * The duration is stored in the time field.
     */
    public function formatWeightDisplay(LiftLog $liftLog): string
    {
        $duration = $liftLog->liftSets->first()?->time ?? 0;
        $weight = $liftLog->display_weight;
        $loggedUnit = $liftLog->liftSets->first()->unit ?? 'lbs';
        
        if (!is_numeric($duration) || $duration <= 0) {
            return '0s hold';
        }
        
        $durationDisplay = $this->formatDuration((int)$duration);
        
        // If there's added weight, include it in the display
        if (is_numeric($weight) && $weight > 0) {
            $weightFormatted = $this->unitResolver()->formatForUser($weight, $loggedUnit, $liftLog->user);
            return "{$durationDisplay} +{$weightFormatted}";
        }
        
        return $durationDisplay;
    }
    
    /**
     * Format duration in seconds to a readable format
     * 
     * @param int $seconds Duration in seconds
     * @return string Formatted duration (e.g., "30s hold", "1m 30s hold")
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s hold";
        }
        
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;
        
        if ($remainingSeconds === 0) {
            return "{$minutes}m hold";
        }
        
        return "{$minutes}m {$remainingSeconds}s hold";
    }
    
    /**
     * Format volume duration in seconds to time format
     * 
     * @param int $seconds Duration in seconds
     * @return string Formatted duration (e.g., "30s hold", "2:20 hold")
     */
    private function formatVolumeDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s hold";
        }
        
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;
        
        // Format as M:SS for volumes over 60 seconds
        return sprintf("%d:%02d hold", $minutes, $remainingSeconds);
    }
    
    /**
     * Format complete static hold display showing duration, weight, and sets
     * 
     * Returns a formatted string like "30s hold × 3 sets" or "30s hold +25 lbs × 3 sets"
     */
    public function formatCompleteDisplay(LiftLog $liftLog): string
    {
        $duration = $liftLog->liftSets->first()?->time ?? 0;
        $weight = $liftLog->display_weight;
        $sets = $liftLog->display_rounds;
        $loggedUnit = $liftLog->liftSets->first()->unit ?? 'lbs';
        
        if (!is_numeric($duration) || $duration <= 0) {
            $duration = 0;
        }
        
        if (!is_numeric($sets) || $sets <= 0) {
            $sets = 1;
        }
        
        $durationDisplay = $this->formatDuration((int)$duration);
        
        // Add weight if present
        if (is_numeric($weight) && $weight > 0) {
            $weightFormatted = $this->unitResolver()->formatForUser($weight, $loggedUnit, $liftLog->user);
            $durationDisplay .= " +{$weightFormatted}";
        }
        
        $setsText = $sets == 1 ? 'set' : 'sets';
        
        return "{$durationDisplay} × {$sets} {$setsText}";
    }
    
    /**
     * Format progression suggestion for static hold exercises
     * 
     * Static hold progression logic:
     * - For durations < 60s: suggest increasing duration by 1-2s (very conservative for difficult holds)
     * - For durations >= 60s: suggest adding weight or additional sets
     */
    public function formatProgressionSuggestion(LiftLog $liftLog): ?string
    {
        $duration = $liftLog->liftSets->first()?->time ?? 0;
        $weight = $liftLog->display_weight;
        $sets = $liftLog->liftSets->count();
        
        if (!is_numeric($duration) || $duration <= 0) {
            return null;
        }
        
        $unitResolver = $this->unitResolver();
        $user = $liftLog->user;
        $preferredUnit = $unitResolver->getPreferredWeightUnit($user);
        $loggedUnit = $liftLog->liftSets->first()->unit ?? 'lbs';
        
        // For holds under 60 seconds, suggest small duration increases
        if ($duration < 60) {
            // Very conservative progression: 1-2 seconds
            $increment = $duration < 30 ? 1 : 2;
            $newDuration = $duration + $increment;
            $newDurationDisplay = $this->formatDuration((int)$newDuration);
            return "Try {$newDurationDisplay} × {$sets} sets";
        }
        
        // For longer holds (60s+), suggest adding weight or sets
        if (!is_numeric($weight) || $weight == 0) {
            // Suggest adding weight
            $durationDisplay = $this->formatDuration((int)$duration);
            $increment = $unitResolver->getWeightIncrement($user);
            $formattedNext = $unitResolver->format($increment, $preferredUnit);
            return "Try {$durationDisplay} +{$formattedNext} × {$sets} sets";
        } else {
            // Suggest adding more sets
            $newSets = $sets + 1;
            $durationDisplay = $this->formatDuration((int)$duration);
            $convertedExtra = $unitResolver->convert($weight, $loggedUnit, $preferredUnit);
            $weightFormatted = $unitResolver->format($convertedExtra, $preferredUnit);
            return "Try {$durationDisplay} +{$weightFormatted} × {$newSets} sets";
        }
    }
    
    /**
     * Get form field definitions for static hold exercises
     * Static hold exercises show duration (time) and optional weight
     */
    public function getFormFieldDefinitions(array $defaults = [], ?User $user = null): array
    {
        $labels = $this->getFieldLabels();
        $increments = $this->getFieldIncrements($user);
        
        return [
            [
                'name' => 'time',
                'label' => $labels['time'],
                'type' => 'numeric',
                'defaultValue' => $defaults['time'] ?? 30,
                'increment' => $increments['time'],
                'min' => self::MIN_DURATION,
                'max' => self::MAX_DURATION,
            ],
            [
                'name' => 'weight',
                'label' => $labels['weight'],
                'type' => 'numeric',
                'defaultValue' => $defaults['weight'] ?? 0,
                'increment' => $increments['weight'],
                'min' => 0,
                'max' => 500,
            ]
        ];
    }
    
    /**
     * Get default weight progression for static holds
     * Static holds don't automatically add weight - keep the same weight
     */
    public function getDefaultWeightProgression(float $lastWeight): float
    {
        return $lastWeight;
    }
    
    /**
     * Get default starting weight for static holds
     * Static holds start with bodyweight only (0 added weight)
     */
    public function getDefaultStartingWeight(\App\Models\Exercise $exercise): float
    {
        return 0;
    }
    
    /**
     * Format logged item display message for static hold exercises
     * Uses static hold-appropriate terminology (duration × sets)
     */
    public function formatLoggedItemDisplay(LiftLog $liftLog): string
    {
        return $this->formatCompleteDisplay($liftLog);
    }
    
    /**
     * Format form message display for static hold exercises
     * Uses static hold-appropriate terminology (duration × sets)
     */
    public function formatFormMessageDisplay(array $lastSession): string
    {
        $duration = $lastSession['time'] ?? 0;
        $weight = $lastSession['weight'] ?? 0;
        $sets = $lastSession['sets'] ?? 1;
        
        // Format duration
        if (!is_numeric($duration) || $duration <= 0) {
            $durationDisplay = '0s hold';
        } else {
            $durationDisplay = $this->formatDuration((int)$duration);
        }
        
        // Add weight if present
        if (is_numeric($weight) && $weight > 0) {
            $loggedUnit = $lastSession['unit'] ?? 'lbs';
            $weightFormatted = $this->unitResolver()->formatForUser($weight, $loggedUnit, auth()->user());
            $durationDisplay .= " +{$weightFormatted}";
        }
        
        $setsText = $sets == 1 ? 'set' : 'sets';
        
        return "{$durationDisplay} × {$sets} {$setsText}";
    }
    
    /**
     * Format table cell display for static hold exercises
     * Returns the complete display as primary text with duration/sets breakdown
     */
    public function formatTableCellDisplay(LiftLog $liftLog): array
    {
        $duration = $liftLog->liftSets->first()?->time ?? 0;
        $weight = $liftLog->display_weight;
        $sets = $liftLog->display_rounds;
        $loggedUnit = $liftLog->liftSets->first()->unit ?? 'lbs';
        
        $durationDisplay = $this->formatDuration($duration);
        
        // Add weight if present
        if (is_numeric($weight) && $weight > 0) {
            $weightFormatted = $this->unitResolver()->formatForUser($weight, $loggedUnit, $liftLog->user);
            $durationDisplay .= " +{$weightFormatted}";
        }
        
        $setsText = "{$sets} " . ($sets == 1 ? 'set' : 'sets');
        
        return [
            'primary' => $durationDisplay,
            'secondary' => $setsText
        ];
    }
    
    /**
     * Format 1RM table cell display for static hold exercises
     * Static hold exercises don't support 1RM calculation
     */
    public function format1RMTableCellDisplay(LiftLog $liftLog): string
    {
        return 'N/A (Static Hold)';
    }
    
    /**
     * Get exercise type display name and icon for static hold exercises
     */
    public function getTypeDisplayInfo(): array
    {
        return [
            'icon' => 'fas fa-hand-paper',
            'name' => 'Static Hold'
        ];
    }
    
    /**
     * Get chart title for static hold exercises
     */
    public function getChartTitle(): string
    {
        return 'Hold Duration Progress';
    }
    
    /**
     * Format a single set badge for static hold exercises.
     * Shows duration with optional added weight, e.g. "30s hold" or "30s hold +25 lbs".
     */
    public function formatSingleSetBadge(LiftSet $set, ?User $user = null): string
    {
        $duration = (int) ($set->time ?? 0);
        $weight = (float) $set->weight;
        $unit = $set->unit ?? 'lbs';

        if ($duration <= 0) {
            return '0s hold';
        }

        $durationDisplay = $this->formatDuration($duration);

        if ($weight > 0) {
            $weightFormatted = $this->unitResolver()->formatForUser($weight, $unit, $user);
            return "{$durationDisplay} +{$weightFormatted}";
        }

        return $durationDisplay;
    }

    /**
     * For static holds, effort is always "1" (one hold per set).
     * The badge itself already contains the duration.
     */
    protected function getSetEffortValue(LiftSet $set): string
    {
        return '1';
    }

    /**
     * Format badge group label for static holds.
     * Renders as "{count} × {badge}" (e.g. "3 × 30s hold") instead of the default.
     */
    protected function formatBadgeGroupLabel(int $count, string $effort, string $badgeLabel): string
    {
        $setsText = $count == 1 ? '1 set' : $count . ' sets';

        if ($badgeLabel === '') {
            return $setsText;
        }

        return "<strong>{$badgeLabel}</strong> -&nbsp;{$setsText}";
    }

    /**
     * For static holds, uniform reps/sets displays as "{count} sets".
     */
    protected function formatUniformRepsSets(int $count, string $effort): string
    {
        return $count == 1 ? '1 set' : $count . ' sets';
    }

    /**
     * Format success message description for static hold exercises
     * Uses duration and sets terminology instead of weight/reps/sets
     */
    public function formatSuccessMessageDescription(?float $weight, int $reps, int $rounds, ?string $bandColor = null, ?int $time = null): string
    {
        // For static holds, time parameter contains duration in seconds
        $duration = $time ?? $reps;
        
        $durationDisplay = $this->formatDuration($duration);
        
        // Add weight if present
        if (is_numeric($weight) && $weight > 0) {
            $unit = $this->unitResolver()->getPreferredWeightUnit(auth()->user());
            $weightFormatted = $this->unitResolver()->format($weight, $unit);
            $durationDisplay .= " +{$weightFormatted}";
        }
        
        $setsText = $rounds == 1 ? 'set' : 'sets';
        
        return "{$durationDisplay} × {$rounds} {$setsText}";
    }
    
    /**
     * Get progression suggestion for static hold exercises
     * Implements duration/weight-based progression logic
     */
    public function getProgressionSuggestion(\App\Models\LiftLog $lastLog, int $userId, int $exerciseId, ?\Carbon\Carbon $forDate = null): ?object
    {
        $lastDuration = $lastLog->liftSets->first()?->time ?? 0;
        $lastWeight = $lastLog->display_weight;
        $lastSets = $lastLog->liftSets->count();
        
        // Validate that we have valid static hold data
        if (!is_numeric($lastDuration) || $lastDuration <= 0) {
            // No valid history, provide sensible defaults
            return $this->getDefaultStaticHoldSuggestion();
        }
        
        // For holds under 60 seconds, suggest small duration increases
        // Don't add weight until they can hold for 60 seconds
        if ($lastDuration < 60) {
            // Very conservative progression: 1-2 seconds
            $increment = $lastDuration < 30 ? 1 : 2;
            $suggestedDuration = min($lastDuration + $increment, self::MAX_DURATION);
            
            return (object)[
                'sets' => $lastSets,
                'time' => $suggestedDuration,
                'weight' => 0, // No weight until 60s hold
                'band_color' => null, // not applicable for static holds
            ];
        }
        
        // For durations >= 60s: suggest adding weight or sets
        if (!is_numeric($lastWeight) || $lastWeight == 0) {
            // Suggest adding weight
            $unitResolver = $this->unitResolver();
            $increment = $unitResolver->getWeightIncrement($lastLog->user);
            return (object)[
                'sets' => $lastSets,
                'time' => $lastDuration,
                'weight' => $increment,
                'band_color' => null,
            ];
        } else {
            // Suggest adding more sets
            $suggestedSets = min($lastSets + 1, 10);
            
            $unitResolver = $this->unitResolver();
            $preferredUnit = $unitResolver->getPreferredWeightUnit($lastLog->user);
            $loggedUnit = $lastLog->liftSets->first()->unit ?? 'lbs';
            $convertedWeight = $unitResolver->convert($lastWeight, $loggedUnit, $preferredUnit);
            
            return (object)[
                'sets' => $suggestedSets,
                'time' => $lastDuration,
                'weight' => $convertedWeight,
                'band_color' => null,
            ];
        }
    }
    
    /**
     * Provide sensible default static hold suggestions when no history exists
     */
    private function getDefaultStaticHoldSuggestion(): object
    {
        return (object)[
            'sets' => 3, // 3 sets
            'time' => 30, // 30 seconds duration
            'weight' => 0, // bodyweight only
            'band_color' => null, // not applicable for static holds
        ];
    }
    
}
