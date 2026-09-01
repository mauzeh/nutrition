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
        
        $prEngine = app(\App\Services\PR\PrEngine::class);
        $family = $prEngine->resolveFamily($liftLog->exercise->log_type ?? 'regular');
        $descriptors = config("pr_families.families.{$family}", []);
        $descriptorMap = array_column($descriptors, null, 'type');

        $records = [];
        
        $hasOneRepPR = $prs->contains(fn($p) => $p->pr_type === 'rep_specific' && (int)$p->rep_count === 1);

        foreach ($prs as $pr) {
            if ($pr->pr_type === 'one_rm' && $hasOneRepPR) {
                continue;
            }
            $descriptor = $descriptorMap[$pr->pr_type] ?? null;
            $formatted = self::formatPRRecord($pr, $liftLog, $descriptor, isBeaten: true);
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
        $currentPRs = \App\Models\PersonalRecord::where('user_id', $liftLog->user_id)
            ->where('exercise_id', $liftLog->exercise_id)
            ->current() // Only unbeaten PRs
            ->get();
        
        if ($currentPRs->isEmpty()) {
            return [];
        }
        
        $beatenPRs = \App\Models\PersonalRecord::where('lift_log_id', $liftLog->id)->get();
        
        $beatenPRMap = [];
        foreach ($beatenPRs as $pr) {
            $key = $pr->pr_type;
            if ($pr->rep_count) {
                $key .= '_' . $pr->rep_count;
            }
            if ($pr->weight) {
                $key .= '_' . $pr->weight;
            }
            $beatenPRMap[$key] = true;
        }
        
        $prEngine = app(\App\Services\PR\PrEngine::class);
        $family = $prEngine->resolveFamily($liftLog->exercise->log_type ?? 'regular');
        $descriptors = config("pr_families.families.{$family}", []);
        $descriptorMap = array_column($descriptors, null, 'type');

        $currentMetrics = $prEngine->computeMetrics($liftLog, $family);
        $records = [];
        
        foreach ($currentPRs as $pr) {
            $key = $pr->pr_type;
            if ($pr->rep_count) {
                $key .= '_' . $pr->rep_count;
            }
            if ($pr->weight) {
                $key .= '_' . $pr->weight;
            }
            
            if (isset($beatenPRMap[$key])) {
                continue;
            }
            
            $descriptor = $descriptorMap[$pr->pr_type] ?? null;
            $formatted = self::formatPRRecord($pr, $liftLog, $descriptor, isBeaten: false);
            $comparison = self::getComparisonValue($pr, $currentMetrics, $liftLog, $descriptor);
            if ($comparison !== null) {
                $formatted['comparison'] = $comparison;
                $records[] = $formatted;
            }
        }
        
        return $records;
    }

    /**
     * Resolve a PR row label from the descriptor config — CONFIG-DRIVEN and aligned with the Athlete
     * engine's templating (no per-pr_type if-chain). The descriptor's `label` is a template:
     * `{n}` → rep count, `{w}` → weight key. A pure-bodyweight volume PR uses the descriptor's
     * optional `bodyweightLabel` ("Total Reps"). Identical convention to
     * athlete/src/shared/logging/prDescriptors.js.
     */
    private static function resolveLabel(\App\Models\PersonalRecord $pr, ?array $descriptor, bool $isPureBodyweight): string
    {
        if ($isPureBodyweight && !empty($descriptor['bodyweightLabel'])) {
            return $descriptor['bodyweightLabel'];
        }
        $label = $descriptor['label'] ?? ucfirst(str_replace('_', ' ', $pr->pr_type));
        if ($pr->rep_count !== null) {
            $label = str_replace('{n}', (string) $pr->rep_count, $label);
        }
        if ($pr->weight !== null) {
            $label = str_replace('{w}', (string) (float) $pr->weight, $label);
        }
        return $label;
    }

    private static function formatPRRecord(\App\Models\PersonalRecord $pr, LiftLog $liftLog, ?array $descriptor, bool $isBeaten): array
    {
        $isPureBodyweight = ($liftLog->exercise->log_type ?? '') === 'bodyweight' && $pr->pr_type === 'volume' && ($pr->weight == 0 || $pr->weight === null);
        $label = self::resolveLabel($pr, $descriptor, $isPureBodyweight);

        $unitResolver = app(\App\Services\UnitResolver::class);
        $viewer = auth()->user() ?? $liftLog->user;
        $sourceUnit = $pr->unit ?? 'lbs';

        $format = $descriptor['format'] ?? 'weight';
        if ($isPureBodyweight) {
            $format = 'reps';
        }
        $formattedValue = match ($format) {
            'weight' => $unitResolver->formatForUser($pr->value, $sourceUnit, $viewer),
            'volume' => $unitResolver->formatForUser($pr->value, $sourceUnit, $viewer),
            'seconds' => (int)$pr->value . 's',
            'reps' => (int)$pr->value . ' reps',
            'sets' => (int)$pr->value . ' set' . ((int)$pr->value > 1 ? 's' : ''),
            'distance' => (int)$pr->value . 'm',
            default => (string)$pr->value,
        };

        if ($isBeaten) {
            $formattedPrev = $pr->previous_value !== null ? match ($format) {
                'weight' => $unitResolver->formatForUser($pr->previous_value, $sourceUnit, $viewer),
                'volume' => $unitResolver->formatForUser($pr->previous_value, $sourceUnit, $viewer),
                'seconds' => (int)$pr->previous_value . 's',
                'reps' => (int)$pr->previous_value . ' reps',
                'sets' => (int)$pr->previous_value . ' set' . ((int)$pr->previous_value > 1 ? 's' : ''),
                'distance' => (int)$pr->previous_value . 'm',
                default => (string)$pr->previous_value,
            } : '—';

            return [
                'label' => $label,
                'value' => $formattedPrev,
                'comparison' => $formattedValue,
            ];
        }

        return [
            'label' => $label,
            'value' => $formattedValue,
            'is_current' => true,
        ];
    }
    
    private static function getComparisonValue(
        \App\Models\PersonalRecord $pr,
        array $currentMetrics,
        LiftLog $liftLog,
        ?array $descriptor
    ): ?string {
        if (!$descriptor || !isset($currentMetrics[$pr->pr_type])) {
            return null;
        }

        $currentVal = $currentMetrics[$pr->pr_type];
        $unitResolver = app(\App\Services\UnitResolver::class);
        $viewer = auth()->user() ?? $liftLog->user;
        $loggedUnit = $liftLog->liftSets->first()->unit ?? 'lbs';
        $format = $descriptor['format'] ?? 'weight';

        if (is_numeric($currentVal)) {
            return match ($format) {
                'weight' => $unitResolver->formatForUser($currentVal, $loggedUnit, $viewer),
                'volume' => $unitResolver->formatForUser($currentVal, $loggedUnit, $viewer),
                'seconds' => (int)$currentVal . 's',
                'reps' => (int)$currentVal . ' reps',
                'sets' => (int)$currentVal . ' set' . ((int)$currentVal > 1 ? 's' : ''),
                'distance' => (int)$currentVal . 'm',
                default => (string)$currentVal,
            };
        }

        if (is_array($currentVal)) {
            $key = $pr->rep_count ?? (string)$pr->weight;
            if (isset($currentVal[$key])) {
                $val = $currentVal[$key];
                return match ($format) {
                    'weight' => $unitResolver->formatForUser($val, $loggedUnit, $viewer),
                    'volume' => $unitResolver->formatForUser($val, $loggedUnit, $viewer),
                    'seconds' => (int)$val . 's',
                    'reps' => (int)$val . ' reps',
                    'sets' => (int)$val . ' set' . ((int)$val > 1 ? 's' : ''),
                    'distance' => (int)$val . 'm',
                    default => (string)$val,
                };
            }
        }

        return null;
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
