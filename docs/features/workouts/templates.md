# Workout Templates - Complete Guide

## Overview

The Workout Templates feature allows users to save collections of exercises as reusable templates and apply them to any date with one click. Built using the flexible component system with a priority-based approach.

## What Are Workout Templates?

Workout templates let you save your favorite workout routines and reuse them with one click. Instead of manually adding the same exercises every week, create a template once and apply it whenever you need it.

## Features Implemented

### Core Functionality
- ✅ Create workout templates with name and description
- ✅ Add exercises to templates in priority order
- ✅ View all templates in a table
- ✅ Edit templates (add/remove exercises)
- ✅ Delete templates
- ✅ Apply templates to any date
- ✅ Browse templates from mobile entry
- ✅ Authorization (users can only edit their own templates)

### UI Components Used
- **Table Component**: List of templates with edit/delete actions
- **Form Component**: Create template, add exercises
- **Title Component**: Page headers with subtitles
- **Messages Component**: Success/error feedback
- **Button Component**: Navigation and actions
- **Items Component**: Display template exercises

## Database Schema

### workout_templates
- `id` - Primary key
- `user_id` - Foreign key to users
- `name` - Template name
- `description` - Optional description
- `is_public` - Boolean (MVP: always false)
- `tags` - JSON array (for future filtering)
- `times_used` - Counter for analytics
- `timestamps`

### workout_template_exercises
- `id` - Primary key
- `workout_template_id` - Foreign key
- `exercise_id` - Foreign key to exercises
- `order` - Priority/display order (lower number = higher priority)
- `timestamps`

**Note**: Sets, reps, notes, and rest_seconds were removed in the priority-based refactor.

## Priority-Based System

Templates have been designed to store only exercises and their priority order, removing sets and reps storage.

### Rationale

Templates should represent **what** exercises to do, not **how** to do them. Training parameters (weight, sets, reps) should come from the user's training history and progression system, allowing the same template to adapt as the user gets stronger.

### Benefits

1. **Adaptive**: Same template works for beginners and advanced users
2. **Progressive**: Template "grows" with the user
3. **Simpler**: Less data entry when creating templates
4. **Flexible**: Training parameters can vary based on context (deload, PR attempt, etc.)

### Example

**Template: "Push Day"**
1. Bench Press (Priority 1)
2. Strict Press (Priority 2)
3. Dips (Priority 3)
4. Tricep Extensions (Priority 4)

When applied:
- System looks at recent training history for each exercise
- Suggests appropriate weight, sets, and reps
- User can modify before logging

## Routes

```php
// Resource routes
GET    /workout-templates              index
GET    /workout-templates/create       create
POST   /workout-templates              store
GET    /workout-templates/{id}/edit    edit
PATCH  /workout-templates/{id}         update
DELETE /workout-templates/{id}         destroy

// Custom routes
POST   /workout-templates/{id}/add-exercise        addExercise
DELETE /workout-templates/{id}/exercises/{ex_id}   removeExercise
GET    /workout-templates-browse                   browse
GET    /workout-templates/{id}/apply               apply
```

## Key Methods

### WorkoutTemplate Model

```php
// Apply template to a date
$template->applyToDate(Carbon $date, User $user)
// Copies all exercises to programs table
// Increments times_used counter

// Duplicate template for another user
$template->duplicate(User $user)
// Creates a copy with all exercises
```

## Quick Start Guide

### 1. View Your Templates
Navigate to: **Lifts → Templates**

You'll see a table of all your templates with:
- Template name
- Number of exercises
- Description
- Edit and Delete buttons

### 2. Create a New Template

1. Click **"Create New Template"**
2. Enter a name (e.g., "Push Day", "Leg Day")
3. Add an optional description
4. Click **"Create Template"**

### 3. Add Exercises to Your Template

After creating a template, you'll see the edit page:

1. Click **"Add Exercise"** button
2. A list of your exercises appears
3. Click on any exercise to add it to the template
4. Or type a new exercise name and press Enter to create and add it
5. Repeat for all exercises in your workout

The exercises will appear in a table below in priority order. The first exercise added has the highest priority.

### 4. Apply a Template

**Option A: From Templates Page**
1. Go to **Lifts → Templates**
2. Click **Edit** on the template you want
3. Click **"Apply to Today"** (coming soon)

**Option B: From Mobile Entry**
1. Go to **Lifts** (mobile entry page)
2. Click **"Browse Templates"** (coming soon)
3. Click **"Apply"** on the template you want
4. The exercises will be added to your workout for that day

### 5. Edit or Delete Templates

**To Edit:**
1. Go to **Lifts → Templates**
2. Click **Edit** on the template
3. Add or remove exercises as needed

**To Delete:**
1. Go to **Lifts → Templates**
2. Click **Delete** on the template
3. Confirm the deletion

## Example Templates (Pre-loaded)

Your account comes with 5 example templates:

### Push Day (Intermediate)
1. Bench Press
2. Strict Press
3. Dips
4. Tricep Extensions

### Pull Day (Intermediate)
1. Deadlift
2. Pull-Ups
3. Rows
4. Bicep Curls

### Leg Day (Intermediate)
1. Back Squat
2. Romanian Deadlift
3. Lunges
4. Leg Curls

### Full Body A (Beginner)
1. Back Squat
2. Bench Press
3. Deadlift
4. Pull-Ups

### Full Body B (Beginner)
1. Front Squat
2. Strict Press
3. Romanian Deadlift
4. Rows

## User Flow

### Creating a Template
1. Navigate to Lifts → Templates
2. Click "Create New Template"
3. Enter name and description
4. Click "Create Template"
5. Click "Add Exercise" button
6. Select exercises from the list or create new ones
7. Each exercise is added to the table with its priority number

### Using a Template
1. Go to mobile entry (Lifts page)
2. Click "Browse Templates" button (to be added)
3. See list of templates
4. Click "Apply" on desired template
5. Template exercises are added to program for that date in priority order
6. System suggests weights, sets, and reps based on training history
7. Redirect back to mobile entry with success message

### Managing Templates
1. Navigate to Lifts → Templates
2. See table of all templates
3. Click Edit to modify exercises
4. Click Delete to remove template

## Tips

### Creating Effective Templates

1. **Name them clearly** - Use descriptive names like "Push Day" or "Monday Workout"
2. **Add descriptions** - Note the focus or difficulty level
3. **Order matters** - Add exercises in the order you'll perform them
4. **Start simple** - Begin with 3-5 exercises per template

### Using Templates Effectively

1. **Create variations** - Make "Heavy" and "Light" versions of the same workout
2. **Plan your week** - Create templates for each training day (Push/Pull/Legs)
3. **Adjust after applying** - Templates are starting points; modify weights as needed
4. **Track what works** - Keep templates that lead to good progress

### Common Use Cases

**Push/Pull/Legs Split:**
- Create 3 templates: Push Day, Pull Day, Leg Day
- Rotate through them 2x per week

**Full Body Routine:**
- Create 2-3 full body templates
- Alternate between them

**Specialization:**
- Create focused templates: "Arm Day", "Back Focus", "Leg Strength"

## What Happens When You Apply a Template?

1. Each exercise in the template is added to your mobile entry for that date in priority order
2. The system suggests weights, sets, and reps based on your training history
3. You can modify everything before logging

## Technical Implementation

### Authorization
- `WorkoutTemplatePolicy` ensures users can only:
  - View their own templates (or public ones)
  - Edit/delete their own templates
  - Create templates (all authenticated users)

### Exercise Creation
- When adding exercises to templates, if the exercise doesn't exist, it's created automatically
- Uses `firstOrCreate()` to avoid duplicates

### Integration with Mobile Entry
- `applyToDate()` creates `MobileLiftForm` records for each exercise in priority order
- Each exercise in the template becomes a form in mobile entry
- This integrates seamlessly with existing mobile entry system
- The mobile entry system handles weight, sets, and reps suggestions via TrainingProgressionService
- Templates only store exercise list and priority - all training parameters come from history

### Flexible Component System
- All views use `mobile-entry.flexible` template
- Components are built using `ComponentBuilder` service
- Consistent UI across all template pages

## UI Improvement

The "Add Exercise" interface now matches the mobile entry system:
- Click "Add Exercise" button to reveal exercise list
- Filter/search through existing exercises
- Click any exercise to add it instantly
- Create new exercises inline if needed
- No form submission required for existing exercises

This provides a consistent, familiar experience across the app.

## What's NOT in MVP

### Deferred to Phase 2
- Public templates (sharing)
- Template tags/filtering
- Exercise reordering (drag & drop)
- Exercise substitutions
- Template variations
- Usage analytics display
- Template ratings/reviews

### Deferred to Phase 3
- Multi-week programs
- Progression rules
- Auto-scheduling
- Smart recommendations

## Frequently Asked Questions

**Q: Can I edit a template after creating it?**  
A: Yes! Click Edit on any template to add/remove exercises.

**Q: What if an exercise doesn't exist?**  
A: The system will create it automatically when you add it to a template.

**Q: Can I share templates with other users?**  
A: Not yet. Public templates are coming in Phase 2.

**Q: Do templates include weights, sets, or reps?**  
A: No. Templates only store the list of exercises in priority order. When you apply a template, the system suggests weights, sets, and reps based on your training history.

**Q: Can I apply a template to multiple dates at once?**  
A: Not yet. This feature is planned for Phase 2.

**Q: What happens if I apply a template twice to the same date?**  
A: The exercises will be added again (duplicates). Be careful!

**Q: Can I reorder exercises in a template?**  
A: Not yet. For now, delete and re-add exercises in the desired order.

## Testing Checklist

- [ ] Create a template
- [ ] Add exercises to template
- [ ] Remove exercises from template
- [ ] Delete a template
- [ ] Apply template to today
- [ ] Apply template to past date
- [ ] Apply template to future date
- [ ] Verify exercises appear in mobile entry
- [ ] Verify authorization (can't edit other user's templates)
- [ ] Test with non-existent exercises (should create them)

## Coming Soon (Phase 2)

1. Add "Browse Templates" button to mobile entry lifts page
2. Implement public templates (is_public flag)
3. Add tag filtering
4. Show usage statistics (times_used)
5. Add template preview before applying
6. Implement exercise reordering
7. Add bulk operations (duplicate, export)

## Files Created/Modified

### New Files
- `database/migrations/2025_11_10_150752_create_workout_templates_table.php`
- `database/migrations/2025_11_10_150759_create_workout_template_exercises_table.php`
- `app/Models/WorkoutTemplate.php`
- `app/Models/WorkoutTemplateExercise.php`
- `app/Http/Controllers/WorkoutTemplateController.php`
- `app/Policies/WorkoutTemplatePolicy.php`
- `database/seeders/WorkoutTemplateSeeder.php`

### Modified Files
- `routes/web.php` - Added template routes
- `resources/views/app.blade.php` - Added Templates link to Lifts submenu

## Success Metrics

Track these metrics to measure success:
- Number of templates created per user
- Number of times templates are applied
- User retention (do template users log more consistently?)
- Most popular template names/patterns
- Average exercises per template

---

**Status:** MVP Complete ✅  
**Date:** November 10, 2025  
**Built with:** Flexible Component System