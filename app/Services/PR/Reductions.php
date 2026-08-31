<?php

namespace App\Services\PR;

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
            'sumProduct' => self::sumProduct($sets, $descriptor['factors']),
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

    public static function sumProduct(array $sets, array $factors): float|int
    {
        $allZeroWeight = true;
        foreach ($sets as $set) {
            $w = self::extractValue($set, 'weight');
            if ($w !== null && $w > 0) {
                $allZeroWeight = false;
                break;
            }
        }

        $sum = 0;
        foreach ($sets as $set) {
            $product = 1;
            $hasNull = false;
            foreach ($factors as $factor) {
                $val = self::extractValue($set, $factor);
                if ($val === null) {
                    $hasNull = true;
                    break;
                }
                // If pure bodyweight log (all zero weight), treat zero weight factor as 1
                if ($allZeroWeight && $factor === 'weight' && $val == 0) {
                    $val = 1;
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
                // Epley formula: weight * (1 + reps / 30)
                $est1RM = $reps === 1 ? (float)$weight : (float)($weight * (1 + $reps / 30.0));
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
                $keyParts[] = (string)$kVal;
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
                if ($val !== null) {
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

    private static function extractValue(mixed $set, string $field): float|int|null
    {
        if (is_array($set)) {
            $val = $set[$field] ?? null;
            if ($val === null && $field === 'duration') {
                $val = $set['time'] ?? null;
            }
            if ($val === null && $field === 'reps') {
                $val = $set['rounds'] ?? null;
            }
            if ($field === 'weight' && $val !== null && strtolower((string)($set['unit'] ?? 'lbs')) === 'kg') {
                $val = round((float)$val * 2.2046226218, 2);
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
                $val = round((float)$val * 2.2046226218, 2);
            }
            if ($field === 'distance' && $val !== null) {
                $val = self::normalizeDistance((float)$val, (string)($set->distance_unit ?? 'm'));
            }
            return $val;
        }

        return null;
    }
}
