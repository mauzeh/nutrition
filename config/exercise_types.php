<?php

/**
 * Exercise Types Configuration
 * 
 * This configuration file defines all available exercise types and their properties.
 * The exercise type system uses the Strategy Pattern to handle different types of
 * exercises (regular, banded, bodyweight) with type-specific behavior.
 * 
 * Configuration Structure:
 * - types: Defines each exercise type with its class, validation, capabilities, etc.
 * - default_type: The fallback type when type cannot be determined
 * - factory: Factory-specific configuration (caching, fallbacks)
 * - validation: Common validation rules applied to all types
 * - display: Global display settings and formatting options
 * 
 * Adding New Exercise Types:
 * 1. Create a new strategy class implementing ExerciseTypeInterface
 * 2. Add configuration entry in the 'types' array
 * 3. Update factory logic if needed (or use configuration-driven creation)
 * 4. Add appropriate tests
 * 
 * @package Config
 * @since 1.0.0
 */

return [
    /**
     * Exercise Type Definitions
     * 
     * Each exercise type is defined with the following properties:
     * - class: The strategy class that implements the exercise type behavior
     * - validation: Laravel validation rules specific to this exercise type
     * - chart_type: The type of chart to generate for progress tracking
     * - supports_1rm: Whether this exercise type supports 1RM calculations
     * - form_fields: Fields that should be displayed in forms for this type
     * - progression_types: Supported progression models for training programs
     * - display_format: How to format the exercise data for display
     */
    'types' => [
        /**
         * Regular Exercise Type
         * 
         * Traditional weight-based exercises using barbells, dumbbells, machines, etc.
         * This is the most common exercise type and serves as the default fallback.
         */
        'regular' => [
            'class' => \App\Services\ExerciseTypes\RegularExerciseType::class,
            'validation' => [
                'weight' => 'required|numeric|min:0|max:2000',
                'reps' => 'required|integer|min:1|max:100',
            ],
            'chart_type' => 'weight_progression',
            'supports_1rm' => true,
            'form_fields' => ['weight', 'reps'],
            'progression_types' => ['weight_progression', 'volume_progression'],
            'display_format' => 'weight_lbs',
            'field_labels' => [
                'weight' => 'Weight (lbs):',
                'reps' => 'Reps:',
                'sets' => 'Sets:',
            ],
            'field_increments' => [
                'weight' => 5,
                'reps' => 1,
                'sets' => 1,
            ],
        ],
        
        /**
         * Banded Exercise Type (DEPRECATED - use banded_resistance or banded_assistance instead)
         * 
         * @deprecated This type is deprecated. Use 'banded_resistance' or 'banded_assistance' instead.
         * Kept for backward compatibility only. Will be removed in future version.
         */
        'banded' => [
            'class' => \App\Services\ExerciseTypes\BandedExerciseType::class,
            'validation' => [
                'band_color' => 'required|string|in:' . implode(',', array_keys(config('bands.colors', ['red', 'blue', 'green']))),
                'reps' => 'required|integer|min:1|max:100',
            ],
            'chart_type' => 'volume_progression',
            'supports_1rm' => false,
            'form_fields' => ['band_color', 'reps'],
            'progression_types' => ['volume_progression', 'band_progression'],
            'display_format' => 'band_color',
            'deprecated' => true,
            'replacement' => ['banded_resistance', 'banded_assistance'],
            'subtypes' => [
                'resistance' => [
                    'description' => 'Resistance bands that add difficulty',
                    'progression_direction' => 'up',
                ],
                'assistance' => [
                    'description' => 'Assistance bands that reduce difficulty',
                    'progression_direction' => 'down',
                ],
            ],
        ],

        /**
         * Banded Resistance Exercise Type
         * 
         * Exercises using resistance bands that add difficulty to the movement.
         * Band color indicates the resistance level. Weight is forced to 0 since
         * bands don't use traditional weight measurements.
         */
        'banded_resistance' => [
            'class' => \App\Services\ExerciseTypes\BandedResistanceExerciseType::class,
            'validation' => [
                'weight' => 'nullable|numeric|in:0',
                'reps' => 'required|integer|min:1|max:100',
                'band_color' => 'required|string|in:' . implode(',', array_keys(config('bands.colors', ['red', 'blue', 'green']))),
            ],
            'chart_type' => 'band_progression',
            'supports_1rm' => false,
            'form_fields' => ['reps', 'band_color'],
            'progression_types' => ['band_progression'],
            'display_format' => 'band_reps',
            'field_labels' => [
                'reps' => 'Reps:',
                'sets' => 'Sets:',
                'band_color' => 'Band Color:',
            ],
            'field_types' => [
                'band_color' => 'select',
            ],
        ],

        /**
         * Banded Assistance Exercise Type
         * 
         * Exercises using assistance bands that reduce difficulty of the movement.
         * Band color indicates the assistance level. Weight is forced to 0 since
         * bands don't use traditional weight measurements.
         */
        'banded_assistance' => [
            'class' => \App\Services\ExerciseTypes\BandedAssistanceExerciseType::class,
            'validation' => [
                'weight' => 'nullable|numeric|in:0',
                'reps' => 'required|integer|min:1|max:100',
                'band_color' => 'required|string|in:' . implode(',', array_keys(config('bands.colors', ['red', 'blue', 'green']))),
            ],
            'chart_type' => 'band_progression',
            'supports_1rm' => false,
            'form_fields' => ['reps', 'band_color'],
            'progression_types' => ['band_progression'],
            'display_format' => 'band_reps',
            'field_labels' => [
                'reps' => 'Reps:',
                'sets' => 'Sets:',
                'band_color' => 'Band Color:',
            ],
            'field_types' => [
                'band_color' => 'select',
            ],
        ],
        
        /**
         * Bodyweight Exercise Type
         * 
         * Exercises that primarily use body weight as resistance. The weight field
         * represents additional weight (e.g., weighted vest, dip belt) rather than
         * the total resistance. Weight can be 0 for pure bodyweight exercises.
         */
        'bodyweight' => [
            'class' => \App\Services\ExerciseTypes\BodyweightExerciseType::class,
            'validation' => [
                'weight' => 'nullable|numeric|min:0',
                'reps' => 'required|integer|min:1|max:100',
            ],
            'chart_type' => 'bodyweight_progression',
            'supports_1rm' => false,
            'form_fields' => ['weight', 'reps'],
            'progression_types' => ['linear', 'double_progression', 'bodyweight_progression'],
            'display_format' => 'bodyweight_with_extra',
            'field_labels' => [
                'weight' => 'Added Weight (lbs):',
                'reps' => 'Reps:',
                'sets' => 'Sets:',
            ],
            'field_increments' => [
                'weight' => 2.5, // Smaller increments for bodyweight
                'reps' => 1,
                'sets' => 1,
            ],
        ],

        /**
         * Cardio Exercise Type
         * 
         * Distance-based cardiovascular exercises like running, cycling, and rowing.
         * The reps field represents distance in meters, and weight is always 0.
         * Sets represent rounds or intervals performed.
         */
        'cardio' => [
            'class' => \App\Services\ExerciseTypes\CardioExerciseType::class,
            'validation' => [
                'reps' => 'required|integer|min:50|max:50000', // Distance in meters
                'weight' => 'nullable|numeric|in:0', // Must be 0
            ],
            'chart_type' => 'cardio_progression',
            'supports_1rm' => false,
            'form_fields' => ['reps'], // Only distance, no weight
            'progression_types' => ['cardio_progression'],
            'display_format' => 'distance_rounds',
            'field_labels' => [
                'reps' => 'Distance (m):',
                'sets' => 'Rounds:',
            ],
            'field_increments' => [
                'reps' => 50, // 50m increments for distance
                'sets' => 1,
            ],
            'field_mins' => [
                'reps' => 50,
                'sets' => 1,
            ],
            'field_maxes' => [
                'reps' => 50000,
                'sets' => 20,
            ],
        ],

        /**
         * Sled Exercise Type
         * 
         * Sled-based exercises (Sled Push, Sled Pull) that combine plate weight
         * with distance. Weight represents plates loaded on the sled, distance
         * represents how far it was pushed/pulled.
         */
        'sled' => [
            'class' => \App\Services\ExerciseTypes\SledExerciseType::class,
            'validation' => [
                'weight' => 'required|numeric|min:0|max:2000',
                'distance' => 'required|numeric|min:0|max:999',
                'distance_unit' => 'required|string|in:m,ft',
            ],
            'chart_type' => 'sled_progression',
            'supports_1rm' => false,
            'form_fields' => ['weight', 'distance', 'distance_unit'],
            'progression_types' => ['weight_progression', 'distance_progression'],
            'display_format' => 'weight_distance',
            'field_labels' => [
                'weight' => 'Weight (lbs):',
                'distance' => 'Distance:',
                'distance_unit' => 'Unit:',
            ],
            'field_increments' => [
                'weight' => 45,
                'distance' => 5,
            ],
            'field_defaults' => [
                'weight' => 90,
                'distance' => 50,
                'distance_unit' => 'm',
            ],
            'field_mins' => [
                'weight' => 0,
                'distance' => 0,
            ],
            'field_maxes' => [
                'weight' => 2000,
                'distance' => 999,
            ],
        ],

        /**
         * Static Hold Exercise Type
         * 
         * Isometric hold exercises commonly used in gymnastics and calisthenics.
         * The time field represents hold duration in seconds, weight is optional added weight.
         * Examples: L-sits, planches, front levers, handstands, hollow body holds.
         */
        'static_hold' => [
            'class' => \App\Services\ExerciseTypes\StaticHoldExerciseType::class,
            'validation' => [
                'time' => 'required|integer|min:1|max:300', // Duration in seconds (1s - 5min)
                'reps' => 'nullable|integer|in:1', // Always 1, set automatically
                'weight' => 'nullable|numeric|min:0|max:500', // Optional added weight
            ],
            'chart_type' => 'hold_duration_progression',
            'supports_1rm' => false,
            'form_fields' => ['time', 'weight'], // Duration and optional weight
            'progression_types' => ['duration_progression', 'weighted_progression'],
            'display_format' => 'hold_duration',
            'field_labels' => [
                'time' => 'Hold Duration (seconds):',
                'weight' => 'Added Weight (lbs):',
                'sets' => 'Sets:',
            ],
            'field_increments' => [
                'time' => 1, // 1-second increments (holds are difficult!)
                'weight' => 5,
                'sets' => 1,
            ],
            'field_defaults' => [
                'time' => 30, // Default 30 second hold
                'weight' => 0, // No weight by default
                'sets' => 3,
            ],
            'field_mins' => [
                'time' => 1,
                'weight' => 0,
                'sets' => 1,
            ],
            'field_maxes' => [
                'time' => 300, // 5 minutes max
                'weight' => 500,
                'sets' => 20,
            ],
        ],
    ],
    
    /**
     * Default Exercise Type
     * 
     * The exercise type to use when the type cannot be determined from
     * exercise properties. Should be the most common/safe type.
     */
    'default_type' => 'regular',
    
    /**
     * Factory Configuration
     * 
     * Settings that control how the ExerciseTypeFactory behaves:
     * - cache_strategies: Whether to cache strategy instances for performance
     * - fallback_type: The type to use when strategy creation fails
     */
    'factory' => [
        'cache_strategies' => true,
        'fallback_type' => 'regular',
    ],
    
    /**
     * Common Validation Rules
     * 
     * Validation rules that apply to all exercise types in addition to
     * type-specific rules. These are merged with type-specific rules.
     */
    'validation' => [
        'common_rules' => [
            'reps' => 'required|integer|min:1|max:100',
            'notes' => 'nullable|string|max:1000',
        ],
    ],
    
    /**
     * Display Configuration
     * 
     * Global settings for how exercise data is formatted and displayed:
     * - weight_unit: The unit to display for weights (lbs, kg)
     * - precision: Number of decimal places for weight display
     * - show_1rm_when_supported: Whether to show 1RM when the exercise type supports it
     */
    'display' => [
        'weight_unit' => 'lbs',
        'precision' => 1,
        'show_1rm_when_supported' => true,
    ],
];