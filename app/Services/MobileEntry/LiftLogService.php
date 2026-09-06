<?php

namespace App\Services\MobileEntry;

use App\Models\LiftLog;
use App\Models\Exercise;
use App\Models\MobileLiftForm;
use App\Models\User;
use App\Services\TrainingProgressionService;
use App\Services\ExerciseAliasService;
use App\Services\Factories\LiftLogFormFactory;
use App\Services\MobileEntry\LiftProgressionService;
use App\Services\MobileEntry\MobileEntryBaseService;
use Carbon\Carbon;

class LiftLogService extends MobileEntryBaseService
{
    protected TrainingProgressionService $trainingProgressionService;
    protected LiftDataCacheService $cacheService;
    protected ExerciseAliasService $aliasService;
    protected LiftLogFormFactory $liftLogFormFactory;

    public function __construct(
        TrainingProgressionService $trainingProgressionService,
        LiftDataCacheService $cacheService,
        ExerciseAliasService $aliasService,
        LiftLogFormFactory $liftLogFormFactory
    ) {
        $this->trainingProgressionService = $trainingProgressionService;
        $this->cacheService = $cacheService;
        $this->aliasService = $aliasService;
        $this->liftLogFormFactory = $liftLogFormFactory;
    }


    
    /**
     * Generate a form component for creating or editing a lift log
     * 
     * @param int $exerciseId The exercise ID
     * @param int $userId The user ID
     * @param Carbon $selectedDate The selected date
     * @param array $redirectParams Optional redirect parameters
     * @param LiftLog|null $existingLiftLog Optional existing lift log for edit mode
     * @param string|null $backUrl Optional back URL for create mode
     * @return array Form component data
     */
    public function generateFormComponent(
        int $exerciseId,
        int $userId,
        Carbon $selectedDate,
        array $redirectParams = [],
        ?LiftLog $existingLiftLog = null,
        ?string $backUrl = null
    ) {
        $isEditMode = $existingLiftLog !== null;
        
        // Block editing logs with non-uniform sets
        if ($isEditMode && !$existingLiftLog->hasUniformSets()) {
            throw new \Exception('Cannot edit a lift log with non-uniform sets.');
        }
        
        // Get the exercise
        if ($isEditMode) {
            $exercise = $existingLiftLog->exercise;
            if (!$exercise) {
                throw new \Exception('Exercise not found for lift log');
            }
        } else {
            $exercise = Exercise::where('id', $exerciseId)
                ->availableToUser($userId)
                ->first();
            
            if (!$exercise) {
                throw new \Exception('Exercise not found or not accessible');
            }
        }
        
        // Get user
        $user = User::find($userId);
        
        // Apply alias to exercise title
        $displayName = $this->aliasService->getDisplayName($exercise, $user);
        $exercise->title = $displayName;
        
        // Prepare defaults and messages based on mode
        if ($isEditMode) {
            $defaults = $this->prepareEditDefaults($existingLiftLog);
            $messages = $this->prepareEditMessages($existingLiftLog);
            $mockForm = $this->createMockForm('edit-' . $existingLiftLog->id, $userId, $exerciseId);
        } else {
            $lastSession = $this->getLastSessionData($exerciseId, $selectedDate, $userId);
            $defaults = $this->prepareCreateDefaults($exercise, $lastSession, $userId, $user);
            $messages = $this->prepareCreateMessages($exercise, $lastSession, $user, $userId);
            $mockForm = $this->createMockForm('standalone-' . $exerciseId, $userId, $exerciseId);
        }

        // Build the form using the factory
        $formComponent = $this->liftLogFormFactory->buildForm(
            $mockForm,
            $exercise,
            $user,
            $defaults,
            $messages,
            $selectedDate,
            $redirectParams
        );
        
        // Apply mode-specific overrides
        if ($isEditMode) {
            $this->applyEditModeOverrides($formComponent, $existingLiftLog, $redirectParams);
        } else {
            $this->applyCreateModeOverrides($formComponent);
        }
        
        return $formComponent;
    }
    


    /**
     * Prepare default values for create mode
     */
    private function prepareCreateDefaults(Exercise $exercise, ?array $lastSession, int $userId, User $user): array
    {
        // Use LiftProgressionService for defaults
        return app(LiftProgressionService::class)->prepareCreateDefaults($exercise, $lastSession, $userId, $user);
    }

    /**
     * Prepare default values for edit mode
     */
    private function prepareEditDefaults(LiftLog $liftLog): array
    {
        $firstSet = $liftLog->liftSets->first();
        $weight = $firstSet->weight ?? 0;
        
        if ($firstSet) {
            $unitResolver = app(\App\Services\UnitResolver::class);
            $targetUnit = $unitResolver->getPreferredWeightUnit($liftLog->user);
            $loggedUnit = $firstSet->unit ?? 'lbs';
            $weight = $unitResolver->convert($weight, $loggedUnit, $targetUnit);
        }
        
        return [
            'weight' => $weight,
            'reps' => $firstSet->reps ?? 0,
            'time' => $firstSet->time ?? 0,
            'distance' => $firstSet->distance ?? 0,
            'distance_unit' => $firstSet->distance_unit ?? 'm',
            'sets' => $liftLog->liftSets->count(),
            'band_color' => $firstSet->band_color ?? 'red',
            'comments' => $liftLog->comments ?? '',
        ];
    }

    /**
     * Prepare messages for create mode
     */
    private function prepareCreateMessages(Exercise $exercise, ?array $lastSession, User $user, int $userId): array
    {
        // Create a temporary MobileLiftForm for message generation
        $tempForm = new MobileLiftForm();
        $tempForm->id = 'temp-form';
        $tempForm->user_id = $userId;
        $tempForm->exercise_id = $exercise->id;
        $tempForm->setRelation('exercise', $exercise);
        
        return $this->generateFormMessagesForMobileForms($tempForm, $lastSession, $user);
    }

    /**
     * Prepare messages for edit mode
     */
    private function prepareEditMessages(LiftLog $liftLog): array
    {
        $loggedDate = Carbon::parse($liftLog->logged_at);
        $dateMessage = $this->generateFriendlyDateMessage($loggedDate);
        
        return [
            [
                'type' => 'info',
                'prefix' => 'Date:',
                'text' => $dateMessage
            ]
        ];
    }

    /**
     * Generate a friendly date message for display
     * 
     * @param Carbon $date
     * @return string
     */
    private function generateFriendlyDateMessage(Carbon $date): string
    {
        $now = Carbon::now();
        
        if ($date->isToday()) {
            return 'Today';
        }
        
        if ($date->isYesterday()) {
            return 'Yesterday';
        }
        
        if ($date->isTomorrow()) {
            return 'Tomorrow';
        }
        
        $daysDiff = (int) abs($now->diffInDays($date));
        
        if ($daysDiff <= 7 && $date->isPast()) {
            return $daysDiff . ' days ago (' . $date->format('l, M j') . ')';
        }
        
        if ($daysDiff <= 7 && $date->isFuture()) {
            return 'In ' . $daysDiff . ' days (' . $date->format('l, M j') . ')';
        }
        
        // For dates more than a week away
        return $date->format('l, F j, Y');
    }

    /**
     * Create a mock MobileLiftForm for the factory
     */
    private function createMockForm(string $id, int $userId, int $exerciseId): MobileLiftForm
    {
        $mockForm = new MobileLiftForm();
        $mockForm->id = $id;
        $mockForm->user_id = $userId;
        $mockForm->exercise_id = $exerciseId;
        
        return $mockForm;
    }

    /**
     * Apply create mode specific overrides to the form component
     */
    private function applyCreateModeOverrides(array &$formComponent): void
    {
        // Override the delete action since this is a standalone form (no MobileLiftForm to delete)
        $formComponent['data']['deleteAction'] = null;
    }

    /**
     * Apply edit mode specific overrides to the form component
     */
    private function applyEditModeOverrides(array &$formComponent, LiftLog $liftLog, array $redirectParams): void
    {
        // Override form settings for edit mode
        $formComponent['data']['id'] = 'edit-lift-' . $liftLog->id;
        $formComponent['data']['formAction'] = route('lift-logs.update', $liftLog->id);
        $formComponent['data']['method'] = 'PUT';
        $formComponent['data']['deleteAction'] = route('lift-logs.destroy', $liftLog->id);
        // Pass redirect params and exercise_id to delete action
        $formComponent['data']['deleteParams'] = array_merge($redirectParams, ['exercise_id' => $liftLog->exercise_id]);
        
        // Update hidden fields for edit mode
        $formComponent['data']['hiddenFields'] = [
            '_method' => 'PUT',
            'exercise_id' => $liftLog->exercise_id,
            'date' => $liftLog->logged_at->toDateString(),
            'logged_at' => $liftLog->logged_at->format('H:i'),
        ];
        
        // Add redirect parameters if provided
        if (!empty($redirectParams['redirect_to'])) {
            $formComponent['data']['hiddenFields']['redirect_to'] = $redirectParams['redirect_to'];
        }
        
        // Update button text
        $formComponent['data']['buttons']['submit'] = 'Update ' . $liftLog->exercise->title;
    }

    /**
     * Generate messages for a form based on mobile lift form and last session
     * 
     * @param MobileLiftForm $form
     * @param array|null $lastSession
     * @param User|null $user
     * @return array
     */
    private function generateFormMessagesForMobileForms($form, $lastSession, $user = null)
    {
        $messages = [];
        
        // Add instructional message for new users or first-time exercises
        if (!$lastSession) {
            $messages[] = [
                'type' => 'tip',
                'prefix' => 'How to log:',
                'text' => str_replace(':exercise', $form->exercise->title, config('mobile_entry_messages.form_guidance.how_to_log'))
            ];
        }
        
        // Add last session info if available
        if ($lastSession) {
            // Format the resistance/weight info using exercise type strategy
            $strategy = $form->exercise->getTypeStrategy();
            $labels = $strategy->getFieldLabels();
            
            // Create a mock lift log for formatting
            $mockLiftLog = new LiftLog();
            $mockLiftLog->exercise = $form->exercise;
            $mockLiftLog->setRelation('liftSets', collect([
                (object)[
                    'weight' => $lastSession['weight'] ?? 0,
                    'reps' => $lastSession['reps'] ?? 0,
                    'band_color' => $lastSession['band_color'] ?? null,
                    'unit' => $lastSession['unit'] ?? 'lbs'
                ]
            ]));
            
            $resistanceText = $strategy->formatWeightDisplay($mockLiftLog);
            
            // Use strategy labels for consistent terminology
            $repsLabel = strtolower(trim($labels['reps'] ?? 'reps', ':'));
            $setsLabel = strtolower(trim($labels['sets'] ?? 'sets', ':'));
            
            // Use strategy to format the message text
            $messageText = $strategy->formatFormMessageDisplay($lastSession);
            
            $messages[] = [
                'type' => 'info',
                'prefix' => str_replace(':date', $lastSession['date'], config('mobile_entry_messages.form_guidance.last_workout')),
                'text' => $messageText
            ];
        }
        
        // Add last session comments if available
        if ($lastSession && !empty($lastSession['comments'])) {
            $messages[] = [
                'type' => 'neutral',
                'prefix' => config('mobile_entry_messages.form_guidance.your_last_notes'),
                'text' => $lastSession['comments']
            ];
        }
        
        // Add progression suggestion only if user has the preference enabled
        if ($lastSession && $user && $user->shouldPrefillSuggestedValues()) {
            $suggestion = $this->trainingProgressionService->getSuggestionDetails(
                $user->id, 
                $form->exercise_id
            );
            
            if ($suggestion) {
                $strategy = $form->exercise->getTypeStrategy();
                $sets = $suggestion->sets ?? $lastSession['sets'] ?? 3;
                
                if (isset($suggestion->band_color)) {
                    // Banded exercise suggestion
                    $messages[] = [
                        'type' => 'tip',
                        'prefix' => config('mobile_entry_messages.form_guidance.try_this'),
                        'text' => $suggestion->band_color . ' band × ' . $suggestion->reps . ' reps × ' . $sets . ' sets' . config('mobile_entry_messages.form_guidance.suggestion_suffix')
                    ];
                } elseif (isset($suggestion->suggestedWeight) && $strategy->getTypeName() !== 'bodyweight') {
                    // Weighted exercise suggestion
                    $unitResolver = app(\App\Services\UnitResolver::class);
                    $preferredUnit = $unitResolver->getPreferredWeightUnit($user);
                    $formattedWeight = $unitResolver->format($suggestion->suggestedWeight, $preferredUnit);
                    $messages[] = [
                        'type' => 'tip',
                        'prefix' => config('mobile_entry_messages.form_guidance.try_this'),
                        'text' => $formattedWeight . ' × ' . $suggestion->reps . ' reps × ' . $sets . ' sets' . config('mobile_entry_messages.form_guidance.suggestion_suffix')
                    ];
                } elseif ($strategy->getTypeName() === 'bodyweight' && isset($suggestion->reps)) {
                    // Bodyweight exercise suggestion
                    $messages[] = [
                        'type' => 'tip',
                        'prefix' => config('mobile_entry_messages.form_guidance.try_this'),
                        'text' => $suggestion->reps . ' reps × ' . $sets . ' sets' . config('mobile_entry_messages.form_guidance.suggestion_suffix')
                    ];
                }
            } elseif ($form->exercise->getTypeStrategy()->getTypeName() !== 'bodyweight') {
                // Fallback to simple progression if service fails
                $sets = $lastSession['sets'] ?? 3;
                $reps = $lastSession['reps'] ?? 5;
                
                $unitResolver = app(\App\Services\UnitResolver::class);
                $preferredUnit = $unitResolver->getPreferredWeightUnit($user);
                $loggedUnit = $lastSession['unit'] ?? 'lbs';
                
                $convertedWeight = $unitResolver->convert($lastSession['weight'] ?? 0, $loggedUnit, $preferredUnit);
                $increment = $unitResolver->getWeightIncrement($user);
                $suggestedWeight = $convertedWeight + $increment;
                $formattedWeight = $unitResolver->format($suggestedWeight, $preferredUnit);
                
                $messages[] = [
                    'type' => 'tip',
                    'prefix' => config('mobile_entry_messages.form_guidance.try_this'),
                    'text' => $formattedWeight . ' × ' . $reps . ' reps × ' . $sets . ' sets' . config('mobile_entry_messages.form_guidance.suggestion_suffix')
                ];
            }
        }
        
        return $messages;
    }

    /**
     * Get last session data for an exercise
     * 
     * @param int $exerciseId
     * @param Carbon $beforeDate
     * @param int $userId
     * @return array|null
     */
    public function getLastSessionData($exerciseId, Carbon $beforeDate, $userId)
    {
        $lastLog = LiftLog::where('user_id', $userId)
            ->where('exercise_id', $exerciseId)
            ->where('logged_at', '<', $beforeDate->toDateString())
            ->with(['liftSets'])
            ->orderBy('logged_at', 'desc')
            ->first();
        
        if (!$lastLog || $lastLog->liftSets->isEmpty()) {
            return null;
        }
        
        $firstSet = $lastLog->liftSets->first();
        
        return [
            'weight' => $firstSet->weight,
            'unit' => $firstSet->unit ?? 'lbs',
            'reps' => $firstSet->reps,
            'time' => $firstSet->time,
            'sets' => $lastLog->liftSets->count(),
            'date' => $lastLog->logged_at->format('M j'),
            'comments' => $lastLog->comments,
            'band_color' => $firstSet->band_color
        ];
    }

    /**
     * Determine default weight for an exercise
     * 
     * @param Exercise $exercise
     * @param array|null $lastSession
     * @return float
     */
    public function getDefaultWeight($exercise, $lastSession, $userId = null)
    {
        $strategy = $exercise->getTypeStrategy();
        
        if ($lastSession && $userId) {
            // Use TrainingProgressionService for intelligent progression
            $suggestion = $this->trainingProgressionService->getSuggestionDetails(
                $userId, 
                $exercise->id
            );
            
            if ($suggestion && isset($suggestion->suggestedWeight)) {
                return $suggestion->suggestedWeight;
            }
        }
        
        // If we have a last session, use strategy's progression logic
        if ($lastSession) {
            return $strategy->getDefaultWeightProgression($lastSession['weight'] ?? 0);
        }
        
        // No last session: use strategy's default starting weight
        return $strategy->getDefaultStartingWeight($exercise);
    }

    /**
     * Generate summary data based on user's logs for the selected date
     * 
     * @param int $userId
     * @param Carbon $selectedDate
     * @return null
     */
    public function generateSummary($userId, Carbon $selectedDate)
    {
        return null;
    }

    /**
     * Generate contextual help messages based on user's current state
     * 
     * @param int $userId
     * @param Carbon $selectedDate
     * @param bool $expandSelection
     * @return array
     */
    public function generateContextualHelpMessages($userId, Carbon $selectedDate, $expandSelection = false)
    {
        $messages = [];

        // If the selection is expanded, show a specific guiding message.
        if ($expandSelection) {
            $messages[] = [
                'type' => 'info',
                'prefix' => 'Let\'s go!',
                'text' => config('mobile_entry_messages.contextual_help.pick_exercise')
            ];
            return $messages;
        }
        
        // Check if user has logged anything today
        $loggedCount = LiftLog::where('user_id', $userId)
            ->whereDate('logged_at', $selectedDate->toDateString())
            ->count();
        
        if ($loggedCount === 0) {
            // No exercises logged yet
            $messages[] = [
                'type' => 'info',
                'prefix' => 'Getting started:',
                'text' => config('mobile_entry_messages.contextual_help.getting_started')
            ];
        }
        
        return $messages;
    }

}