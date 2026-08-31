<?php

namespace App\Services\ExerciseTypes;

use App\Models\LiftLog;
use App\Models\LiftSet;
use App\Models\User;

/**
 * Exercise Type Strategy Interface
 * 
 * This interface defines the contract for all exercise type strategies in the system.
 * Each exercise type (regular, banded, bodyweight) implements this interface to provide
 * type-specific behavior for validation, data processing, display formatting, and capabilities.
 * 
 * The Strategy Pattern is used to eliminate conditional logic throughout the application
 * and provide a clean, extensible architecture for handling different exercise types.
 * 
 * @package App\Services\ExerciseTypes
 * @since 1.0.0
 * 
 * @example
 * // Basic usage with factory
 * $strategy = ExerciseTypeFactory::create($exercise);
 * $rules = $strategy->getValidationRules($user);
 * $processedData = $strategy->processLiftData($inputData);
 * $display = $strategy->formatWeightDisplay($liftLog);
 * 
 * @example
 * // Direct instantiation
 * $strategy = new RegularExerciseType();
 * if ($strategy->canCalculate1RM()) {
 *     $oneRmDisplay = $strategy->format1RMDisplay($liftLog);
 * }
 */
interface ExerciseTypeInterface
{
    /**
     * Get validation rules for lift data based on exercise type
     * 
     * Returns Laravel validation rules array that should be applied when
     * validating lift log data for this exercise type. Rules may vary
     * based on user preferences (e.g., show_extra_weight setting).
     * 
     * @param User|null $user The user context for user-specific validation rules
     * @return array Laravel validation rules array
     * 
     * @example
     * // Regular exercise validation
     * ['weight' => 'required|numeric|min:0', 'reps' => 'required|integer|min:1']
     * 
     * @example
     * // Banded exercise validation
     * ['band_color' => 'required|string|in:red,blue,green', 'reps' => 'required|integer|min:1']
     */
    public function getValidationRules(?User $user = null): array;
    
    /**
     * Get form fields that should be displayed for this exercise type
     * 
     * Returns an array of field names that should be shown in forms
     * for this exercise type. Used by view components to determine
     * which input fields to render.
     * 
     * @return array Array of field names (e.g., ['weight', 'reps', 'band_color'])
     * 
     * @example
     * // Regular exercise fields
     * ['weight', 'reps']
     * 
     * @example
     * // Banded exercise fields
     * ['band_color', 'reps']
     */
    public function getFormFields(): array;
    
    /**
     * Process lift data according to exercise type rules
     * 
     * Transforms and validates lift log data according to the specific
     * requirements of this exercise type. This includes setting default
     * values, nullifying inappropriate fields, and validating required data.
     * 
     * @param array $data Raw lift log data from form input
     * @return array Processed and validated lift log data
     * @throws InvalidExerciseDataException When data is invalid for this exercise type
     * 
     * @example
     * // Regular exercise processing
     * $input = ['weight' => '135', 'reps' => '8', 'band_color' => 'red'];
     * $output = ['weight' => 135, 'reps' => 8, 'band_color' => null];
     * 
     * @example
     * // Banded exercise processing
     * $input = ['weight' => '50', 'band_color' => 'blue', 'reps' => '10'];
     * $output = ['weight' => 0, 'band_color' => 'blue', 'reps' => 10];
     */
    public function processLiftData(array $data): array;
    
    /**
     * Process exercise data according to exercise type rules
     * 
     * Transforms exercise creation/update data to ensure consistency
     * with the exercise type. This includes setting appropriate flags
     * and nullifying conflicting properties.
     * 
     * @param array $data Raw exercise data from form input
     * @return array Processed exercise data
     * 
     * @example
     * // Regular exercise processing
     * $input = ['title' => 'Bench Press', 'exercise_type' => 'bodyweight'];
     * $output = ['title' => 'Bench Press', 'exercise_type' => 'regular'];
     */
    public function processExerciseData(array $data): array;
    
    /**
     * Check if this exercise type supports 1RM calculation
     * 
     * Determines whether one-rep-max calculations are meaningful
     * and supported for this exercise type. Used by services
     * to decide whether to perform 1RM calculations.
     * 
     * @return bool True if 1RM calculation is supported, false otherwise
     * 
     * @example
     * // Regular and bodyweight exercises support 1RM
     * $regularStrategy->canCalculate1RM(); // returns true
     * $bodyweightStrategy->canCalculate1RM(); // returns true
     * 
     * // Banded exercises typically don't support 1RM
     * $bandedStrategy->canCalculate1RM(); // returns false
     */
    public function canCalculate1RM(): bool;
    
    /**
     * Calculate one-rep max for a lift set
     * 
     * Calculates the estimated 1RM based on weight and reps.
     * Each exercise type may have different calculation logic
     * (e.g., bodyweight exercises add user's bodyweight to the calculation).
     * 
     * @param float $weight The weight used in the lift
     * @param int $reps Number of reps performed
     * @param LiftLog $liftLog The lift log context (for accessing user, date, etc.)
     * @return float Calculated 1RM value
     * @throws UnsupportedOperationException If 1RM is not supported for this exercise type
     * 
     * @example
     * // Regular exercise: 135 lbs × 8 reps = ~170 lbs 1RM
     * $regularStrategy->calculate1RM(135, 8, $liftLog); // returns 170.0
     * 
     * @example
     * // Bodyweight exercise: 25 lbs extra + bodyweight × 8 reps
     * $bodyweightStrategy->calculate1RM(25, 8, $liftLog); // returns calculated value including bodyweight
     */
    public function calculate1RM(float $weight, int $reps, LiftLog $liftLog): float;
    
    /**
     * Get the chart type appropriate for this exercise type
     * 
     * Returns the chart type identifier that should be used
     * when generating progress charts for this exercise type.
     * Chart types correspond to different chart generators.
     * 
     * @return string Chart type identifier (e.g., 'one_rep_max', 'volume_progression')
     * 
     * @example
     * // Different chart types for different exercise types
     * $regularStrategy->getChartType(); // 'one_rep_max'
     * $bandedStrategy->getChartType(); // 'volume_progression'
     * $bodyweightStrategy->getChartType(); // 'bodyweight_progression'
     */
    public function getChartType(): string;
    
    /**
     * Get supported progression types for this exercise type
     * 
     * Returns an array of progression model identifiers that
     * are appropriate for this exercise type. Used by training
     * progression services to suggest appropriate progression schemes.
     * 
     * @return array Array of progression type identifiers
     * 
     * @example
     * // Regular exercises support multiple progression types
     * ['linear', 'double_progression']
     * 
     * @example
     * // Banded exercises have specialized progression types
     * ['volume_progression', 'band_progression']
     */
    public function getSupportedProgressionTypes(): array;
    
    /**
     * Get raw display weight value from a lift set
     * 
     * Returns the raw value that should be used for display_weight attribute.
     * For regular/bodyweight exercises, this is the weight field.
     * For banded exercises, this is the band_color field.
     * 
     * @param \App\Models\LiftSet|object $liftSet The lift set to extract weight from
     * @return mixed Raw weight value (numeric for regular/bodyweight, string for banded)
     * 
     * @example
     * // Regular exercise: returns 135
     * // Banded exercise: returns "blue"
     * // Bodyweight exercise: returns 25 (extra weight)
     */
    public function getRawDisplayWeight($liftSet);
    
    /**
     * Format weight display for lift logs
     * 
     * Formats the weight/resistance information for display in tables,
     * charts, and other UI elements. The format varies significantly
     * between exercise types (weight in lbs, band colors, bodyweight notation).
     * 
     * @param LiftLog $liftLog The lift log to format
     * @return string Formatted display string
     * 
     * @example
     * // Regular exercise formatting
     * "135 lbs"
     * 
     * @example
     * // Banded exercise formatting
     * "Band: Blue"
     * 
     * @example
     * // Bodyweight exercise formatting
     * "Bodyweight +25 lbs"
     */
    public function formatWeightDisplay(LiftLog $liftLog): string;
    
    /**
     * Format 1RM display for lift logs
     * 
     * Formats the one-rep-max value for display. Only called for
     * exercise types that support 1RM calculation. The format
     * may include additional context (e.g., "estimated", "includes bodyweight").
     * 
     * @param LiftLog $liftLog The lift log to format
     * @return string Formatted 1RM display string
     * @throws UnsupportedOperationException If 1RM is not supported for this exercise type
     * 
     * @example
     * // Regular exercise 1RM formatting
     * "155.0 lbs"
     * 
     * @example
     * // Bodyweight exercise 1RM formatting
     * "200 lbs (est. incl. BW)"
     */
    public function format1RMDisplay(LiftLog $liftLog): string;
    
    /**
     * Format progression suggestion for lift logs
     * 
     * Analyzes the lift log data and provides a progression suggestion
     * if appropriate. Returns null if no suggestion is available or
     * if the current performance doesn't warrant a progression change.
     * 
     * @param LiftLog $liftLog The lift log to analyze
     * @return string|null Formatted progression suggestion or null
     * 
     * @example
     * // Bodyweight exercise suggestion
     * "Consider adding 5-10 lbs extra weight"
     * 
     * @example
     * // Banded exercise suggestion
     * "Try blue band with 8 reps"
     * 
     * @example
     * // No suggestion available
     * null
     */
    public function formatProgressionSuggestion(LiftLog $liftLog): ?string;
    
    /**
     * Get the type name identifier
     * 
     * Returns a unique string identifier for this exercise type.
     * Used for configuration lookups, logging, error messages,
     * and factory pattern implementation.
     * 
     * @return string Type identifier (e.g., 'regular', 'banded', 'bodyweight')
     * 
     * @example
     * $strategy->getTypeName(); // 'regular'
     */
    public function getTypeName(): string;
    
    /**
     * Get the configuration for this exercise type
     * 
     * Returns the full configuration array for this exercise type
     * from the config/exercise_types.php file. Includes validation rules,
     * display settings, capabilities, and other type-specific configuration.
     * 
     * @return array Configuration array from config file
     * 
     * @example
     * [
     *     'class' => 'App\Services\ExerciseTypes\RegularExerciseType',
     *     'validation' => ['weight' => 'required|numeric|min:0'],
     *     'supports_1rm' => true,
     *     'chart_type' => 'one_rep_max',
     *     // ... other configuration
     * ]
     */
    public function getTypeConfig(): array;
    
    /**
     * Get form field definitions for mobile entry forms
     * 
     * Returns an array of field definitions that should be used to generate
     * mobile entry forms for this exercise type. Each field definition includes
     * the field configuration needed to render the appropriate input controls.
     * 
     * @param array $defaults Default values for the form fields
     * @param User|null $user User context for user-specific field behavior
     * @return array Array of field definitions
     * 
     * @example
     * // Regular exercise field definitions
     * [
     *     [
     *         'name' => 'weight',
     *         'label' => 'Weight (lbs):',
     *         'type' => 'numeric',
     *         'increment' => 5,
     *         'min' => 0,
     *         'max' => 600,
     *         'defaultValue' => 135
     *     ],
     *     [
     *         'name' => 'reps',
     *         'label' => 'Reps:',
     *         'type' => 'numeric',
     *         'increment' => 1,
     *         'min' => 1,
     *         'defaultValue' => 5
     *     ]
     * ]
     * 
     * @example
     * // Cardio exercise field definitions
     * [
     *     [
     *         'name' => 'reps',
     *         'label' => 'Distance (m):',
     *         'type' => 'numeric',
     *         'increment' => 50,
     *         'min' => 50,
     *         'max' => 50000,
     *         'defaultValue' => 500
     *     ]
     * ]
     */
    public function getFormFieldDefinitions(array $defaults = [], ?User $user = null): array;
    
    /**
     * Get field labels for this exercise type
     * 
     * Returns an array mapping field names to their display labels.
     * Used to customize field labels based on exercise type (e.g., "Distance" vs "Reps").
     * 
     * @return array Array mapping field names to labels
     * 
     * @example
     * // Regular exercise labels
     * ['weight' => 'Weight (lbs):', 'reps' => 'Reps:', 'sets' => 'Sets:']
     * 
     * @example
     * // Cardio exercise labels
     * ['reps' => 'Distance (m):', 'sets' => 'Rounds:']
     */
    public function getFieldLabels(?User $user = null): array;
    
    /**
     * Get increment values for numeric fields
     * 
     * Returns an array mapping field names to their increment values
     * for use in mobile entry forms with +/- buttons.
     * 
     * @return array Array mapping field names to increment values
     * 
     * @example
     * // Regular exercise increments
     * ['weight' => 5, 'reps' => 1, 'sets' => 1]
     * 
     * @example
     * // Cardio exercise increments
     * ['reps' => 50, 'sets' => 1] // 50m increments for distance
     */
    public function getFieldIncrements(?User $user = null): array;
    
    /**
     * Format logged item display message for mobile entry
     * 
     * Formats the display message for logged items in the mobile entry interface.
     * This combines weight/resistance display with reps/sets information in a
     * format appropriate for the exercise type.
     * 
     * @param LiftLog $liftLog The lift log to format
     * @return string Formatted display message
     * 
     * @example
     * // Regular exercise: "135 lbs × 8 reps × 3 sets"
     * // Cardio exercise: "500m × 3 rounds"
     * // Bodyweight exercise: "Bodyweight × 10 reps × 3 sets"
     */
    public function formatLoggedItemDisplay(LiftLog $liftLog): string;
    
    /**
     * Format form message display for mobile entry
     * 
     * Formats the last session message for mobile entry forms.
     * This shows what the user did in their previous workout in a
     * format appropriate for the exercise type.
     * 
     * @param array $lastSession Last session data
     * @return string Formatted message text
     * 
     * @example
     * // Regular exercise: "135 lbs × 8 reps × 3 sets"
     * // Cardio exercise: "500m × 3 rounds"
     * // Bodyweight exercise: "Bodyweight +25 lbs × 8 reps × 3 sets"
     */
    public function formatFormMessageDisplay(array $lastSession): string;
    
    /**
     * Format table cell display for workouts table
     * 
     * Formats the weight/resistance information for display in table cells.
     * Returns structured data that the view can format appropriately.
     * 
     * @param LiftLog $liftLog The lift log to format
     * @return array|string Structured display data or simple string for cardio
     * 
     * @example
     * // Regular exercise: ['primary' => '135 lbs', 'secondary' => '8 x 3']
     * // Cardio exercise: "500m × 3 rounds"
     * // Bodyweight exercise: ['primary' => 'Bodyweight', 'secondary' => '8 x 3', 'tertiary' => '+ 25 lbs']
     */
    public function formatTableCellDisplay(LiftLog $liftLog);
    
    /**
     * Format 1RM table cell display
     * 
     * Formats the 1RM information for display in table cells.
     * Returns appropriate text for exercise types that don't support 1RM.
     * 
     * @param LiftLog $liftLog The lift log to format
     * @return string Formatted 1RM display or N/A message
     * 
     * @example
     * // Regular exercise: "155 lbs"
     * // Cardio exercise: "N/A (Cardio)"
     * // Bodyweight exercise: "200 lbs (est. incl. BW)"
     */
    public function format1RMTableCellDisplay(LiftLog $liftLog): string;
    
    /**
     * Get exercise type display name and icon
     * 
     * Returns a formatted display name with icon for showing exercise type
     * in recommendations, exercise lists, and other UI elements.
     * 
     * @return array Array with 'icon' and 'name' keys
     * 
     * @example
     * // Regular exercise: ['icon' => 'fas fa-dumbbell', 'name' => 'Weighted']
     * // Cardio exercise: ['icon' => 'fas fa-running', 'name' => 'Cardio']
     * // Bodyweight exercise: ['icon' => 'fas fa-user', 'name' => 'Bodyweight']
     */
    public function getTypeDisplayInfo(): array;
    
    /**
     * Get chart title for exercise logs page
     * 
     * Returns the appropriate chart title for the exercise type.
     * 
     * @return string Chart title
     * 
     * @example
     * // Regular exercise: "1RM Progress"
     * // Cardio exercise: "Distance Progress"
     * // Bodyweight exercise: "Volume Progress"
     */
    public function getChartTitle(): string;
    
    /**
     * Format a single set as a badge label for mobile summary display.
     *
     * Each exercise type defines what "one set" looks like in badge form:
     * - Regular:           "185 lbs"
     * - Bodyweight:        "+25 lbs" or "" (pure bodyweight)
     * - Cardio:            "500m" or "6 cal"
     * - Static Hold:       "30s hold" or "30s hold +25 lbs"
     * - Banded Resistance: "Red band"
     * - Banded Assistance: "Green band"
     *
     * The base orchestrator in formatMobileSummaryDisplay groups identical
     * consecutive sets and prefixes each badge with the group count and rep info.
     *
     * @param LiftSet $set The individual set to format
     * @param User|null $user The user for unit preference resolution
     * @return string The badge label for this set
     */
    public function formatSingleSetBadge(LiftSet $set, ?User $user = null): string;

    /**
     * Format mobile summary display for exercise summary component
     * 
     * Returns structured data for displaying exercise summaries in mobile components.
     * Includes weight display, reps/sets formatting, and display preferences.
     * 
     * @param LiftLog $liftLog The lift log to format
     * @return array Structured display data
     * 
     * @example
     * // Regular exercise
     * [
     *     'weight' => '135 lbs',
     *     'repsSets' => '3 x 8',
     *     'showWeight' => true
     * ]
     * 
     * @example
     * // Cardio exercise
     * [
     *     'weight' => '',
     *     'repsSets' => '500m × 3 rounds',
     *     'showWeight' => false
     * ]
     */
    public function formatMobileSummaryDisplay(LiftLog $liftLog): array;
    
    /**
     * Format success message description for lift log creation
     * 
     * Formats the workout description part of success messages when lift logs are created.
     * Each exercise type has its own format for displaying the workout details.
     * 
     * @param float|null $weight The weight used (may be null for some exercise types)
     * @param int $reps Number of reps performed
     * @param int $rounds Number of sets/rounds performed
     * @param string|null $bandColor Band color for banded exercises (optional)
     * @return string Formatted workout description
     * 
     * @example
     * // Regular exercise: "135 lbs × 8 reps × 3 sets"
     * // Cardio exercise: "500m × 3 rounds"
     * // Bodyweight exercise: "8 reps × 3 sets" or "+25 lbs × 8 reps × 3 sets"
     * // Banded exercise: "Blue band × 8 reps × 3 sets"
     */
    public function formatSuccessMessageDescription(?float $weight, int $reps, int $rounds, ?string $bandColor = null): string;
    
    /**
     * Get progression suggestion for this exercise type
     * 
     * Analyzes the most recent lift log and provides a progression suggestion
     * appropriate for this exercise type. Different exercise types have different
     * progression strategies (linear weight progression, band progression, distance
     * progression, etc.).
     * 
     * @param LiftLog $lastLog The most recent lift log for this exercise
     * @param int $userId User ID for context (used for bodyweight calculations, history, etc.)
     * @param int $exerciseId Exercise ID for context
     * @param \Carbon\Carbon|null $forDate Date context for progression calculations
     * @return object|null Progression suggestion object with sets, reps, weight, band_color properties, or null if no suggestion available
     * 
     * @example
     * // Regular exercise progression
     * (object)[
     *     'sets' => 3,
     *     'reps' => 5,
     *     'suggestedWeight' => 140.0,
     *     'lastWeight' => 135.0,
     *     'lastReps' => 5,
     *     'lastSets' => 3
     * ]
     * 
     * @example
     * // Banded exercise progression
     * (object)[
     *     'sets' => 3,
     *     'reps' => 9,
     *     'band_color' => 'blue'
     * ]
     * 
     * @example
     * // Cardio exercise progression
     * (object)[
     *     'sets' => 1,
     *     'reps' => 600, // distance in meters
     *     'weight' => 0,
     *     'band_color' => null
     * ]
     */
    public function getProgressionSuggestion(LiftLog $lastLog, int $userId, int $exerciseId, ?\Carbon\Carbon $forDate = null): ?object;
    
    /**
     * Get default weight progression when no intelligent suggestion is available
     * 
     * @param float $lastWeight The weight from the last session
     * @return float The suggested weight for the next session
     */
    public function getDefaultWeightProgression(float $lastWeight): float;
    
    /**
     * Get default starting weight for a new exercise with no history
     * 
     * @param \App\Models\Exercise $exercise The exercise to get starting weight for
     * @return float The default starting weight
     */
    public function getDefaultStartingWeight(\App\Models\Exercise $exercise): float;
    
}