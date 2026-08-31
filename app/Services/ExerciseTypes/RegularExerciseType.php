<?php

namespace App\Services\ExerciseTypes;

use App\Models\LiftLog;
use App\Services\ExerciseTypes\Exceptions\InvalidExerciseDataException;

/**
 * Regular Exercise Type Strategy
 * 
 * Handles traditional weight-based exercises like barbell and dumbbell movements.
 * This is the most common exercise type and serves as the default fallback.
 * 
 * Characteristics:
 * - Requires weight field for all lift logs
 * - Supports 1RM calculation
 * - Uses weight-based display formatting
 * - Nullifies band_color field (incompatible with regular exercises)
 * - Supports linear and double progression models
 * 
 * @package App\Services\ExerciseTypes
 * @since 1.0.0
 * 
 * @example
 * // Typical usage for exercises like "Bench Press", "Squat", "Deadlift"
 * $strategy = new RegularExerciseType();
 * $processedData = $strategy->processLiftData([
 *     'weight' => '135',
 *     'reps' => '8',
 *     'band_color' => 'red' // Will be nullified
 * ]);
 * // Result: ['weight' => 135, 'reps' => 8, 'band_color' => null]
 */
class RegularExerciseType extends BaseExerciseType
{
    /**
     * Get the type name identifier
     */
    public function getTypeName(): string
    {
        return 'regular';
    }
    
    /**
     * Process lift data according to regular exercise rules
     */
    public function processLiftData(array $data): array
    {
        // For regular exercises, ensure weight is set and band_color is null
        $processedData = $data;
        
        // Validate weight is present and numeric
        if (!isset($processedData['weight'])) {
            throw InvalidExerciseDataException::missingField('weight', $this->getTypeName());
        }
        
        if (!is_numeric($processedData['weight'])) {
            throw InvalidExerciseDataException::invalidWeight($processedData['weight'], $this->getTypeName());
        }
        
        if ($processedData['weight'] < 0) {
            throw InvalidExerciseDataException::forField('weight', $this->getTypeName(), 'weight cannot be negative');
        }
        
        // Nullify band_color for regular exercises
        $processedData['band_color'] = null;
        
        return $processedData;
    }
    
    /**
     * Process exercise data according to regular exercise rules
     */
    public function processExerciseData(array $data): array
    {
        $processedData = $data;
        
        // For regular exercises, ensure exercise_type is set correctly
        $processedData['exercise_type'] = 'regular';
        
        // Regular exercises are not bodyweight exercises and don't use bands
        $processedData['is_bodyweight'] = false;
        
        return $processedData;
    }
    
    /**
     * Format weight display for regular exercises
     */
    public function formatWeightDisplay(LiftLog $liftLog): string
    {
        $weight = $liftLog->display_weight;
        $loggedUnit = $liftLog->liftSets->first()->unit ?? 'lbs';
        
        if (!is_numeric($weight) || $weight <= 0) {
            $targetUnit = $this->unitResolver()->getPreferredWeightUnit($liftLog->user);
            return '0 ' . $targetUnit;
        }
        
        return $this->unitResolver()->formatForUser($weight, $loggedUnit, $liftLog->user);
    }
    
    // ========================================================================
    // PR DETECTION METHODS
    // ========================================================================
    
    /**
     * Get supported PR types for regular exercises
     * 
     * Regular exercises support these PR types:
     * - ONE_RM: Estimated one-rep max
     * - REP_SPECIFIC: Best weight for specific rep counts (1-10 reps)
     * - VOLUME: Total session volume (weight × reps × sets)
     * - DENSITY: Most sets completed at a specific weight
     * 
     * Note: Hypertrophy PRs (best reps at weight) are also supported but stored
     * separately in the database with pr_type='hypertrophy' string, not as an enum flag.
     */
}