# Redirect System - Complete Guide

## Overview

The redirect system centralizes all conditional redirect logic that was previously scattered across multiple controllers. This makes redirect behavior more maintainable, testable, and configurable.

## RedirectService Architecture

### Configuration

All redirect configurations are stored in `config/redirects.php`. The structure is:

```php
'controller_name' => [
    'action_name' => [
        'redirect_target' => [
            'route' => 'route.name',
            'params' => ['param1', 'param2'],
        ],
        'default' => [
            'route' => 'default.route.name',
            'params' => ['param1'],
        ],
    ],
],
```

### Example Configuration

```php
'lift_logs' => [
    'store' => [
        'mobile-entry' => [
            'route' => 'mobile-entry.lifts',
            'params' => ['date', 'submitted_lift_log_id'],
        ],
        'workouts' => [
            'route' => 'workouts.index',
            'params' => ['workout_id'],
        ],
        'default' => [
            'route' => 'exercises.show-logs',
            'params' => ['exercise_id'],
        ],
    ],
],
```

### Usage in Controllers

#### Basic Usage

```php
use App\Services\RedirectService;

class MyController extends Controller
{
    protected $redirectService;

    public function __construct(RedirectService $redirectService)
    {
        $this->redirectService = $redirectService;
    }

    public function store(Request $request)
    {
        // ... your logic ...

        return $this->redirectService->getRedirect(
            'controller_name',  // Config key for this controller
            'store',            // Action name
            $request,           // Request object
            [                   // Context data for route parameters
                'id' => $model->id,
                'other_param' => $value,
            ],
            'Success message'   // Flash message (optional)
        );
    }
}
```

#### How Parameters Are Resolved

The service resolves route parameters in this order:

1. **Context array** - Values passed in the `$context` parameter
2. **Request input** - Values from `$request->input()`
3. **Special mappings** - Automatic conversions:
   - `exercise_id` → `exercise` (for exercise routes)
   - `template_id` → `id` (legacy support)
   - `workout_id` → `id` (for workout routes)

#### Dynamic Redirects

For cases where the redirect route is passed directly (like `food_logs.destroy`):

```php
'food_logs' => [
    'destroy' => [
        'dynamic' => true,  // Accepts any route name
        'default' => [
            'route' => 'food-logs.index',
            'params' => ['date'],
        ],
    ],
],
```

## Parameter Flow Implementation

### Problem Solved
When clicking "Log Now" from workouts, the redirect parameters (`redirect_to` and `workout_id`) were being lost because the flow goes through an intermediate route (`add-lift-form`) before reaching the final form page (`mobile-entry.lifts`).

### Solution
Pass redirect parameters through the entire chain using URL parameters and hidden form fields.

### Complete Flow

#### 1. Initial Click (Workouts)
```
User clicks "Log Now" on workout
↓
URL: /mobile-entry/add-lift-form/{exercise}?date=2025-11-12&redirect_to=workouts&workout_id=5
```

#### 2. Add Lift Form (Intermediate Route)
**Controller:** `MobileEntryController::addLiftForm()`
- Receives redirect params from URL
- Creates the mobile lift form in database
- **Passes redirect params forward** in redirect URL

```php
$redirectParams = ['date' => $selectedDate->toDateString()];

if ($request->has('redirect_to')) {
    $redirectParams['redirect_to'] = $request->input('redirect_to');
}

if ($request->has('workout_id')) {
    $redirectParams['workout_id'] = $request->input('workout_id');
}

return redirect()->route('mobile-entry.lifts', $redirectParams);
```

#### 3. Mobile Entry Lifts Page
**Controller:** `MobileEntryController::lifts()`
- Receives redirect params from URL
- Captures them and passes to service

```php
$redirectParams = [];
if ($request->has('redirect_to')) {
    $redirectParams['redirect_to'] = $request->input('redirect_to');
}
if ($request->has('workout_id')) {
    $redirectParams['workout_id'] = $request->input('workout_id');
}

$forms = $formService->generateForms(Auth::id(), $selectedDate, $redirectParams);
```

#### 4. Form Generation
**Service:** `LiftLogService::generateForms()`
- Receives redirect params as method parameter
- **Embeds them as hidden fields** in the form

```php
$hiddenFields = [
    'exercise_id' => $exercise->id,
    'date' => $selectedDate->toDateString(),
    'mobile_lift_form_id' => $form->id
];

if (!empty($redirectParams['redirect_to'])) {
    $hiddenFields['redirect_to'] = $redirectParams['redirect_to'];
    
    // Legacy support for template_id
    if (!empty($redirectParams['template_id'])) {
        $hiddenFields['template_id'] = $redirectParams['template_id'];
    }
    
    // Add workout_id if it exists
    if (!empty($redirectParams['workout_id'])) {
        $hiddenFields['workout_id'] = $redirectParams['workout_id'];
    }
} else {
    $hiddenFields['redirect_to'] = 'mobile-entry-lifts';
}
```

#### 5. Form Submission
When user submits the lift log form:
- Hidden fields are submitted with the form data
- `LiftLogController::store()` receives them via `$request->input()`
- `RedirectService` uses them to determine where to redirect

#### 6. Final Redirect
**Service:** `RedirectService::getRedirect()`
- Reads `redirect_to` and `workout_id` from request
- Maps `workout_id` → `id` (since the route expects `id` parameter)
- Redirects back to workouts with the workout expanded

```
Redirect to: /workouts?id=5
```

## Special Parameter Mappings

The service includes special handling for parameters that need to be renamed for route compatibility:

### `exercise_id` → `exercise`
Exercise routes expect the parameter to be named `exercise`, but forms and configs use `exercise_id`.

```php
// Config uses 'exercise_id'
'params' => ['exercise_id']

// Service maps to 'exercise' for the route
route('exercises.show-logs', ['exercise' => 123])
```

### `workout_id` → `id`
Workout routes expect the parameter to be named `id`, but forms and configs use `workout_id` for clarity.

```php
// Config uses 'workout_id'
'params' => ['workout_id']

// Service maps to 'id' for the route
route('workouts.index', ['id' => 5])
```

**Parameter Mapping in RedirectService:**
```php
// Special handling for workout_id parameter
// The route expects 'id' but config uses 'workout_id'
if ($paramName === 'workout_id') {
    if (isset($context['workout_id'])) {
        $params['id'] = $context['workout_id'];
        continue;
    }
    if ($request->has('workout_id')) {
        $params['id'] = $request->input('workout_id');
        continue;
    }
}
```

### `template_id` → `id` (Legacy)
Legacy support for the old template naming convention.

```php
// Config uses 'template_id'
'params' => ['template_id']

// Service maps to 'id' for the route
route('workouts.index', ['id' => 5])
```

## Benefits

1. **Centralized Configuration** - All redirect logic in one place
2. **Easy to Modify** - Change redirect behavior without touching controller code
3. **Testable** - Service can be unit tested independently
4. **Consistent** - Same pattern across all controllers
5. **Discoverable** - Easy to see all redirect paths in the config file
6. **No database storage needed** - params flow through URL and form fields
7. **Backward compatible** - if no redirect params provided, defaults work
8. **Clean separation** - each layer only knows about its immediate next step
9. **Works with validation errors** - hidden fields persist through form resubmission

## Migration Guide

### Before (Old Pattern)

```php
if ($request->input('redirect_to') === 'mobile-entry') {
    return redirect()->route('mobile-entry.lifts', [
        'date' => $request->input('date'),
        'id' => $model->id,
    ])->with('success', 'Success!');
} else {
    return redirect()->route('default.route')->with('success', 'Success!');
}
```

### After (New Pattern)

```php
return $this->redirectService->getRedirect(
    'controller_name',
    'action',
    $request,
    ['id' => $model->id],
    'Success!'
);
```

## Adding New Redirect Configurations

1. Open `config/redirects.php`
2. Add your controller and action configuration
3. Inject `RedirectService` into your controller
4. Replace redirect logic with `$this->redirectService->getRedirect()`

## Testing

The service includes a validation method to check if a redirect target is valid:

```php
$isValid = $this->redirectService->isValidRedirectTarget(
    'lift_logs',
    'store',
    'mobile-entry'
);
```

This can be used in tests or validation logic.

## Key Implementation Points

1. **No database storage needed** - params flow through URL and form fields
2. **Backward compatible** - if no redirect params provided, defaults to `mobile-entry-lifts`
3. **Clean separation** - each layer only knows about its immediate next step
4. **Works with validation errors** - hidden fields persist through form resubmission

## Files Modified

- `app/Http/Controllers/MobileEntryController.php` - Pass params through intermediate route
- `app/Services/MobileEntry/LiftLogService.php` - Accept params and embed in hidden fields
- `app/Services/RedirectService.php` - Map `workout_id` → `id` for route parameters
- `config/redirects.php` - Configure workout redirect targets

## Common Use Cases

### Workout Logging Flow
1. User clicks "Log Now" from workout page
2. Parameters flow through intermediate routes
3. Form includes hidden redirect fields
4. After logging, user returns to workout page with workout expanded

### Mobile Entry Flow
1. User logs exercise from mobile entry
2. Default redirect returns to mobile entry
3. Date and context preserved

### Exercise Detail Flow
1. User logs exercise from exercise detail page
2. Redirect returns to exercise logs
3. Exercise context preserved

This system provides a robust, maintainable way to handle complex redirect flows while keeping the code clean and testable.