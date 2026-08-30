<?php

namespace App\Listeners;

use App\Events\LiftLogCompleted;
use App\Services\PRDetectionService;
use App\Services\PRRecalculationService;
use App\Models\PersonalRecord;
use App\Models\LiftLog;

class DetectAndRecordPRs
{
    public function __construct(
        protected PRDetectionService $prDetectionService,
        protected PRRecalculationService $prRecalculationService
    ) {}
    
    public function handle(LiftLogCompleted $event): void
    {
        $liftLog = $event->liftLog;
        
        // Eager load relationships needed for PR detection
        $liftLog->load(['exercise', 'liftSets']);
        
        // Check if there are any logs after this one (backdating scenario)
        $hasSubsequentLogs = LiftLog::where('user_id', $liftLog->user_id)
            ->where('exercise_id', $liftLog->exercise_id)
            ->where('logged_at', '>', $liftLog->logged_at)
            ->exists();
        
        // If this is an UPDATE or a BACKDATED lift, recalculate all PRs for this exercise
        if ($event->isUpdate || $hasSubsequentLogs) {
            $this->prRecalculationService->recalculateAllPRsForExercise(
                $liftLog->user_id,
                $liftLog->exercise_id
            );
            return;
        }
        
        // Normal case: new lift with no subsequent logs
        // Just detect PRs for this log
        $prs = $this->prDetectionService->detectPRsWithDetails($liftLog);
        
        // Create PR records
        foreach ($prs as $pr) {
            PersonalRecord::create([
                'user_id' => $liftLog->user_id,
                'exercise_id' => $liftLog->exercise_id,
                'lift_log_id' => $liftLog->id,
                'pr_type' => $pr['type'],
                'rep_count' => $pr['rep_count'] ?? null,
                'weight' => $pr['weight'] ?? null,
                'unit' => $pr['unit'] ?? ($liftLog->liftSets->first()->unit ?? 'lbs'),
                'value' => $pr['value'],
                'previous_pr_id' => $pr['previous_pr_id'] ?? null,
                'previous_value' => $pr['previous_value'] ?? null,
                'achieved_at' => $liftLog->logged_at,
            ]);
        }
        
        // Update lift log flags
        $liftLog->update([
            'is_pr' => count($prs) > 0,
            'pr_count' => count($prs),
        ]);
    }
}
