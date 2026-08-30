<?php

namespace App\Services;

use App\Enums\PRType;
use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class PRDetectionService
{
    /**
     * Store the last calculation snapshot for debugging purposes
     * This is populated during PR detection and can be retrieved for logging
     */
    private ?array $lastCalculationSnapshot = null;

    /**
     * Check if a single lift log is a PR compared to previous lifts
     * Returns bitwise flags indicating which types of PRs were achieved
     * 
     * BACKWARD COMPATIBLE: Returns int that evaluates to true/false in boolean context
     * - 0 (PRType::NONE) = no PR = false
     * - >0 (any PR flags) = PR achieved = true
     * 
     * @param LiftLog $liftLog The lift log to check
     * @param Exercise $exercise The exercise being performed
     * @param User $user The user who performed the lift
     * @return int Bitwise flags of PR types (0 if no PR)
     */
    public function isLiftLogPR(LiftLog $liftLog, Exercise $exercise, User $user): int
    {
        $strategy = $exercise->getTypeStrategy();
        
        // Check if this exercise type supports PRs
        $supportedPRTypes = $strategy->getSupportedPRTypes();
        if (empty($supportedPRTypes)) {
            $this->lastCalculationSnapshot = [
                'supported_pr_types' => [],
                'reason' => 'Exercise type does not support PR tracking',
            ];
            return PRType::NONE->value;
        }
        
        // Get all previous lift logs for this exercise (before this one)
        $previousLogs = LiftLog::where('exercise_id', $exercise->id)
            ->where('user_id', $user->id)
            ->where('logged_at', '<', $liftLog->logged_at)
            ->with('liftSets')
            ->orderBy('logged_at', 'asc')
            ->get();
        
        // Use strategy to detect PRs
        $currentMetrics = $strategy->calculateCurrentMetrics($liftLog);
        $prs = $strategy->compareToPrevious($currentMetrics, $previousLogs, $liftLog);
        
        // Convert PR array to bitwise flags for backward compatibility
        $prFlags = PRType::NONE->value;
        foreach ($prs as $pr) {
            $prType = $this->mapPRTypeStringToEnum($pr['type']);
            if ($prType) {
                $prFlags |= $prType->value;
            }
        }
        
        // Store snapshot for logging (backward-compatible format)
        $this->lastCalculationSnapshot = $this->buildCalculationSnapshot(
            $liftLog,
            $currentMetrics,
            $prs,
            $previousLogs,
            $prFlags
        );
        
        return $prFlags;
    }

    /**
     * Get the last calculation snapshot for logging purposes
     * Returns null if no calculation has been performed yet
     * 
     * @return array|null
     */
    public function getLastCalculationSnapshot(): ?array
    {
        return $this->lastCalculationSnapshot;
    }

    /**
     * Calculate which lift logs contain PRs from a collection of logs
     * Processes logs chronologically to determine which were PRs "at the time they happened"
     * 
     * @param Collection $liftLogs Collection of lift logs to analyze
     * @return array Array of lift log IDs that contain PRs (any type)
     */
    public function calculatePRLogIds(Collection $liftLogs): array
    {
        if ($liftLogs->isEmpty()) {
            return [];
        }

        $prLogIds = [];
        
        // Group logs by exercise to process each exercise independently
        $logsByExercise = $liftLogs->groupBy('exercise_id');
        
        foreach ($logsByExercise as $exerciseId => $exerciseLogs) {
            $firstLog = $exerciseLogs->first();
            $strategy = $firstLog->exercise->getTypeStrategy();
            
            // Check if this exercise type supports PRs
            $supportedPRTypes = $strategy->getSupportedPRTypes();
            if (empty($supportedPRTypes)) {
                continue;
            }

            // Sort logs by date (oldest first) to process chronologically
            $sortedLogs = $exerciseLogs->sortBy('logged_at');
            
            // Process each log chronologically
            foreach ($sortedLogs as $index => $log) {
                // Get all previous logs for this exercise (logs before current one)
                $previousLogs = $sortedLogs->take($index);
                
                // Use strategy to detect PRs
                $currentMetrics = $strategy->calculateCurrentMetrics($log);
                $prs = $strategy->compareToPrevious($currentMetrics, $previousLogs, $log);
                
                if (!empty($prs)) {
                    $prLogIds[] = $log->id;
                }
            }
        }
        
        return $prLogIds;
    }

    /**
     * Detect PRs for a lift log and return detailed information for database storage
     * Returns an array of PR records with all necessary data for PersonalRecord model
     * 
     * @param LiftLog $liftLog The lift log to check for PRs
     * @return array Array of PR details, each containing type, value, previous_pr_id, etc.
     */
    public function detectPRsWithDetails(LiftLog $liftLog): array
    {
        $exercise = $liftLog->exercise;
        $strategy = $exercise->getTypeStrategy();
        
        // Check if this exercise type supports PRs
        $supportedPRTypes = $strategy->getSupportedPRTypes();
        if (empty($supportedPRTypes)) {
            return [];
        }
        
        // Get all previous lift logs for this exercise (before this one)
        $previousLogs = LiftLog::where('exercise_id', $exercise->id)
            ->where('user_id', $liftLog->user_id)
            ->where('logged_at', '<', $liftLog->logged_at)
            ->with('liftSets')
            ->orderBy('logged_at', 'asc')
            ->get();
        
        // Use strategy to calculate current metrics and compare to previous
        $currentMetrics = $strategy->calculateCurrentMetrics($liftLog);
        $prs = $strategy->compareToPrevious($currentMetrics, $previousLogs, $liftLog);
        
        // Enrich PRs with previous_pr_id references
        return $this->enrichPRsWithPreviousPRIds($prs, $liftLog);
    }
    
    /**
     * Enrich PR records with previous_pr_id references
     * 
     * @param array $prs Array of PR records from strategy
     * @param LiftLog $liftLog The current lift log
     * @return array Enriched PR records with previous_pr_id set
     */
    private function enrichPRsWithPreviousPRIds(array $prs, LiftLog $liftLog): array
    {
        $enrichedPRs = [];
        
        foreach ($prs as $pr) {
            $previousPRId = null;
            
            // Find the PersonalRecord ID for the previous PR
            if (isset($pr['previous_lift_log_id']) && $pr['previous_lift_log_id']) {
                $query = \App\Models\PersonalRecord::where('lift_log_id', $pr['previous_lift_log_id'])
                    ->where('pr_type', $pr['type']);
                
                // Add type-specific filters
                if ($pr['type'] === 'rep_specific' && isset($pr['rep_count'])) {
                    $query->where('rep_count', $pr['rep_count']);
                } elseif ($pr['type'] === 'hypertrophy' && isset($pr['weight'])) {
                    $query->where('weight', $pr['weight']);
                } elseif ($pr['type'] === 'speed' && isset($pr['weight'], $pr['rep_count'])) {
                    $query->where('weight', $pr['weight'])
                        ->where('rep_count', $pr['rep_count']);
                }
                
                $previousPR = $query->first();
                $previousPRId = $previousPR?->id;
            }
            
            $enrichedPRs[] = array_merge($pr, [
                'previous_pr_id' => $previousPRId,
            ]);
        }
        
        return $enrichedPRs;
    }
    
    /**
     * Map PR type string to PRType enum
     * 
     * @param string $typeString PR type string (e.g., 'one_rm', 'volume')
     * @return PRType|null
     */
    private function mapPRTypeStringToEnum(string $typeString): ?PRType
    {
        return match($typeString) {
            'one_rm' => PRType::ONE_RM,
            'rep_specific' => PRType::REP_SPECIFIC,
            'volume' => PRType::VOLUME,
            'hypertrophy' => null, // Hypertrophy is stored as string, not in enum
            'time' => PRType::TIME,
            'density' => PRType::DENSITY,
            'endurance' => PRType::ENDURANCE,
            'consistency' => PRType::CONSISTENCY,
            'load' => PRType::LOAD,
            'distance' => PRType::DISTANCE,
            'duration' => PRType::DURATION,
            'speed' => PRType::SPEED,
            default => null,
        };
    }
    
    /**
     * Build calculation snapshot in backward-compatible format for logging
     * 
     * @param LiftLog $liftLog Current lift log
     * @param array $currentMetrics Metrics calculated by strategy
     * @param array $prs PRs detected by strategy
     * @param Collection $previousLogs Previous lift logs
     * @param int $prFlags Bitwise PR flags
     * @return array Snapshot in legacy format
     */
    private function buildCalculationSnapshot(
        LiftLog $liftLog,
        array $currentMetrics,
        array $prs,
        Collection $previousLogs,
        int $prFlags
    ): array {
        $snapshot = [
            'current_lift' => [
                'lift_log_id' => $liftLog->id,
                'logged_at' => $liftLog->logged_at->toIso8601String(),
                'metrics' => $currentMetrics,
            ],
            'previous_logs_count' => $previousLogs->count(),
            'previous_bests' => [],
            'pr_reasons' => [],
            'why_not_pr' => [],
        ];
        
        // Build previous_bests from PRs detected
        foreach ($prs as $pr) {
            $prType = $pr['type'];
            
            $bestEntry = [
                'value' => $pr['previous_value'],
                'lift_log_id' => $pr['previous_lift_log_id'],
            ];
            
            // Add type-specific fields
            if ($prType === 'rep_specific' && isset($pr['rep_count'])) {
                $bestEntry['rep_count'] = $pr['rep_count'];
            } elseif ($prType === 'hypertrophy' && isset($pr['weight'])) {
                $bestEntry['weight'] = $pr['weight'];
            }
            
            $snapshot['previous_bests'][$prType] = $bestEntry;
            
            // Add PR reason
            $snapshot['pr_reasons'][$prType] = $this->buildPRReason($pr);
        }
        
        // Build why_not_pr for types that weren't PRs
        $allPRTypes = ['one_rm', 'volume', 'rep_specific'];
        $detectedTypes = array_column($prs, 'type');
        
        foreach ($allPRTypes as $prType) {
            if (!in_array($prType, $detectedTypes)) {
                // Find the previous best for this type
                $previousBest = $this->findPreviousBest($previousLogs, $prType, $currentMetrics);
                
                if ($previousBest['value'] !== null) {
                    $snapshot['previous_bests'][$prType] = [
                        'value' => $previousBest['value'],
                        'lift_log_id' => $previousBest['lift_log_id'],
                    ];
                    
                    $snapshot['why_not_pr'][$prType] = $this->buildWhyNotPRReason(
                        $prType,
                        $currentMetrics,
                        $previousBest
                    );
                }
            }
        }
        
        return $snapshot;
    }
    
    /**
     * Build PR reason message
     */
    private function buildPRReason(array $pr): string
    {
        $type = $pr['type'];
        $value = $pr['value'];
        $previousValue = $pr['previous_value'];
        $previousLiftId = $pr['previous_lift_log_id'];
        
        if ($previousValue === null) {
            return "First recorded {$type}";
        }
        
        return match($type) {
            'one_rm' => sprintf(
                'New 1RM: %.1f lbs (previous: %.1f lbs from lift #%d)',
                $value,
                $previousValue,
                $previousLiftId
            ),
            'volume' => sprintf(
                'New volume: %d lbs (previous: %d lbs from lift #%d)',
                (int)$value,
                (int)$previousValue,
                $previousLiftId
            ),
            'rep_specific' => sprintf(
                'New %d-rep max: %.1f lbs (previous: %.1f lbs from lift #%d)',
                $pr['rep_count'],
                $value,
                $previousValue,
                $previousLiftId
            ),
            'hypertrophy' => sprintf(
                'New best at %.1f lbs: %d reps (previous: %d reps from lift #%d)',
                $pr['weight'],
                (int)$value,
                (int)$previousValue,
                $previousLiftId
            ),
            'time' => sprintf(
                'New time: %d seconds (previous: %d seconds from lift #%d)',
                (int)$value,
                (int)$previousValue,
                $previousLiftId
            ),
            default => "New {$type} PR: {$value}",
        };
    }
    
    /**
     * Build why-not-PR reason message
     */
    private function buildWhyNotPRReason(string $prType, array $currentMetrics, array $previousBest): string
    {
        $currentValue = match($prType) {
            'one_rm' => $currentMetrics['best_1rm'] ?? 0,
            'volume' => $currentMetrics['total_volume'] ?? 0,
            default => 0,
        };
        
        $previousValue = $previousBest['value'];
        $previousLiftId = $previousBest['lift_log_id'];
        
        return match($prType) {
            'one_rm' => sprintf(
                'Current 1RM (%.1f lbs) did not exceed previous best (%.1f lbs from lift #%d)',
                $currentValue,
                $previousValue,
                $previousLiftId
            ),
            'volume' => sprintf(
                'Current volume (%d lbs) did not exceed previous best (%d lbs from lift #%d)',
                (int)$currentValue,
                (int)$previousValue,
                $previousLiftId
            ),
            default => "Not a {$prType} PR",
        };
    }
    
    /**
     * Find previous best for a given PR type
     */
    private function findPreviousBest(Collection $previousLogs, string $prType, array $currentMetrics): array
    {
        if ($previousLogs->isEmpty()) {
            return ['value' => null, 'lift_log_id' => null];
        }
        
        $bestValue = 0;
        $liftLogId = null;
        
        foreach ($previousLogs as $log) {
            $logMetrics = $log->exercise->getTypeStrategy()->calculateCurrentMetrics($log);
            
            $value = match($prType) {
                'one_rm' => $logMetrics['best_1rm'] ?? 0,
                'volume' => $logMetrics['total_volume'] ?? 0,
                default => 0,
            };
            
            if ($value > $bestValue) {
                $bestValue = $value;
                $liftLogId = $log->id;
            }
        }
        
        return [
            'value' => $bestValue > 0 ? $bestValue : null,
            'lift_log_id' => $liftLogId,
        ];
    }
}
