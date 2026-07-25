<?php

namespace App\Services\ExerciseTypes;

use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\User;

/**
 * Sled Exercise Type Strategy
 *
 * Handles sled-based exercises (Sled Push, Sled Pull) that combine plate weight
 * with distance. Display shows the weight badge (plate weight) and distance as
 * the effort metric instead of reps.
 *
 * Characteristics:
 * - Weight represents plates loaded on the sled
 * - Distance + distance_unit represent how far the sled was pushed/pulled
 * - No reps field (single effort per set)
 * - Does not support 1RM calculation
 * - Uses distance-based display formatting
 *
 * @package App\Services\ExerciseTypes
 * @since 1.0.0
 */
class SledExerciseType extends BaseExerciseType
{
    /**
     * Get the type name identifier
     */
    public function getTypeName(): string
    {
        return 'sled';
    }

    /**
     * Process lift data according to sled exercise rules
     *
     * For sled exercises:
     * - Weight represents plates on the sled
     * - Distance and distance_unit are required
     * - Band color is always null
     * - Reps is not used (set to null)
     */
    public function processLiftData(array $data): array
    {
        $processedData = $data;

        // Nullify fields not used by sled
        $processedData['band_color'] = null;
        $processedData['reps'] = null;

        return $processedData;
    }

    /**
     * Process exercise data according to sled exercise rules
     */
    public function processExerciseData(array $data): array
    {
        $processedData = $data;
        $processedData['exercise_type'] = 'sled';
        $processedData['is_bodyweight'] = false;

        return $processedData;
    }

    /**
     * Format weight display for sled exercises
     * Shows the plate weight loaded on the sled.
     */
    public function formatWeightDisplay(LiftLog $liftLog): string
    {
        $firstSet = $liftLog->relationLoaded('liftSets')
            ? $liftLog->liftSets->first()
            : $liftLog->liftSets()->first();

        if (!$firstSet) {
            return '0 lbs';
        }

        $weight = (float) $firstSet->weight;
        $unit = $firstSet->unit ?? 'lbs';

        if ($weight <= 0) {
            return 'BW';
        }

        return $weight . ' ' . $unit;
    }

    /**
     * Format a single set badge for sled exercises.
     * Shows plate weight (e.g. "90 lbs").
     */
    public function formatSingleSetBadge(LiftSet $set, ?User $user = null): string
    {
        $weight = (float) $set->weight;
        $unit = $set->unit ?? 'lbs';

        if ($weight <= 0) {
            return 'BW';
        }

        return $this->unitResolver()->formatForUser($weight, $unit, $user);
    }

    /**
     * For sled, effort is the distance (e.g. "200 m") not reps.
     */
    protected function getSetEffortValue(LiftSet $set): string
    {
        $distance = (float) ($set->distance ?? 0);
        $unit = $set->distance_unit ?? 'm';

        if ($distance <= 0) {
            return '0 ' . $unit;
        }

        // Integer display for whole numbers, decimal for fractional
        $display = ($distance == (int) $distance) ? (int) $distance : $distance;

        return $display . ' ' . $unit;
    }

    /**
     * Format uniform reps/sets for sled.
     * Renders as "{count} x {distance}" (e.g. "1 x 200 m").
     */
    protected function formatUniformRepsSets(int $count, string $effort): string
    {
        return $count . ' x ' . $effort;
    }

    /**
     * Format complete display showing weight and distance
     */
    public function formatCompleteDisplay(LiftLog $liftLog): string
    {
        $weightDisplay = $this->formatWeightDisplay($liftLog);

        $firstSet = $liftLog->relationLoaded('liftSets')
            ? $liftLog->liftSets->first()
            : $liftLog->liftSets()->first();

        if (!$firstSet) {
            return $weightDisplay;
        }

        $distance = (float) ($firstSet->distance ?? 0);
        $unit = $firstSet->distance_unit ?? 'm';

        if ($distance <= 0) {
            return $weightDisplay;
        }

        $distanceDisplay = ($distance == (int) $distance) ? (int) $distance : $distance;

        return "{$weightDisplay} × {$distanceDisplay}{$unit}";
    }

    /**
     * Get form field definitions for sled exercises
     */
    public function getFormFieldDefinitions(array $defaults = [], ?User $user = null): array
    {
        return [
            [
                'name' => 'weight',
                'label' => 'Weight (lbs):',
                'type' => 'numeric',
                'defaultValue' => $defaults['weight'] ?? 90,
                'increment' => 45,
                'min' => 0,
                'max' => 1000,
            ],
            [
                'name' => 'distance',
                'label' => 'Distance:',
                'type' => 'numeric',
                'defaultValue' => $defaults['distance'] ?? 50,
                'increment' => 5,
                'min' => 0,
                'max' => 999,
            ],
        ];
    }

    /**
     * Format logged item display message for sled exercises
     */
    public function formatLoggedItemDisplay(LiftLog $liftLog): string
    {
        return $this->formatCompleteDisplay($liftLog);
    }

    /**
     * Format form message display for sled exercises
     */
    public function formatFormMessageDisplay(array $lastSession): string
    {
        $weight = $lastSession['weight'] ?? 0;
        $distance = $lastSession['distance'] ?? 0;
        $unit = $lastSession['distance_unit'] ?? 'm';

        return "{$weight} lbs × {$distance}{$unit}";
    }

    /**
     * Format table cell display for sled exercises
     */
    public function formatTableCellDisplay(LiftLog $liftLog): array
    {
        return [
            'primary' => $this->formatCompleteDisplay($liftLog),
        ];
    }

    /**
     * Format 1RM table cell display for sled exercises
     * Sled exercises don't support 1RM calculation
     */
    public function format1RMTableCellDisplay(LiftLog $liftLog): string
    {
        return 'N/A (Sled)';
    }

    /**
     * Get exercise type display name and icon for sled exercises
     */
    public function getTypeDisplayInfo(): array
    {
        return [
            'icon' => 'fas fa-arrow-right',
            'name' => 'Sled',
        ];
    }

    /**
     * Get chart title for sled exercises
     */
    public function getChartTitle(): string
    {
        return 'Sled Progress';
    }

    /**
     * Format progression suggestion for sled exercises
     */
    public function formatProgressionSuggestion(LiftLog $liftLog): ?string
    {
        $firstSet = $liftLog->relationLoaded('liftSets')
            ? $liftLog->liftSets->first()
            : $liftLog->liftSets()->first();

        if (!$firstSet) {
            return null;
        }

        $weight = (float) $firstSet->weight;
        $distance = (float) ($firstSet->distance ?? 0);
        $unit = $firstSet->distance_unit ?? 'm';

        // Suggest adding 45 lbs or extending distance
        $newWeight = $weight + 45;

        return "Try {$newWeight} lbs × {$distance}{$unit}";
    }

    /**
     * Format success message description for sled exercises
     */
    public function formatSuccessMessageDescription(?float $weight, int $reps, int $rounds, ?string $bandColor = null): string
    {
        $weightDisplay = $weight ? "{$weight} lbs" : 'BW';
        $setsText = $rounds == 1 ? '1 set' : "{$rounds} sets";

        return "{$weightDisplay} × {$setsText}";
    }

    /**
     * Normalize distance to integer meters for cross-unit PR comparison.
     * Both Athlete (JS) and Logger (PHP) MUST use identical logic:
     *   - 'm': round(distance) — already meters, round to int
     *   - 'ft': round(distance * 0.3048) — convert then round to int
     *
     * Contract: output is always a non-negative integer.
     *
     * @param float|int $distance Raw distance value
     * @param string $unit 'm' or 'ft'
     * @return int Integer meters
     */
    public static function normalizeDistanceToMeters(float|int $distance, string $unit): int
    {
        if ($distance <= 0) return 0;
        if ($unit === 'ft') return (int) round($distance * 0.3048);
        return (int) round($distance);
    }

    /**
     * Get supported PR types for sled exercises
     *
     * Sled supports three PR types:
     * - sled_weight: heaviest plate load in any single set
     * - sled_distance: longest single push/pull (normalized to meters)
     * - sled_volume: highest sum of weight × normalizedDistance in a session
     */
    public function getSupportedPRTypes(): array
    {
        return [
            \App\Enums\PRType::VOLUME,
        ];
    }

    /**
     * Calculate current metrics from a lift log
     *
     * For sled exercises:
     * - maxWeight: heaviest plate load in any single set (integer lbs)
     * - maxDistance: longest single push in normalized meters (integer)
     * - totalVolume: sum of weight × normalizedDistance across all sets (integer)
     */
    public function calculateCurrentMetrics(LiftLog $liftLog): array
    {
        $maxWeight = 0;
        $maxDistance = 0;
        $totalVolume = 0;

        foreach ($liftLog->liftSets as $set) {
            $weight = (int) ($set->weight ?? 0);
            $distance = self::normalizeDistanceToMeters(
                (float) ($set->distance ?? 0),
                $set->distance_unit ?? 'm'
            );

            if ($weight > $maxWeight) $maxWeight = $weight;
            if ($distance > $maxDistance) $maxDistance = $distance;
            $totalVolume += $weight * $distance;
        }

        return [
            'maxWeight' => $maxWeight,
            'maxDistance' => $maxDistance,
            'totalVolume' => $totalVolume,
        ];
    }

    /**
     * Compare current metrics to previous logs and detect PRs
     *
     * PR detection rules (identical to Athlete JS):
     * - All values are integers after normalization
     * - PR fires when current > stored (strict greater than, no tolerance)
     * - First-ever log: all three fire if non-zero
     */
    public function compareToPrevious(array $currentMetrics, \Illuminate\Database\Eloquent\Collection $previousLogs, LiftLog $currentLog): array
    {
        $prs = [];

        if ($previousLogs->isEmpty()) {
            if ($currentMetrics['maxWeight'] > 0) {
                $prs[] = [
                    'type' => 'sled_weight',
                    'value' => $currentMetrics['maxWeight'],
                    'previous_value' => null,
                    'previous_lift_log_id' => null,
                ];
            }
            if ($currentMetrics['maxDistance'] > 0) {
                $prs[] = [
                    'type' => 'sled_distance',
                    'value' => $currentMetrics['maxDistance'],
                    'previous_value' => null,
                    'previous_lift_log_id' => null,
                ];
            }
            if ($currentMetrics['totalVolume'] > 0) {
                $prs[] = [
                    'type' => 'sled_volume',
                    'value' => $currentMetrics['totalVolume'],
                    'previous_value' => null,
                    'previous_lift_log_id' => null,
                ];
            }
            return $prs;
        }

        // Find previous bests
        $bestWeight = 0;
        $bestWeightLogId = null;
        $bestDistance = 0;
        $bestDistanceLogId = null;
        $bestVolume = 0;
        $bestVolumeLogId = null;

        foreach ($previousLogs as $log) {
            $logVolume = 0;
            foreach ($log->liftSets as $set) {
                $weight = (int) ($set->weight ?? 0);
                $distance = self::normalizeDistanceToMeters(
                    (float) ($set->distance ?? 0),
                    $set->distance_unit ?? 'm'
                );

                if ($weight > $bestWeight) {
                    $bestWeight = $weight;
                    $bestWeightLogId = $log->id;
                }
                if ($distance > $bestDistance) {
                    $bestDistance = $distance;
                    $bestDistanceLogId = $log->id;
                }
                $logVolume += $weight * $distance;
            }
            if ($logVolume > $bestVolume) {
                $bestVolume = $logVolume;
                $bestVolumeLogId = $log->id;
            }
        }

        // Weight PR (strict integer comparison, no tolerance)
        if ($currentMetrics['maxWeight'] > $bestWeight) {
            $prs[] = [
                'type' => 'sled_weight',
                'value' => $currentMetrics['maxWeight'],
                'previous_value' => $bestWeight,
                'previous_lift_log_id' => $bestWeightLogId,
            ];
        }

        // Distance PR (strict integer comparison, no tolerance)
        if ($currentMetrics['maxDistance'] > $bestDistance) {
            $prs[] = [
                'type' => 'sled_distance',
                'value' => $currentMetrics['maxDistance'],
                'previous_value' => $bestDistance,
                'previous_lift_log_id' => $bestDistanceLogId,
            ];
        }

        // Volume PR (strict integer comparison, no tolerance)
        if ($currentMetrics['totalVolume'] > $bestVolume) {
            $prs[] = [
                'type' => 'sled_volume',
                'value' => $currentMetrics['totalVolume'],
                'previous_value' => $bestVolume,
                'previous_lift_log_id' => $bestVolumeLogId,
            ];
        }

        return $prs;
    }

    /**
     * Format PR display for beaten PRs table
     */
    public function formatPRDisplay(\App\Models\PersonalRecord $pr, LiftLog $liftLog): array
    {
        return match($pr->pr_type) {
            'sled_weight' => [
                'label' => 'Heaviest Sled',
                'value' => $pr->previous_value ? ((int) $pr->previous_value) . ' lbs' : '-',
                'comparison' => ((int) $pr->value) . ' lbs',
            ],
            'sled_distance' => [
                'label' => 'Farthest Push',
                'value' => $pr->previous_value ? ((int) $pr->previous_value) . 'm' : '-',
                'comparison' => ((int) $pr->value) . 'm',
            ],
            'sled_volume' => [
                'label' => 'Session Volume',
                'value' => $pr->previous_value ? number_format((float) $pr->previous_value) : '-',
                'comparison' => number_format((float) $pr->value),
            ],
            default => [
                'label' => ucfirst(str_replace('_', ' ', $pr->pr_type)),
                'value' => $pr->previous_value ? (string) $pr->previous_value : '-',
                'comparison' => (string) $pr->value,
            ],
        };
    }

    /**
     * Format PR display for current records table
     */
    public function formatCurrentPRDisplay(\App\Models\PersonalRecord $pr, LiftLog $liftLog, bool $isCurrent): array
    {
        return match($pr->pr_type) {
            'sled_weight' => [
                'label' => 'Heaviest Sled',
                'value' => ((int) $pr->value) . ' lbs',
                'is_current' => $isCurrent,
            ],
            'sled_distance' => [
                'label' => 'Farthest Push',
                'value' => ((int) $pr->value) . 'm',
                'is_current' => $isCurrent,
            ],
            'sled_volume' => [
                'label' => 'Session Volume',
                'value' => number_format((float) $pr->value),
                'is_current' => $isCurrent,
            ],
            default => [
                'label' => ucfirst(str_replace('_', ' ', $pr->pr_type)),
                'value' => (string) $pr->value,
                'is_current' => $isCurrent,
            ],
        };
    }

    /**
     * Get progression suggestion for sled exercises
     */
    public function getProgressionSuggestion(LiftLog $lastLog, int $userId, int $exerciseId, ?\Carbon\Carbon $forDate = null): ?object
    {
        $firstSet = $lastLog->liftSets->first();

        if (!$firstSet) {
            return (object) [
                'sets' => 1,
                'weight' => 90,
                'distance' => 50,
                'distance_unit' => 'm',
                'reps' => null,
                'band_color' => null,
            ];
        }

        $weight = (float) $firstSet->weight;
        $distance = (float) ($firstSet->distance ?? 50);
        $unit = $firstSet->distance_unit ?? 'm';

        return (object) [
            'sets' => $lastLog->liftSets->count(),
            'weight' => $weight + 45,
            'distance' => $distance,
            'distance_unit' => $unit,
            'reps' => null,
            'band_color' => null,
        ];
    }
}
