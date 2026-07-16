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
    public function getSupportedPRTypes(): array
    {
        return [
            \App\Enums\PRType::ONE_RM,
            \App\Enums\PRType::REP_SPECIFIC,
            \App\Enums\PRType::VOLUME,
            \App\Enums\PRType::DENSITY,
        ];
    }
    
    /**
     * Calculate current metrics from a lift log
     * 
     * Extracts all relevant metrics for PR detection:
     * - best_1rm: Highest estimated 1RM from all sets
     * - total_volume: Sum of (weight × reps) for all sets
     * - rep_weights: Map of rep count => best weight for that rep count
     * - weight_reps: Map of weight => best reps at that weight
     * - weight_sets: Map of weight => number of sets at that weight
     */
    public function calculateCurrentMetrics(LiftLog $liftLog): array
    {
        $best1RM = 0;
        $totalVolume = 0;
        $repWeights = []; // [reps => weight]
        $weightReps = []; // [weight_string => reps]
        $weightSets = []; // [weight_string => set_count]
        $weightMinReps = []; // [weight_string => min_reps] - for density PR
        
        foreach ($liftLog->liftSets as $set) {
            if ($set->weight > 0 && $set->reps > 0) {
                // Calculate 1RM
                try {
                    $estimated1RM = $this->calculate1RM($set->weight, $set->reps, $liftLog);
                    $best1RM = max($best1RM, $estimated1RM);
                } catch (\Exception $e) {
                    // Skip if calculation fails
                }
                
                // Calculate volume
                $totalVolume += ($set->weight * $set->reps);
                
                // Track rep-specific (only up to 10 reps)
                if ($set->reps <= 10) {
                    if (!isset($repWeights[$set->reps]) || $set->weight > $repWeights[$set->reps]) {
                        $repWeights[$set->reps] = $set->weight;
                    }
                }
                
                // Track hypertrophy (best reps at weight) - use string key to preserve float precision
                $weightKey = (string)$set->weight;
                if (!isset($weightReps[$weightKey]) || $set->reps > $weightReps[$weightKey]) {
                    $weightReps[$weightKey] = $set->reps;
                }
                
                // Track density (number of sets at weight) - use string key to preserve float precision
                if (!isset($weightSets[$weightKey])) {
                    $weightSets[$weightKey] = 0;
                    $weightMinReps[$weightKey] = $set->reps; // Initialize with first set's reps
                }
                $weightSets[$weightKey]++;
                // Track minimum reps at this weight for density PR comparison
                $weightMinReps[$weightKey] = min($weightMinReps[$weightKey], $set->reps);
            }
        }
        
        return [
            'best_1rm' => $best1RM,
            'total_volume' => $totalVolume,
            'rep_weights' => $repWeights,
            'weight_reps' => $weightReps,
            'weight_sets' => $weightSets,
            'weight_min_reps' => $weightMinReps,
        ];
    }
    
    /**
     * Compare current metrics to previous logs and detect PRs
     * 
     * Checks each PR type against previous bests and returns array of PR records.
     */
    public function compareToPrevious(array $currentMetrics, \Illuminate\Database\Eloquent\Collection $previousLogs, LiftLog $currentLog): array
    {
        $prs = [];
        $tolerance = 0.1; // Weight tolerance for comparisons
        $volumeTolerancePercent = 0.01; // 1% relative tolerance for volume
        
        // If no previous logs, all non-zero metrics are PRs
        if ($previousLogs->isEmpty()) {
            if ($currentMetrics['best_1rm'] > 0) {
                $prs[] = [
                    'type' => 'one_rm',
                    'value' => $currentMetrics['best_1rm'],
                    'previous_value' => null,
                    'previous_lift_log_id' => null,
                ];
            }
            
            if ($currentMetrics['total_volume'] > 0) {
                $prs[] = [
                    'type' => 'volume',
                    'value' => $currentMetrics['total_volume'],
                    'previous_value' => null,
                    'previous_lift_log_id' => null,
                ];
            }
            
            foreach ($currentMetrics['rep_weights'] as $reps => $weight) {
                if ($weight > 0) {
                    $prs[] = [
                        'type' => 'rep_specific',
                        'rep_count' => $reps,
                        'value' => $weight,
                        'previous_value' => null,
                        'previous_lift_log_id' => null,
                    ];
                }
            }
            
            return $prs;
        }
        
        $targetUnit = $currentLog->liftSets->first()->unit ?? 'lbs';

        // Check 1RM PR
        $best1RMResult = $this->getBest1RM($previousLogs, $targetUnit);
        if ($currentMetrics['best_1rm'] > $best1RMResult['value'] + $tolerance) {
            $prs[] = [
                'type' => 'one_rm',
                'value' => $currentMetrics['best_1rm'],
                'previous_value' => $best1RMResult['value'],
                'previous_lift_log_id' => $best1RMResult['lift_log_id'],
            ];
        }
        
        // Check Volume PR
        $bestVolumeResult = $this->getBestVolume($previousLogs, $targetUnit);
        $volumeTolerance = $bestVolumeResult['value'] * $volumeTolerancePercent;
        if ($currentMetrics['total_volume'] > $bestVolumeResult['value'] + $volumeTolerance) {
            $prs[] = [
                'type' => 'volume',
                'value' => $currentMetrics['total_volume'],
                'previous_value' => $bestVolumeResult['value'],
                'previous_lift_log_id' => $bestVolumeResult['lift_log_id'],
            ];
        }
        
        // Check Rep-Specific PRs
        foreach ($currentMetrics['rep_weights'] as $reps => $weight) {
            $previousBestResult = $this->getBestWeightForReps($previousLogs, $reps, $targetUnit);
            
            // No previous best at this rep count — first time is always a PR
            if ($previousBestResult['weight'] == 0) {
                $prs[] = [
                    'type' => 'rep_specific',
                    'rep_count' => $reps,
                    'value' => $weight,
                    'previous_value' => null,
                    'previous_lift_log_id' => null,
                ];
                continue;
            }
            
            if ($weight > $previousBestResult['weight'] + $tolerance) {
                $prs[] = [
                    'type' => 'rep_specific',
                    'rep_count' => $reps,
                    'value' => $weight,
                    'previous_value' => $previousBestResult['weight'],
                    'previous_lift_log_id' => $previousBestResult['lift_log_id'],
                ];
            }
        }
        
        // Check Hypertrophy PRs (best reps at a given weight)
        foreach ($currentMetrics['weight_reps'] as $weightKey => $reps) {
            $weight = (float)$weightKey; // Convert string key back to float
            $previousBestResult = $this->getBestRepsAtWeight($previousLogs, $weight, $targetUnit);
            // Only award hypertrophy PR if there was a previous lift at this weight
            if ($reps > $previousBestResult['reps'] && $previousBestResult['reps'] > 0) {
                $prs[] = [
                    'type' => 'hypertrophy',
                    'weight' => $weight,
                    'value' => $reps,
                    'previous_value' => $previousBestResult['reps'],
                    'previous_lift_log_id' => $previousBestResult['lift_log_id'],
                ];
            }
        }
        
        // Check Density PRs (most sets at a given weight)
        foreach ($currentMetrics['weight_sets'] as $weightKey => $sets) {
            $weight = (float)$weightKey; // Convert string key back to float
            $minReps = $currentMetrics['weight_min_reps'][$weightKey]; // Get minimum reps at this weight
            $previousBestResult = $this->getBestSetsAtWeight($previousLogs, $weight, $targetUnit, $minReps);
            // Only award density PR if there was a previous lift at this weight (within tolerance)
            if ($sets > $previousBestResult['sets'] && $previousBestResult['sets'] > 0) {
                $prs[] = [
                    'type' => 'density',
                    'weight' => $weight, // Store the actual weight used in current lift
                    'value' => $sets,
                    'previous_value' => $previousBestResult['sets'],
                    'previous_lift_log_id' => $previousBestResult['lift_log_id'],
                ];
            }
        }
        
        return $prs;
    }
    
    /**
     * Format PR display for beaten PRs table
     */
    public function formatPRDisplay(\App\Models\PersonalRecord $pr, LiftLog $liftLog): array
    {
        // Special handling for 1RM PRs: skip if it's a true 1RM (from a 1-rep set)
        // because it will be shown as a "1 Rep" PR instead (redundant)
        if ($pr->pr_type === 'one_rm') {
            $hasOneRepSet = $liftLog->liftSets->contains(function ($set) {
                return $set->reps === 1 && $set->weight > 0;
            });
            
            // Return empty array to signal this PR should be skipped
            if ($hasOneRepSet) {
                return [];
            }
        }
        
        $sourceUnit = $pr->unit ?? 'lbs';
        $viewer = auth()->user() ?? $liftLog->user;
        
        return match($pr->pr_type) {
            'one_rm' => [
                'label' => 'Est 1RM',
                'value' => $pr->previous_value ? $this->unitResolver()->formatForUser($pr->previous_value, $sourceUnit, $viewer) : '—',
                'comparison' => $this->unitResolver()->formatForUser($pr->value, $sourceUnit, $viewer),
            ],
            'volume' => [
                'label' => 'Volume',
                'value' => $pr->previous_value ? $this->unitResolver()->formatForUser($pr->previous_value, $sourceUnit, $viewer) : '—',
                'comparison' => $this->unitResolver()->formatForUser($pr->value, $sourceUnit, $viewer),
            ],
            'rep_specific' => [
                'label' => $pr->rep_count . ' Rep' . ($pr->rep_count > 1 ? 's' : ''),
                'value' => ($pr->previous_value && $pr->previous_value > 0) ? $this->unitResolver()->formatForUser($pr->previous_value, $sourceUnit, $viewer) : '—',
                'comparison' => $this->unitResolver()->formatForUser($pr->value, $sourceUnit, $viewer),
            ],
            'hypertrophy' => [
                'label' => 'Best @ ' . $this->unitResolver()->formatForUser($pr->weight, $sourceUnit, $viewer),
                'value' => $pr->previous_value ? (int)$pr->previous_value . ' reps' : '—',
                'comparison' => (int)$pr->value . ' reps',
            ],
            'density' => [
                'label' => 'Sets @ ' . $this->unitResolver()->formatForUser($pr->weight, $sourceUnit, $viewer),
                'value' => $pr->previous_value ? intval($pr->previous_value) . ' set' . (intval($pr->previous_value) > 1 ? 's' : '') : '—',
                'comparison' => intval($pr->value) . ' set' . (intval($pr->value) > 1 ? 's' : ''),
            ],
            default => [
                'label' => ucfirst(str_replace('_', ' ', $pr->pr_type)),
                'value' => $pr->previous_value ? (string)$pr->previous_value : '—',
                'comparison' => (string)$pr->value,
            ],
        };
    }
    
    /**
     * Format PR display for current records table
     */
    public function formatCurrentPRDisplay(\App\Models\PersonalRecord $pr, LiftLog $liftLog, bool $isCurrent): array
    {
        $sourceUnit = $pr->unit ?? 'lbs';
        $viewer = auth()->user() ?? $liftLog->user;
        
        return match($pr->pr_type) {
            'one_rm' => [
                'label' => 'Est 1RM',
                'value' => $this->unitResolver()->formatForUser($pr->value, $sourceUnit, $viewer),
                'is_current' => $isCurrent,
            ],
            'volume' => [
                'label' => 'Volume',
                'value' => $this->unitResolver()->formatForUser($pr->value, $sourceUnit, $viewer),
                'is_current' => $isCurrent,
            ],
            'rep_specific' => [
                'label' => $pr->rep_count . ' Rep' . ($pr->rep_count > 1 ? 's' : ''),
                'value' => $this->unitResolver()->formatForUser($pr->value, $sourceUnit, $viewer),
                'is_current' => $isCurrent,
            ],
            'hypertrophy' => [
                'label' => 'Best @ ' . $this->unitResolver()->formatForUser($pr->weight, $sourceUnit, $viewer),
                'value' => (int)$pr->value . ' reps',
                'is_current' => $isCurrent,
            ],
            'density' => [
                'label' => 'Sets @ ' . $this->unitResolver()->formatForUser($pr->weight, $sourceUnit, $viewer),
                'value' => intval($pr->value) . ' set' . (intval($pr->value) > 1 ? 's' : ''),
                'is_current' => $isCurrent,
            ],
            default => [
                'label' => ucfirst(str_replace('_', ' ', $pr->pr_type)),
                'value' => (string)$pr->value,
                'is_current' => $isCurrent,
            ],
        };
    }
    
    // ========================================================================
    // HELPER METHODS
    // ========================================================================
    
    /**
     * Get best 1RM from previous logs, normalized to target unit
     */
    private function getBest1RM(\Illuminate\Database\Eloquent\Collection $logs, string $targetUnit): array
    {
        $best1RM = 0;
        $liftLogId = null;
        $unitResolver = $this->unitResolver();
        
        foreach ($logs as $log) {
            $loggedUnit = $log->liftSets->first()->unit ?? 'lbs';
            foreach ($log->liftSets as $set) {
                if ($set->weight > 0 && $set->reps > 0) {
                    try {
                        $weightInTargetUnit = $unitResolver->convert($set->weight, $loggedUnit, $targetUnit);
                        $estimated1RM = $this->calculate1RM($weightInTargetUnit, $set->reps, $log);
                        if ($estimated1RM > $best1RM) {
                            $best1RM = $estimated1RM;
                            $liftLogId = $log->id;
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        }
        
        return ['value' => $best1RM, 'lift_log_id' => $liftLogId];
    }
    
    /**
     * Get best volume from previous logs, normalized to target unit
     */
    private function getBestVolume(\Illuminate\Database\Eloquent\Collection $logs, string $targetUnit): array
    {
        $bestVolume = 0;
        $liftLogId = null;
        $unitResolver = $this->unitResolver();
        
        foreach ($logs as $log) {
            $logVolume = 0;
            $loggedUnit = $log->liftSets->first()->unit ?? 'lbs';
            foreach ($log->liftSets as $set) {
                if ($set->weight > 0 && $set->reps > 0) {
                    $weightInTargetUnit = $unitResolver->convert($set->weight, $loggedUnit, $targetUnit);
                    $logVolume += ($weightInTargetUnit * $set->reps);
                }
            }
            if ($logVolume > $bestVolume) {
                $bestVolume = $logVolume;
                $liftLogId = $log->id;
            }
        }
        
        return ['value' => $bestVolume, 'lift_log_id' => $liftLogId];
    }
    
    /**
     * Get best weight for specific rep count from previous logs, normalized to target unit
     */
    private function getBestWeightForReps(\Illuminate\Database\Eloquent\Collection $logs, int $targetReps, string $targetUnit): array
    {
        $maxWeight = 0;
        $liftLogId = null;
        $unitResolver = $this->unitResolver();
        
        foreach ($logs as $log) {
            $loggedUnit = $log->liftSets->first()->unit ?? 'lbs';
            foreach ($log->liftSets as $set) {
                if ($set->reps == $targetReps) {
                    $weightInTargetUnit = $unitResolver->convert($set->weight, $loggedUnit, $targetUnit);
                    if ($weightInTargetUnit > $maxWeight) {
                        $maxWeight = $weightInTargetUnit;
                        $liftLogId = $log->id;
                    }
                }
            }
        }
        
        return ['weight' => $maxWeight, 'lift_log_id' => $liftLogId];
    }
    
    /**
     * Get best reps at specific weight from previous logs, normalized to target unit
     */
    private function getBestRepsAtWeight(\Illuminate\Database\Eloquent\Collection $logs, float $targetWeight, string $targetUnit): array
    {
        $maxReps = 0;
        $liftLogId = null;
        $tolerance = (strtolower($targetUnit) === 'kg') ? 0.25 : 0.5; // Weight tolerance for matching
        $unitResolver = $this->unitResolver();
        
        foreach ($logs as $log) {
            $loggedUnit = $log->liftSets->first()->unit ?? 'lbs';
            foreach ($log->liftSets as $set) {
                if ($set->reps > 0) {
                    $weightInTargetUnit = $unitResolver->convert($set->weight, $loggedUnit, $targetUnit);
                    if (abs($weightInTargetUnit - $targetWeight) <= $tolerance && $set->reps > $maxReps) {
                        $maxReps = $set->reps;
                        $liftLogId = $log->id;
                    }
                }
            }
        }
        
        return ['reps' => $maxReps, 'lift_log_id' => $liftLogId];
    }
    
    /**
     * Get best number of sets at specific weight from previous logs, normalized to target unit
     * Only counts sets that meet or exceed the minimum rep threshold
     * 
     * @param Collection $logs Previous lift logs
     * @param float $targetWeight Weight to match (with tolerance)
     * @param string $targetUnit The target unit to normalize to
     * @param int $minReps Minimum reps required for a set to count
     * @return array ['sets' => int, 'lift_log_id' => int|null]
     */
    private function getBestSetsAtWeight(\Illuminate\Database\Eloquent\Collection $logs, float $targetWeight, string $targetUnit, int $minReps = 1): array
    {
        $maxSets = 0;
        $liftLogId = null;
        $tolerance = (strtolower($targetUnit) === 'kg') ? 0.25 : 0.5; // Weight tolerance for matching
        $unitResolver = $this->unitResolver();
        
        foreach ($logs as $log) {
            $setsAtWeight = 0;
            $loggedUnit = $log->liftSets->first()->unit ?? 'lbs';
            foreach ($log->liftSets as $set) {
                // Count sets that match weight (within tolerance) AND meet minimum rep threshold
                $weightInTargetUnit = $unitResolver->convert($set->weight, $loggedUnit, $targetUnit);
                if (abs($weightInTargetUnit - $targetWeight) <= $tolerance && $set->reps >= $minReps) {
                    $setsAtWeight++;
                }
            }
            if ($setsAtWeight > $maxSets) {
                $maxSets = $setsAtWeight;
                $liftLogId = $log->id;
            }
        }
        
        return ['sets' => $maxSets, 'lift_log_id' => $liftLogId];
    }
    
    /**
     * Format weight value for display
     */
    private function formatWeight(float $weight): string
    {
        return $weight == floor($weight) ? number_format($weight, 0) : number_format($weight, 1);
    }
}