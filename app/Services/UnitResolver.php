<?php

namespace App\Services;

use App\Models\User;

class UnitResolver
{
    public const LBS_TO_KG = 0.45359237;
    public const KG_TO_LBS = 2.2046226218;

    /**
     * Convert value from one unit to another with target-specific rounding.
     */
    public function convert(float $value, string $from, string $to): float
    {
        $fromLower = strtolower($from);
        $toLower = strtolower($to);

        if ($fromLower === $toLower) {
            return $value;
        }

        if ($fromLower === 'lbs' && $toLower === 'kg') {
            $converted = $value * self::LBS_TO_KG;
            return round($converted * 2) / 2;
        }

        if ($fromLower === 'kg' && $toLower === 'lbs') {
            $converted = $value * self::KG_TO_LBS;
            return (float) round($converted);
        }

        return $value;
    }

    /**
     * Format a value for display with its unit label.
     * lbs: 0 decimals for whole numbers. kg: 0 or 1 decimal (for .5 values).
     */
    public function format(float $value, string $unit): string
    {
        $unitLower = strtolower($unit);

        if ($unitLower === 'lbs') {
            if (fmod($value, 1.0) == 0.0) {
                return number_format($value, 0) . ' lbs';
            }
            return number_format($value, 1) . ' lbs';
        }

        if ($unitLower === 'kg') {
            $rounded = round($value * 2) / 2;
            if (fmod($rounded, 1.0) == 0.0) {
                return number_format($rounded, 0) . ' kg';
            }
            return number_format($rounded, 1) . ' kg';
        }

        return $value . ' ' . $unit;
    }

    /**
     * Resolve user's preferred weight unit. Falls back to config default.
     */
    public function getPreferredWeightUnit(?User $user = null): string
    {
        if ($user !== null && !empty($user->weight_unit)) {
            return $user->weight_unit;
        }

        return config('exercise_types.display.weight_unit', 'lbs');
    }

    /**
     * Convert a raw weight to the user's preferred unit and format for display.
     */
    public function formatForUser(float $value, string $sourceUnit, ?User $user = null): string
    {
        $targetUnit = $this->getPreferredWeightUnit($user);
        $converted = $this->convert($value, $sourceUnit, $targetUnit);
        return $this->format($converted, $targetUnit);
    }

    /**
     * Get the weight input step/increment for the user's preferred unit.
     * lbs: 5.0, kg: 2.5
     */
    public function getWeightIncrement(?User $user = null): float
    {
        $unit = $this->getPreferredWeightUnit($user);
        return strtolower($unit) === 'kg' ? 2.5 : 5.0;
    }

    /**
     * Get the weight input step attribute for HTML inputs.
     * lbs: 1.0, kg: 0.5
     */
    public function getWeightStep(?User $user = null): float
    {
        $unit = $this->getPreferredWeightUnit($user);
        return strtolower($unit) === 'kg' ? 0.5 : 1.0;
    }
}
