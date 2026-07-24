<?php

namespace App\Sync\Controllers;

use App\Models\Exercise;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ExerciseController
{
    /**
     * Create a user-scoped exercise from the Athlete app.
     *
     * POST /api/sync/exercises
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'canonical_name' => 'nullable|string|max:255',
            'exercise_type' => 'nullable|string|max:50',
            'log_type' => 'nullable|string|max:50',
            'show_in_feed' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $title = $request->input('title');
        $canonicalName = $request->input('canonical_name') ?: Str::snake(Str::lower($title));

        // Check if user already has an exercise with this canonical name
        $existing = Exercise::withTrashed()
            ->where('user_id', $user->id)
            ->where('canonical_name', $canonicalName)
            ->first();

        if ($existing) {
            // If soft-deleted, restore it
            if ($existing->trashed()) {
                $existing->restore();
            }
            // Update fields
            $existing->update([
                'title' => $title,
                'exercise_type' => $request->input('exercise_type', 'regular'),
                'log_type' => $request->input('log_type'),
                'show_in_feed' => $request->input('show_in_feed', true),
            ]);

            return response()->json([
                'status' => 'ok',
                'exercise_id' => $existing->id,
                'canonical_name' => $existing->canonical_name,
            ]);
        }

        // Create new user-scoped exercise
        $exercise = Exercise::create([
            'title' => $title,
            'canonical_name' => $canonicalName,
            'user_id' => $user->id,
            'exercise_type' => $request->input('exercise_type', 'regular'),
            'log_type' => $request->input('log_type'),
            'show_in_feed' => $request->input('show_in_feed', true),
        ]);

        return response()->json([
            'status' => 'ok',
            'exercise_id' => $exercise->id,
            'canonical_name' => $exercise->canonical_name,
        ], 201);
    }
}
