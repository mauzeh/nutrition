<?php

namespace App\Services\ExerciseTypes;

use App\Models\Exercise;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Exercise Type Factory
 * 
 * Factory class responsible for creating appropriate exercise type strategy instances
 * based on exercise properties. Implements the Factory Pattern to eliminate conditional
 * logic throughout the application and provide a centralized point for strategy creation.
 * 
 * The factory supports:
 * - Automatic strategy selection based on exercise properties
 * - Strategy caching for performance optimization
 * - Graceful fallback mechanisms for error recovery
 * - Configuration-driven strategy creation
 * - Safe creation methods that never throw exceptions
 * 
 * @package App\Services\ExerciseTypes
 * @since 1.0.0
 * 
 * @example
 * // Basic usage
 * $strategy = ExerciseTypeFactory::create($exercise);
 * $rules = $strategy->getValidationRules();
 * 
 * @example
 * // Safe creation (never throws exceptions)
 * $strategy = ExerciseTypeFactory::createSafe($exercise);
 * 
 * @example
 * // Validation helper
 * $rules = ExerciseTypeFactory::validateExerciseData($exercise, $data, $user);
 */
class ExerciseTypeFactory
{
    /**
     * Cache of created strategy instances
     * 
     * Keyed by exercise properties to avoid repeated instantiation
     * of the same strategy types. Can be disabled via configuration.
     * 
     * @var array<string, ExerciseTypeInterface>
     */
    private static array $strategies = [];
    
    /**
     * Create an exercise type strategy for the given exercise
     */
    public static function create(Exercise $exercise): ExerciseTypeInterface
    {
        $key = self::generateKey($exercise);
        
        if (config('exercise_types.factory.cache_strategies', true) && isset(self::$strategies[$key])) {
            return self::$strategies[$key];
        }
        
        $strategy = self::createStrategy($exercise);
        
        if (config('exercise_types.factory.cache_strategies', true)) {
            self::$strategies[$key] = $strategy;
        }
        
        return $strategy;
    }

    /**
     * Create an exercise type strategy by type name string.
     */
    public static function createFromTypeName(string $typeName): ExerciseTypeInterface
    {
        $typeConfig = config("exercise_types.types.{$typeName}");

        if ($typeConfig && isset($typeConfig['class']) && class_exists($typeConfig['class'])) {
            return new $typeConfig['class']();
        }

        return new RegularExerciseType();
    }
    
    /**
     * Create a strategy instance based on exercise properties
     * Updated to handle new exercise type values: regular, bodyweight, banded_resistance, banded_assistance
     */
    private static function createStrategy(Exercise $exercise): ExerciseTypeInterface
    {
        try {
            // Determine exercise type based on properties
            $typeName = self::determineExerciseType($exercise);
            
            // Handle legacy 'banded' type mapping for backward compatibility
            if ($typeName === 'banded') {
                // Fall back to checking legacy fields if needed
                $typeName = $exercise->band_type === 'assistance' ? 'banded_assistance' : 'banded_resistance';
            }
            
            // Get the class from configuration
            $typeConfig = config("exercise_types.types.{$typeName}");
            
            if (!$typeConfig || !isset($typeConfig['class'])) {
                throw new InvalidArgumentException("No configuration found for exercise type: {$typeName}");
            }
            
            $className = $typeConfig['class'];
            
            if (!class_exists($className)) {
                throw new InvalidArgumentException("Exercise type class does not exist: {$className}");
            }
            
            $strategy = new $className();
            
            if (!$strategy instanceof ExerciseTypeInterface) {
                throw new InvalidArgumentException("Exercise type class must implement ExerciseTypeInterface: {$className}");
            }
            
            return $strategy;
            
        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::warning('Failed to create exercise type strategy', [
                'exercise_id' => $exercise->id,
                'exercise_type' => self::determineExerciseType($exercise),
                'error' => $e->getMessage(),
            ]);
            
            return self::createFallbackStrategy($e);
        }
    }
    
    /**
     * Create a fallback strategy when primary strategy creation fails
     */
    private static function createFallbackStrategy(\Exception $originalException): ExerciseTypeInterface
    {
        // Try the configured fallback type
        $fallbackType = config('exercise_types.factory.fallback_type', 'regular');
        
        try {
            $fallbackConfig = config("exercise_types.types.{$fallbackType}");
            
            if ($fallbackConfig && isset($fallbackConfig['class']) && class_exists($fallbackConfig['class'])) {
                $fallbackStrategy = new $fallbackConfig['class']();
                
                if ($fallbackStrategy instanceof ExerciseTypeInterface) {
                    return $fallbackStrategy;
                }
            }
        } catch (\Exception $fallbackException) {
            \Log::error('Fallback strategy creation also failed', [
                'fallback_type' => $fallbackType,
                'fallback_error' => $fallbackException->getMessage(),
                'original_error' => $originalException->getMessage(),
            ]);
        }
        
        // Last resort: try to create RegularExerciseType directly
        try {
            return new RegularExerciseType();
        } catch (\Exception $lastResortException) {
            \Log::critical('All fallback mechanisms failed', [
                'original_error' => $originalException->getMessage(),
                'fallback_error' => $fallbackException->getMessage() ?? 'N/A',
                'last_resort_error' => $lastResortException->getMessage(),
            ]);
            
            // If even the direct instantiation fails, throw the original exception
            throw $originalException;
        }
    }
    
    /**
     * Determine the exercise type based on exercise properties
     * Uses the exercise_type field directly
     */
    private static function determineExerciseType(Exercise $exercise): string
    {
        // Use exercise_type field directly - all exercises should have this set
        if (!empty($exercise->exercise_type)) {
            return $exercise->exercise_type;
        }
        
        // Default fallback for any edge cases
        return 'regular';
    }
    
    /**
     * Generate a cache key for the exercise
     * Simplified to use only exercise_type
     */
    private static function generateKey(Exercise $exercise): string
    {
        return sprintf('exercise_%d_%s', $exercise->id, $exercise->exercise_type);
    }
    
    /**
     * Clear the strategy cache
     */
    public static function clearCache(): void
    {
        self::$strategies = [];
    }
    
    /**
     * Get all available exercise types from configuration
     */
    public static function getAvailableTypes(): array
    {
        return array_keys(config('exercise_types.types', []));
    }
    
    /**
     * Check if a given exercise type is supported
     */
    public static function isTypeSupported(string $typeName): bool
    {
        $typeConfig = config("exercise_types.types.{$typeName}");
        return $typeConfig && isset($typeConfig['class']) && class_exists($typeConfig['class']);
    }
    
    /**
     * Create a strategy with graceful fallback for legacy code compatibility
     * This method never throws exceptions and always returns a valid strategy
     */
    public static function createSafe(Exercise $exercise): ExerciseTypeInterface
    {
        try {
            return self::create($exercise);
        } catch (\Exception $e) {
            // Log the error but don't throw it
            Log::warning('Safe strategy creation fell back to RegularExerciseType', [
                'exercise_id' => $exercise->id,
                'error' => $e->getMessage(),
            ]);
            
            // Always return RegularExerciseType as the safest fallback
            return new RegularExerciseType();
        }
    }
    
    /**
     * Validate exercise data using the appropriate strategy with fallback
     */
    public static function validateExerciseData(Exercise $exercise, array $data, $user = null): array
    {
        try {
            $strategy = self::create($exercise);
            $rules = $strategy->getValidationRules($user);
            
            // Add common validation rules
            $commonRules = config('exercise_types.validation.common_rules', []);
            $rules = array_merge($commonRules, $rules);
            
            return $rules;
        } catch (\Exception $e) {
            Log::warning('Validation rule generation failed, using basic rules', [
                'exercise_id' => $exercise->id,
                'error' => $e->getMessage(),
            ]);
            
            // Return basic validation rules as fallback
            return [
                'weight' => 'nullable|numeric|min:0',
                'reps' => 'required|integer|min:1|max:100',
                'band_color' => 'nullable|string',
                'notes' => 'nullable|string|max:1000',
            ];
        }
    }
}