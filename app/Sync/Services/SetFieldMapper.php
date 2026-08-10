<?php

namespace App\Sync\Services;

use App\Models\LiftSet;

class SetFieldMapper
{
    /**
     * Map front-end set data to database columns.
     */
    public function mapToColumns(string $logType, array $setData, string $weightUnit): array
    {
        $columns = [
            'unit' => $weightUnit,
            'weight' => 0,
        ];

        switch ($logType) {
            case 'barbell':
            case 'single-dumbbell':
            case 'dual-dumbbell':
            case 'machine':
                $columns['weight'] = $setData['weight'] ?? null;
                $columns['reps'] = $setData['reps'] ?? null;
                break;

            case 'bodyweight':
            case 'added-weight':
                $columns['weight'] = $setData['addedWeight'] ?? $setData['weight'] ?? 0;
                $columns['reps'] = $setData['reps'] ?? null;
                break;

            case 'kettlebell':
                $columns['weight'] = $setData['kbWeight'] ?? $setData['weight'] ?? null;
                $columns['reps'] = $setData['reps'] ?? null;
                break;

            case 'ball':
                $columns['weight'] = $setData['ballWeight'] ?? $setData['weight'] ?? null;
                $columns['reps'] = $setData['reps'] ?? null;
                break;

            case 'bodyweight-reps':
                $columns['reps'] = $setData['reps'] ?? null;
                break;

            case 'static-hold':
                $columns['time'] = $setData['duration'] ?? null;
                break;

            case 'weighted-carry':
                $columns['weight'] = $setData['weight'] ?? null;
                $columns['time'] = $setData['duration'] ?? null;
                break;

            case 'dual-kettlebell':
                $columns['weight'] = $setData['kbWeight'] ?? $setData['weight'] ?? null;
                $columns['time'] = $setData['duration'] ?? null;
                break;

            case 'cardio':
                $columns['distance'] = $setData['distance'] ?? null;
                $columns['distance_unit'] = $setData['distanceUnit'] ?? $setData['distance_unit'] ?? null;
                $columns['time'] = $setData['time'] ?? null;
                $columns['calories'] = $setData['calories'] ?? null;
                break;

            case 'cardio-calories':
                $columns['calories'] = $setData['calories'] ?? null;
                break;

            case 'cardio-distance':
                $columns['distance'] = $setData['distance'] ?? null;
                $columns['distance_unit'] = $setData['distanceUnit'] ?? $setData['distance_unit'] ?? null;
                $columns['time'] = $setData['time'] ?? null;
                break;

            case 'banded':
                $columns['band_color'] = $setData['bandColor'] ?? $setData['band_color'] ?? null;
                $columns['reps'] = $setData['reps'] ?? null;
                break;

            case 'sled':
                $columns['weight'] = $setData['weight'] ?? null;
                $columns['distance'] = $setData['distance'] ?? null;
                $columns['distance_unit'] = $setData['distanceUnit'] ?? $setData['distance_unit'] ?? null;
                break;
        }

        return $columns;
    }

    /**
     * Map database columns to wire format for the Athlete sync API.
     *
     * Always returns 'weight' — never typed aliases (kbWeight, addedWeight,
     * ballWeight). The Athlete side owns field renaming and unit conversion
     * based on the logType included in the response.
     */
    public function mapFromColumns(string $logType, LiftSet $set): array
    {
        $data = [];

        switch ($logType) {
            case 'barbell':
            case 'single-dumbbell':
            case 'dual-dumbbell':
            case 'machine':
            case 'bodyweight':
            case 'added-weight':
            case 'kettlebell':
            case 'ball':
                $data['weight'] = $set->weight;
                $data['reps'] = $set->reps;
                break;

            case 'bodyweight-reps':
                $data['reps'] = $set->reps;
                break;

            case 'static-hold':
                $data['duration'] = $set->time;
                break;

            case 'weighted-carry':
            case 'dual-kettlebell':
                $data['weight'] = $set->weight;
                $data['duration'] = $set->time;
                break;

            case 'cardio':
                $data['distance'] = $set->distance;
                $data['distance_unit'] = $set->distance_unit;
                $data['time'] = $set->time;
                $data['calories'] = $set->calories;
                break;

            case 'cardio-calories':
                $data['calories'] = $set->calories;
                break;

            case 'cardio-distance':
                $data['distance'] = $set->distance;
                $data['distance_unit'] = $set->distance_unit;
                $data['time'] = $set->time;
                break;

            case 'banded':
                $data['band_color'] = $set->band_color;
                $data['reps'] = $set->reps;
                break;

            case 'sled':
                $data['weight'] = $set->weight;
                $data['distance'] = $set->distance;
                $data['distance_unit'] = $set->distance_unit;
                break;
        }

        return $data;
    }
}
