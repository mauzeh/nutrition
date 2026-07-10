# Table Component

## Overview

The table component provides a tabular CRUD list optimized for narrow mobile screens. Each row displays up to 3 lines of text with custom action buttons. Supports two-level hierarchical rows with expandable/collapsible sub-items.

## Features

- **Mobile-optimized**: Designed for narrow screens with touch-friendly buttons
- **Multi-line rows**: Support for up to 3 lines of text per row
- **Custom actions**: Flexible action buttons with any icon and color
- **Two-level hierarchy**: Support for sub-items under parent rows
- **Expandable sub-items**: Collapsible sub-items with chevron icon (optional)
- **Responsive**: Adapts to different screen sizes
- **Accessible**: Proper ARIA labels and keyboard navigation
- **Confirmation dialogs**: Optional delete confirmations

## Visual Design

Each row consists of:
- **Expand button** (optional): Chevron icon to expand/collapse sub-items
- **Line 1**: Bold, primary text (e.g., item name)
- **Line 2**: Secondary text (e.g., description, metadata)
- **Line 3**: Muted, italic text (e.g., additional details)
- **Action buttons**: Custom buttons with any icon and color

Sub-items appear below parent rows with:
- Darker background for visual hierarchy
- Minimal left padding for space efficiency
- Same structure as parent rows (3 lines + actions)
- Optional expand/collapse functionality

## Usage

### Basic Example with Custom Actions

```php
use App\Services\ComponentBuilder as C;

$components[] = C::table()
    ->row(1, 'Item Name', 'Description', 'Details')
        ->linkAction('fa-edit', '/edit/1', 'Edit')
        ->formAction('fa-trash', '/delete/1', 'DELETE', [], 'Delete', 'btn-danger', true)
        ->add()
    ->row(2, 'Another Item', 'More info', null)
        ->linkAction('fa-edit', '/edit/2', 'Edit')
        ->formAction('fa-trash', '/delete/2', 'DELETE', [], 'Delete', 'btn-danger', true)
        ->add()
    ->emptyMessage('No items yet.')
    ->build();
```

### With Sub-Items (Expandable)

```php
$components[] = C::table()
    ->row(1, 'Workout Template', '3 exercises', 'Upper body routine')
        ->linkAction('fa-edit', '/edit/1', 'Edit')
        ->formAction('fa-trash', '/delete/1', 'DELETE', [], 'Delete', 'btn-danger', true)
        ->subItem(11, 'Bench Press', '4 sets × 8 reps', '185 lbs')
            ->linkAction('fa-play', '/log/11', 'Log now', 'btn-log-now')
            ->formAction('fa-trash', '/remove/11', 'DELETE', [], 'Remove', 'btn-danger', true)
            ->add()
        ->subItem(12, 'Rows', '4 sets × 10 reps', '135 lbs')
            ->linkAction('fa-play', '/log/12', 'Log now', 'btn-log-now')
            ->formAction('fa-trash', '/remove/12', 'DELETE', [], 'Remove', 'btn-danger', true)
            ->add()
        ->add()
    ->confirmMessage('deleteItem', 'Are you sure?')
    ->build();
```

### With Non-Collapsible Sub-Items

```php
$components[] = C::table()
    ->row(1, 'Quick Stretches', 'Always visible', null)
        ->linkAction('fa-edit', '/edit/1', 'Edit')
        ->subItem(11, 'Neck Rolls', '2 minutes', null)
            ->linkAction('fa-play', '/log/11', 'Log now', 'btn-log-now')
            ->add()
        ->collapsible(false) // Sub-items always visible, no expand button
        ->add()
    ->build();
```

### Complete Controller Example

```php
public function workouts(Request $request)
{
    $workouts = Workout::with('exercises')->where('user_id', Auth::id())->get();
    
    $tableBuilder = C::table()
        ->emptyMessage('No workouts yet. Create your first routine!')
        ->confirmMessage('deleteItem', 'Are you sure you want to delete this workout?')
        ->ariaLabel('Workout routines');
    
    foreach ($workouts as $workout) {
        $rowBuilder = $tableBuilder->row(
            $workout->id,
            $workout->name,
            $workout->exercises->count() . ' exercises',
            'Last: ' . $workout->last_completed_at?->diffForHumans()
        )
        ->linkAction('fa-edit', route('workouts.edit', $workout->id), 'Edit')
        ->formAction('fa-trash', route('workouts.destroy', $workout->id), 'DELETE', 
            ['redirect' => 'workouts'], 'Delete', 'btn-danger', true);
        
        // Add exercises as sub-items
        foreach ($workout->exercises as $exercise) {
            $rowBuilder->subItem(
                $exercise->id,
                $exercise->name,
                $exercise->sets . ' sets × ' . $exercise->reps . ' reps',
                null
            )
            ->linkAction('fa-play', route('exercises.log', $exercise->id), 'Log now', 'btn-log-now')
            ->formAction('fa-trash', route('workouts.remove-exercise', [$workout->id, $exercise->id]), 
                'DELETE', [], 'Remove', 'btn-danger', true)
            ->add();
        }
        
        $rowBuilder->add();
    }
    
    $data = [
        'components' => [
            C::title('My Workouts')->build(),
            $tableBuilder->build(),
            C::button('Add New Workout')
                ->ariaLabel('Create a new workout routine')
                ->build(),
        ]
    ];
    
    return view('mobile-entry.flexible', compact('data'));
}
```

## API Reference

### TableComponentBuilder Methods

#### `row($id, $line1, $line2 = null, $line3 = null)`
Add a row to the table with custom actions.

**Parameters:**
- `int $id` - Row identifier
- `string $line1` - First line (bold, primary text) - **Required**
- `string|null $line2` - Second line (secondary text) - Optional
- `string|null $line3` - Third line (muted, italic text) - Optional

**Returns:** `TableRowBuilder` for chaining actions and sub-items

#### `emptyMessage($message)`
Set the message displayed when no rows exist.

**Parameters:**
- `string $message` - Empty state message

**Returns:** `self`

#### `confirmMessage($key, $message)`
Add a confirmation dialog for delete actions.

**Parameters:**
- `string $key` - Message key (typically 'deleteItem')
- `string $message` - Confirmation message text

**Returns:** `self`

#### `ariaLabel($label)`
Set the ARIA label for the table section.

**Parameters:**
- `string $label` - Accessibility label

**Returns:** `self`

#### `build()`
Build the component and return the array structure.

**Returns:** `array` with keys `type` and `data`

### TableRowBuilder Methods

#### `linkAction($icon, $url, $ariaLabel = null, $cssClass = null)`
Add a link action button (GET request).

**Parameters:**
- `string $icon` - FontAwesome icon class (e.g., 'fa-edit', 'fa-play')
- `string $url` - Action URL
- `string|null $ariaLabel` - Accessibility label
- `string|null $cssClass` - Additional CSS classes (e.g., 'btn-log-now')

**Returns:** `self`

#### `formAction($icon, $url, $method = 'POST', $params = [], $ariaLabel = null, $cssClass = null, $requiresConfirm = false)`
Add a form action button (POST/DELETE request).

**Parameters:**
- `string $icon` - FontAwesome icon class (e.g., 'fa-trash')
- `string $url` - Action URL
- `string $method` - HTTP method ('POST', 'DELETE', etc.)
- `array $params` - Hidden form parameters (e.g., ['redirect' => 'home'])
- `string|null $ariaLabel` - Accessibility label
- `string|null $cssClass` - Additional CSS classes (e.g., 'btn-danger')
- `bool $requiresConfirm` - Show confirmation dialog before submit

**Returns:** `self`

#### `subItem($id, $line1, $line2 = null, $line3 = null)`
Add a sub-item to this row.

**Parameters:**
- `int $id` - Sub-item identifier
- `string $line1` - First line (bold, primary text) - **Required**
- `string|null $line2` - Second line (secondary text) - Optional
- `string|null $line3` - Third line (muted, italic text) - Optional

**Returns:** `TableSubItemBuilder` for chaining sub-item actions

#### `collapsible($collapsible = true)`
Set whether sub-items should be collapsible (default: true).

**Parameters:**
- `bool $collapsible` - If false, sub-items are always visible with no expand button

**Returns:** `self`

#### `add()`
Finish configuring the row and return to the parent table builder.

**Returns:** `TableComponentBuilder`

### TableSubItemBuilder Methods

#### `linkAction($icon, $url, $ariaLabel = null, $cssClass = null)`
Add a link action button to the sub-item. Same parameters as TableRowBuilder.

**Returns:** `self`

#### `formAction($icon, $url, $method = 'POST', $params = [], $ariaLabel = null, $cssClass = null, $requiresConfirm = false)`
Add a form action button to the sub-item. Same parameters as TableRowBuilder.

**Returns:** `self`

#### `add()`
Finish configuring the sub-item and return to the parent row builder.

**Returns:** `TableRowBuilder`

## Styling

The table component uses these CSS classes:

- `.component-table-section` - Container section
- `.component-table` - Table wrapper
- `.component-table-row` - Individual row
- `.component-table-row.has-subitems` - Row with sub-items
- `.btn-table-expand` - Expand/collapse button (chevron icon)
- `.btn-table-expand.expanded` - Expanded state (rotated chevron)
- `.component-table-cell` - Cell containing text lines
- `.cell-title` - First line (bold)
- `.cell-content` - Second line (secondary)
- `.cell-detail` - Third line (muted, italic)
- `.component-table-actions` - Action buttons container
- `.btn-table-edit` - Action button (customizable with CSS classes)
- `.btn-table-delete` - Delete button
- `.btn-log-now` - Green "Log now" button variant
- `.component-table-subitems` - Sub-items container
- `.component-table-subitem` - Individual sub-item
- `.component-table-empty` - Empty state message

## Responsive Behavior

### Desktop (> 768px)
- Full padding and spacing
- Larger touch targets
- Hover effects enabled

### Tablet (480px - 768px)
- Reduced padding
- Adjusted font sizes
- Maintained touch targets

### Mobile (< 480px)
- Minimal padding for maximum content
- Smaller but still accessible buttons
- Optimized for one-handed use

## Accessibility

- Proper ARIA labels for screen readers
- Keyboard navigation support
- Focus indicators on all interactive elements
- Sufficient color contrast
- Touch targets meet minimum size requirements (44x44px)

## JavaScript Integration

The table component integrates with mobile-entry.js for two features:

### Delete Confirmations
```javascript
// Automatically handles delete confirmations
// Reads messages from data-table-confirm-messages attribute
// Shows browser confirm() dialog before submitting delete form
```

### Expand/Collapse Sub-Items
```javascript
// setupTableExpand() function handles expand/collapse
// Toggles visibility of sub-items container
// Rotates chevron icon 90 degrees when expanded
// Updates aria-label for accessibility
```

The expand/collapse state is not persisted - sub-items are hidden by default on page load unless `collapsible(false)` is set.

## Best Practices

1. **Keep line 1 concise** - This is the primary identifier, keep it short
2. **Use line 2 for metadata** - Duration, frequency, counts, etc.
3. **Line 3 is optional** - Only use if you have additional context
4. **Provide empty messages** - Always set a helpful empty state message
5. **Use confirmation dialogs** - Prevent accidental deletions with `requiresConfirm: true`
6. **Add form params** - Include redirect URLs or context data in formAction params
7. **Set ARIA labels** - Improve accessibility for screen readers
8. **Use appropriate icons** - fa-edit for edit, fa-trash for delete, fa-play for log/start
9. **Color code actions** - Use btn-log-now (green) for positive actions, btn-danger (red) for destructive
10. **Limit sub-item depth** - Only one level of sub-items is supported
11. **Consider collapsible** - Use `collapsible(false)` for short lists that should always be visible
12. **Order actions logically** - Place most common action first (left to right)

## Examples

### Simple List
```php
C::table()
    ->row(1, 'Task 1', null, null)
        ->linkAction('fa-edit', '/edit/1', 'Edit')
        ->formAction('fa-trash', '/delete/1', 'DELETE', [], 'Delete', 'btn-danger', true)
        ->add()
    ->row(2, 'Task 2', null, null)
        ->linkAction('fa-edit', '/edit/2', 'Edit')
        ->formAction('fa-trash', '/delete/2', 'DELETE', [], 'Delete', 'btn-danger', true)
        ->add()
    ->emptyMessage('No tasks.')
    ->build()
```

### With Custom Action Colors
```php
C::table()
    ->row(1, 'Exercise', '3 sets × 10 reps', '135 lbs')
        ->linkAction('fa-play', '/log/1', 'Log now', 'btn-log-now') // Green button
        ->linkAction('fa-edit', '/edit/1', 'Edit') // Orange button
        ->formAction('fa-trash', '/delete/1', 'DELETE', [], 'Delete', 'btn-danger', true) // Red button
        ->add()
    ->build()
```

### With Reorder Buttons
```php
C::table()
    ->row(1, 'First Item', 'Priority: 1', null)
        ->linkAction('fa-arrow-up', '#', 'Move up', 'btn-disabled') // Disabled at top
        ->linkAction('fa-arrow-down', '/move/1/down', 'Move down')
        ->formAction('fa-trash', '/delete/1', 'DELETE', [], 'Delete', 'btn-danger', true)
        ->add()
    ->row(2, 'Second Item', 'Priority: 2', null)
        ->linkAction('fa-arrow-up', '/move/2/up', 'Move up')
        ->linkAction('fa-arrow-down', '#', 'Move down', 'btn-disabled') // Disabled at bottom
        ->formAction('fa-trash', '/delete/2', 'DELETE', [], 'Delete', 'btn-danger', true)
        ->add()
    ->build()
```

### With All Features (Hierarchical)
```php
C::table()
    ->row(1, 'Workout Template', '3 exercises', 'Upper body')
        ->linkAction('fa-edit', '/edit/1', 'Edit')
        ->formAction('fa-trash', '/delete/1', 'DELETE', ['redirect' => 'templates'], 'Delete', 'btn-danger', true)
        ->subItem(11, 'Bench Press', '4 sets × 8 reps', '185 lbs')
            ->linkAction('fa-play', '/log/11', 'Log now', 'btn-log-now')
            ->linkAction('fa-arrow-up', '#', 'Move up', 'btn-disabled')
            ->linkAction('fa-arrow-down', '/move/11/down', 'Move down')
            ->formAction('fa-trash', '/remove/11', 'DELETE', [], 'Remove', 'btn-danger', true)
            ->add()
        ->subItem(12, 'Rows', '4 sets × 10 reps', '135 lbs')
            ->linkAction('fa-play', '/log/12', 'Log now', 'btn-log-now')
            ->linkAction('fa-arrow-up', '/move/12/up', 'Move up')
            ->linkAction('fa-arrow-down', '/move/12/down', 'Move down')
            ->formAction('fa-trash', '/remove/12', 'DELETE', [], 'Remove', 'btn-danger', true)
            ->add()
        ->add()
    ->emptyMessage('No templates found.')
    ->confirmMessage('deleteItem', 'Delete this item permanently?')
    ->ariaLabel('Workout templates')
    ->build()
```

## Testing

When testing table components:

```php
// Access table component
$tableComponent = collect($data['components'])
    ->where('type', 'table')
    ->first();

// Check rows
$this->assertCount(3, $tableComponent['data']['rows']);

// Check first row
$firstRow = $tableComponent['data']['rows'][0];
$this->assertEquals('Item Name', $firstRow['line1']);
$this->assertEquals('Description', $firstRow['line2']);
$this->assertEquals('Details', $firstRow['line3']);

// Check empty message
$this->assertEquals('No items yet.', $tableComponent['data']['emptyMessage']);
```

## Demo

Visit `/flexible/table-example` to see a working demo of the table component.

---

**Component Type:** `table`  
**View File:** `resources/views/mobile-entry/components/table.blade.php`  
**Builder Class:** `TableComponentBuilder`  
**CSS Classes:** `.component-table-*`
