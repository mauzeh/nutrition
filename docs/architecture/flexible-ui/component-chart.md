# Chart Component Documentation

## Overview

The Chart component provides a native, type-safe way to integrate Chart.js charts into the flexible component system without relying on raw HTML.

## Features

- **Native Component Builder API** - Clean, fluent interface for building charts
- **Automatic Library Loading** - Chart.js and date adapter loaded on-demand
- **Type Safety** - Full PHP type hints and IDE autocomplete support
- **Helper Methods** - Common configurations like time scales, legends, axis labels
- **Accessibility** - Built-in ARIA labels and semantic HTML
- **Performance** - Libraries loaded once and cached across multiple charts

## Basic Usage

```php
use App\Services\ComponentBuilder as C;

$components[] = C::chart('myChart', 'Sales Over Time')
    ->type('line')
    ->datasets($chartData['datasets'])
    ->timeScale('day')
    ->beginAtZero()
    ->showLegend()
    ->build();
```

## API Reference

### Creating a Chart

```php
ComponentBuilder::chart(string $canvasId, string $title)
```

**Parameters:**
- `$canvasId` - Unique ID for the canvas element
- `$title` - Chart title displayed above the chart

### Chart Types

```php
->type(string $type)
```

Supported types: `line`, `bar`, `pie`, `doughnut`, `radar`, `polarArea`, `bubble`, `scatter`

### Datasets

```php
->datasets(array $datasets)
```

Pass Chart.js dataset format:

```php
$datasets = [
    [
        'label' => 'Weight (lbs)',
        'data' => [
            ['x' => '2024-11-01', 'y' => 135],
            ['x' => '2024-11-03', 'y' => 140],
            // ...
        ],
        'borderColor' => 'rgb(75, 192, 192)',
        'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
        'tension' => 0.1,
    ]
]
```

### Helper Methods

#### Time Scale Configuration

```php
->timeScale(string $unit = 'day', ?string $displayFormat = null)
```

Configures X-axis as a time scale. Common units: `day`, `week`, `month`, `year`

#### Y-Axis Configuration

```php
->beginAtZero(bool $value = true)
```

Forces Y-axis to start at zero.

```php
->yAxisLabel(string $label)
```

Sets Y-axis label.

#### X-Axis Configuration

```php
->xAxisLabel(string $label)
```

Sets X-axis label.

#### Legend

```php
->showLegend(bool $value = true)
```

Shows or hides the chart legend.

#### Styling

```php
->height(int $pixels)
```

Sets canvas height in pixels.

```php
->containerClass(string $class)
```

Sets container CSS class (default: `form-container`).

```php
->noAspectRatio()
```

Disables aspect ratio maintenance for custom sizing.

#### Accessibility

```php
->ariaLabel(string $label)
```

Sets ARIA label for screen readers.

### Advanced Options

```php
->options(array $options)
```

Pass any Chart.js options object. This merges with existing options.

```php
->options([
    'plugins' => [
        'tooltip' => [
            'mode' => 'index',
            'intersect' => false,
        ]
    ]
])
```

## Complete Example

```php
public function showLogs(Exercise $exercise)
{
    $liftLogs = $exercise->liftLogs()
        ->where('user_id', auth()->id())
        ->orderBy('logged_at', 'desc')
        ->get();

    $chartData = $this->chartService->generateProgressChart($liftLogs, $exercise);

    $components = [];
    
    // Add chart
    if (!empty($chartData['datasets'])) {
        $components[] = ComponentBuilder::chart('progressChart', 'Progress Over Time')
            ->type('line')
            ->datasets($chartData['datasets'])
            ->timeScale('day')
            ->beginAtZero()
            ->showLegend()
            ->yAxisLabel('Weight (lbs)')
            ->ariaLabel('Exercise progress chart showing weight over time')
            ->build();
    }
    
    // Add table
    $components[] = ComponentBuilder::table()
        ->rows($rows)
        ->build();
    
    return view('mobile-entry.flexible', ['data' => ['components' => $components]]);
}
```

## Implementation Details

### Files

- **Builder:** `app/Services/ComponentBuilder.php` - `ChartComponentBuilder` class
- **View:** `resources/views/mobile-entry/components/chart.blade.php`
- **JavaScript:** `public/js/chart-component.js`

### How It Works

1. **Component Building** - Controller uses fluent API to build chart configuration
2. **View Rendering** - Blade component renders canvas with data attributes
3. **Script Loading** - JavaScript automatically loads Chart.js libraries on demand
4. **Chart Initialization** - Script reads data attributes and creates Chart.js instance

### Library Loading

The chart component automatically loads:
- Chart.js (v4.x) from CDN
- chartjs-adapter-date-fns for time scale support

Libraries are loaded once per page and cached for subsequent charts.

## Upgrading from Raw HTML

**Before:**
```php
C::rawHtml('
    <div class="form-container">
        <h3>My Chart</h3>
        <canvas id="chart"></canvas>
    </div>
    <script src="...chart.js"></script>
    <script>
        new Chart(ctx, { ... });
    </script>
')
```

**After:**
```php
C::chart('chart', 'My Chart')
    ->type('line')
    ->datasets($data)
    ->timeScale('day')
    ->beginAtZero()
    ->showLegend()
    ->build()
```

## Examples

See working examples at:
- `/labs/chart-example` - Standalone chart demo
- `/exercises/{id}/logs` - Real-world usage in exercise logs

## Browser Support

Requires modern browsers with ES6 support. Chart.js v4.x requirements apply.
