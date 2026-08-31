<?php

namespace App\Actions\LiftLogs;

use App\Events\LiftLogged;
use App\Events\LiftLogCompleted;
use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\PRDetectionLog;
use App\Models\User;
use App\Services\ExerciseTypes\ExerciseTypeFactory;
use App\Services\ExerciseTypes\Exceptions\InvalidExerciseDataException;
use App\Services\ExerciseAliasService;
use App\Services\PRDetectionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CreateLiftLogAction
{
    public function __construct(
        private ExerciseAliasService $exerciseAliasService,
        private PRDetectionService $prDetectionService
    ) {}

    public function execute(Request $request, User $user): array
    {
        $exercise = Exercise::find($request->input('exercise_id'));
        
        // Validate the request
        $this->validateRequest($request, $exercise, $user);
        
        // Process the logged time
        $loggedAt = $this->processLoggedTime($request);
        
        // Create the lift log
        $liftLog = $this->createLiftLog($request, $user, $loggedAt);
        
        // Dispatch event
        LiftLogged::dispatch($liftLog);
        
        // Process and create lift sets
        $this->createLiftSets($request, $liftLog, $exercise);
        
        // Dispatch event for PR detection (synchronous)
        LiftLogCompleted::dispatch($liftLog, false);
        
        $liftLog->refresh();
        $liftLog->load(['exercise', 'liftSets']);

        // Check if this is a PR
        $prFlags = $this->checkIfPR($liftLog, $exercise, $user);
        
        return [
            'liftLog' => $liftLog,
            'isPR' => $prFlags, // Backward compatible: int works as bool (0=false, >0=true)
            'successMessage' => $this->generateSuccessMessage(
                $exercise, 
                $request->input('weight'), 
                $request->input('reps') ?? $request->input('time') ?? 0, // Use time for static holds
                $request->input('rounds'), 
                $request->input('band_color'), 
                $prFlags
            )
        ];
    }
    
    private function validateRequest(Request $request, ?Exercise $exercise, User $user): void
    {
        // Base validation rules
        $rules = [
            'exercise_id' => 'required|exists:exercises,id',
            'comments' => 'nullable|string',
            'date' => 'nullable|date', // Make date optional - will default to today if not provided
            'logged_at' => 'nullable|date_format:H:i',
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
        // If no date provided, default to today (this handles the stale page fix)
        $loggedAtDate = $request->input('date') 
            ? Carbon::parse($request->input('date'))
            : Carbon::today();
        
        // If no time provided (mobile entry), use current time but ensure it stays within the selected date
        if ($request->has('logged_at') && $request->input('logged_at')) {
            $loggedAt = $loggedAtDate->setTimeFromTimeString($request->input('logged_at'));
        } else {
            // Use current time, but if we're logging for a different date, use a safe default time
            $currentTime = now();
            if ($loggedAtDate->toDateString() === $currentTime->toDateString()) {
                // Same date - use current time
                $loggedAt = $loggedAtDate->setTime($currentTime->hour, $currentTime->minute);
            } else {
                // Different date - use a safe default time (12:00 PM) to avoid date boundary issues
                $loggedAt = $loggedAtDate->setTime(12, 0);
            }
        }
        
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
    
    private function createLiftLog(Request $request, User $user, Carbon $loggedAt): LiftLog
    {
        return LiftLog::create([
            'exercise_id' => $request->input('exercise_id'),
            'comments' => $request->input('comments'),
            'logged_at' => $loggedAt,
            'user_id' => $user->id,
            'workout_id' => $request->input('workout_id'),
        ]);
    }
    
    private function createLiftSets(Request $request, LiftLog $liftLog, Exercise $exercise): void
    {
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
        
        try {
            $liftData = $exerciseTypeStrategy->processLiftData($liftDataInput);
        } catch (InvalidExerciseDataException $e) {
            // Delete the created lift log since data processing failed
            $liftLog->delete();
            throw $e;
        }

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
    
    private function checkIfPR(LiftLog $liftLog, Exercise $exercise, User $user): array
    {
        $prTypes = $this->prDetectionService->isLiftLogPR($liftLog, $exercise, $user);
        
        // Log the PR detection result for debugging and support
        PRDetectionLog::create([
            'lift_log_id' => $liftLog->id,
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'pr_types_detected' => $prTypes,
            'calculation_snapshot' => $this->prDetectionService->getLastCalculationSnapshot() ?? [],
            'trigger_event' => 'created',
            'detected_at' => now(),
        ]);
        
        return $prTypes;
    }
    
    private function generateSuccessMessage(Exercise $exercise, $weight, int $reps, int $rounds, ?string $bandColor = null, array $prTypes = []): string
    {
        // Get display name (alias if exists, otherwise title)
        $exerciseTitle = $this->exerciseAliasService->getDisplayName($exercise, Auth::user());
        
        // Use strategy pattern to format workout description
        $strategy = $exercise->getTypeStrategy();
        
        // For static holds, pass time parameter (reps is always 1, time contains duration)
        // For other exercises, time will be null
        $time = $exercise->exercise_type === 'static_hold' ? $reps : null;
        $workoutDescription = $strategy->formatSuccessMessageDescription($weight, $reps, $rounds, $bandColor, $time);
        
        // Get celebratory messages from config and replace placeholders
        $celebrationTemplates = config('mobile_entry_messages.success.lift_logged');
        $randomTemplate = $celebrationTemplates[array_rand($celebrationTemplates)];
        
        // Replace placeholders in the template
        $message = str_replace([':exercise', ':details'], [$exerciseTitle, $workoutDescription], $randomTemplate);
        
        // Add PR indicator if this is a personal record
        if (!empty($prTypes)) {
            $message .= ' 🏆 PR!';
        }
        
        return $message;
    }
}