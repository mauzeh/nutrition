# WOD System: Syntax & Code Editor Guide

## Overview

The WOD (Workout of the Day) system allows you to create structured workouts using a simple text-based syntax. Workouts are written in a code editor with syntax highlighting, line numbers, and autocomplete for exercise names.

## WOD Syntax

### Blocks

Workouts are organized into blocks. Each block starts with a header:

```
# Block Name
```

You can use 1-3 hash marks (`#`, `##`, `###`) - they all work the same way.

**Examples:**
```
# Strength
# Block 1: Warm-up
## Conditioning
### Accessory Work
```

### Exercises

Exercises are written with brackets around the name, optionally followed by a scheme or description.

**Important:** 
- **Brackets `[...]`** = Exercises that can be logged by users
- All bracketed exercises will be matched to your exercise library using fuzzy matching

**Format Options:**

1. **Sets x Reps**: `3x8` or `3 x 8`
   ```
   [Bench Press] 3x8
   [Bench Press]: 3x8        // Also works with colon
   [Warm-up Push-ups] 2x10
   ```

2. **Rep Ladder**: `5-5-5-3-3-1`
   ```
   [Back Squat] 5-5-5-5-5
   [Deadlift]: 5-3-1-1-1     // Colon optional
   ```

3. **Rep Range**: `3x8-12`
   ```
   [Dumbbell Row] 3x8-12
   [Face Pulls]: 3x15-20
   ```

4. **Freeform Text**: Any text after the exercise name
   ```
   [Back Squat] 5 reps, building
   [Deadlift] work up to heavy single
   [Stretching] 5 minutes
   [Mobility Work] as needed
   ```

5. **Single Set**: Just a number
   ```
   [Max Deadlift] 1
   ```

6. **Time/Distance**: `500m`, `5min`, `2km`, `30sec`
   ```
   [Row] 500m
   [Run]: 5min
   [Bike] 2km
   [Plank] 30sec
   ```

7. **Time Format**: `2:00` (minutes:seconds)
   ```
   [L-Sit Hold] 0:30
   ```

**Note:** The colon (`:`) is completely optional. All these formats work:
- `[Exercise] 3x8` ✓
- `[Exercise]: 3x8` ✓
- `[Exercise] any text here` ✓
- `[Exercise]: any text here` ✓

### Special Formats

#### AMRAP (As Many Rounds As Possible)

```
AMRAP 12min:
10 [Box Jumps]
15 [Push-ups]
20 [Air Squats]
```

#### EMOM (Every Minute On the Minute)

```
EMOM 16min:
5 [Pull-ups]
10 [Push-ups]
```

#### For Time

```
For Time:
100 [Wall Balls]
75 [Kettlebell Swings]
50 [Box Jumps]
```

Or with rep scheme:

```
21-15-9 For Time:
[Thrusters]
[Pull-ups]
```

#### Rounds

```
5 Rounds:
10 [Push-ups]
20 [Squats]
30 [Sit-ups]
```

### Comments

Add comments using `//` or `--`:

```
# Strength
// Focus on form today
[Back Squat] 5x5
-- Keep rest periods to 2 minutes
```

Comments are ignored when parsing.

### Complete Examples

#### CrossFit Style

```
# Strength
[Back Squat] 5-5-3-3-1-1

# Metcon
21-15-9 For Time:
[Thrusters]
[Pull-ups]
```

#### Bodybuilding Style

```
# Chest & Triceps
[Bench Press] 4x8
[Incline Dumbbell Press] 3x10-12
[Cable Flyes] 3x15

# Triceps
[Skull Crushers] 3x12
[Rope Pushdowns] 3x15
```

#### Functional Fitness

```
# Warm-up
Row 500m easy pace
Dynamic stretching 5 minutes

# WOD
AMRAP 20min:
5 [Pull-ups]
10 [Push-ups]
15 [Air Squats]

# Cool Down
Stretch 10min
```

#### Strength & Conditioning

```
# Block 1: Strength
[Deadlift] 5-5-5-3-3-1
[Romanian Deadlift] 3x8

# Block 2: Accessory
[Dumbbell Row] 3x12
[Face Pulls] 3x15-20

# Block 3: Conditioning
EMOM 12min:
10 [Kettlebell Swings]
5 [Burpees]
```

## Code Editor

### Overview

The code editor is an IDE-like component for WOD syntax editing with syntax highlighting, line numbers, autocomplete, and enhanced editing UX. It uses a simple overlay approach that works immediately without build steps.

### Features

**Syntax Highlighting:**
- Headers (`# Block Name`) - Green, bold
- Exercises (`[Exercise]`) - Orange/brown, bold
- Special formats (`AMRAP`, `EMOM`, etc.) - Purple, bold
- Rep schemes (`3x8`, `5-5-5`) - Light green
- Comments (`//`, `--`) - Green, italic
- Brackets - Gray

**Editor Features:**
- Line numbers (scrolls with content)
- Auto-indent (maintains indentation on Enter)
- Tab support (inserts 2 spaces)
- Real-time syntax highlighting
- Exercise name autocomplete
- Responsive mobile design
- Accessible (proper ARIA labels)
- Progressive enhancement (falls back to textarea)

**Autocomplete:**
- Triggers when typing inside brackets `[` or `[[`
- Shows dropdown with matching exercises (max 10)
- Filters by substring match (case-insensitive)
- Arrow keys to navigate, Enter to select, Escape to close
- Exercise names embedded inline for instant availability

### Basic Usage

```php
use App\Services\ComponentBuilder as C;

$components[] = C::codeEditor('my-editor', 'Code')
    ->name('code_field')
    ->value($existingCode)
    ->build();
```

### Full Configuration

```php
$components[] = C::codeEditor('wod-editor', 'WOD Syntax')
    ->name('wod_syntax')              // Form field name
    ->value($workout->wod_syntax)     // Initial content
    ->placeholder($exampleSyntax)     // Placeholder text
    ->mode('wod-syntax')              // Syntax mode
    ->theme('dark')                   // Theme (currently only 'dark')
    ->height('400px')                 // Editor height
    ->lineNumbers(true)               // Show line numbers
    ->lineWrapping(true)              // Wrap long lines
    ->readOnly(false)                 // Read-only mode
    ->autofocus(false)                // Auto-focus on load
    ->ariaLabel('WOD Syntax Editor')  // Accessibility label
    ->build();
```

### Architecture

The code editor uses a **simple overlay approach**:

1. **Textarea as base** - Standard textarea for form submission and no-JS fallback
2. **Syntax highlighting overlay** - Positioned div with colored HTML
3. **Transparent text** - Textarea text is transparent, overlay shows colored version
4. **Line numbers** - Separate div that scrolls with content

### Files Structure

**Backend (PHP):**
- `app/Services/Components/Interactive/CodeEditorComponentBuilder.php` - Component builder
- `app/Services/ComponentBuilder.php` - Factory method
- `app/Services/WodParser.php` - Parses WOD syntax into structured JSON

**Frontend (Views):**
- `resources/views/mobile-entry/components/code-editor.blade.php` - Standalone editor component
- `resources/views/mobile-entry/components/wod-form.blade.php` - Specialized WOD form with embedded editor
- `resources/views/mobile-entry/flexible.blade.php` - Includes exercise names for autocomplete

**Frontend (JavaScript):**
- `public/js/mobile-entry/components/code-editor.js` - Editor initialization and syntax highlighting
- `public/js/mobile-entry/components/code-editor-autocomplete.js` - Exercise name autocomplete

**Frontend (CSS):**
- `public/css/mobile-entry/components/code-editor.css` - Editor styling and theme

### Usage Examples

#### Read-Only Viewer

```php
$components[] = C::codeEditor('viewer', 'View Code')
    ->name('code')
    ->value($code)
    ->readOnly(true)
    ->lineNumbers(true)
    ->build();
```

#### Minimal Editor

```php
$components[] = C::codeEditor('simple', 'Code')
    ->name('code')
    ->value($code)
    ->lineNumbers(false)
    ->height('200px')
    ->build();
```

#### Full-Featured Editor

```php
$components[] = C::codeEditor('advanced', 'Advanced Editor')
    ->name('code')
    ->value($code)
    ->mode('wod-syntax')
    ->height('600px')
    ->lineNumbers(true)
    ->lineWrapping(true)
    ->autofocus(true)
    ->ariaLabel('Advanced code editor with syntax highlighting')
    ->build();
```

### Customization

#### Adding New Syntax Modes

1. **Add highlighting function** in `code-editor.js`:

```javascript
function highlightMyMode(text) {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/pattern/g, '<span class="cm-my-token">$1</span>');
}
```

2. **Add CSS classes** in `code-editor.css`:

```css
.cm-my-token {
    color: #4ec9b0;
    font-weight: bold;
}
```

3. **Register mode** in `initCodeEditors()`:

```javascript
if (config.mode === 'my-mode') {
    initMyModeEditor(textarea, wrapper, config);
}
```

#### Styling

Change colors in `public/css/mobile-entry/components/code-editor.css`:

```css
.cm-wod-header {
    color: #your-color;
}
```

Change font:

```css
.cm-scroller {
    font-family: 'Your Font', monospace;
}
```

Custom height:

```php
->height('600px')  // Fixed height
->height('50vh')   // Viewport-relative
```

### JavaScript API

#### Accessing the Editor

```javascript
const textarea = document.querySelector('#my-editor-textarea');
const value = textarea.value;  // Get content
textarea.value = 'new content'; // Set content
textarea.dispatchEvent(new Event('input')); // Trigger update
```

#### Events

```javascript
const textarea = document.querySelector('#my-editor-textarea');

// Listen for changes
textarea.addEventListener('input', (e) => {
    console.log('Content changed:', e.target.value);
});

// Listen for scroll
textarea.addEventListener('scroll', (e) => {
    console.log('Scrolled to:', e.target.scrollTop);
});
```

### Accessibility

Always provide meaningful labels:

```php
->ariaLabel('WOD Syntax Editor - Enter your workout syntax here')
```

Supported keyboard shortcuts:
- **Tab** - Insert 2 spaces
- **Enter** - New line with auto-indent
- **Ctrl+A** - Select all
- **Ctrl+C/V/X** - Copy/paste/cut
- **Ctrl+Z/Y** - Undo/redo (browser native)

### Mobile Considerations

- Touch scrolling handled automatically
- Editor adjusts when virtual keyboard appears
- Consider viewport-relative heights on mobile:

```php
$isMobile = request()->header('User-Agent') // detect mobile
$height = $isMobile ? '300px' : '500px';

->height($height)
```

### Performance

- Handles ~500 lines smoothly
- No noticeable lag on mobile
- Regex-based highlighting is fast enough
- For larger documents, consider pagination or upgrading to CodeMirror 6

### Browser Compatibility

Tested and working:
- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- Mobile Safari (iOS)
- Chrome Mobile (Android)

Falls back to regular textarea in browsers with JavaScript disabled or very old browsers.

### Security

The editor escapes all HTML to prevent XSS:

```javascript
.replace(/&/g, '&amp;')
.replace(/</g, '&lt;')
.replace(/>/g, '&gt;')
```

Always validate on the server side:

```php
$validated = $request->validate([
    'wod_syntax' => 'required|string|max:10000',
]);
```

## WOD Parser

### Overview

The `WodParser` service parses WOD text syntax into structured JSON for storage and display.

### Features

- Block parsing (# headers)
- Exercise schemes (3x8, 5-5-5, 3x8-12, etc.)
- Freeform text after exercise names (no colon required)
- Special formats (AMRAP, EMOM, For Time, Rounds)
- Time/distance formats (500m, 5min, 2:00)
- Comment support (// and --)
- Exercise parsing with single bracket notation
- Unparse capability (convert back to text)

### Data Structure

```json
{
  "blocks": [
    {
      "name": "Strength",
      "exercises": [
        {
          "type": "exercise",
          "name": "Back Squat",
          "scheme": {
            "type": "rep_ladder",
            "reps": [5, 5, 5, 5, 5],
            "display": "5-5-5-5-5"
          }
        }
      ]
    }
  ],
  "parsed_at": "2025-12-03T15:26:32Z"
}
```

### Usage

```php
use App\Services\WodParser;

// Parse WOD syntax
$parser = new WodParser();
$parsed = $parser->parse($wodSyntax);

// Convert back to text
$text = $parser->unparse($parsed);
```

## Database Schema

### Workouts Table

- `wod_syntax` (text) - Stores the raw WOD text
- `wod_parsed` (json) - Stores the parsed structure

### Model Methods

```php
// Check if workout is a WOD
$workout->isWod();

// Check if workout is a template
$workout->isTemplate();

// Query only WODs
Workout::wods()->get();

// Query only templates
Workout::templates()->get();
```

## User Workflow

### Creating a WOD

1. Navigate to `/workouts/create?type=wod`
2. Enter WOD name
3. Write WOD syntax in code editor (with autocomplete and syntax highlighting)
4. Add optional description
5. Submit - syntax is parsed and validated

### Viewing WODs

- WODs appear in workouts list alongside templates
- Shows block count
- Expandable to see all blocks and exercises
- Displays with emoji indicators:
  - 📦 for blocks
  - ⏱️ for timed formats (AMRAP, EMOM, For Time)
  - 🔄 for rounds

### Logging from WOD

- Each exercise shows "Log now" button
- Clicking takes you to lift-logs/create
- After logging, shows completed status with edit/delete options
- Exercises matched by name from database using fuzzy matching

### Editing WODs

- Shows parsed preview of blocks and exercises
- Edit form with code editor
- Re-parses on save

## Tips

1. **Keep it simple**: The syntax is designed to be quick to type
2. **Use brackets for exercises**: Use `[Exercise]` for exercises you want users to log
3. **Use plain text for notes**: Use plain text for warm-up instructions or non-trackable items
4. **Be specific**: Include weight recommendations if needed
5. **Use blocks**: Organize your workout into logical sections
6. **No indentation needed**: Exercises following special formats are automatically grouped
7. **Blank lines**: Use blank lines between blocks for readability - they're ignored
8. **Autocomplete**: Type `[[` to trigger exercise name autocomplete

## Testing

Run WOD parser tests:

```bash
php artisan test --filter=WodParserTest
```

## Integration Points

- **Workouts**: WODs and templates coexist in same table
- **Exercises**: WOD exercises link to existing exercise database
- **Lift Logs**: Logging from WODs creates standard lift logs
- **Exercise Aliases**: Respected when displaying WOD exercises
- **Authorization**: Uses existing workout policies
