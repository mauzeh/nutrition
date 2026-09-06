<?php

namespace App\Services\ExerciseTypes;

use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\User;
use App\Services\ExerciseTypes\Exceptions\UnsupportedOperationException;

/**
 * Base Exercise Type Strategy
 * 
 * Abstract base class that provides common functionality for all exercise type strategies.
 * Implements default behavior for most ExerciseTypeInterface methods and loads
 * configuration from the config/exercise_types.php file.
 * 
 * Concrete exercise type classes should extend this class and implement the
 * abstract methods: getTypeName(), processLiftData(), and formatWeightDisplay().
 * 
 * @package App\Services\ExerciseTypes
 * @since 1.0.0
 * 
 * @example
 * // Creating a new exercise type
 * class CustomExerciseType extends BaseExerciseType
 * {
 *     public function getTypeName(): string { return 'custom'; }
 *     public function processLiftData(array $data): array { return $data; }
 *     public function formatWeightDisplay(LiftLog $liftLog): string { return '...'; }
 * }
 */
abstract class BaseExerciseType implements ExerciseTypeInterface
{
    /**
     * Configuration array loaded from config/exercise_types.php
     * 
     * @var array
     */
    protected array $config;
    
    /**
     * Initialize the exercise type strategy with configuration
     * 
     * Loads the configuration for this exercise type from the config file
     * based on the type name returned by getTypeName().
     */
    public function __construct()
    {
        $this->config = config('exercise_types.types.' . $this->getTypeName()) ?? [];
    }
    
    /**
     * Get validation rules for lift data based on exercise type
     */
    public function getValidationRules(?User $user = null): array
    {
        $rules = $this->config['validation'] ?? [];

        // Derive the group-rule enforcement (e.g. "at least one of {time,reps}") from the SINGLE
        // declarative `group_rule` source, rather than hand-authoring required_without twice. The sync
        // path reads the same `group_rule` via SetGroupRuleValidator; both compile from one source.
        return $this->mergeGroupRuleValidation($rules);
    }

    /**
     * Compile this type's declarative `group_rule` into Laravel validation rules and merge them into
     * the given base rules. Currently supports `require_one_of` → a `required_without` cross-pair over
     * the group's fields. The single source is `config.group_rule`; nothing else authors the rule.
     *
     * @param array<string,string> $rules
     * @return array<string,string>
     */
    protected function mergeGroupRuleValidation(array $rules): array
    {
        $groupRule = $this->config['group_rule'] ?? null;
        if (($groupRule['kind'] ?? null) !== 'require_one_of') {
            return $rules;
        }

        $fields = $groupRule['fields'] ?? [];
        foreach ($fields as $field) {
            $others = array_values(array_filter($fields, fn ($f) => $f !== $field));
            if ($others === []) {
                continue;
            }
            $requiredWithout = 'required_without:' . implode(',', $others);
            // Append to any existing type/format constraints for the field (e.g. nullable|integer|...).
            $rules[$field] = isset($rules[$field]) && $rules[$field] !== ''
                ? $rules[$field] . '|' . $requiredWithout
                : 'nullable|' . $requiredWithout;
        }

        return $rules;
    }
    
    /**
     * Get form fields that should be displayed for this exercise type
     */
    public function getFormFields(): array
    {
        return $this->config['form_fields'] ?? [];
    }
    
    /**
     * Process exercise data according to exercise type rules
     * Default implementation returns data unchanged
     */
    public function processExerciseData(array $data): array
    {
        return $data;
    }
    
    /**
     * Check if this exercise type supports 1RM calculation
     */
    public function canCalculate1RM(): bool
    {
        return $this->config['supports_1rm'] ?? false;
    }
    
    /**
     * Calculate one-rep max for a lift set
     * Default implementation uses the Epley formula
     */
    public function calculate1RM(float $weight, int $reps, LiftLog $liftLog): float
    {
        if (!$this->canCalculate1RM()) {
            throw UnsupportedOperationException::for1RM($this->getTypeName());
        }
        
        if ($reps === 1) {
            return $weight;
        }
        
        // 1RM formulas are unreliable above 10 reps due to diminishing returns
        // Cap at 10 reps for calculation purposes
        $effectiveReps = min($reps, 10);
        
        return $weight * (1 + (0.0333 * $effectiveReps));
    }
    
    /**
     * Get the chart type appropriate for this exercise type
     */
    public function getChartType(): string
    {
        return $this->config['chart_type'] ?? 'default';
    }
    
    /**
     * Get supported progression types for this exercise type
     */
    public function getSupportedProgressionTypes(): array
    {
        return $this->config['progression_types'] ?? ['linear'];
    }
    
    /**
     * Format 1RM display for lift logs
     * Default implementation throws exception if 1RM not supported
     */
    public function format1RMDisplay(LiftLog $liftLog): string
    {
        if (!$this->canCalculate1RM()) {
            throw UnsupportedOperationException::for1RM($this->getTypeName());
        }
        
        $oneRepMax = $liftLog->one_rep_max;
        return $oneRepMax > 0 ? number_format($oneRepMax, 1) . ' lbs' : '';
    }
    
    /**
     * Format progression suggestion for lift logs
     * Default implementation returns null (no suggestion)
     */
    public function formatProgressionSuggestion(LiftLog $liftLog): ?string
    {
        return null;
    }
    
    /**
     * Get the configuration for this exercise type
     */
    public function getTypeConfig(): array
    {
        return $this->config;
    }
    
    /**
     * Get form field definitions for mobile entry forms
     * Default implementation builds definitions from configuration
     */
    public function getFormFieldDefinitions(array $defaults = [], ?User $user = null): array
    {
        $formFields = $this->getFormFields();
        $labels = $this->getFieldLabels($user);
        $increments = $this->getFieldIncrements($user);
        $definitions = [];
        
        foreach ($formFields as $fieldName) {
            $definition = [
                'name' => $fieldName,
                'label' => $labels[$fieldName] ?? ucfirst($fieldName) . ':',
                'type' => $this->getFieldType($fieldName),
                'defaultValue' => $defaults[$fieldName] ?? $this->getDefaultValue($fieldName),
            ];
            
            // Add numeric field properties
            if ($definition['type'] === 'numeric') {
                $definition['increment'] = $increments[$fieldName] ?? 1;
                $definition['min'] = $this->getFieldMin($fieldName);
                $definition['max'] = $this->getFieldMax($fieldName);
                if ($fieldName === 'weight') {
                    $definition['step'] = $this->unitResolver()->getWeightStep($user);
                } else {
                    $definition['step'] = 1;
                }
            }
            
            // Add select field properties
            if ($definition['type'] === 'select') {
                $definition['options'] = $this->getFieldOptions($fieldName);
            }
            
            $definitions[] = $definition;
        }
        
        return $definitions;
    }
    
    /**
     * Get field labels for this exercise type
     * Default implementation uses standard labels
     */
    public function getFieldLabels(?User $user = null): array
    {
        $labels = $this->config['field_labels'] ?? [
            'weight' => 'Weight (lbs):',
            'reps' => 'Reps:',
            'sets' => 'Sets:',
            'band_color' => 'Band Color:',
        ];

        $unit = $this->unitResolver()->getPreferredWeightUnit($user);
        foreach ($labels as $key => $value) {
            $labels[$key] = str_replace('(lbs)', "({$unit})", $value);
        }

        return $labels;
    }
    
    /**
     * Get increment values for numeric fields
     * Default implementation uses standard increments
     */
    public function getFieldIncrements(?User $user = null): array
    {
        $increments = $this->config['field_increments'] ?? [
            'weight' => 5,
            'reps' => 1,
            'sets' => 1,
        ];

        if (array_key_exists('weight', $increments)) {
            $increments['weight'] = $this->unitResolver()->getWeightIncrement($user);
        }

        return $increments;
    }
    
    /**
     * Format logged item display message for mobile entry
     * Default implementation combines weight display with reps/sets
     */
    public function formatLoggedItemDisplay(LiftLog $liftLog): string
    {
        // For non-uniform sets, show the full multi-set summary
        if (!$liftLog->hasUniformSets()) {
            return $liftLog->formatSetsSummary();
        }

        $weightText = $this->formatWeightDisplay($liftLog);
        $setCount = $liftLog->liftSets->count();
        $firstSet = $liftLog->liftSets->first();
        
        if (!$firstSet) {
            return $weightText;
        }
        
        $repsSetsText = $setCount . ' x ' . $firstSet->reps;
        
        // For bodyweight with no additional weight, just show reps/sets
        if ($this->getTypeName() === 'bodyweight' && $firstSet->weight == 0) {
            return $repsSetsText;
        }
        
        return $weightText . ' × ' . $repsSetsText;
    }
    
    /**
     * Format form message display for mobile entry
     * Default implementation combines weight display with reps/sets using standard terminology
     */
    public function formatFormMessageDisplay(array $lastSession): string
    {
        // Create a mock lift log for formatting
        $mockLiftLog = new \App\Models\LiftLog();
        $mockLiftLog->user = auth()->user();
        
        // Create a mock exercise to avoid null pointer errors
        $mockExercise = new \App\Models\Exercise();
        $mockExercise->exercise_type = $this->getTypeName();
        $mockLiftLog->setRelation('exercise', $mockExercise);
        
        $mockLiftLog->setRelation('liftSets', collect([
            (object)[
                'weight' => $lastSession['weight'] ?? 0,
                'reps' => $lastSession['reps'] ?? 0,
                'band_color' => $lastSession['band_color'] ?? null,
                'unit' => $lastSession['unit'] ?? 'lbs'
            ]
        ]));
        
        $resistanceText = $this->formatWeightDisplay($mockLiftLog);
        
        return $resistanceText . ' × ' . $lastSession['reps'] . ' reps × ' . $lastSession['sets'] . ' sets';
    }
    
    /**
     * Format table cell display for workouts table
     * Returns array with primary and secondary text for table cell display
     */
    public function formatTableCellDisplay(LiftLog $liftLog): array
    {
        $weightText = $this->formatWeightDisplay($liftLog);
        $repsText = $liftLog->display_reps . ' x ' . $liftLog->display_rounds;
        
        return [
            'primary' => $weightText,
            'secondary' => $repsText
        ];
    }
    
    /**
     * Format 1RM table cell display
     * Default implementation shows 1RM if supported, otherwise returns N/A
     */
    public function format1RMTableCellDisplay(LiftLog $liftLog): string
    {
        if (!$this->canCalculate1RM()) {
            return 'N/A (' . ucfirst($this->getTypeName()) . ')';
        }
        
        return round($liftLog->one_rep_max) . ' lbs';
    }
    
    /**
     * Get exercise type display name and icon
     * Default implementation returns generic weighted exercise info
     */
    public function getTypeDisplayInfo(): array
    {
        return [
            'icon' => 'fas fa-dumbbell',
            'name' => 'Weighted'
        ];
    }
    
    /**
     * Get chart title for exercise logs page
     * Default implementation returns 1RM Progress if supported, otherwise Volume Progress
     */
    public function getChartTitle(): string
    {
        return $this->canCalculate1RM() ? '1RM Progress' : 'Volume Progress';
    }
    
    /**
     * Format mobile summary display for exercise summary component.
     *
     * Template Method: orchestrates display for both uniform and non-uniform sets.
     * Subclasses customize behavior by implementing formatSingleSetBadge().
     *
     * Returns:
     * - Uniform sets: ['weight' => ..., 'repsSets' => ..., 'showWeight' => bool]
     * - Non-uniform:  ['weight' => null, 'repsSets' => null, 'showWeight' => false, 'multiSetBadges' => [...]]
     */
    public function formatMobileSummaryDisplay(LiftLog $liftLog): array
    {
        $sets = $liftLog->relationLoaded('liftSets')
            ? $liftLog->liftSets
            : $liftLog->liftSets()->get();

        if ($sets->isEmpty()) {
            return ['weight' => '', 'repsSets' => '', 'showWeight' => false];
        }

        $user = $liftLog->relationLoaded('user') ? $liftLog->user : $liftLog->user()->first();

        // Non-uniform path: group consecutive identical sets into badges
        if (!$liftLog->hasUniformSets()) {
            $badges = $this->buildGroupedSetBadges($sets, $user);

            return [
                'weight' => null,
                'repsSets' => null,
                'showWeight' => false,
                'multiSetBadges' => $badges,
            ];
        }

        // Uniform path: single weight badge + reps×sets badge
        $firstSet = $sets->first();
        $badgeLabel = $this->formatSingleSetBadge($firstSet, $user);
        $count = $sets->count();
        $effort = $this->getSetEffortValue($firstSet);
        $repsSets = $this->formatUniformRepsSets($count, $effort);
        $showWeight = $badgeLabel !== '';

        return [
            'weight' => $badgeLabel,
            'repsSets' => $repsSets,
            'showWeight' => $showWeight,
        ];
    }

    /**
     * Format the reps/sets label for uniform sets.
     * Default: "{count} x {effort}" (e.g. "3 x 8").
     * Override for types where effort isn't reps (static holds, cardio).
     */
    protected function formatUniformRepsSets(int $count, string $effort): string
    {
        return $count . ' x ' . $effort;
    }

    /**
     * Build grouped set badges for non-uniform sets.
     *
     * Groups consecutive sets that share the same badge label AND effort value,
     * then formats each group as "{count}×{effort} @ {badge}" or "{count}×{effort}"
     * when the badge is empty (pure bodyweight).
     */
    protected function buildGroupedSetBadges($sets, ?User $user): array
    {
        $groups = [];
        $currentBadge = null;
        $currentEffort = null;
        $currentCount = 0;

        foreach ($sets as $set) {
            $badge = $this->formatSingleSetBadge($set, $user);
            $effort = $this->getSetEffortValue($set);

            if ($badge === $currentBadge && $effort === $currentEffort) {
                $currentCount++;
            } else {
                if ($currentCount > 0) {
                    $groups[] = $this->formatBadgeGroupLabel($currentCount, $currentEffort, $currentBadge);
                }
                $currentBadge = $badge;
                $currentEffort = $effort;
                $currentCount = 1;
            }
        }

        // Push last group
        if ($currentCount > 0) {
            $groups[] = $this->formatBadgeGroupLabel($currentCount, $currentEffort, $currentBadge);
        }

        return $groups;
    }

    /**
     * Get the "effort" value for a set — the dimension that's not the load.
     * Default: reps. Override for exercise types where effort is something else (e.g. duration).
     */
    protected function getSetEffortValue(LiftSet $set): string
    {
        return (string) ((int) $set->reps);
    }

    /**
     * Format a single badge group label.
     * e.g. "5×8 - <strong>185 lbs</strong>" or "2×10" (no load label for pure bodyweight)
     */
    protected function formatBadgeGroupLabel(int $count, string $effort, string $badgeLabel): string
    {
        if ($badgeLabel === '') {
            return "{$count} × {$effort}";
        }

        return "{$count} × {$effort} -&nbsp;<strong>{$badgeLabel}</strong>";
    }
    
    /**
     * Format success message description for lift log creation
     * Default implementation for regular weighted exercises
     */
    public function formatSuccessMessageDescription(?float $weight, int $reps, int $rounds, ?string $bandColor = null): string
    {
        return $weight . ' lbs × ' . $reps . ' reps × ' . $rounds . ' sets';
    }
    
    /**
     * Get progression suggestion for this exercise type
     * Default implementation uses progression models (LinearProgression or DoubleProgression)
     */
    public function getProgressionSuggestion(\App\Models\LiftLog $lastLog, int $userId, int $exerciseId, ?\Carbon\Carbon $forDate = null): ?object
    {
        // Get the progression model service
        $progressionModel = $this->getProgressionModel($lastLog);
        
        return $progressionModel->suggest($userId, $exerciseId, $forDate);
    }
    
    /**
     * Get the appropriate progression model for this exercise type
     * Protected helper method for progression suggestions
     */
    protected function getProgressionModel(\App\Models\LiftLog $liftLog): \App\Services\ProgressionModels\ProgressionModel
    {
        $oneRepMaxService = app(\App\Services\OneRepMaxCalculatorService::class);
        
        // Try to infer progression model from recent workout history first
        $inferredModel = $this->inferProgressionModelFromHistory($liftLog->user_id, $liftLog->exercise_id, $oneRepMaxService);
        if ($inferredModel) {
            return $inferredModel;
        }
        
        $supportedProgressionTypes = $this->getSupportedProgressionTypes();
        
        // For exercises that support both linear and double progression, use rep-range logic
        if (in_array('linear', $supportedProgressionTypes) && in_array('double_progression', $supportedProgressionTypes)) {
            // Use rep-range logic to decide between linear and double progression
            if ($liftLog->display_reps >= 8 && $liftLog->display_reps <= 12) {
                return new \App\Services\ProgressionModels\DoubleProgression($oneRepMaxService);
            }
            return new \App\Services\ProgressionModels\LinearProgression($oneRepMaxService);
        }
        
        // For exercises that only support linear progression, use LinearProgression
        if (in_array('linear', $supportedProgressionTypes)) {
            return new \App\Services\ProgressionModels\LinearProgression($oneRepMaxService);
        }
        
        // For exercises that only support double progression, use DoubleProgression
        if (in_array('double_progression', $supportedProgressionTypes)) {
            return new \App\Services\ProgressionModels\DoubleProgression($oneRepMaxService);
        }

        // Fallback to rep-range based selection
        if ($liftLog->display_reps >= 8 && $liftLog->display_reps <= 12) {
            return new \App\Services\ProgressionModels\DoubleProgression($oneRepMaxService);
        }

        return new \App\Services\ProgressionModels\LinearProgression($oneRepMaxService);
    }
    
    /**
     * Infer progression model from workout history
     * Protected helper method for progression suggestions
     */
    protected function inferProgressionModelFromHistory(int $userId, int $exerciseId, \App\Services\OneRepMaxCalculatorService $oneRepMaxService): ?\App\Services\ProgressionModels\ProgressionModel
    {
        $recentLogs = \App\Models\LiftLog::where('user_id', $userId)
            ->where('exercise_id', $exerciseId)
            ->with(['liftSets'])
            ->orderBy('logged_at', 'desc')
            ->take(2)
            ->get();

        if ($recentLogs->count() < 2) {
            return null; // Not enough data to infer
        }

        $newer = $recentLogs->first();
        $older = $recentLogs->last();

        $unitResolver = app(\App\Services\UnitResolver::class);
        $newerUnit = $newer->liftSets->first()->unit ?? 'lbs';
        $olderUnit = $older->liftSets->first()->unit ?? 'lbs';
        $olderWeightInNewerUnit = $unitResolver->convert($older->display_weight, $olderUnit, $newerUnit);

        $weightChange = $newer->display_weight - $olderWeightInNewerUnit;
        $repsChange = $newer->display_reps - $older->display_reps;

        // DoubleProgression pattern: same weight, reps increased OR weight increased with reps reset to lower value
        if ($weightChange == 0 && $repsChange > 0) {
            // Same weight, reps increased - classic DoubleProgression
            return new \App\Services\ProgressionModels\DoubleProgression($oneRepMaxService);
        }

        if ($weightChange > 0 && $repsChange < 0 && $newer->display_reps >= 8 && $newer->display_reps <= 12) {
            // Weight increased, reps decreased to 8-12 range - likely DoubleProgression reset
            return new \App\Services\ProgressionModels\DoubleProgression($oneRepMaxService);
        }

        // LinearProgression pattern: weight increased, same reps
        if ($weightChange > 0 && $repsChange == 0) {
            return new \App\Services\ProgressionModels\LinearProgression($oneRepMaxService);
        }

        return null; // Pattern unclear, use fallback logic
    }
    
    /**
     * Get field type for a given field name
     * Protected helper method for form field generation
     */
    protected function getFieldType(string $fieldName): string
    {
        $fieldTypes = $this->config['field_types'] ?? [];
        
        if (isset($fieldTypes[$fieldName])) {
            return $fieldTypes[$fieldName];
        }
        
        // Default field types based on field name
        switch ($fieldName) {
            case 'band_color':
                return 'select';
            case 'weight':
            case 'reps':
            case 'sets':
            case 'time':
            case 'duration':
                return 'numeric';
            default:
                return 'text';
        }
    }
    
    /**
     * Get default value for a field
     * Protected helper method for form field generation
     */
    protected function getDefaultValue(string $fieldName)
    {
        $defaults = $this->config['field_defaults'] ?? [];
        
        if (isset($defaults[$fieldName])) {
            return $defaults[$fieldName];
        }
        
        // Standard defaults
        switch ($fieldName) {
            case 'weight':
                return 0;
            case 'reps':
                return 5;
            case 'sets':
                return 3;
            case 'band_color':
                return 'red';
            default:
                return '';
        }
    }
    
    /**
     * Get minimum value for a numeric field
     * Protected helper method for form field generation
     */
    protected function getFieldMin(string $fieldName): int
    {
        $mins = $this->config['field_mins'] ?? [];
        
        if (isset($mins[$fieldName])) {
            return $mins[$fieldName];
        }
        
        // Standard minimums
        switch ($fieldName) {
            case 'weight':
                return 0;
            case 'reps':
            case 'sets':
                return 1;
            default:
                return 0;
        }
    }
    
    /**
     * Get maximum value for a numeric field
     * Protected helper method for form field generation
     */
    protected function getFieldMax(string $fieldName): int
    {
        $maxes = $this->config['field_maxes'] ?? [];
        
        if (isset($maxes[$fieldName])) {
            return $maxes[$fieldName];
        }
        
        // Standard maximums
        switch ($fieldName) {
            case 'weight':
                return 600;
            case 'reps':
                return 100;
            case 'sets':
                return 20;
            default:
                return 1000;
        }
    }
    
    /**
     * Get options for a select field
     * Protected helper method for form field generation
     */
    protected function getFieldOptions(string $fieldName): array
    {
        if ($fieldName === 'band_color') {
            $bandColors = config('bands.colors', []);
            return array_map(function($color) {
                return ['value' => $color, 'label' => ucfirst($color)];
            }, array_keys($bandColors));
        }
        
        return [];
    }
    
    /**
     * Get raw display weight value from a lift set
     * Default implementation returns the weight field
     */
    public function getRawDisplayWeight($liftSet)
    {
        return $liftSet->weight ?? 0;
    }
    
    /**
     * Get default weight progression when no intelligent suggestion is available
     * Default implementation adds 5 lbs for regular exercises
     */
    public function getDefaultWeightProgression(float $lastWeight): float
    {
        $increment = $this->unitResolver()->getWeightIncrement(auth()->user());
        return $lastWeight + $increment;
    }
    
    /**
     * Get default starting weight for a new exercise with no history
     * Default implementation returns 95 lbs
     */
    public function getDefaultStartingWeight(\App\Models\Exercise $exercise): float
    {
        // Default starting weights for common exercises
        $defaults = [
            'bench_press' => 135,
            'squat' => 185,
            'deadlift' => 225,
            'overhead_press' => 95,
            'barbell_row' => 115,
        ];
        
        $canonicalName = $exercise->canonical_name ?? '';
        return $defaults[$canonicalName] ?? 95;
    }
    
    /**
     * Get the type name identifier
     * Must be implemented by concrete classes
     */
    abstract public function getTypeName(): string;
    
    /**
     * Process lift data according to exercise type rules
     * Must be implemented by concrete classes
     */
    abstract public function processLiftData(array $data): array;
    
    /**
     * Format weight display for lift logs
     * Must be implemented by concrete classes
     */
    abstract public function formatWeightDisplay(LiftLog $liftLog): string;
    
    /**
     * Format a single set as a badge label.
     *
     * Default implementation: formats weight with unit via UnitResolver.
     * Returns empty string when weight is zero (e.g. pure bodyweight).
     * Subclasses override for type-specific display (bands, duration, distance).
     */
    public function formatSingleSetBadge(LiftSet $set, ?User $user = null): string
    {
        $weight = (float) $set->weight;
        $unit = $set->unit ?? 'lbs';

        if ($weight <= 0) {
            return '';
        }

        return $this->unitResolver()->formatForUser($weight, $unit, $user);
    }

    /**
     * Get the UnitResolver service instance.
     */
    protected function unitResolver(): \App\Services\UnitResolver
    {
        return app(\App\Services\UnitResolver::class);
    }
}