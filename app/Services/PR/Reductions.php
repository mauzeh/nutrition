<?php

namespace App\Services\PR;

use App\Services\UnitResolver;

final class Reductions
{
    /**
     * Reduce sets based on descriptor 'reduce' strategy.
     *
     * @param array $sets Array of set models or arrays
     * @param array $descriptor PR family descriptor
     * @return float|int|array|null
     */
    public static function reduce(array $sets, array $descriptor): float|int|array|null
    {
        if (empty($sets)) {
            return null;
        }

        $minGroupSize = $descriptor['minGroupSize'] ?? 1;
        if (count($sets) < $minGroupSize) {
            return null;
        }

        return match ($descriptor['reduce']) {
            'maxOf' => self::maxOf($sets, $descriptor['field']),
            'minOf' => self::minOf($sets, $descriptor['field']),
            'sumOf' => isset($descriptor['keyFields']) 
                ? [ (string)(count($sets)) => self::sumOf($sets, $descriptor['field']) ]
                : self::sumOf($sets, $descriptor['field']),
            'sumProduct' => self::sumProduct($sets, $descriptor['factors'], $descriptor),
            'estimated1RM' => self::estimated1RM($sets, $descriptor['field'], $descriptor['repField']),
            'perKey' => self::perKey($sets, $descriptor),
            default => throw new \InvalidArgumentException("Unknown reduction primitive: {$descriptor['reduce']}"),
        };
    }

    public static function maxOf(array $sets, string $field): float|int|null
    {
        $max = null;
        foreach ($sets as $set) {
            $val = self::extractValue($set, $field);
            if ($val !== null && ($max === null || $val > $max)) {
                $max = $val;
            }
        }
        return $max;
    }

    public static function minOf(array $sets, string $field): float|int|null
    {
        $min = null;
        foreach ($sets as $set) {
            $val = self::extractValue($set, $field);
            if ($val !== null && ($min === null || $val < $min)) {
                $min = $val;
            }
        }
        return $min;
    }

    public static function sumOf(array $sets, string $field): float|int
    {
        $sum = 0;
        foreach ($sets as $set) {
            $val = self::extractValue($set, $field);
            if ($val !== null) {
                $sum += $val;
            }
        }
        return $sum;
    }

    public static function sumProduct(array $sets, array $factors, array|string|null $descriptorOrMode = null): float|int|null
    {
        $mode = is_array($descriptorOrMode) ? ($descriptorOrMode['mode'] ?? null) : $descriptorOrMode;

        if ($mode === 'weighted') {
            $hasAnyWeight = false;
            foreach ($sets as $set) {
                $w = self::extractValue($set, 'weight');
                if ($w !== null && $w > 0) {
                    $hasAnyWeight = true;
                    break;
                }
            }
            if (!$hasAnyWeight) {
                return null;
            }
        } elseif ($mode === 'bodyweight') {
            foreach ($sets as $set) {
                $w = self::extractValue($set, 'weight');
                if ($w !== null && $w > 0) {
                    return null;
                }
            }
        }

        $sum = 0;
        foreach ($sets as $set) {
            $product = 1;
            $hasNull = false;
            foreach ($factors as $factor) {
                // Volume converts mass kg→lbs at FULL precision (no per-set 2-decimal round) and
                // sums, matching the Athlete engine's "sum then convert once" order. Per-set
                // rounding (via extractValue) drifted the total by up to 0.01 vs Athlete.
                $val = self::extractValueRaw($set, $factor);
                if ($val === null) {
                    $hasNull = true;
                    break;
                }
                $product *= $val;
            }
            if (!$hasNull) {
                $sum += $product;
            }
        }
        return $sum;
    }

    public static function estimated1RM(array $sets, string $field, string $repField): float|null
    {
        $best1RM = null;
        foreach ($sets as $set) {
            $weight = self::extractValue($set, $field);
            $reps = self::extractValue($set, $repField);

            if ($weight !== null && $reps !== null && $weight > 0 && $reps > 0) {
                // Epley variant — MUST match BaseExerciseType::calculate1RM and the Athlete
                // engine (calculate1RM): coefficient 0.0333, effective reps capped at 10
                // (formulas are unreliable past 10 reps). A prior reps/30 form here diverged
                // from the frozen cross-app contract; the contract suite guards this.
                if ($reps === 1) {
                    $est1RM = (float) $weight;
                } else {
                    $effectiveReps = min($reps, 10);
                    $est1RM = (float) ($weight * (1 + (0.0333 * $effectiveReps)));
                }
                if ($best1RM === null || $est1RM > $best1RM) {
                    $best1RM = $est1RM;
                }
            }
        }
        return $best1RM;
    }

    public static function perKey(array $sets, array $descriptor): array
    {
        $keyFields = $descriptor['keyFields'];
        $aggregate = $descriptor['aggregate'];
        $valueField = $descriptor['valueField'] ?? null;
        $maxReps = $descriptor['maxReps'] ?? null;

        $grouped = [];

        foreach ($sets as $set) {
            $keyParts = [];
            $validKey = true;
            foreach ($keyFields as $kf) {
                $kVal = self::extractValue($set, $kf);
                if ($kVal === null && $kf === 'rounds') {
                    $kVal = count($sets);
                }
                if ($kVal === null) {
                    $validKey = false;
                    break;
                }
                // A weight key component (e.g. the speed bucket's loadComp) MUST be the
                // whole-pound rounded value — byte-identical to the Athlete engine's
                // buildCompositeKey (Math.round(toComparable(...))). extractValue yields the
                // 2-decimal kg→lbs value; without this round a kg log would bucket as
                // "220.46|50" while Athlete buckets "220|50", silently diverging speed PRs.
                if (self::resolveField($kf) === 'weight') {
                    $kVal = (int) round((float) $kVal);
                }
                $keyParts[] = (string) $kVal;
            }

            if (!$validKey) {
                continue;
            }

            // Rep-specific maxReps cap check if keyFields contains reps
            if ($maxReps !== null && isset($set['reps']) && $set['reps'] > $maxReps) {
                continue;
            }

            $compositeKey = implode('|', $keyParts);

            if ($aggregate === 'count') {
                $grouped[$compositeKey] = ($grouped[$compositeKey] ?? 0) + 1;
            } elseif ($aggregate === 'sumReps') {
                $reps = self::extractValue($set, 'reps') ?? 0;
                $grouped[$compositeKey] = ($grouped[$compositeKey] ?? 0) + $reps;
            } elseif ($aggregate === 'maxValue') {
                $val = self::extractValue($set, $valueField);
                // Skip non-positive values (e.g. 0 added weight on a pure-bodyweight rep) so an
                // unweighted set does not seed a 0-weight rep-max record — mirror of the Athlete
                // rep-max reduction (which skips weight <= 0).
                if ($val !== null && $val > 0) {
                    if (!isset($grouped[$compositeKey]) || $val > $grouped[$compositeKey]) {
                        $grouped[$compositeKey] = $val;
                    }
                }
            } elseif ($aggregate === 'sumValue') {
                $val = self::extractValue($set, $valueField) ?? 0;
                $grouped[$compositeKey] = ($grouped[$compositeKey] ?? 0) + $val;
            } elseif ($aggregate === 'minValue') {
                $val = self::extractValue($set, $valueField);
                if ($val !== null) {
                    if (!isset($grouped[$compositeKey]) || $val < $grouped[$compositeKey]) {
                        $grouped[$compositeKey] = $val;
                    }
                }
            }
        }

        $minKeyCount = $descriptor['minKeyCount'] ?? null;
        if ($minKeyCount !== null) {
            $grouped = array_filter($grouped, fn($val) => $val >= $minKeyCount);
        }

        return $grouped;
    }

    /**
     * Normalize a distance to integer meters (parity with the Athlete engine and the
     * former LoadOutput strategy). Feet convert via 0.3048; meters round to integer.
     * This is what makes distance/speed PRs comparable across mixed distance_units.
     */
    private static function normalizeDistance(float $distance, string $unit): int
    {
        if ($distance <= 0) {
            return 0;
        }
        $meters = strtolower($unit) === 'ft' ? $distance * 0.3048 : $distance;
        return (int) round($meters);
    }

    /**
     * Read a set field in the comparable frame. Mass (weight) converts kg→lbs; distance normalizes
     * to integer meters. $roundMass controls whether the kg→lbs conversion is rounded to 2 decimals:
     * true (default) for scalar/keyed reads whose stored value + unit tolerance need 2-dp precision;
     * false for volume (sumProduct), which converts at full precision and rounds once at the end to
     * match the Athlete engine's "sum then convert" order.
     */
    private static function extractValueRaw(mixed $set, string $field): float|int|null
    {
        return self::extractValue($set, $field, roundMass: false);
    }

    // The only role token is 'load' → the weight column. Logger has no dialect: all mass lives in `weight`.
    private static function resolveField(string $field): string
    {
        return $field === 'load' ? 'weight' : $field;
    }

    private static function extractValue(mixed $set, string $field, bool $roundMass = true): float|int|null
    {
        $field = self::resolveField($field);

        $convertMass = function ($val) {
            return (float) $val * UnitResolver::KG_TO_LBS;
        };

        if (is_array($set)) {
            $val = $set[$field] ?? null;
            if ($val === null && $field === 'duration') {
                $val = $set['time'] ?? null;
            }
            if ($val === null && $field === 'reps') {
                $val = $set['rounds'] ?? null;
            }
            if ($field === 'weight' && $val !== null && strtolower((string)($set['unit'] ?? 'lbs')) === 'kg') {
                $val = $convertMass($val);
            }
            if ($field === 'distance' && $val !== null) {
                $val = self::normalizeDistance((float)$val, (string)($set['distance_unit'] ?? 'm'));
            }
            return $val;
        }

        if (is_object($set)) {
            $val = $set->{$field} ?? null;
            if ($val === null && $field === 'duration') {
                $val = $set->time ?? null;
            }
            if ($val === null && $field === 'reps') {
                $val = $set->rounds ?? null;
            }
            if ($field === 'weight' && $val !== null && strtolower((string)($set->unit ?? 'lbs')) === 'kg') {
                $val = $convertMass($val);
            }
            if ($field === 'distance' && $val !== null) {
                $val = self::normalizeDistance((float)$val, (string)($set->distance_unit ?? 'm'));
            }
            return $val;
        }

        return null;
    }
}
