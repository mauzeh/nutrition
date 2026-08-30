<?php

namespace App\Services\LiftLogTableRowBuilder;

use App\Models\LiftLog;
use App\Services\Components\Display\PRRecordsTableComponentBuilder;

/**
 * Assembles PR records components for lift log rows
 */
class PRRecordsComponentAssembler
{
    /**
     * Assemble PR records components for a lift log
     */
    public static function assemble(LiftLog $liftLog, RowConfig $config): array
    {
        $components = [];
        $viewLogsUrl = self::buildViewLogsUrl($liftLog, $config);
        
        if ($liftLog->is_pr) {
            $components = array_merge(
                self::buildBeatenPRComponents($liftLog),
                self::buildCurrentRecordsComponents($liftLog, isForPR: true)
            );
        } else {
            $components = self::buildCurrentRecordsComponents($liftLog, isForPR: false);
        }
        
        // Always add footer link
        $components[] = (new PRRecordsTableComponentBuilder(''))
            ->records([])
            ->current()
            ->footerLink($viewLogsUrl, 'View history')
            ->build();
        
        return $components;
    }
    
    /**
     * Build components for beaten PRs
     */
    private static function buildBeatenPRComponents(LiftLog $liftLog): array
    {
        $prRecords = self::getPRRecordsForBeatenPRs($liftLog);
        
        if (empty($prRecords)) {
            return [];
        }
        
        return [(new PRRecordsTableComponentBuilder('Records beaten:'))
            ->records($prRecords)
            ->beaten()
            ->build()];
    }
    
    /**
     * Build components for current records
     */
    private static function buildCurrentRecordsComponents(LiftLog $liftLog, bool $isForPR): array
    {
        $currentRecords = self::getCurrentRecordsTable($liftLog);
        
        if (empty($currentRecords)) {
            return [];
        }
        
        $title = $isForPR ? 'Not beaten:' : 'History:';
        
        return [(new PRRecordsTableComponentBuilder($title))
            ->records($currentRecords)
            ->current()
            ->build()];
    }

    /**
     * Get PR records for beaten PRs in table format
     */
    private static function getPRRecordsForBeatenPRs(LiftLog $liftLog): array
    {
        // Check if this is the first lift for this exercise
        $isFirstLift = !\App\Models\LiftLog::where('exercise_id', $liftLog->exercise_id)
            ->where('user_id', $liftLog->user_id)
            ->where('id', '!=', $liftLog->id)
            ->exists();
        
        if ($isFirstLift) {
            return [[
                'label' => 'Achievement',
                'value' => 'First time!',
                'comparison' => ''
            ]];
        }
        
        // Use PersonalRecord database records
        $prs = \App\Models\PersonalRecord::where('lift_log_id', $liftLog->id)
            ->get();
        
        if ($prs->isEmpty()) {
            return [];
        }
        
        // Get the exercise type strategy for formatting
        $strategy = $liftLog->exercise->getTypeStrategy();
        
        $records = [];
        
        // Use the strategy's formatPRDisplay method for each PR
        foreach ($prs as $pr) {
            $formatted = $strategy->formatPRDisplay($pr, $liftLog);
            
            // Skip if the strategy returns empty array (e.g., redundant 1RM)
            if (!empty($formatted)) {
                $records[] = $formatted;
            }
        }
        
        return $records;
    }
    
    /**
     * Get current records for an exercise in table format
     */
    private static function getCurrentRecordsTable(LiftLog $liftLog): array
    {
        // Use PersonalRecord database records to get current PRs
        $currentPRs = \App\Models\PersonalRecord::where('user_id', $liftLog->user_id)
            ->where('exercise_id', $liftLog->exercise_id)
            ->current() // Only unbeaten PRs
            ->get();
        
        if ($currentPRs->isEmpty()) {
            return [];
        }
        
        $strategy = $liftLog->exercise->getTypeStrategy();
        
        // Get PRs that were beaten by THIS lift (to exclude from current records)
        $beatenPRs = \App\Models\PersonalRecord::where('lift_log_id', $liftLog->id)
            ->get();
        
        // Create a map of beaten PR types with their specific details
        $beatenPRMap = [];
        foreach ($beatenPRs as $pr) {
            if ($pr->pr_type === 'rep_specific') {
                $beatenPRMap['rep_specific_' . $pr->rep_count] = true;
            } elseif ($pr->pr_type === 'hypertrophy') {
                $beatenPRMap['hypertrophy_' . $pr->weight] = true;
            } elseif ($pr->pr_type === 'density') {
                // Density PRs are weight/duration-specific
                $beatenPRMap['density_' . $pr->weight . '_' . $pr->rep_count] = true;
            } elseif ($pr->pr_type === 'consistency') {
                // Consistency PRs are set-count-specific
                $beatenPRMap['consistency_' . $pr->rep_count] = true;
            } elseif ($pr->pr_type === 'speed') {
                $beatenPRMap['speed_' . $pr->weight . '_' . $pr->rep_count] = true;
            } elseif ($pr->pr_type === 'time') {
                $beatenPRMap['time'] = true;
            } elseif ($pr->pr_type === 'endurance') {
                $beatenPRMap['endurance'] = true;
            } else {
                $beatenPRMap[$pr->pr_type] = true;
            }
        }
        
        $records = [];
        
        // Calculate current metrics using the strategy
        $currentMetrics = $strategy->calculateCurrentMetrics($liftLog);
        
        // Filter out beaten PRs and format using strategy
        foreach ($currentPRs as $pr) {
            // Check if this PR was beaten
            $key = $pr->pr_type;
            if ($pr->pr_type === 'rep_specific') {
                $key = 'rep_specific_' . $pr->rep_count;
            } elseif ($pr->pr_type === 'hypertrophy') {
                $key = 'hypertrophy_' . $pr->weight;
            } elseif ($pr->pr_type === 'density') {
                $key = 'density_' . $pr->weight . '_' . $pr->rep_count;
            } elseif ($pr->pr_type === 'consistency') {
                $key = 'consistency_' . $pr->rep_count;
            } elseif ($pr->pr_type === 'speed') {
                $key = 'speed_' . $pr->weight . '_' . $pr->rep_count;
            }
            
            if (isset($beatenPRMap[$key])) {
                continue; // Skip beaten PRs
            }
            
            // Use strategy to format the PR display
            $formatted = $strategy->formatCurrentPRDisplay($pr, $liftLog, true);
            
            // Add comparison value based on current metrics
            $comparison = self::getComparisonValue($pr, $currentMetrics, $liftLog, $strategy);
            if ($comparison !== null) {
                $formatted['comparison'] = $comparison;
                $records[] = $formatted;
            }
        }
        
        return $records;
    }
    
    /**
     * Get comparison value for a PR based on current metrics
     */
    private static function getComparisonValue(
        \App\Models\PersonalRecord $pr,
        array $currentMetrics,
        LiftLog $liftLog,
        $strategy
    ): ?string {
        if (method_exists($strategy, 'comparisonValue')) {
            $value = $strategy->comparisonValue($pr, $currentMetrics, $liftLog);
            if ($value !== null) {
                return $value;
            }
        }

        $isBodyweight = $liftLog->exercise->exercise_type === 'bodyweight';
        $isStaticHold = $liftLog->exercise->exercise_type === 'static_hold';
        $isCardio = $liftLog->exercise->exercise_type === 'cardio';
        $hasExtraWeight = $liftLog->liftSets->max('weight') > 0;
        
        switch ($pr->pr_type) {
            case 'one_rm':
                if (!$isBodyweight && isset($currentMetrics['best_1rm'])) {
                    $unitResolver = app(\App\Services\UnitResolver::class);
                    $loggedUnit = $liftLog->liftSets->first()->unit ?? 'lbs';
                    return $unitResolver->formatForUser($currentMetrics['best_1rm'], $loggedUnit, $liftLog->user);
                }
                return null;
                
            case 'volume':
                // Static holds: use formatVolumeDuration via strategy
                if ($isStaticHold && isset($currentMetrics['total_volume'])) {
                    $tempPR = new \App\Models\PersonalRecord();
                    $tempPR->pr_type = 'volume';
                    $tempPR->value = $currentMetrics['total_volume'];
                    $formatted = $strategy->formatCurrentPRDisplay($tempPR, $liftLog, false);
                    return $formatted['value'];
                }
                // Cardio: use strategy formatting
                elseif ($isCardio && isset($currentMetrics['total_volume'])) {
                    $tempPR = new \App\Models\PersonalRecord();
                    $tempPR->pr_type = 'volume';
                    $tempPR->value = $currentMetrics['total_volume'];
                    $formatted = $strategy->formatCurrentPRDisplay($tempPR, $liftLog, false);
                    return $formatted['value'];
                }
                // Bodyweight: show total reps
                elseif ($isBodyweight && !$hasExtraWeight && isset($currentMetrics['total_reps'])) {
                    return sprintf('%d reps', (int)$currentMetrics['total_reps']);
                }
                // Weighted exercises: show weight × reps
                elseif (isset($currentMetrics['total_volume'])) {
                    $unitResolver = app(\App\Services\UnitResolver::class);
                    $loggedUnit = $liftLog->liftSets->first()->unit ?? 'lbs';
                    return $unitResolver->formatForUser($currentMetrics['total_volume'], $loggedUnit, $liftLog->user);
                }
                return null;
                
            case 'rep_specific':
                if (isset($currentMetrics['rep_weights'][$pr->rep_count])) {
                    $currentWeight = $currentMetrics['rep_weights'][$pr->rep_count];
                    $unitResolver = app(\App\Services\UnitResolver::class);
                    $loggedUnit = $liftLog->liftSets->first()->unit ?? 'lbs';
                    return $unitResolver->formatForUser($currentWeight, $loggedUnit, $liftLog->user);
                }
                return null;
                
            case 'hypertrophy':
                return null;
                
            case 'time':
                if (isset($currentMetrics['best_hold'])) {
                    $tempPR = new \App\Models\PersonalRecord();
                    $tempPR->pr_type = 'time';
                    $tempPR->value = $currentMetrics['best_hold'];
                    $formatted = $strategy->formatCurrentPRDisplay($tempPR, $liftLog, false);
                    return $formatted['value'];
                }
                return null;
                
            case 'consistency':
                if (isset($currentMetrics['min_hold']) && isset($currentMetrics['total_sets'])) {
                    $tempPR = new \App\Models\PersonalRecord();
                    $tempPR->pr_type = 'consistency';
                    $tempPR->value = $currentMetrics['min_hold'];
                    $tempPR->rep_count = $currentMetrics['total_sets'];
                    $formatted = $strategy->formatCurrentPRDisplay($tempPR, $liftLog, false);
                    return $formatted['value'];
                }
                return null;
                
            case 'density':
                // Density PRs are weight/duration-specific, so we can't show a simple comparison
                return null;
                
            case 'endurance':
                if (isset($currentMetrics['best_distance'])) {
                    $tempPR = new \App\Models\PersonalRecord();
                    $tempPR->pr_type = 'endurance';
                    $tempPR->value = $currentMetrics['best_distance'];
                    $formatted = $strategy->formatCurrentPRDisplay($tempPR, $liftLog, false);
                    return $formatted['value'];
                }
                return null;
                
            default:
                return null;
        }
    }
    
    /**
     * Format weight value for display
     */
    private static function formatWeight(float $weight): string
    {
        $rounded = round($weight, 1);
        
        if ($rounded == floor($rounded)) {
            return number_format($rounded, 0);
        }
        
        return number_format($rounded, 1);
    }
    
    /**
     * Build view logs URL with context parameters
     */
    private static function buildViewLogsUrl(LiftLog $liftLog, RowConfig $config): string
    {
        $url = route('exercises.show-logs', $liftLog->exercise);
        
        // Add context parameters if coming from mobile-entry-lifts
        if ($config->redirectContext === 'mobile-entry-lifts') {
            $params = array_filter([
                'from' => $config->redirectContext,
                'date' => $config->selectedDate,
            ]);
            
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
        }
        
        return $url;
    }
}
