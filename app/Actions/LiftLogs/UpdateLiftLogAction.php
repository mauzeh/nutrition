<?php

namespace App\Actions\LiftLogs;

use App\Events\LiftLogCompleted;
use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\PRDetectionLog;
use App\Models\User;
use App\Services\ExerciseTypes\ExerciseTypeFactory;
use App\Services\ExerciseTypes\Exceptions\InvalidExerciseDataException;
use App\Services\PRDetectionService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class UpdateLiftLogAction
{
    public function __construct(
        private PRDetectionService $prDetectionService
    ) {}

    public function execute(Request $request, LiftLog $liftLog, User $user): LiftLog
    {
        // Authorize the user can update this lift log
        if ($liftLog->user_id !== $user->id) {
            abort(403, 'Unauthorized action.');
        }

        $exercise = Exercise::find($request->input('exercise_id'));
        
        // Validate the request
        $this->validateRequest($request, $exercise, $user);
        
        // Process the logged time
        $loggedAt = $this->processLoggedTime($request);
        
        // Update the lift log
        $liftLog->update([
            'exercise_id' => $request->input('exercise_id'),
            'comments' => $request->input('comments'),
            'logged_at' => $loggedAt,
        ]);

        // Delete existing lift sets and create new ones
        $this->updateLiftSets($request, $liftLog, $exercise);
        
        $liftLog->refresh();
        $liftLog->load(['exercise', 'liftSets']);

        // Dispatch event for PR recalculation (synchronous)
        LiftLogCompleted::dispatch($liftLog, true);
        
        // Re-check PR status after update and log it
        $this->checkAndLogPR($liftLog, $exercise, $user);
        
        return $liftLog->fresh();
    }
    
    private function validateRequest(Request $request, ?Exercise $exercise, User $user): void
    {
        // Base validation rules
        $rules = [
            'exercise_id' => 'required|exists:exercises,id',
            'comments' => 'nullable|string',
            'date' => 'required|date',
            'logged_at' => 'required|date_format:H:i',
            'reps' => 'required|integer|min:1',
            'rounds' => 'required|integer|min:1',
        ];

        // Use exercise type strategy for validation rules
        if ($exercise) {
            $exerciseTypeStrategy = ExerciseTypeFactory::create($exercise);
            $typeSpecificRules = $exerciseTypeStrategy->getValidationRules($user);
            $rules = array_merge($rules, $typeSpecificRules);
        }

        $request->validate($rules);
    }
    
    private function processLoggedTime(Request $request): Carbon
    {
        $loggedAtDate = Carbon::parse($request->input('date'));
        $loggedAt = $loggedAtDate->setTimeFromTimeString($request->input('logged_at'));
        
        // Round time to nearest 15-minute interval, but ensure we don't cross date boundaries
        $minutes = $loggedAt->minute;
        $remainder = $minutes % 15;
        if ($remainder !== 0) {
            $newLoggedAt = $loggedAt->copy()->addMinutes(15 - $remainder);
            // Only apply rounding if it doesn't change the date
            if ($newLoggedAt->toDateString() === $loggedAtDate->toDateString()) {
                $loggedAt = $newLoggedAt;
            } else {
                // If rounding would cross date boundary, round down instead
                $loggedAt = $loggedAt->subMinutes($remainder);
            }
        }
        
        return $loggedAt;
    }
    
    private function updateLiftSets(Request $request, LiftLog $liftLog, Exercise $exercise): void
    {
        // Delete existing lift sets
        $liftLog->liftSets()->delete();

        // Create new lift sets
        $rounds = $request->input('rounds');

        // Use exercise type strategy to process lift data
        $exerciseTypeStrategy = ExerciseTypeFactory::create($exercise);
        
        // Build lift data from request - include all possible fields
        $liftDataInput = [
            'weight' => $request->input('weight'),
            'band_color' => $request->input('band_color'),
            'reps' => $request->input('reps'),
            'time' => $request->input('time'), // For static holds
            'notes' => $request->input('comments'),
        ];
        
        $liftData = $exerciseTypeStrategy->processLiftData($liftDataInput);

        $unitResolver = app(\App\Services\UnitResolver::class);
        $userUnit = $unitResolver->getPreferredWeightUnit($liftLog->user);

        for ($i = 0; $i < $rounds; $i++) {
            $liftLog->liftSets()->create([
                'weight' => $liftData['weight'] ?? 0,
                'unit' => $userUnit,
                'reps' => $liftData['reps'],
                'time' => $liftData['time'] ?? null,
                'notes' => $liftData['notes'],
                'band_color' => $liftData['band_color'],
                'distance' => $liftData['distance'] ?? null,
                'distance_unit' => $liftData['distance_unit'] ?? null,
                'calories' => $liftData['calories'] ?? null,
            ]);
        }
    }
    
    private function checkAndLogPR(LiftLog $liftLog, Exercise $exercise, User $user): void
    {
        $prTypes = $this->prDetectionService->isLiftLogPR($liftLog, $exercise, $user);
        
        // Log the PR detection result for debugging and support
        PRDetectionLog::create([
            'lift_log_id' => $liftLog->id,
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'pr_types_detected' => $prTypes,
            'calculation_snapshot' => $this->prDetectionService->getLastCalculationSnapshot() ?? [],
            'trigger_event' => 'updated',
            'detected_at' => now(),
        ]);
    }
}