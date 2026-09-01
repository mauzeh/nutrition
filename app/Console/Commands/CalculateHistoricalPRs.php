<?php

namespace App\Console\Commands;

use App\Models\Exercise;
use App\Models\LiftLog;
use App\Models\User;
use App\Services\PRRecalculationService;
use Illuminate\Console\Command;

class CalculateHistoricalPRs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prs:calculate-historical
                            {--user= : Calculate PRs for a specific user ID}
                            {--exercise= : Calculate PRs for a specific exercise ID}
                            {--dry-run : Show what would be done without making changes}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate PRs for all historical lift logs';

    protected PRRecalculationService $prRecalculationService;

    public function __construct(PRRecalculationService $prRecalculationService)
    {
        parent::__construct();
        $this->prRecalculationService = $prRecalculationService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $userId = $this->option('user');
        $exerciseId = $this->option('exercise');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        // Build query for user-exercise combinations.
        $logQuery = LiftLog::query()
            ->select('user_id', 'exercise_id')
            ->distinct();

        // Also include pairs that have PersonalRecord rows but NO (active) lift logs. When every log
        // for an exercise has been deleted, its PRs are orphaned — recalculateAllPRsForExercise()
        // deletes-then-rebuilds, so recalcing a log-less pair produces zero PRs and clears the
        // orphans. Sourcing combinations only from lift_logs used to skip these pairs entirely,
        // leaving stale (and sometimes duplicated) "current" PRs behind. Recalc now auto-cleans them.
        $prQuery = \App\Models\PersonalRecord::query()
            ->select('user_id', 'exercise_id')
            ->distinct();

        if ($userId) {
            $logQuery->where('user_id', $userId);
            $prQuery->where('user_id', $userId);
        }

        if ($exerciseId) {
            $logQuery->where('exercise_id', $exerciseId);
            $prQuery->where('exercise_id', $exerciseId);
        }

        $combinations = $logQuery->get()
            ->concat($prQuery->get())
            ->unique(fn ($c) => $c->user_id . '|' . $c->exercise_id)
            ->values();
        $totalCombinations = $combinations->count();

        if ($totalCombinations === 0) {
            $this->error('No lift logs or personal records found to process.');
            return 1;
        }

        // Show summary
        $this->info('Historical PR Calculation');
        $this->info('========================');
        $this->newLine();
        
        if ($userId) {
            // withTrashed so a soft-deleted user (whose logs/PRs may still need recalc + cleanup)
            // doesn't crash the summary display.
            $user = User::withTrashed()->find($userId);
            $label = $user ? $user->name : 'unknown';
            $this->info("User: {$label} (ID: {$userId})");
        } else {
            $userCount = User::whereHas('liftLogs')->count();
            $this->info("Users: {$userCount}");
        }

        if ($exerciseId) {
            $exercise = Exercise::withTrashed()->find($exerciseId);
            $label = $exercise ? $exercise->title : 'unknown';
            $this->info("Exercise: {$label} (ID: {$exerciseId})");
        } else {
            $exerciseCount = Exercise::whereHas('liftLogs')->count();
            $this->info("Exercises: {$exerciseCount}");
        }

        $this->info("User-Exercise combinations: {$totalCombinations}");
        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Confirm before proceeding
        if (!$force && !$dryRun) {
            if (!$this->confirm('Do you want to proceed with PR calculation?')) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        // Process each user-exercise combination
        $progressBar = $this->output->createProgressBar($totalCombinations);
        $progressBar->setFormat('verbose');
        $progressBar->start();

        $processedCount = 0;
        $errorCount = 0;
        $errors = [];

        foreach ($combinations as $combination) {
            try {
                if (!$dryRun) {
                    $this->prRecalculationService->recalculateAllPRsForExercise(
                        $combination->user_id,
                        $combination->exercise_id
                    );
                }
                $processedCount++;
            } catch (\Exception $e) {
                $errorCount++;
                $errors[] = [
                    'user_id' => $combination->user_id,
                    'exercise_id' => $combination->exercise_id,
                    'error' => $e->getMessage()
                ];
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Show results
        $this->info('Results');
        $this->info('=======');
        $this->info("Processed: {$processedCount} / {$totalCombinations}");
        
        if ($errorCount > 0) {
            $this->error("Errors: {$errorCount}");
            $this->newLine();
            
            if ($this->confirm('Show error details?', true)) {
                $this->table(
                    ['User ID', 'Exercise ID', 'Error'],
                    array_map(fn($e) => [$e['user_id'], $e['exercise_id'], $e['error']], $errors)
                );
            }
        } else {
            $this->info('No errors encountered.');
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('DRY RUN COMPLETE - No changes were made');
            $this->info('Run without --dry-run to apply changes');
        }

        return $errorCount > 0 ? 1 : 0;
    }
}
