# Flexible UI Component Reference

Complete API reference and architectural guide for the flexible component-based UI system.

## Architecture Overview

The flexible UI system uses a component-based architecture that is completely flexible and loosely coupled. Every section is optional, components can repeat, and there are no hardcoded array keys.

### Component-Based Structure

Instead of a fixed view with hardcoded sections, the UI renders a list of components:

```php
$data = [
    'components' => [
        ['type' => 'navigation', 'data' => [...]],
        ['type' => 'title', 'data' => [...]],
        ['type' => 'form', 'data' => [...]],
        ['type' => 'form', 'data' => [...]],  // Components can repeat!
        ['type' => 'items', 'data' => [...]],
    ]
];

return view('mobile-entry.flexible', compact('data'));
```

### Key Benefits

1. **Fully Optional** - Include only the components you need
2. **No Hardcoded Keys** - View loops through components dynamically
3. **Repeatable** - Use multiple forms, messages, etc.
4. **Reorderable** - Components render in the order you define them
5. **Type-Safe** - Builder classes provide IDE autocomplete
6. **Backward Compatible** - Existing code continues to work

### Available Component Types

1. **navigation** - Date navigation (prev/today/next) or custom navigation
2. **title** - Page title with optional subtitle and back button
3. **messages** - Interface messages (success, error, warning, info, tip)
4. **summary** - Summary statistics grid
5. **button** - Action button (like "Add Exercise")
6. **item-list** - Filterable item selection list with create functionality
7. **form** - Data entry form with numeric fields, selects, and comments
8. **items** - Display of previously logged items with edit/delete actions
9. **table** - Tabular CRUD list with expandable sub-items
10. **quick-actions** - Standardized action button grid
11. **pr-cards** - Personal record tracking with visual highlights
12. **calculator-grid** - Interactive calculation display
13. **code-editor** - IDE-like syntax editor with highlighting
14. **markdown** - Rich text rendering with custom styling
15. **chart** - Chart.js integration with enhanced styling
16. **tabs** - Tabbed interface with multiple content panels

## Import

```php
use App\Services\ComponentBuilder as C;
```

## Navigation Component

```php
C::navigation()
    ->prev('← Prev', route('mobile-entry.lifts', ['date' => $prevDay]))
    ->center('Today', route('mobile-entry.lifts', ['date' => $today]))
    ->next('Next →', route('mobile-entry.lifts', ['date' => $nextDay]))
    ->ariaLabel('Date navigation')
    ->build()
```

## Title Component

### Basic Title
```php
C::title('Main Title', 'Optional Subtitle')->build()
```

### Title with Back Button

> **Added in v1.2** - November 11, 2025

```php
C::title('Exercise Details', 'View and edit information')
    ->backButton('fa-arrow-left', route('exercises.index'), 'Back to exercises')
    ->build()
```

**Back Button Parameters:**
- `icon` (required) - FontAwesome icon class (e.g., 'fa-arrow-left', 'fa-times', 'fa-chevron-left')
- `url` (required) - Destination URL (use `route()` helper)
- `ariaLabel` (optional) - Accessibility label (defaults to 'Go back')

**Visual Layout:**
```
[←]        Page Title        
           Subtitle Text
```

**Features:**
- Icon-only button positioned on the left
- Title and subtitle remain centered
- 44px touch target for mobile
- Optional aria label for accessibility
- Smooth hover and focus transitions

**Use Cases:**
- Detail pages (exercise details, template editor)
- Edit pages (edit workout template, edit exercise)
- Nested navigation (list → detail → edit)

**Common Patterns:**
```php
// Template editor
C::title($template->name, 'Edit template')
    ->backButton('fa-arrow-left', route('workout-templates.index'), 'Back to templates')
    ->build()

// Detail view
C::title($exercise->title, 'Exercise details')
    ->backButton('fa-arrow-left', route('exercises.index'), 'Back to exercises')
    ->build()

// Close pattern (modal-like)
C::title('Quick Add', 'Add exercise to today')
    ->backButton('fa-times', route('mobile-entry.lifts'), 'Close')
    ->build()
```

## Messages Component

```php
C::messages()
    ->success('Success message', 'Prefix:')
    ->error('Error message')
    ->warning('Warning message')
    ->info('Info message')
    ->tip('Tip message')
    ->build()
```

## Summary Component

```php
C::summary()
    ->item('total', 1250, 'Calories')
    ->item('completed', 3, 'Entries')
    ->item('average', 85, '7-Day Avg')
    ->ariaLabel('Daily summary')
    ->build()
```

## Button Component

```php
C::button('Add Exercise')
    ->ariaLabel('Add new exercise')
    ->cssClass('btn-primary btn-success')
    ->initialState('visible')  // 'visible' (default) or 'hidden'
    ->build()
```

**Initial State Options:**
- `visible` (default) - Button shown on page load
- `hidden` - Button hidden on page load (useful when item list starts expanded)

## Item List Component

```php
C::itemList()
    ->item('exercise-1', 'Bench Press', '/add/1', 'In Program', 'in-program', 1)
    ->item('exercise-2', 'Squats', '/add/2', 'Recent', 'recent', 2)
    ->filterPlaceholder('Search exercises...')
    ->noResultsMessage('No exercises found.')
    ->createForm(route('create'), 'exercise_name', ['date' => $date])
    ->initialState('collapsed')  // 'collapsed' (default) or 'expanded'
    ->build()
```

**Initial State Options:**
- `collapsed` (default) - List hidden on page load, shown when "Add" button clicked
- `expanded` - List visible on page load (useful for dedicated add pages)

**Coordinated States Example:**
```php
// Collapsed list (default behavior)
C::button('Add Exercise')->build(),
C::itemList()->items(...)->build()

// Expanded list (quick-add workflow)
C::button('Add Exercise')->initialState('hidden')->build(),
C::itemList()->items(...)->initialState('expanded')->build()
```

## Form Component

### Basic Form
```php
C::form('form-id', 'Form Title')
    ->type('primary')  // primary, success, warning, secondary, danger, info
    ->formAction(route('lift-logs.store'))
    ->deleteAction(route('mobile-entry.remove-form', ['id' => 'form-id']))
    ->submitButton('Log Exercise')
    ->build()
```

### Form with Messages
```php
C::form('workout-1', 'Bench Press')
    ->type('primary')
    ->formAction(route('lift-logs.store'))
    ->message('info', '135 lbs × 10 reps × 3 sets', 'Last time:')
    ->message('tip', 'Try 140 lbs × 10 reps × 3 sets', 'Suggestion:')
    ->build()
```

### Form with Numeric Fields
```php
C::form('workout-1', 'Bench Press')
    ->type('primary')
    ->formAction(route('lift-logs.store'))
    ->numericField('weight', 'Weight:', 135, 5, 45, 500)  // name, label, default, increment, min, max
    ->numericField('reps', 'Reps:', 10, 1, 1, 100)
    ->numericField('sets', 'Sets:', 3, 1, 1, 10)
    ->build()
```

### Form with Select Field
```php
C::form('band-exercise-1', 'Band Pull-aparts')
    ->type('primary')
    ->formAction(route('lift-logs.store'))
    ->selectField('band_color', 'Band Color:', [
        ['value' => 'red', 'label' => 'Red'],
        ['value' => 'blue', 'label' => 'Blue'],
        ['value' => 'green', 'label' => 'Green']
    ], 'red')  // default value
    ->numericField('reps', 'Reps:', 12, 1, 1, 50)
    ->build()
```

### Form with Text Field
```php
C::form('custom-1', 'Custom Entry')
    ->type('secondary')
    ->formAction(route('custom.store'))
    ->textField('name', 'Name:', 'Default', 'Placeholder text')
    ->build()
```

### Form with Comment Field
```php
C::form('workout-1', 'Bench Press')
    ->type('primary')
    ->formAction(route('lift-logs.store'))
    ->commentField('Notes:', 'How did it feel?', 'Default text')
    ->build()
```

### Form with Hidden Fields
```php
C::form('workout-1', 'Bench Press')
    ->type('primary')
    ->formAction(route('lift-logs.store'))
    ->hiddenField('exercise_id', 123)
    ->hiddenField('date', '2025-11-10')
    ->hiddenField('redirect_to', 'mobile-entry-lifts')
    ->build()
```

## Items Component (Logged Items)

### Basic Items
```php
C::items()
    ->item(1, 'Morning Workout', 25, route('edit', 1), route('delete', 1))
        ->message('success', '135 lbs × 10 reps × 3 sets', 'Completed!')
        ->freeformText('Felt great today!')
        ->deleteParams(['date' => '2025-11-10'])
        ->add()
    ->emptyMessage('No workouts logged yet!')
    ->confirmMessage('deleteItem', 'Are you sure?')
    ->build()
```

### Multiple Items
```php
$itemsBuilder = C::items()
    ->confirmMessage('deleteItem', 'Are you sure you want to delete?')
    ->confirmMessage('removeForm', 'Are you sure you want to remove?');

foreach ($logs as $log) {
    $itemsBuilder->item(
        $log->id,
        $log->title,
        null,
        route('edit', $log->id),
        route('delete', $log->id)
    )
    ->message('success', $log->details, 'Completed!')
    ->freeformText($log->comments ?? '')
    ->deleteParams(['redirect_to' => 'mobile-entry-lifts'])
    ->add();
}

if (empty($logs)) {
    $itemsBuilder->emptyMessage('No items yet.');
}

return $itemsBuilder->build()['data'];
```

## Form Types and Colors

| Type | Color | Border | Usage |
|------|-------|--------|-------|
| `primary` | Blue | `#007bff` | Main/important forms (exercises) |
| `success` | Green | `#28a745` | Positive/completion forms (food) |
| `warning` | Yellow | `#ffc107` | Attention forms (measurements) |
| `secondary` | Gray | `#6c757d` | General/default forms |
| `danger` | Red | `#dc3545` | Error/critical forms |
| `info` | Light Blue | `#17a2b8` | Informational forms |

## Complete Usage Examples

### Example 1: Full-Featured Mobile Entry Interface

```php
public function lifts(Request $request, LiftLogService $formService)
{
    $selectedDate = $request->input('date') 
        ? Carbon::parse($request->input('date')) 
        : Carbon::today();
    
    $prevDay = $selectedDate->copy()->subDay();
    $nextDay = $selectedDate->copy()->addDay();
    $today = Carbon::today();
    
    // Get data from services
    $forms = $formService->generateForms(Auth::id(), $selectedDate);
    $loggedItems = $formService->generateLoggedItems(Auth::id(), $selectedDate);
    $itemSelectionList = $formService->generateItemSelectionList(Auth::id(), $selectedDate);
    $summary = $formService->generateSummary(Auth::id(), $selectedDate);
    
    // Build components array
    $components = [];
    
    // Navigation
    $components[] = C::navigation()
        ->prev('← Prev', route('mobile-entry.lifts', ['date' => $prevDay->toDateString()]))
        ->center('Today', route('mobile-entry.lifts', ['date' => $today->toDateString()]))
        ->next('Next →', route('mobile-entry.lifts', ['date' => $nextDay->toDateString()]))
        ->build();
    
    // Title
    $components[] = C::title($selectedDate->format('M j, Y'))->build();
    
    // Messages (from session)
    if (session()->has('success') || session()->has('error')) {
        $messagesBuilder = C::messages();
        if (session('success')) {
            $messagesBuilder->success(session('success'));
        }
        if (session('error')) {
            $messagesBuilder->error(session('error'));
        }
        $components[] = $messagesBuilder->build();
    }
    
    // Summary (if available)
    if ($summary) {
        $summaryBuilder = C::summary();
        foreach ($summary['values'] as $key => $value) {
            $summaryBuilder->item($key, $value, $summary['labels'][$key] ?? null);
        }
        $components[] = $summaryBuilder->build();
    }
    
    // Add Exercise button
    $components[] = C::button('Add Exercise')
        ->ariaLabel('Add new exercise')
        ->build();
    
    // Item selection list
    $itemListBuilder = C::itemList()
        ->filterPlaceholder($itemSelectionList['filterPlaceholder'])
        ->noResultsMessage($itemSelectionList['noResultsMessage']);
    
    foreach ($itemSelectionList['items'] as $item) {
        $itemListBuilder->item(
            $item['id'],
            $item['name'],
            $item['href'],
            $item['type']['label'],
            $item['type']['cssClass'],
            $item['type']['priority']
        );
    }
    
    if (isset($itemSelectionList['createForm'])) {
        $itemListBuilder->createForm(
            $itemSelectionList['createForm']['action'],
            $itemSelectionList['createForm']['inputName'],
            $itemSelectionList['createForm']['hiddenFields']
        );
    }
    
    $components[] = $itemListBuilder->build();
    
    // Forms (from service)
    foreach ($forms as $form) {
        $components[] = ['type' => 'form', 'data' => $form];
    }
    
    // Logged items (from service)
    $components[] = ['type' => 'items', 'data' => $loggedItems];
    
    $data = ['components' => $components];
    
    return view('mobile-entry.flexible', compact('data'));
}
```

### Example 2: Standalone Form (No Navigation)

```php
public function quickLog()
{
    $data = [
        'components' => [
            C::title('Quick Workout Log')->build(),
            
            C::messages()
                ->info('Log your workout quickly')
                ->build(),
            
            C::form('quick-log', 'Log Your Workout')
                ->type('primary')
                ->formAction(route('workouts.store'))
                ->numericField('sets', 'Sets:', 3, 1, 1, 10)
                ->numericField('reps', 'Reps:', 10, 1, 1, 50)
                ->numericField('weight', 'Weight:', 135, 5, 45, 500)
                ->commentField('Notes:', 'How did it feel?')
                ->submitButton('Save Workout')
                ->build(),
        ]
    ];
    
    return view('mobile-entry.flexible', compact('data'));
}
```

### Example 3: Multiple Forms

```php
public function workoutTemplate()
{
    $data = [
        'components' => [
            C::title('Today\'s Workout', 'Push Day')->build(),
            
            C::form('ex-1', 'Bench Press')
                ->type('primary')
                ->formAction(route('lift-logs.store'))
                ->message('info', '135 lbs × 10 reps × 3 sets', 'Last time:')
                ->numericField('weight', 'Weight:', 135, 5, 45, 500)
                ->numericField('reps', 'Reps:', 10, 1, 1, 50)
                ->numericField('sets', 'Sets:', 3, 1, 1, 10)
                ->hiddenField('exercise_id', 1)
                ->submitButton('Log Bench Press')
                ->build(),
            
            C::form('ex-2', 'Overhead Press')
                ->type('primary')
                ->formAction(route('lift-logs.store'))
                ->message('info', '95 lbs × 8 reps × 3 sets', 'Last time:')
                ->numericField('weight', 'Weight:', 95, 5, 45, 300)
                ->numericField('reps', 'Reps:', 8, 1, 1, 50)
                ->numericField('sets', 'Sets:', 3, 1, 1, 10)
                ->hiddenField('exercise_id', 2)
                ->submitButton('Log Overhead Press')
                ->build(),
        ]
    ];
    
    return view('mobile-entry.flexible', compact('data'));
}
```

### Example 4: Custom Component Order

Components can appear in any order to match your workflow:

```php
$data = [
    'components' => [
        C::messages()->warning('Complete your profile to continue')->build(),
        C::title('Profile Setup', '25% Complete')->build(),
        C::summary()
            ->item('completed', 2, 'Fields Done')
            ->item('remaining', 6, 'Fields Left')
            ->build(),
        C::form('profile', 'Personal Information')
            ->type('secondary')
            ->formAction(route('profile.update'))
            ->textField('name', 'Full Name:', auth()->user()->name)
            ->textField('email', 'Email:', auth()->user()->email)
            ->submitButton('Save Profile')
            ->build(),
    ]
];
```

## Complete Controller Example

```php
use App\Services\ComponentBuilder as C;

public function lifts(Request $request, LiftLogService $formService)
{
    $selectedDate = $request->input('date') 
        ? Carbon::parse($request->input('date')) 
        : Carbon::today();
    
    $prevDay = $selectedDate->copy()->subDay();
    $nextDay = $selectedDate->copy()->addDay();
    $today = Carbon::today();
    
    // Get data from services
    $forms = $formService->generateForms(Auth::id(), $selectedDate);
    $loggedItems = $formService->generateLoggedItems(Auth::id(), $selectedDate);
    $itemSelectionList = $formService->generateItemSelectionList(Auth::id(), $selectedDate);
    
    // Build components array
    $components = [];
    
    // Navigation
    $components[] = C::navigation()
        ->prev('← Prev', route('mobile-entry.lifts', ['date' => $prevDay->toDateString()]))
        ->center('Today', route('mobile-entry.lifts', ['date' => $today->toDateString()]))
        ->next('Next →', route('mobile-entry.lifts', ['date' => $nextDay->toDateString()]))
        ->build();
    
    // Title
    $components[] = C::title('Today')->build();
    
    // Messages (if any)
    if ($interfaceMessages['hasMessages']) {
        $messagesBuilder = C::messages();
        foreach ($interfaceMessages['messages'] as $message) {
            $messagesBuilder->add($message['type'], $message['text'], $message['prefix'] ?? null);
        }
        $components[] = $messagesBuilder->build();
    }
    
    // Add Exercise button
    $components[] = C::button('Add Exercise')
        ->ariaLabel('Add new exercise')
        ->build();
    
    // Item selection list
    $itemListBuilder = C::itemList()
        ->filterPlaceholder($itemSelectionList['filterPlaceholder'])
        ->noResultsMessage($itemSelectionList['noResultsMessage']);
    
    foreach ($itemSelectionList['items'] as $item) {
        $itemListBuilder->item(
            $item['id'],
            $item['name'],
            $item['href'],
            $item['type']['label'],
            $item['type']['cssClass'],
            $item['type']['priority']
        );
    }
    
    if (isset($itemSelectionList['createForm'])) {
        $itemListBuilder->createForm(
            $itemSelectionList['createForm']['action'],
            $itemSelectionList['createForm']['inputName'],
            $itemSelectionList['createForm']['hiddenFields']
        );
    }
    
    $components[] = $itemListBuilder->build();
    
    // Forms
    foreach ($forms as $form) {
        $components[] = ['type' => 'form', 'data' => $form];
    }
    
    // Logged items
    $components[] = ['type' => 'items', 'data' => $loggedItems];
    
    $data = ['components' => $components];
    
    return view('mobile-entry.flexible', compact('data'));
}
```

## Architecture Files

- **Main View**: `resources/views/mobile-entry/flexible.blade.php`
- **Component Views**: `resources/views/mobile-entry/components/*.blade.php`
- **Builder Service**: `app/Services/ComponentBuilder.php`
- **Component Builders**: `app/Services/Components/*/`
- **CSS**: `public/css/mobile-entry/components/*.css`
- **JavaScript**: `public/js/mobile-entry.js`, `public/js/*.js`

## Production Status

All mobile entry interfaces now use the flexible UI system:

1. ✅ `lifts()` - Uses ComponentBuilder and flexible view
2. ✅ `foods()` - Uses ComponentBuilder and flexible view  
3. ✅ `measurements()` - Uses ComponentBuilder and flexible view
4. ✅ User management pages - Component-based architecture
5. ✅ Exercise management - Enhanced with component-based patterns

## Tips and Best Practices

1. **Always use `build()`** - Don't forget to call `build()` at the end of the chain
2. **Extract data for forms/items** - Services return `build()['data']` directly
3. **Check for null** - Summary can be null, check before adding to components
4. **Use type-safe methods** - ComponentBuilder provides type hints for all methods
5. **Chain methods** - All builder methods return `$this` for fluent chaining
6. **Empty messages** - Always set `emptyMessage` for items component (even if empty string)
7. **Coordinate states** - When using button + item list, coordinate their initial states
8. **Component order matters** - Components render in the order you add them to the array

## Common Patterns

### Conditional Components
```php
// Only add summary if it exists
$summary = $formService->generateSummary(Auth::id(), $selectedDate);
if ($summary) {
    $summaryBuilder = C::summary();
    foreach ($summary['values'] as $key => $value) {
        $summaryBuilder->item($key, $value, $summary['labels'][$key] ?? null);
    }
    $components[] = $summaryBuilder->build();
}
```

### Dynamic Messages
```php
$messagesBuilder = C::messages();
if (session('success')) {
    $messagesBuilder->success(session('success'));
}
if (session('error')) {
    $messagesBuilder->error(session('error'));
}
// Only add if there are messages
if ($messagesBuilder->hasMessages()) {
    $components[] = $messagesBuilder->build();
}
```

### Reusing Service Data
```php
// Services return data in the correct format
$forms = $formService->generateForms($userId, $date);
foreach ($forms as $form) {
    $components[] = ['type' => 'form', 'data' => $form];
}

$loggedItems = $formService->generateLoggedItems($userId, $date);
$components[] = ['type' => 'items', 'data' => $loggedItems];
```

### Multiple Independent Lists
```php
// Exercise list - collapsed by default
$components[] = C::button('Add Exercise')->addClass('btn-add-item')->build();
$components[] = C::itemList()
    ->item('ex-1', 'Bench Press', '#', 'Available', 'regular', 3)
    ->filterPlaceholder('Search exercises...')
    ->createForm(route('exercise.create'), 'exercise_name')
    ->build();

// Meal list - expanded by default  
$components[] = C::button('Add Meal')->addClass('btn-add-item')->initialState('hidden')->build();
$components[] = C::itemList()
    ->item('meal-1', 'Chicken & Rice', '#', 'Favorite', 'in-program', 4)
    ->filterPlaceholder('Search meals...')
    ->createForm(route('meal.create'), 'meal_name')
    ->initialState('expanded')
    ->build();
```

## Quick Actions Component

Standardized action button grid for common page operations.

```php
C::quickActions('Quick Actions')
    ->formAction('fa-star', route('exercise.promote', $exercise), 'POST', [], 'Promote', 'btn-primary')
    ->formAction('fa-code-fork', route('exercise.merge.form', $exercise), 'GET', [], 'Merge', 'btn-secondary')
    ->formAction('fa-trash', route('exercise.destroy', $exercise), 'DELETE', [], 'Delete', 'btn-danger', 'Are you sure?')
    ->linkAction('fa-edit', route('exercise.edit', $exercise), 'Edit', 'btn-secondary')
    ->initialState('visible')
    ->build()
```

**Methods:**
- `formAction($icon, $action, $method, $params, $text, $cssClass, $confirm, $disabled, $disabledReason)` - Form-based action
- `linkAction($icon, $url, $text, $cssClass)` - Link-based action  
- `initialState($state)` - 'visible' (default) or 'hidden'

**Use Cases:**
- Exercise management (promote, merge, delete)
- User administration interfaces
- Any page requiring multiple related actions

## Table Component

### Basic Table
```php
C::table()
    ->row(1, 'Line 1', 'Line 2', 'Line 3', '/edit/1', '/delete/1')
        ->add()
    ->row(2, 'Another row', 'Secondary text', null, '/edit/2', '/delete/2')
        ->add()
    ->emptyMessage('No items yet.')
    ->confirmMessage('deleteItem', 'Are you sure?')
    ->build()
```

### Table with Delete Parameters
```php
C::table()
    ->row(1, 'Workout Name', '30 minutes', 'Last: 2 days ago', '/edit/1', '/delete/1')
        ->deleteParams(['date' => '2025-11-10'])
        ->add()
    ->build()
```

### Table Methods

| Method | Parameters | Description |
|--------|-----------|-------------|
| `row()` | `$id, $line1, $line2, $line3, $editAction, $deleteAction` | Add a row (line2 and line3 optional) |
| `deleteParams()` | `array $params` | Add hidden params to delete form |
| `add()` | - | Finish row and return to table builder |
| `emptyMessage()` | `string $message` | Message when no rows |
| `confirmMessage()` | `string $key, string $message` | Delete confirmation message |
| `ariaLabel()` | `string $label` | Accessibility label |
| `build()` | - | Build the component |

### Complete Table Example (v1.3 Features)
```php
$components[] = C::table()
    ->row(1, 'Morning Workout', '3 exercises', 'Last completed: 2 days ago')
        ->linkAction('fa-edit', route('edit', 1), 'Edit')
        ->formAction('fa-trash', route('delete', 1), 'DELETE', [], 'Delete', 'btn-danger', true)
        // Sub-items with messages and actions
        ->subItem(11, 'Push-ups', '3 sets × 15 reps', 'Tap to log')
            ->message('info', '45 total reps last time', 'History:')
            ->linkAction('fa-play', route('log', 11), 'Log now', 'btn-log-now')
            ->add()
        ->subItem(12, 'Pull-ups', '3 sets × 8 reps', 'Tap to log')
            ->message('tip', 'Try to beat your record!', 'Goal:')
            ->linkAction('fa-play', route('log', 12), 'Log now', 'btn-log-now')
            ->add()
        ->initialState('expanded')  // Start expanded
        ->add()
    ->row(2, 'Evening Workout', '2 exercises')
        ->linkAction('fa-info', route('details', 2), 'Details', 'btn-info-circle')
        ->formAction('fa-trash', route('delete', 2), 'DELETE', [], 'Delete', 'btn-danger', true)
        ->compact()  // 75% button size
        ->subItem(21, 'Squats', 'Felt strong!', null)
            ->message('success', '225 lbs × 5 reps × 5 sets', 'Completed:')
            ->linkAction('fa-pencil', route('edit', 21), 'Edit', 'btn-transparent')
            ->formAction('fa-trash', route('delete', 21), 'DELETE', [], 'Delete', 'btn-danger', true)
            ->add()
        ->add()
    ->row(3, 'Quick Stretches', 'Always visible')
        ->linkAction('fa-edit', route('edit', 3), 'Edit')
        ->subItem(31, 'Neck Rolls', '2 minutes')
            ->linkAction('fa-play', route('log', 31), 'Log')
            ->add()
        ->collapsible(false)  // No expand/collapse
        ->add()
    ->emptyMessage('No workouts yet!')
    ->confirmMessage('deleteItem', 'Are you sure?')
    ->ariaLabel('Workout routines')
    ->build();
```

### Table Row Methods (v1.3)

| Method | Parameters | Description |
|--------|-----------|-------------|
| `linkAction()` | `$icon, $url, $ariaLabel, $cssClass` | Add link button (GET) |
| `formAction()` | `$icon, $url, $method, $params, $ariaLabel, $cssClass, $confirm` | Add form button (POST/DELETE) |
| `subItem()` | `$id, $line1, $line2, $line3` | Add expandable sub-item |
| `titleClass()` | `string $class` | CSS class for title ('cell-title-large' for 1.4em) |
| `compact()` | `bool $compact` | Use 75% button size |
| `collapsible()` | `bool $collapsible` | Enable/disable expand/collapse (default: true) |
| `initialState()` | `string $state` | 'expanded' or 'collapsed' (default: 'collapsed') |

### Table Sub-Item Methods (v1.3)

| Method | Parameters | Description |
|--------|-----------|-------------|
| `linkAction()` | `$icon, $url, $ariaLabel, $cssClass` | Add link button |
| `formAction()` | `$icon, $url, $method, $params, $ariaLabel, $cssClass, $confirm` | Add form button |
| `message()` | `$type, $text, $prefix` | Add inline message |
| `compact()` | `bool $compact` | Use 75% button size |

### Button Style Classes (v1.3)

| Class | Appearance | Use Case |
|-------|-----------|----------|
| `btn-transparent` | White icon, no background | Subtle edit actions |
| `btn-info-circle` | Circle with border | Info/details actions |
| `btn-log-now` | Green background | Primary workout actions |
| `btn-danger` | Red background | Delete actions |

### Message Types for Sub-Items

| Type | Color | Use Case |
|------|-------|----------|
| `success` | Green | Completed actions |
| `info` | Blue | Informational messages |
| `tip` | Yellow | Suggestions/goals |
| `warning` | Orange | Cautions |
| `error` | Red | Errors/issues |
| `neutral` | Gray | General notes |

### Clickable Sub-Items (v1.3)

Sub-items with a single link action automatically become fully clickable:

```php
// Entire row is clickable (not just the button)
->subItem(1, 'Push-ups', '3 sets × 15 reps', 'Tap anywhere to log')
    ->linkAction('fa-play', route('log'), 'Log now', 'btn-log-now')
    ->add()
```

**Features:**
- Larger touch target for mobile
- Visual hover feedback on entire row
- Button still functional
- Automatic detection (no config needed)

## Tabs Component

Create tabbed interfaces where each tab can contain any combination of other components.

### Basic Tabs
```php
C::tabs('my-tabs')
    ->tab('first', 'First Tab', $firstTabComponents, 'fa-home', true)
    ->tab('second', 'Second Tab', $secondTabComponents, 'fa-chart-line')
    ->build()
```

### Complete Example
```php
// Components for the "Log Lift" tab
$logLiftComponents = [
    C::form('bench-press-log', 'Bench Press')
        ->type('primary')
        ->formAction(route('lift-logs.store'))
        ->numericField('weight', 'Weight (lbs):', 185, 5, 45, 500)
        ->numericField('reps', 'Reps:', 8, 1, 1, 50)
        ->submitButton('Log Workout')
        ->build(),
    
    C::summary()
        ->item('streak', '12 days', 'Current Streak')
        ->item('pr', '185 lbs', 'Current PR')
        ->build(),
];

// Components for the "History" tab
$historyComponents = [
    C::chart('progress-chart', 'Progress Chart')
        ->type('line')
        ->datasets($chartData)
        ->build(),
    
    C::table()
        ->row(1, 'Recent Workout', 'Details')
        ->build(),
];

// Create the tabbed interface
$components = [
    C::title('Bench Press Tracker')->build(),
    
    C::tabs('lift-tracker-tabs')
        ->tab('log', 'Log Lift', $logLiftComponents, 'fa-plus', true)
        ->tab('history', 'History', $historyComponents, 'fa-chart-line')
        ->ariaLabels([
            'section' => 'Lift tracking interface',
            'tabList' => 'Switch between logging and history views',
            'tabPanel' => 'Content for selected tab'
        ])
        ->build(),
];
```

### Tab Configuration

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | string | Unique identifier for the tab |
| `label` | string | Display text for the tab button |
| `components` | array | Array of component data to render in the tab |
| `icon` | string | Optional FontAwesome icon class (e.g., 'fa-plus', 'fa-chart-line') |
| `active` | bool | Whether this tab should be active by default |

### Methods

| Method | Parameters | Description |
|--------|-----------|-------------|
| `tab()` | `$id, $label, $components, $icon, $active` | Add a tab with content |
| `activeTab()` | `string $tabId` | Set which tab should be active by default |
| `ariaLabels()` | `array $labels` | Set accessibility labels |
| `build()` | - | Build the component |

### Accessibility Features

- **ARIA Support**: Full ARIA labels and roles for screen readers
- **Keyboard Navigation**: Arrow keys, Home, End keys for tab switching
- **Focus Management**: Proper focus handling when switching tabs
- **Screen Reader**: Proper tab/tabpanel relationships

### Keyboard Navigation

| Key | Action |
|-----|--------|
| `←` / `→` | Navigate between tabs |
| `Home` | Go to first tab |
| `End` | Go to last tab |
| `Tab` | Move focus to tab content |

### Responsive Design

- **Mobile Optimized**: Scrollable tab navigation on small screens
- **Touch Friendly**: 44px minimum touch targets
- **Icon Handling**: Icons hidden on very small screens (< 480px)
- **Flexible Layout**: Tabs expand to fill available width

### Use Cases

- **Form + Analytics**: Logging interface with historical data
- **Settings Tabs**: Different configuration sections
- **Multi-Step Workflows**: Break complex processes into tabs
- **Content Organization**: Group related functionality

### Integration with Other Components

Each tab can contain any combination of components:

```php
$tabComponents = [
    C::messages()->info('Tab-specific message')->build(),
    C::form('tab-form', 'Form in Tab')->build(),
    C::table()->row(1, 'Data', 'In Tab')->build(),
    C::chart('tab-chart', 'Chart in Tab')->build(),
];

C::tabs('example')
    ->tab('content', 'Content Tab', $tabComponents)
    ->build()
```

### Example: Lift Logging with History

```php
public function tabbedLiftLogger(Request $request)
{
    // Chart data for history tab
    $chartData = [
        'datasets' => [
            [
                'label' => 'Working Weight (lbs)',
                'data' => [
                    ['x' => '2024-11-01', 'y' => 135],
                    ['x' => '2024-11-05', 'y' => 140],
                    // ... more data points
                ],
                'borderColor' => 'rgb(75, 192, 192)',
                'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
            ]
        ]
    ];
    
    // Log tab components
    $logComponents = [
        C::form('bench-press-log', 'Bench Press')
            ->type('primary')
            ->formAction(route('lift-logs.store'))
            ->message('info', '185 lbs × 8 reps × 3 sets', 'Last workout:')
            ->numericField('weight', 'Weight (lbs):', 185, 5, 45, 500)
            ->numericField('reps', 'Reps:', 8, 1, 1, 50)
            ->numericField('sets', 'Sets:', 3, 1, 1, 10)
            ->submitButton('Log Workout')
            ->build(),
        
        C::summary()
            ->item('streak', '12 days', 'Current Streak')
            ->item('pr', '185 lbs', 'Current PR')
            ->build(),
    ];
    
    // History tab components
    $historyComponents = [
        C::chart('bench-progress-chart', 'Bench Press Progress')
            ->type('line')
            ->datasets($chartData['datasets'])
            ->timeScale('day')
            ->showLegend()
            ->build(),
        
        C::table()
            ->row(1, 'Nov 26, 2024', '170 lbs × 5 reps × 3 sets', '1RM: 227 lbs')
                ->badge('Today', 'success')
                ->badge('170 lbs', 'dark', true)
                ->badge('PR!', 'success')
                ->linkAction('fa-edit', route('edit'), 'Edit')
                ->add()
            ->build(),
    ];
    
    $data = [
        'components' => [
            C::title('Bench Press Tracker', 'Log workouts and view progress')
                ->backButton('fa-arrow-left', route('exercises.index'), 'Back')
                ->build(),
            
            C::messages()
                ->success('Great progress! You\'ve increased 35 lbs this month.')
                ->tip('Use arrow keys to navigate between tabs', 'Accessibility:')
                ->build(),
            
            C::tabs('lift-tracker-tabs')
                ->tab('log', 'Log Lift', $logComponents, 'fa-plus', true)
                ->tab('history', 'History', $historyComponents, 'fa-chart-line')
                ->build(),
        ],
    ];
    
    return view('mobile-entry.flexible', compact('data'));
}
```

### Files

- **Builder**: `app/Services/Components/Interactive/TabsComponentBuilder.php`
- **Template**: `resources/views/mobile-entry/components/tabs.blade.php`
- **CSS**: `public/css/mobile-entry/components/tabs.css`
- **JavaScript**: `public/js/mobile-entry/tabs.js`

### Custom Events

The tabs component triggers custom events for integration:

```javascript
// Listen for tab changes
document.addEventListener('tabChanged', function(e) {
    console.log('Switched to tab:', e.detail.tabId);
    console.log('Container:', e.detail.container);
});
```
