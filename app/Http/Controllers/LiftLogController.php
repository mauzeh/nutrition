<?php

namespace App\Http\Controllers;

use App\Actions\LiftLogs\CreateLiftLogAction;
use App\Actions\LiftLogs\UpdateLiftLogAction;
use App\Models\Exercise;
use App\Models\LiftLog;
use App\Services\ExerciseTypes\Exceptions\InvalidExerciseDataException;
use App\Services\RedirectService;
use App\Services\MobileEntry\LiftLogService;
use App\Services\ExerciseAliasService;
use App\Services\ComponentBuilder;
use App\Services\ExercisePageService;
use App\Services\FabService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LiftLogController extends Controller
{
    public function __construct(
        private RedirectService $redirectService,
        private CreateLiftLogAction $createLiftLogAction,
        private UpdateLiftLogAction $updateLiftLogAction,
        private LiftLogService $liftLogService,
        private ExerciseAliasService $exerciseAliasService,
        private ExercisePageService $exercisePageService,
        private FabService $fabService
    ) {}
    
    /**
     * Show the form for creating a new lift log entry
     */
    public function create(Request $request)
    {
        if (!$request->input('exercise_id')) {
            return redirect()->route('mobile-entry.lifts')
                ->with('error', 'No exercise specified.');
        }
        
        $exercise = Exercise::where('id', $request->input('exercise_id'))
            ->availableToUser(Auth::id())
            ->first();
            
        if (!$exercise) {
            return redirect()->route('mobile-entry.lifts')
                ->with('error', 'Exercise not found or not accessible.');
        }
        
        // Extract redirect parameters
        $redirectParams = [];
        if ($request->has('redirect_to')) {
            $redirectParams['redirect_to'] = $request->input('redirect_to');
        }
        if ($request->has('workout_id')) {
            $redirectParams['workout_id'] = $request->input('workout_id');
        }
        
        $components = $this->exercisePageService->generatePage(
            $exercise,
            Auth::id(),
            'log', // Default to log tab for lift-logs/create
            null, // No 'from' context
            $request->input('date') ?: Carbon::today()->toDateString(),
            $redirectParams
        );
        
        $data = [
            'components' => $components
        ];
        
        return view('mobile-entry.flexible', compact('data'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(LiftLog $liftLog, Request $request)
    {
        if ($liftLog->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }
        
        // Load necessary relationships
        $liftLog->load(['exercise', 'liftSets']);
        
        // Block editing if sets are non-uniform (different weight/reps across sets)
        if (!$liftLog->hasUniformSets()) {
            $data = [
                'components' => [
                    ComponentBuilder::title(
                        $liftLog->exercise->title,
                        'This log has variable sets and cannot be edited here'
                    )->build(),
                    ComponentBuilder::messages()
                        ->add('info', $liftLog->formatSetsSummary())
                        ->build(),
                ],
                'autoscroll' => false,
            ];

            return view('mobile-entry.flexible', compact('data'));
        }
        
        // Capture redirect parameters from the request
        $redirectParams = [];
        if ($request->has('redirect_to')) {
            $redirectParams['redirect_to'] = $request->input('redirect_to');
        }
        
        // Generate edit form component using the service
        $formComponent = $this->liftLogService->generateFormComponent(
            $liftLog->exercise_id,
            Auth::id(),
            Carbon::parse($liftLog->logged_at),
            $redirectParams,
            $liftLog
        );
        
        $data = [
            'components' => [$formComponent],
            'autoscroll' => true
        ];
        
        return view('mobile-entry.flexible', compact('data'));
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $userId = Auth::id();

        // Build components array
        $components = [
            ComponentBuilder::title(
                'Metrics',
                'Select an exercise to view your training history, personal records, and 1RM calculator.'
            )->build(),
        ];
        
        // Add success/error messages if present
        if ($sessionMessages = ComponentBuilder::messagesFromSession()) {
            $components[] = $sessionMessages;
        }
        
        // Check if user has any logged exercises
        $hasLoggedExercises = LiftLog::where('user_id', $userId)->exists();
        
        // Build exercise list
        if (!$hasLoggedExercises) {
            $components[] = ComponentBuilder::messages()
                ->add('info', config('mobile_entry_messages.empty_states.metrics_getting_started'))
                ->build();
            $components[] = ComponentBuilder::button('Log Now')
                ->asLink(route('mobile-entry.lifts', ['expand_selection' => true]))
                ->build();
        } else {
            // Use unified service
            $unifiedService = app(\App\Services\UnifiedExerciseListService::class);
            $exerciseListData = $unifiedService->generate($userId, [
                'context' => 'metrics',
                'filter_exercises' => 'logged-only',
                'show_popular' => false,
                'url_generator' => fn($exercise) => route('exercises.show-logs', [
                    'exercise' => $exercise,
                    'from' => 'lift-logs-index'
                ]),
                'create_form' => null,
                'initial_state' => 'expanded',
                'show_cancel_button' => false,
                'restrict_height' => false,
                'aria_labels' => [
                    'section' => 'Exercise selection list',
                    'selectItem' => 'Select exercise to view history',
                ],
            ]);
            
            $components[] = [
                'type' => 'item-list',
                'data' => $exerciseListData,
            ];
        }

        // Add FAB
        $components[] = $this->fabService->createConnectionFab(Auth::user(), 'lifts');

        $data = ['components' => $components];

        return view('mobile-entry.flexible', compact('data'));
    }
    
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $result = $this->createLiftLogAction->execute($request, Auth::user());
            
            return $this->redirectService->getRedirect(
                'lift_logs',
                'store',
                $request,
                [
                    'submitted_lift_log_id' => $result['liftLog']->id,
                    'exercise' => $result['liftLog']->exercise_id,
                ],
                $result['successMessage']
            )->with('is_pr', $result['isPR']);
            
        } catch (InvalidExerciseDataException $e) {
            return back()->withErrors(['exercise_data' => $e->getMessage()])->withInput();
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, LiftLog $liftLog)
    {
        try {
            $updatedLiftLog = $this->updateLiftLogAction->execute($request, $liftLog, Auth::user());
            
            return $this->redirectService->getRedirect(
                'lift_logs',
                'update',
                $request,
                [
                    'submitted_lift_log_id' => $updatedLiftLog->id,
                    'exercise' => $updatedLiftLog->exercise_id,
                ],
                'Lift log updated successfully.'
            );
            
        } catch (InvalidExerciseDataException $e) {
            return back()->withErrors(['exercise_data' => $e->getMessage()])->withInput();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(LiftLog $liftLog)
    {
        if ($liftLog->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }
        
        // Generate a simple deletion message before deleting
        $exercise = $liftLog->exercise;
        $exerciseTitle = $this->exerciseAliasService->getDisplayName($exercise, Auth::user());
        
        $isMobileEntry = in_array(request()->input('redirect_to'), ['mobile-entry', 'mobile-entry-lifts', 'workouts']);
        
        if ($isMobileEntry) {
            $deletionMessage = str_replace(':exercise', $exerciseTitle, config('mobile_entry_messages.success.lift_deleted_mobile'));
        } else {
            $deletionMessage = str_replace(':exercise', $exerciseTitle, config('mobile_entry_messages.success.lift_deleted'));
        }
        
        // Store info before deletion for PR recalculation
        $userId = $liftLog->user_id;
        $exerciseId = $liftLog->exercise_id;
        $wasPR = $liftLog->is_pr;
        
        $liftLog->delete();
        
        // Recalculate all PRs for this exercise upon deletion
        app(\App\Services\PRRecalculationService::class)->recalculateAllPRsForExercise(
            $userId,
            $exerciseId
        );

        return $this->redirectService->getRedirect(
            'lift_logs',
            'destroy',
            request(),
            [],
            $deletionMessage
        );
    }
}
