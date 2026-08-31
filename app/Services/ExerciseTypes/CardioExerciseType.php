<?php

namespace App\Services\ExerciseTypes;

use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\User;
use App\Services\ExerciseTypes\Exceptions\InvalidExerciseDataException;

/**
 * Cardio Exercise Type Strategy
 * 
 * Handles distance-based cardiovascular exercises like running, cycling, and rowing.
 * Uses the existing database schema by mapping reps to distance (in meters) and
 * sets to rounds, while always setting weight to 0.
 * 
 * Characteristics:
 * - Weight is always 0 (forced)
 * - Band color is always null (not applicable)
 * - Reps field represents distance in meters
 * - Sets field represents rounds/intervals
 * - Does not support 1RM calculation
 * - Uses cardio-specific display formatting
 * - Validates distance within reasonable bounds (50m - 50km)
 * 
 * @package App\Services\ExerciseTypes
 * @since 1.0.0
 * 
 * @example
 * // Typical usage for exercises like "Run", "Cycle", "Row"
 * $strategy = new CardioExerciseType();
 * $processedData = $strategy->processLiftData([
 *     'reps' => '500',     // Distance in meters
 *     'sets' => '7',       // Number of rounds
 *     'weight' => '25',    // Will be forced to 0
 *     'band_color' => 'red' // Will be nullified
 * ]);
 * // Result: ['reps' => 500, 'sets' => 7, 'weight' => 0, 'band_color' => null]
 * 
 * @example
 * // Display formatting
 * $display = $strategy->formatWeightDisplay($liftLog);
 * // Result: "500m"
 */
class CardioExerciseType extends BaseExerciseType
{
    /**
     * Minimum distance in meters (50m)
     */
    private const MIN_DISTANCE = 50;
    
    /**
     * Maximum distance in meters (50km = 50,000m)
     */
    private const MAX_DISTANCE = 50000;
    
    /**
     * Get the type name identifier
     */
    public function getTypeName(): string
    {
        return 'cardio';
    }
    
    /**
     * Process lift data according to cardio exercise rules
     * 
     * For cardio exercises:
     * - Weight is always forced to 0
     * - Band color is always nullified
     * - Reps field represents distance in meters and must be validated
     * - Distance must be between 50m and 50km
     */
    public function processLiftData(array $data): array
    {
        $processedData = $data;
        
        // Force weight to 0 for cardio exercises
        $processedData['weight'] = 0;
        
        // Nullify band_color for cardio exercises
        $processedData['band_color'] = null;
        
        // Validate distance (stored in reps field)
        if (!isset($processedData['reps'])) {
            throw InvalidExerciseDataException::missingField('reps', $this->getTypeName());
        }
        
        if (!is_numeric($processedData['reps'])) {
            throw InvalidExerciseDataException::forField('reps', $this->getTypeName(), 'distance must be a number');
        }
        
        $distance = (int) $processedData['reps'];
        
        if ($distance < self::MIN_DISTANCE) {
            throw InvalidExerciseDataException::forField('reps', $this->getTypeName(), 'distance must be at least ' . self::MIN_DISTANCE . ' meters');
        }
        
        if ($distance > self::MAX_DISTANCE) {
            throw InvalidExerciseDataException::forField('reps', $this->getTypeName(), 'distance cannot exceed ' . self::MAX_DISTANCE . ' meters');
        }
        
        $processedData['distance'] = (float) $distance;
        $processedData['distance_unit'] = 'm';
        $processedData['reps'] = null;
        
        return $processedData;
    }
    
    /**
     * Process exercise data according to cardio exercise rules
     */
    public function processExerciseData(array $data): array
    {
        $processedData = $data;
        
        // For cardio exercises, ensure exercise_type is set correctly
        $processedData['exercise_type'] = 'cardio';
        
        // Cardio exercises are not bodyweight exercises and don't use bands
        $processedData['is_bodyweight'] = false;
        
        return $processedData;
    }
    
    /**
     * Format weight display for cardio exercises
     * 
     * For cardio exercises, we display distance or calories depending on log_type.
     * The distance is stored in the distance field, calories in the calories field.
     */
    public function formatWeightDisplay(LiftLog $liftLog): string
    {
        // Check if this is a calories-based cardio exercise
        $logType = $liftLog->log_type ?: ($liftLog->exercise->log_type ?? null);
        if ($logType === 'cardio-calories') {
            $firstSet = $liftLog->relationLoaded('liftSets')
                ? $liftLog->liftSets->first()
                : $liftLog->liftSets()->first();
            $calories = $firstSet?->calories ?? 0;
            return $calories . ' cal';
        }

        $distance = $liftLog->display_distance;
        
        if (!is_numeric($distance) || $distance <= 0) {
            return '0m';
        }
        
        // Handle edge cases for very short or very long distances
        if ($distance < 100) {
            // For very short distances, show with decimal if needed
            return number_format($distance, 0) . 'm';
        } elseif ($distance >= 10000) {
            // For distances 10km and above, show in km format
            $kilometers = $distance / 1000;
            return number_format($kilometers, 1) . 'km';
        } else {
            // Standard format for distances between 100m and 10km
            return number_format($distance, 0) . 'm';
        }
    }
    
    /**
     * Format complete cardio display showing distance/calories and rounds
     * 
     * Returns a formatted string like "500m × 7 rounds" for distance-based cardio,
     * or "6 cal × 2 rounds" for calories-based cardio.
     */
    public function formatCompleteDisplay(LiftLog $liftLog): string
    {
        // Check if this is a calories-based cardio exercise
        $logType = $liftLog->log_type ?: ($liftLog->exercise->log_type ?? null);
        if ($logType === 'cardio-calories') {
            $rounds = $liftLog->display_rounds;
            if (!is_numeric($rounds) || $rounds <= 0) {
                $rounds = 1;
            }
            $firstSet = $liftLog->relationLoaded('liftSets')
                ? $liftLog->liftSets->first()
                : $liftLog->liftSets()->first();
            $calories = $firstSet?->calories ?? 0;
            $roundsText = $rounds == 1 ? 'round' : 'rounds';
            return "{$calories} cal × {$rounds} {$roundsText}";
        }

        $distance = $liftLog->display_distance;
        $rounds = $liftLog->display_rounds;
        
        if (!is_numeric($distance) || $distance <= 0) {
            $distance = 0;
        }
        
        if (!is_numeric($rounds) || $rounds <= 0) {
            $rounds = 1;
        }
        
        $distanceDisplay = $this->formatWeightDisplay($liftLog);
        $roundsText = $rounds == 1 ? 'round' : 'rounds';
        
        return "{$distanceDisplay} × {$rounds} {$roundsText}";
    }
    
    /**
     * Format progression suggestion for cardio exercises
     * 
     * Cardio progression logic:
     * - For distances < 1000m: suggest increasing distance by 50-100m
     * - For distances >= 1000m: suggest adding additional rounds
     */
    public function formatProgressionSuggestion(LiftLog $liftLog): ?string
    {
        $distance = $liftLog->display_distance;
        $rounds = $liftLog->liftSets->count();
        
        if (!is_numeric($distance) || $distance <= 0) {
            return null;
        }
        
        if ($distance < 1000) {
            // For shorter distances, suggest increasing distance
            $increment = $distance < 500 ? 50 : 100;
            $newDistance = $distance + $increment;
            return "Try {$newDistance}m × {$rounds} rounds";
        } else {
            // For longer distances, suggest adding rounds
            $newRounds = $rounds + 1;
            return "Try {$distance}m × {$newRounds} rounds";
        }
    }
    
    /**
     * Get form field definitions for cardio exercises
     * Cardio exercises only show distance (reps) field, never weight
     */
    public function getFormFieldDefinitions(array $defaults = [], ?User $user = null): array
    {
        $labels = $this->getFieldLabels();
        $increments = $this->getFieldIncrements();
        
        return [
            [
                'name' => 'reps',
                'label' => $labels['reps'],
                'type' => 'numeric',
                'defaultValue' => $defaults['reps'] ?? 500,
                'increment' => $increments['reps'],
                'min' => self::MIN_DISTANCE,
                'max' => self::MAX_DISTANCE,
            ]
        ];
    }
    
    /**
     * Format logged item display message for cardio exercises
     * Uses cardio-appropriate terminology (distance × rounds)
     */
    public function formatLoggedItemDisplay(LiftLog $liftLog): string
    {
        return $this->formatCompleteDisplay($liftLog);
    }
    
    /**
     * Format form message display for cardio exercises
     * Uses cardio-appropriate terminology (distance × rounds)
     */
    public function formatFormMessageDisplay(array $lastSession): string
    {
        $distance = $lastSession['distance'] ?? $lastSession['reps'] ?? 0;
        $rounds = $lastSession['sets'] ?? 1;
        
        // Format distance directly
        if (!is_numeric($distance) || $distance <= 0) {
            $distanceDisplay = '0m';
        } elseif ($distance < 100) {
            $distanceDisplay = number_format($distance, 0) . 'm';
        } elseif ($distance >= 10000) {
            $kilometers = $distance / 1000;
            $distanceDisplay = number_format($kilometers, 1) . 'km';
        } else {
            $distanceDisplay = number_format($distance, 0) . 'm';
        }
        
        $roundsText = $rounds == 1 ? 'round' : 'rounds';
        
        return "{$distanceDisplay} × {$rounds} {$roundsText}";
    }
    
    /**
     * Format table cell display for cardio exercises
     * Returns the complete cardio display as primary text only
     */
    public function formatTableCellDisplay(LiftLog $liftLog): array
    {
        // For cardio, we show the complete display as the primary text only
        return [
            'primary' => $this->formatCompleteDisplay($liftLog)
        ];
    }
    
    /**
     * Format 1RM table cell display for cardio exercises
     * Cardio exercises don't support 1RM calculation
     */
    public function format1RMTableCellDisplay(LiftLog $liftLog): string
    {
        return 'N/A (Cardio)';
    }
    
    /**
     * Get exercise type display name and icon for cardio exercises
     */
    public function getTypeDisplayInfo(): array
    {
        return [
            'icon' => 'fas fa-running',
            'name' => 'Cardio'
        ];
    }
    
    /**
     * Get chart title for cardio exercises
     */
    public function getChartTitle(): string
    {
        return 'Distance Progress';
    }
    
    /**
     * Format a single set badge for cardio exercises.
     * Shows distance (e.g. "500m") or calories (e.g. "6 cal").
     */
    public function formatSingleSetBadge(LiftSet $set, ?User $user = null): string
    {
        // Calories-based cardio
        if ($set->calories && $set->calories > 0) {
            return $set->calories . ' cal';
        }

        // Distance-based cardio
        $distance = (float) ($set->distance ?? 0);

        if ($distance <= 0) {
            return '0m';
        }

        if ($distance < 100) {
            return number_format($distance, 0) . 'm';
        } elseif ($distance >= 10000) {
            return number_format($distance / 1000, 1) . 'km';
        }

        return number_format($distance, 0) . 'm';
    }

    /**
     * For cardio, effort is "1 round" per set — not reps.
     * The grouping badge shows count of rounds, e.g. "3× @ 500m".
     * Override effort label to empty since distance IS the badge.
     */
    protected function getSetEffortValue(LiftSet $set): string
    {
        return '1';
    }

    /**
     * Format badge group label for cardio.
     * Renders as "{count} × {badge}" (e.g. "3 × 500m") instead of standard "{count}×{effort} @ {badge}".
     */
    protected function formatBadgeGroupLabel(int $count, string $effort, string $badgeLabel): string
    {
        $roundsText = $count == 1 ? '1 round' : $count . ' rounds';

        if ($badgeLabel === '') {
            return $roundsText;
        }

        return "<strong>{$badgeLabel}</strong> -&nbsp;{$roundsText}";
    }

    /**
     * For cardio, uniform reps/sets displays as "{count} rounds".
     */
    protected function formatUniformRepsSets(int $count, string $effort): string
    {
        return $count == 1 ? '1 round' : $count . ' rounds';
    }

    /**
     * Format success message description for cardio exercises
     * Uses distance and rounds terminology instead of weight/reps/sets
     */
    public function formatSuccessMessageDescription(?float $weight, int $reps, int $rounds, ?string $bandColor = null): string
    {
        // For cardio, reps represents distance and rounds represents rounds
        $distance = $reps;
        
        // Format distance display
        if ($distance < 100) {
            $distanceDisplay = number_format($distance, 0) . 'm';
        } elseif ($distance >= 10000) {
            $kilometers = $distance / 1000;
            $distanceDisplay = number_format($kilometers, 1) . 'km';
        } else {
            $distanceDisplay = number_format($distance, 0) . 'm';
        }
        
        $roundsText = $rounds == 1 ? 'round' : 'rounds';
        
        return "{$distanceDisplay} × {$rounds} {$roundsText}";
    }
    
    /**
     * Get progression suggestion for cardio exercises
     * Implements distance/rounds-based progression logic
     */
    public function getProgressionSuggestion(\App\Models\LiftLog $lastLog, int $userId, int $exerciseId, ?\Carbon\Carbon $forDate = null): ?object
    {
        $lastDistance = $lastLog->display_distance; // distance in meters
        $lastRounds = $lastLog->liftSets->count();
        
        // Validate that we have valid cardio data
        if (!is_numeric($lastDistance) || $lastDistance <= 0) {
            // No valid history, provide sensible defaults
            return $this->getDefaultCardioSuggestion();
        }
        
        // For distances < 1000m: suggest increasing distance by 50-100m
        if ($lastDistance < 1000) {
            $increment = $lastDistance < 500 ? 50 : 100;
            $suggestedDistance = $lastDistance + $increment;
            
            // Cap the distance increase to reasonable limits
            $suggestedDistance = min($suggestedDistance, 1500);
            
            return (object)[
                'sets' => $lastRounds,
                'distance' => (float) $suggestedDistance,
                'distance_unit' => 'm',
                'reps' => $suggestedDistance,
                'weight' => 0, // always 0 for cardio
                'band_color' => null, // not applicable for cardio
            ];
        }
        
        // For distances >= 1000m: suggest adding additional rounds
        $suggestedRounds = $lastRounds + 1;
        
        // Cap the rounds to reasonable limits
        $suggestedRounds = min($suggestedRounds, 10);
        
        return (object)[
            'sets' => $suggestedRounds,
            'distance' => (float) $lastDistance,
            'distance_unit' => 'm',
            'reps' => $lastDistance,
            'weight' => 0, // always 0 for cardio
            'band_color' => null, // not applicable for cardio
        ];
    }
    
    /**
     * Provide sensible default cardio suggestions when no history exists
     */
    private function getDefaultCardioSuggestion(): object
    {
        return (object)[
            'sets' => 1, // 1 round
            'distance' => 500.0, // 500m distance
            'distance_unit' => 'm',
            'reps' => 500,
            'weight' => 0, // always 0 for cardio
            'band_color' => null, // not applicable for cardio
        ];
    }
    
}