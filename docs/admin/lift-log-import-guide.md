# Lift Log Import Command - Admin Guide

The `lift-log:import-json` command allows administrators to import workout data from JSON files with intelligent duplicate detection and user-friendly prompts.

## Quick Start

```bash
# Basic import
php artisan lift-log:import-json workout_data.json --user-email=user@example.com

# Import with overwrite (no prompts)
php artisan lift-log:import-json workout_data.json --user-email=user@example.com --overwrite

# Import for specific date
php artisan lift-log:import-json workout_data.json --user-email=user@example.com --date="2024-01-15"
```

## JSON Format

Your JSON file must contain an array of exercise objects:

```json
[
  {
    "exercise": "Bench Press",
    "canonical_name": "bench_press",
    "weight": 225,
    "reps": 5,
    "sets": 1,
    "exercise_type": "regular",
    "notes": "Optional notes"
  },
  {
    "exercise": "Plank",
    "canonical_name": "plank",
    "weight": 0,
    "reps": 60,
    "sets": 1,
    "exercise_type": "bodyweight",
    "notes": "time in seconds"
  }
]
```

## Common Admin Scenarios

### 1. New User Onboarding
```bash
# Import Stefan's workout history
php artisan lift-log:import-json stefan_workout_formatted.json --user-email=stefan@swaans.com --date="2024-01-15"
```

### 2. Data Migration
```bash
# Migrate from another fitness app
php artisan lift-log:import-json myfitnesspal_export.json --user-email=user@example.com --overwrite
```

### 3. Bulk Historical Import
```bash
# Import multiple months of data
php artisan lift-log:import-json jan_2024.json --user-email=athlete@example.com --date="2024-01-01"
php artisan lift-log:import-json feb_2024.json --user-email=athlete@example.com --date="2024-02-01"
```

### 4. Data Correction
```bash
# Fix incorrect data by overwriting
php artisan lift-log:import-json corrected_data.json --user-email=user@example.com --overwrite
```

### 5. Automated Scripts
```bash
# For CI/CD or automated backups
php artisan lift-log:import-json backup.json --user-email=user@example.com --overwrite --no-interaction
```

## Duplicate Detection

The command automatically detects duplicates based on:
- Same user
- Same exercise (canonical name)
- Same date
- Same weight and reps

When duplicates are found, you'll see an interactive prompt with options:
1. **Skip duplicates** - Import only new exercises
2. **Overwrite existing** - Replace duplicate lift logs
3. **Cancel import** - Abort the operation

## Exercise Mapping

If an exercise doesn't exist in the global database, you'll be prompted to:
1. **Create new user exercise** - Add it as a user-specific exercise
2. **Map to existing global exercise** - Link to an existing global exercise by canonical name

## Flags and Options

- `--user-email=email` - Target user for the import
- `--date=YYYY-MM-DD` - Specific date for the workout (defaults to today)
- `--overwrite` - Skip duplicate prompts and overwrite existing data
- `--no-interaction` - Run without any prompts (useful for scripts)

## Troubleshooting

### Common Issues

**File not found**
```bash
# Check file path and permissions
ls -la your_file.json
```

**User not found**
```bash
# Verify user exists
php artisan tinker --execute="echo User::where('email', 'user@example.com')->exists() ? 'Found' : 'Not found';"
```

**Invalid JSON**
```bash
# Validate JSON syntax
cat your_file.json | python -m json.tool
```

**Exercise mapping issues**
- Follow the interactive prompts to create user exercises or map to global exercises
- Check that canonical_name values are lowercase with underscores
- Ensure exercise names are descriptive and unique
- New exercises will be created as user-specific, not global

### Best Practices

1. **Test with small files first** - Validate your JSON format with a few exercises
2. **Use meaningful canonical names** - Follow the pattern: `exercise_name` (lowercase, underscores)
3. **Backup before overwriting** - Always backup existing data before using `--overwrite`
4. **Verify user emails** - Ensure target users exist in the system
5. **Use specific dates** - Provide `--date` for historical imports

## Example Workflows

### Stefan's Onboarding
```bash
# 1. Prepare Stefan's data in JSON format
# 2. Import his historical workouts
php artisan lift-log:import-json stefan_workout_formatted.json --user-email=stefan@swaans.com --date="2024-01-15"

# 3. Handle any exercise mapping prompts
# 4. Verify import success in the application
```

### Data Migration Project
```bash
# 1. Export data from old system to JSON
# 2. Import for each user with overwrite
php artisan lift-log:import-json user1_data.json --user-email=user1@example.com --overwrite
php artisan lift-log:import-json user2_data.json --user-email=user2@example.com --overwrite

# 3. Verify all data imported correctly
```

### Regular Backup Restoration
```bash
# Automated script for backup restoration
php artisan lift-log:import-json daily_backup.json --user-email=user@example.com --overwrite --no-interaction
```

This command provides a robust, user-friendly way to import workout data while maintaining data integrity and providing flexibility for various admin workflows.