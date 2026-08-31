---
inclusion: always
---

# Project Conventions

Architectural principles specific to this Laravel app. These apply to all work, not just specific features.

## Data Integrity

- **Never repurpose a column for something it wasn't designed for.** If you need to store distance, add a `distance` column. Don't stuff it into `reps` and rely on context to interpret it. New features get proper schema.
- **Don't duplicate data across columns.** One source of truth per datum. If you need the same information in a different shape, derive it at read time — don't store it twice. Two copies desync.
- **Migrations are forward-only in production.** Never modify a migration that has been run. Create a new migration to alter the schema. Rollback is for development only.
- **A migration is not "done" until it has RUN.** Authoring a migration and passing its feature test (which uses in-memory SQLite via `RefreshDatabase`) is NOT the same as the migration having run against the real MySQL DB. Before relying on a migration's effect — especially for manual/cross-app verification — actually run `php artisan migrate` and confirm `php artisan migrate:status` shows it **Ran**. A committed-but-Pending migration silently leaves the app on pre-migration behavior for the affected rows. (Learned when a re-type migration sat Pending and the app kept dispatching the old exercise-type strategy, producing a cross-app PR mismatch that looked like a code bug.)
- **Deleting rows in a data migration: mind SoftDeletes and self-referential FKs.** Two traps, both real:
  - **SoftDeletes:** most core models (`LiftLog`, `LiftSet`, `Exercise`, `PersonalRecord`, …) use `SoftDeletes`, so an Eloquent `->delete()` (including inside services like `PRRecalculationService::recalculateAllPRsForExercise`) only sets `deleted_at` — the row is NOT physically removed. If a migration must eliminate rows (e.g. before dropping an enum value they use), hard-delete via the query builder (`DB::table('...')->where(...)->delete()`), which also bypasses the soft-delete scope. A raw `DB::table()->count()` still sees soft-deleted rows, so asserts built on it will trip over them.
  - **Self-referential FKs:** `personal_records.previous_pr_id` is a self-FK with `ON DELETE NO ACTION`. MySQL checks it row-by-row mid-statement, so a bulk `DELETE ... WHERE pr_type IN (...)` that removes a parent before its child in the same statement fails with a 1451 integrity violation — even when the end state would be consistent. Null out the inbound references first (`UPDATE ... SET previous_pr_id = NULL WHERE previous_pr_id IN (<ids to delete>)`), THEN delete. (SQLite in tests does not enforce this the same way, so the test can pass while MySQL fails — see the "run it" rule above.)

## Architecture

- **Domain folders for isolated features.** New feature modules that don't belong in the existing web UI flow get their own `app/X/` directory (controllers, actions, services, models, middleware, commands). Don't scatter new feature code across `app/Http/Controllers/`, `app/Services/`, etc. unless it's genuinely shared.
- **Existing web UI behavior is sacred.** Changes to shared models (LiftLog, LiftSet, User, Exercise) must not break existing views, controllers, or tests. If you modify a shared model, run the full test suite to verify.
- **Dispatch events, don't inline side effects.** When an action has downstream consequences (PR detection, notifications), dispatch an event. Don't inline the logic in the controller or action. The event system decouples concerns.

## Naming

- **No brand names in code.** Class names, URLs, and route prefixes describe function, not marketing. The product may be renamed at any time. Use descriptive, generic names.
- **Descriptive over clever.** `ExerciseResolverService` not `Resolver`. `StoreSyncLogAction` not `StoreAction`. The name should tell you what it does without reading the code.

## Shared Model Changes

When adding columns to existing tables (`lift_logs`, `lift_sets`, `users`, `exercises`):

1. Make new columns nullable (don't break existing rows)
2. Add to the model's `$fillable` array
3. Add appropriate casts if needed
4. Verify existing functionality still works (tests pass, web UI unaffected)
5. If the column changes how existing data is interpreted (like moving cardio distance from `reps` to `distance`), update all upstream readers in the same PR
