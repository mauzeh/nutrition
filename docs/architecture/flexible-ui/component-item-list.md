# Item List Component Guide

## Overview

The Item List component provides a searchable, filterable list interface for selecting items with optional create functionality. It's designed for mobile-first workflows where users need to quickly find and select from a list of options or create new items when none match their search.

## Features

- **Searchable filtering** with real-time results
- **Categorized items** with visual badges and priority sorting
- **Create functionality** with contextual button behavior
- **Initial state configuration** (collapsed/expanded)
- **Auto-scroll and focus** for optimal UX
- **Multiple independent lists** on the same page
- **Mobile-optimized** touch targets and keyboard handling

## Basic Usage

```php
use App\Services\ComponentBuilder as C;

$components[] = C::itemList()
    ->item('ex-1', 'Bench Press', route('exercise.select', 1), 'In Program', 'in-program', 4)
    ->item('ex-2', 'Squats', route('exercise.select', 2), 'Recent', 'recent', 1)
    ->item('ex-3', 'Deadlift', route('exercise.select', 3), 'Available', 'regular', 3)
    ->filterPlaceholder('Search exercises...')
    ->noResultsMessage('No exercises found.')
    ->createForm(route('exercise.create'), 'exercise_name', ['date' => $date])
    ->initialState('collapsed')
    ->build();
```

## API Reference

### Creating an Item List

```php
ComponentBuilder::itemList()
```

### Adding Items

```php
->item(string $id, string $title, string $url, string $badge = null, string $badgeClass = null, int $priority = 0)
```

**Parameters:**
- `$id` - Unique identifier for the item
- `$title` - Display name of the item
- `$url` - URL to navigate to when item is selected
- `$badge` - Optional badge text (e.g., "In Program", "Recent")
- `$badgeClass` - CSS class for badge styling (e.g., "in-program", "recent")
- `$priority` - Sort priority (lower numbers appear first)

### Configuration Methods

```php
->filterPlaceholder(string $placeholder)
```
Sets the search input placeholder text.

```php
->noResultsMessage(string $message)
```
Message displayed when no items match the search filter.

```php
->createForm(string $action, string $inputName, array $hiddenFields = [], string $buttonTextTemplate = 'Create "{term}"')
```
Enables item creation functionality.

**Parameters:**
- `$action` - Form submission URL
- `$inputName` - Name attribute for the search term input
- `$hiddenFields` - Additional form fields (e.g., date, user_id)
- `$buttonTextTemplate` - Template for create button text (use `{term}` placeholder)

```php
->initialState(string $state)
```
Sets initial visibility state: `'collapsed'` (default) or `'expanded'`.

```php
->ariaLabel(string $label)
```
Sets accessibility label for screen readers.

## Initial State Configuration

> **Added in v1.1** - November 11, 2025

Control whether the item list starts collapsed (hidden) or expanded (visible) when the page loads.

### Collapsed State (Default)

Use for clean, minimal interfaces where users explicitly choose to add items:

```php
// Button visible, list hidden
C::button('Add Exercise')
    ->addClass('btn-add-item')
    ->initialState('visible')  // default
    ->build(),

C::itemList()
    ->item('ex-1', 'Bench Press', '#', 'In Program', 'in-program', 4)
    ->filterPlaceholder('Search exercises...')
    ->initialState('collapsed')  // default
    ->build()
```

### Expanded State

Use when item selection is the primary action:

```php
// Button hidden, list visible
C::button('Add Exercise')
    ->addClass('btn-add-item')
    ->initialState('hidden')
    ->build(),

C::itemList()
    ->item('ex-1', 'Bench Press', '#', 'In Program', 'in-program', 4)
    ->filterPlaceholder('Search exercises...')
    ->initialState('expanded')
    ->build()
```

### Context-Aware States

Conditionally set initial state based on URL parameters:

```php
public function edit(Request $request, WorkoutTemplate $template)
{
    $shouldExpand = $request->query('expand') === 'true';
    
    $buttonBuilder = C::button('Add Exercise')->addClass('btn-add-item');
    $listBuilder = C::itemList()->items(...);
    
    if ($shouldExpand) {
        $buttonBuilder->initialState('hidden');
        $listBuilder->initialState('expanded');
    }
    
    $components[] = $buttonBuilder->build();
    $components[] = $listBuilder->build();
    
    return view('mobile-entry.flexible', compact('data'));
}
```

**Usage:** Link with pre-expanded list:
```php
route('workout-templates.edit', ['template' => $id]) . '?expand=true'
```

## Item Categories and Badges

Items can be categorized with badges for better organization and visual hierarchy:

### Badge Classes

```php
// Priority items (dark blue)
->item('ex-1', 'Bench Press', '#', 'In Program', 'in-program', 1)

// Recent items (green)
->item('ex-2', 'Squats', '#', 'Recent', 'recent', 2)

// Regular items (gray)
->item('ex-3', 'Deadlift', '#', 'Available', 'regular', 3)

// Custom badge
->item('ex-4', 'Pull-ups', '#', 'Favorite', 'favorite', 1)
```

### Priority Sorting

Items are automatically sorted by priority (ascending), then alphabetically by title:

```php
// This order in code...
->item('ex-3', 'Zebra Exercise', '#', null, null, 3)
->item('ex-1', 'Alpha Exercise', '#', null, null, 1)
->item('ex-2', 'Beta Exercise', '#', null, null, 1)

// Results in this display order:
// 1. Alpha Exercise (priority 1)
// 2. Beta Exercise (priority 1, alphabetical)
// 3. Zebra Exercise (priority 3)
```

## Create Functionality

### Dynamic Create Button

The create button appears contextually when users search for items that don't exist:

```php
C::itemList()
    ->item('ex-1', 'Bench Press', '#', 'Available', 'regular', 3)
    ->filterPlaceholder('Search exercises...')
    ->createForm(
        route('mobile-entry.create-exercise'),
        'exercise_name',
        ['date' => $selectedDate->toDateString()],
        'Create "{term}"'
    )
    ->build()
```

**Behavior:**
- Hidden by default when browsing items
- Appears when user types a search term with no matches
- Button text dynamically shows what will be created: `Create "Bulgarian Split Squat"`
- Includes helpful message: `No matches found for "Bulgarian Split Squat"`

### Create Button Templates

Customize the create button text with the `{term}` placeholder:

```php
// Standard format
'Create "{term}"' → "Create 'Bulgarian Split Squat'"

// Alternative formats
'Add new: {term}' → "Add new: Bulgarian Split Squat"
'+ {term}' → "+ Bulgarian Split Squat"
'New {term}' → "New Bulgarian Split Squat"
```

### Form Submission

The create form automatically includes:
- Search term as the specified input name
- Any additional hidden fields
- CSRF token for security

```php
// Results in this form when user searches for "New Exercise":
<form method="POST" action="/mobile-entry/create-exercise">
    @csrf
    <input type="hidden" name="exercise_name" value="New Exercise">
    <input type="hidden" name="date" value="2025-12-24">
    <button type="submit">Create "New Exercise"</button>
</form>
```

## Multiple Independent Lists

You can have multiple item lists on the same page, each with independent behavior:

```php
public function multipleCategories(Request $request)
{
    $data = [
        'components' => [
            C::title('Add Items')->build(),
            
            // Exercise list - collapsed by default
            C::button('Add Exercise')->addClass('btn-add-item')->build(),
            C::itemList()
                ->item('ex-1', 'Bench Press', '#', 'Available', 'regular', 3)
                ->filterPlaceholder('Search exercises...')
                ->createForm(route('exercise.create'), 'exercise_name')
                ->build(),
            
            // Meal list - expanded by default
            C::button('Add Meal')->addClass('btn-add-item')->initialState('hidden')->build(),
            C::itemList()
                ->item('meal-1', 'Chicken & Rice', '#', 'Favorite', 'in-program', 4)
                ->filterPlaceholder('Search meals...')
                ->createForm(route('meal.create'), 'meal_name')
                ->initialState('expanded')
                ->build(),
        ]
    ];
    
    return view('mobile-entry.flexible', compact('data'));
}
```

**Key Features:**
- Each button/list pair operates independently
- Automatically linked by proximity in component array
- Different initial states per list
- Separate search filters and create functionality

## Auto-Scroll and Focus Behavior

When a list becomes expanded (either initially or via button click):

1. **Auto-scroll** - Positions the filter input near the top of viewport (5% from top)
2. **Auto-focus** - Focuses the search input field
3. **Mobile keyboard** - Opens keyboard on mobile devices (when triggered by user action)

### Technical Implementation

```javascript
// Scroll behavior
const scrollTarget = filterInput.getBoundingClientRect().top + window.pageYOffset - (window.innerHeight * 0.05);
window.scrollTo({ top: scrollTarget, behavior: 'smooth' });

// Focus with delay for smooth animation
setTimeout(() => {
    filterInput.focus();
}, 300);
```

### When Auto-Scroll Occurs

- ✅ User clicks "Add" button to expand list
- ✅ List starts expanded via `initialState('expanded')`
- ✅ List expanded via URL parameter (`?expand=true`)
- ❌ List is already visible (no redundant scrolling)

## Advanced Examples

### Workout Exercise Selection

```php
public function editWorkout(WorkoutTemplate $template)
{
    $exercises = Exercise::where('user_id', auth()->id())
        ->orWhere('is_global', true)
        ->get();
    
    $listBuilder = C::itemList()
        ->filterPlaceholder('Search exercises...')
        ->noResultsMessage('No exercises found.')
        ->createForm(
            route('exercises.create'),
            'exercise_name',
            ['redirect' => route('workout-templates.edit', $template)]
        );
    
    foreach ($exercises as $exercise) {
        $badge = null;
        $badgeClass = null;
        $priority = 3; // default
        
        // Categorize exercises
        if ($template->exercises->contains($exercise->id)) {
            $badge = 'In Workout';
            $badgeClass = 'in-program';
            $priority = 1;
        } elseif ($exercise->recent_logs_count > 0) {
            $badge = 'Recent';
            $badgeClass = 'recent';
            $priority = 2;
        }
        
        $listBuilder->item(
            'ex-' . $exercise->id,
            $exercise->title,
            route('workout-templates.add-exercise', [$template, $exercise]),
            $badge,
            $badgeClass,
            $priority
        );
    }
    
    $components[] = $listBuilder->build();
    
    return view('mobile-entry.flexible', compact('data'));
}
```

### Food/Ingredient Selection

```php
public function selectIngredient(Request $request)
{
    $date = $request->get('date', today());
    $ingredients = Ingredient::popular()->get();
    
    $components[] = C::itemList()
        ->filterPlaceholder('Search ingredients...')
        ->noResultsMessage('No ingredients found. Try creating a new one!')
        ->createForm(
            route('ingredients.create'),
            'ingredient_name',
            [
                'date' => $date->toDateString(),
                'redirect' => route('mobile-entry.foods', ['date' => $date])
            ],
            'Create "{term}"'
        )
        ->ariaLabel('Select ingredient to log')
        ->initialState('expanded') // Primary action
        ->build();
    
    foreach ($ingredients as $ingredient) {
        $components[0]['data']['items'][] = [
            'id' => 'ing-' . $ingredient->id,
            'title' => $ingredient->name,
            'url' => route('mobile-entry.log-ingredient', [$ingredient, 'date' => $date]),
            'badge' => $ingredient->is_favorite ? 'Favorite' : null,
            'badgeClass' => $ingredient->is_favorite ? 'in-program' : null,
            'priority' => $ingredient->is_favorite ? 1 : 3
        ];
    }
    
    return view('mobile-entry.flexible', compact('data'));
}
```

## CSS Classes and Styling

### Component Structure

```html
<section class="component-list-section" data-initial-state="collapsed">
    <div class="component-header">
        <h2 class="component-heading">Items</h2>
    </div>
    <div class="component-body">
        <div class="component-list-filter">
            <input type="text" placeholder="Search items...">
        </div>
        <div class="component-list-items">
            <a href="#" class="component-list-item">
                <span class="component-list-item-title">Item Name</span>
                <span class="component-list-item-badge in-program">In Program</span>
            </a>
        </div>
        <div class="component-list-create-form" style="display: none;">
            <!-- Create form -->
        </div>
    </div>
</section>
```

### Badge Styling

Badge classes are defined in `public/css/mobile-entry/components/list.css`:

```css
.component-list-item-badge.in-program {
    background-color: #1e3a8a; /* Dark blue */
    color: white;
}

.component-list-item-badge.recent {
    background-color: #059669; /* Green */
    color: white;
}

.component-list-item-badge.regular {
    background-color: #6b7280; /* Gray */
    color: white;
}
```

### State Classes

```css
.component-list-section.active {
    /* List is visible/expanded */
}

.component-button-section.hidden {
    display: none;
}
```

## JavaScript Integration

The item list functionality is handled by `public/js/mobile-entry.js`:

### Key Functions

- `setupItemListToggle()` - Links buttons to lists and handles initial states
- `setupItemListFiltering()` - Real-time search filtering
- `setupCreateButtonBehavior()` - Dynamic create button visibility

### Automatic Loading

JavaScript is automatically included when item list components are detected. No manual script tags needed.

## Accessibility

### ARIA Labels

```php
C::itemList()
    ->ariaLabel('Select exercise to add to workout')
    ->build()
```

### Keyboard Navigation

- Tab navigation through items
- Enter key to select items
- Escape key to close expanded lists
- Arrow keys for item navigation

### Screen Reader Support

- Proper heading hierarchy (`h2` for component titles)
- ARIA labels for interactive elements
- Live region updates for search results
- Form labels and descriptions

## Performance Considerations

### Large Item Lists

For lists with 100+ items:

```php
// Use server-side filtering instead of client-side
C::itemList()
    ->filterPlaceholder('Type to search...')
    ->noResultsMessage('No results. Try a different search term.')
    // Don't load all items at once - use AJAX filtering
    ->build()
```

### Mobile Optimization

- Touch targets are 44px minimum
- Smooth scrolling animations
- Debounced search input (300ms delay)
- Efficient DOM manipulation

## Browser Compatibility

Tested and working in:
- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- Mobile browsers (iOS Safari, Chrome Mobile)

## Backward Compatibility

The item list component is fully backward compatible. To add new features:

```php
// v1.0 (still works)
C::itemList()
    ->item('ex-1', 'Exercise', '#')
    ->build()

// v1.1+ (with new features)
C::itemList()
    ->item('ex-1', 'Exercise', '#', 'Recent', 'recent', 1)
    ->initialState('expanded')
    ->createForm('#', 'name')
    ->build()
```

## Troubleshooting

### List doesn't expand on page load
- Check `data-initial-state="expanded"` in HTML output
- Verify JavaScript console for errors
- Clear browser cache

### Search filtering not working
- Ensure items have proper `data-title` attributes
- Check for JavaScript errors in console
- Verify `setupItemListFiltering()` is called

### Create button not appearing
- Confirm `createForm()` is configured
- Check that search term has no matching results
- Verify form action URL is correct

### Multiple lists interfering
- Ensure button and list are adjacent in components array
- Check unique IDs are generated
- Verify no custom JavaScript conflicts

## Examples and Demos

Live examples available at:
- `/flexible/with-nav` - Default collapsed state
- `/flexible/expanded-list` - Single expanded list
- `/flexible/multiple-lists` - Multiple independent lists
- `/mobile-entry/lifts` - Production exercise selection
- `/mobile-entry/foods` - Production ingredient selection

## Related Components

- [Button Component](component-builder-quick-reference.md#button-component) - For "Add Item" buttons
- [Form Component](component-builder-quick-reference.md#form-component) - For create functionality
- [Messages Component](component-builder-quick-reference.md#messages-component) - For user feedback

---

**Status:** Production Ready ✅  
**Added:** v1.0 (November 10, 2025)  
**Enhanced:** v1.1 (November 11, 2025) - Initial state configuration  
**Last Updated:** v1.5 (December 24, 2025)