# Tabs Component

The tabs component creates tabbed interfaces where each tab can contain any combination of other components. Perfect for organizing related functionality like forms and analytics, or breaking complex workflows into manageable sections.

## Quick Start

```php
use App\Services\ComponentBuilder as C;

// Create components for each tab
$logComponents = [
    C::form('workout-log', 'Log Workout')->build(),
    C::summary()->item('streak', '12 days', 'Current Streak')->build(),
];

$historyComponents = [
    C::chart('progress-chart', 'Progress')->build(),
    C::table()->row(1, 'Recent Workout', 'Details')->build(),
];

// Create tabbed interface
$component = C::tabs('workout-tabs')
    ->tab('log', 'Log Workout', $logComponents, 'fa-plus', true)
    ->tab('history', 'History', $historyComponents, 'fa-chart-line')
    ->build();
```

## Features

### ✅ Accessibility First
- Full ARIA support with proper roles and labels
- Keyboard navigation (arrow keys, home, end)
- Screen reader compatible
- Focus management

### ✅ Mobile Optimized
- Responsive tab navigation with horizontal scrolling
- Touch-friendly 44px minimum targets
- Icons hidden on very small screens
- Smooth animations and transitions

### ✅ Flexible Content
- Each tab can contain any combination of components
- No restrictions on component types or count
- Dynamic content loading support
- Custom event system for integration

### ✅ Developer Friendly
- Fluent API with method chaining
- Type-safe builder pattern
- Automatic script loading
- Custom styling support

## API Reference

### Basic Usage

```php
C::tabs($id)
    ->tab($id, $label, $components, $icon, $active)
    ->activeTab($tabId)
    ->ariaLabels($labels)
    ->build()
```

### Methods

| Method | Parameters | Description |
|--------|-----------|-------------|
| `tab()` | `$id, $label, $components, $icon, $active` | Add a tab with content |
| `activeTab()` | `string $tabId` | Set active tab (alternative to active parameter) |
| `ariaLabels()` | `array $labels` | Customize accessibility labels |
| `build()` | - | Build the component |

### Tab Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | string | ✅ | Unique identifier for the tab |
| `label` | string | ✅ | Display text for the tab button |
| `components` | array | ✅ | Array of built component data |
| `icon` | string | ❌ | FontAwesome icon class (e.g., 'fa-plus') |
| `active` | bool | ❌ | Whether this tab should be active by default |

## Examples

### Example 1: Lift Logging with History

```php
public function tabbedLiftLogger(Request $request)
{
    // Sample chart data
    $chartData = [
        'datasets' => [
            [
                'label' => 'Working Weight (lbs)',
                'data' => [
                    ['x' => '2024-11-01', 'y' => 135],
                    ['x' => '2024-11-05', 'y' => 140],
                    ['x' => '2024-11-08', 'y' => 145],
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
            ->item('volume', '4,440 lbs', 'Total Volume')
            ->build(),
    ];
    
    // History tab components
    $historyComponents = [
        C::chart('bench-progress-chart', 'Bench Press Progress')
            ->type('line')
            ->datasets($chartData['datasets'])
            ->timeScale('day')
            ->beginAtZero()
            ->showLegend()
            ->build(),
        
        C::table()
            ->row(1, 'Nov 26, 2024', '170 lbs × 5 reps × 3 sets', '1RM: 227 lbs')
                ->badge('Today', 'success')
                ->badge('170 lbs', 'dark', true)
                ->badge('PR!', 'success')
                ->linkAction('fa-edit', route('edit'), 'Edit')
                ->add()
            ->row(2, 'Nov 22, 2024', '165 lbs × 6 reps × 3 sets', '1RM: 220 lbs')
                ->badge('4 days ago', 'info')
                ->badge('165 lbs', 'dark', true)
                ->linkAction('fa-edit', route('edit'), 'Edit')
                ->add()
            ->spacedRows()
            ->build(),
    ];
    
    $data = [
        'components' => [
            C::title('Bench Press Tracker', 'Log workouts and view progress')
                ->backButton('fa-arrow-left', route('exercises.index'), 'Back')
                ->build(),
            
            C::messages()
                ->success('Great progress! You\'ve increased 35 lbs this month.')
                ->info('This demonstrates a tabbed interface with form and chart components.')
                ->tip('Use arrow keys to navigate between tabs', 'Accessibility:')
                ->build(),
            
            C::tabs('lift-tracker-tabs')
                ->tab('log', 'Log Lift', $logComponents, 'fa-plus', true)
                ->tab('history', 'History', $historyComponents, 'fa-chart-line')
                ->ariaLabels([
                    'section' => 'Lift tracking interface',
                    'tabList' => 'Switch between logging and history views',
                    'tabPanel' => 'Content for selected tab'
                ])
                ->build(),
        ],
    ];
    
    return view('mobile-entry.flexible', compact('data'));
}
```

### Example 2: Settings Tabs

```php
public function userSettings()
{
    $profileComponents = [
        C::form('profile-form', 'Profile Information')
            ->type('primary')
            ->textField('name', 'Full Name:', auth()->user()->name)
            ->textField('email', 'Email:', auth()->user()->email)
            ->submitButton('Save Profile')
            ->build(),
    ];
    
    $preferencesComponents = [
        C::form('preferences-form', 'Preferences')
            ->type('secondary')
            ->selectField('theme', 'Theme:', [
                ['value' => 'light', 'label' => 'Light'],
                ['value' => 'dark', 'label' => 'Dark'],
            ], 'dark')
            ->checkboxField('notifications', 'Email Notifications', true)
            ->submitButton('Save Preferences')
            ->build(),
    ];
    
    $securityComponents = [
        C::form('security-form', 'Security Settings')
            ->type('warning')
            ->passwordField('current_password', 'Current Password')
            ->passwordField('new_password', 'New Password')
            ->passwordField('confirm_password', 'Confirm Password')
            ->submitButton('Update Password')
            ->build(),
    ];
    
    $data = [
        'components' => [
            C::title('Account Settings')->build(),
            
            C::tabs('settings-tabs')
                ->tab('profile', 'Profile', $profileComponents, 'fa-user', true)
                ->tab('preferences', 'Preferences', $preferencesComponents, 'fa-cog')
                ->tab('security', 'Security', $securityComponents, 'fa-lock')
                ->build(),
        ],
    ];
    
    return view('mobile-entry.flexible', compact('data'));
}
```

### Example 3: Multi-Step Workflow

```php
public function workoutBuilder()
{
    $exerciseComponents = [
        C::messages()->info('Select exercises for your workout')->build(),
        C::itemList()
            ->item('ex-1', 'Bench Press', '#', 'Compound', 'in-program', 1)
            ->item('ex-2', 'Squats', '#', 'Compound', 'in-program', 1)
            ->filterPlaceholder('Search exercises...')
            ->build(),
    ];
    
    $setsComponents = [
        C::messages()->info('Configure sets and reps for each exercise')->build(),
        C::form('sets-form', 'Exercise Configuration')
            ->numericField('sets', 'Sets:', 3, 1, 1, 10)
            ->numericField('reps', 'Reps:', 10, 1, 1, 50)
            ->build(),
    ];
    
    $reviewComponents = [
        C::messages()->success('Review your workout before saving')->build(),
        C::table()
            ->row(1, 'Bench Press', '3 sets × 10 reps', '135 lbs')
            ->row(2, 'Squats', '3 sets × 8 reps', '185 lbs')
            ->build(),
        C::form('save-workout', 'Save Workout')
            ->textField('name', 'Workout Name:', 'Push Day')
            ->submitButton('Save Workout')
            ->build(),
    ];
    
    $data = [
        'components' => [
            C::title('Workout Builder', 'Create a custom workout')->build(),
            
            C::tabs('workout-builder-tabs')
                ->tab('exercises', 'Exercises', $exerciseComponents, 'fa-dumbbell', true)
                ->tab('sets', 'Sets & Reps', $setsComponents, 'fa-list-ol')
                ->tab('review', 'Review', $reviewComponents, 'fa-check')
                ->build(),
        ],
    ];
    
    return view('mobile-entry.flexible', compact('data'));
}
```

## Accessibility

### ARIA Labels

Customize accessibility labels for better screen reader support:

```php
C::tabs('my-tabs')
    ->tab('first', 'First Tab', $components)
    ->ariaLabels([
        'section' => 'Main content tabs',
        'tabList' => 'Navigate between content sections',
        'tabPanel' => 'Content for the selected section'
    ])
    ->build()
```

### Keyboard Navigation

| Key | Action |
|-----|--------|
| `←` / `→` | Navigate between tabs |
| `Home` | Go to first tab |
| `End` | Go to last tab |
| `Tab` | Move focus to tab content |
| `Enter` / `Space` | Activate focused tab |

### Screen Reader Support

- Proper `role="tablist"` and `role="tab"` attributes
- `aria-selected` states for active tabs
- `aria-controls` and `aria-labelledby` relationships
- Hidden inactive panels with `hidden` attribute

## Styling

### CSS Classes

| Class | Element | Purpose |
|-------|---------|---------|
| `.component-tabs-section` | Container | Main tabs wrapper |
| `.tabs-nav` | Navigation | Tab button container |
| `.tab-button` | Button | Individual tab buttons |
| `.tab-button.active` | Button | Active tab styling |
| `.tabs-content` | Container | Tab panel container |
| `.tab-panel` | Panel | Individual tab content |
| `.tab-panel.active` | Panel | Active panel styling |

### Custom Styling

```css
/* Custom tab button colors */
.tab-button.active {
    color: #your-brand-color;
    border-bottom-color: #your-brand-color;
}

/* Custom animations */
.tab-panel {
    transition: opacity 0.3s ease-in-out;
}
```

## JavaScript Integration

### Custom Events

Listen for tab changes in your JavaScript:

```javascript
document.addEventListener('tabChanged', function(e) {
    console.log('Switched to tab:', e.detail.tabId);
    console.log('Container:', e.detail.container);
    
    // Trigger chart redraws, form validations, etc.
    if (e.detail.tabId === 'history') {
        // Redraw charts when history tab is shown
        window.dispatchEvent(new Event('resize'));
    }
});
```

### Manual Tab Switching

```javascript
// Switch to a specific tab programmatically
const container = document.querySelector('[data-tabs-id="my-tabs"]');
window.TabsComponent.switchTab(container, 'history');
```

## Performance Considerations

### Lazy Loading

For tabs with heavy content (charts, large tables), consider lazy loading:

```php
// Only load chart data when history tab is accessed
$historyComponents = [];
if ($request->get('tab') === 'history') {
    $historyComponents[] = C::chart('heavy-chart', 'Data')
        ->datasets($expensiveChartData)
        ->build();
} else {
    $historyComponents[] = C::messages()
        ->info('Chart will load when tab is selected')
        ->build();
}
```

### Script Loading

The tabs component automatically loads required JavaScript:

```php
// Automatically includes public/js/mobile-entry/tabs.js
C::tabs('my-tabs')->build()
```

## Common Patterns

### Form + Analytics

Perfect for interfaces where users need to input data and view historical trends:

```php
C::tabs('data-tabs')
    ->tab('input', 'Log Data', [$formComponent, $summaryComponent], 'fa-plus', true)
    ->tab('analytics', 'View Trends', [$chartComponent, $tableComponent], 'fa-chart-line')
    ->build()
```

### Settings Organization

Group related settings into logical tabs:

```php
C::tabs('settings-tabs')
    ->tab('general', 'General', $generalSettings, 'fa-cog', true)
    ->tab('notifications', 'Notifications', $notificationSettings, 'fa-bell')
    ->tab('privacy', 'Privacy', $privacySettings, 'fa-shield-alt')
    ->build()
```

### Multi-Step Workflows

Break complex processes into manageable steps:

```php
C::tabs('wizard-tabs')
    ->tab('step1', 'Step 1', $step1Components, 'fa-user', true)
    ->tab('step2', 'Step 2', $step2Components, 'fa-cog')
    ->tab('step3', 'Step 3', $step3Components, 'fa-check')
    ->build()
```

## Testing

### Component Structure

```php
// Test tab structure
$component = C::tabs('test-tabs')
    ->tab('first', 'First Tab', [])
    ->tab('second', 'Second Tab', [])
    ->build();

$this->assertEquals('tabs', $component['type']);
$this->assertCount(2, $component['data']['tabs']);
$this->assertEquals('first', $component['data']['activeTab']);
```

### Integration Testing

```php
// Test in controller
$response = $this->get('/tabbed-interface');
$response->assertOk();

$data = $response->viewData('data');
$tabsComponents = collect($data['components'])->where('type', 'tabs');
$this->assertCount(1, $tabsComponents);
```

## Files

- **Builder**: `app/Services/Components/Interactive/TabsComponentBuilder.php`
- **Template**: `resources/views/mobile-entry/components/tabs.blade.php`
- **CSS**: `public/css/mobile-entry/components/tabs.css`
- **JavaScript**: `public/js/mobile-entry/tabs.js`

## Browser Support

- **Modern Browsers**: Full support with all features
- **IE11**: Basic functionality (no CSS Grid, reduced animations)
- **Mobile**: Optimized for iOS Safari and Chrome Mobile
- **Screen Readers**: NVDA, JAWS, VoiceOver compatible

## Migration from Hardcoded Tabs

If you have existing hardcoded tab implementations:

```php
// Old hardcoded approach
$data = [
    'activeTab' => 'log',
    'logContent' => $logData,
    'historyContent' => $historyData,
];

// New component approach
$data = [
    'components' => [
        C::tabs('interface-tabs')
            ->tab('log', 'Log', $logComponents, 'fa-plus', true)
            ->tab('history', 'History', $historyComponents, 'fa-chart-line')
            ->build(),
    ]
];
```

## Best Practices

1. **Meaningful Tab Labels**: Use clear, descriptive labels
2. **Logical Ordering**: Place most important/frequently used tabs first
3. **Icon Consistency**: Use consistent icon styles across tabs
4. **Content Organization**: Group related functionality within tabs
5. **Performance**: Consider lazy loading for heavy content
6. **Accessibility**: Always provide proper ARIA labels
7. **Mobile First**: Test on mobile devices for usability

## Troubleshooting

### Tabs Not Switching

Check that JavaScript is loading:
```html
<!-- Should be automatically included -->
<script src="/js/mobile-entry/tabs.js"></script>
```

### Content Not Showing

Ensure components are properly built:
```php
// Wrong - missing build()
$components = [C::form('test', 'Test')];

// Correct - with build()
$components = [C::form('test', 'Test')->build()];
```

### Accessibility Issues

Verify ARIA labels are set:
```php
C::tabs('my-tabs')
    ->ariaLabels([
        'section' => 'Content tabs',
        'tabList' => 'Navigate content',
        'tabPanel' => 'Tab content'
    ])
    ->build()
```