<?php

namespace App\Services;

use App\Models\LiftLog;
use App\Models\PersonalRecord;
use Illuminate\Support\Facades\DB;

class PRRecalculationService
{
    public function __construct(
        protected PRDetectionService $prDetectionService
    ) {}
    
    /**
     * Recalculate ALL PRs for an exercise
     * This is called when a lift is updated, backdated, or deleted
     * 
     * @param int $userId User ID
     * @param int $exerciseId Exercise ID
     * @return void
     */
    public function recalculateAllPRsForExercise(int $userId, int $exerciseId): void
    {
        DB::transaction(function () use ($userId, $exerciseId) {
            // Delete ALL existing PR records for this exercise
            PersonalRecord::where('user_id', $userId)
                ->where('exercise_id', $exerciseId)
                ->delete();
            
            // Get ALL lift logs for this exercise, ordered chronologically
            $logs = LiftLog::where('user_id', $userId)
                ->where('exercise_id', $exerciseId)
                ->with(['exercise', 'liftSets'])
                ->orderBy('logged_at', 'asc')
                ->orderBy('id', 'asc') // Secondary sort for same timestamp
                ->get();
            
            // Process each log chronologically
            foreach ($logs as $log) {
                // Detect PRs by comparing against logs before this one
                // We pass the log's ID so it doesn't compare against itself
                $prs = $this->prDetectionService->detectPRsWithDetails($log);
                
                // Create PR records
                foreach ($prs as $pr) {
                    PersonalRecord::create([
                        'user_id' => $log->user_id,
                        'exercise_id' => $log->exercise_id,
                        'lift_log_id' => $log->id,
                        'pr_type' => $pr['type'],
                        'rep_count' => $pr['rep_count'] ?? null,
                        'weight' => $pr['weight'] ?? null,
                        'unit' => $pr['unit'] ?? ($log->liftSets->first()->unit ?? 'lbs'),
                        'value' => $pr['value'],
                        'previous_pr_id' => $pr['previous_pr_id'] ?? null,
                        'previous_value' => $pr['previous_value'] ?? null,
                        'achieved_at' => $log->logged_at,
                    ]);
                }
                
                // Update lift log flags
                $log->update([
                    'is_pr' => count($prs) > 0,
                    'pr_count' => count($prs),
                ]);
            }
        });
    }
}
