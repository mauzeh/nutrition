# Sync API Implementation — Prompt for Antigravity CLI

> **Status: Completed.** Built the entire Sync API from spec. Cardio distance migration, SetFieldMapper, ExerciseResolverService, 5 controllers, request logging, full test coverage.

## Before You Start

Read these files in order. They contain everything you need to implement this feature correctly.

### 1. Steering (project rules — always follow)
```
.kiro/steering/git-workflow.md          → commit freely, NEVER push, NEVER merge into main
.kiro/steering/safe-operations.md       → files to never touch, bash safety, artisan safety
.kiro/steering/project-conventions.md   → architectural principles for this project
.kiro/steering/laravel-boost.md         → Laravel framework conventions (PHP style, testing, Pint)
```

### 2. Feature Spec (what to build)
```
.kiro/steering/sync-api-context.md      → project context, data model awareness, existing patterns
.kiro/specs/squirby-sync-api/requirements.md → requirements (23 requirements with acceptance criteria)
.kiro/specs/squirby-sync-api/design.md       → architecture, file map, components, data models, development principles
.kiro/specs/squirby-sync-api/tasks.md        → ordered implementation plan with dependencies
```

### 3. Existing Code to Understand (read before modifying)
```
app/Models/LiftLog.php                  → the main model you're extending
app/Models/LiftSet.php                  → the set model you're extending
app/Models/Exercise.php                 → exercise resolution target (canonical_name, title, aliases)
app/Models/ExerciseAlias.php            → alias system for name matching
app/Models/User.php                     → adding HasApiTokens trait
app/Actions/LiftLogs/CreateLiftLogAction.php → existing pattern for Actions (follow this style)
app/Services/ExerciseTypes/CardioExerciseType.php → the strategy you'll modify for the distance migration
app/Services/Charts/CardioProgressionChartGenerator.php → reads cardio data for charts
app/Events/LiftLogCompleted.php         → event to dispatch after storing a log
routes/api.php                          → currently empty (don't use this, create routes/sync.php)
config/cors.php                         → add api/sync/* to paths
config/logging.php                      → add sync_requests channel
bootstrap/app.php                       → register middleware and route file here (Laravel 12)
```

## Execution Plan

Follow the tasks in `.kiro/specs/squirby-sync-api/tasks.md` in order. Each numbered section is a phase. Complete each phase before moving to the next.

### Phase order:
1. **Schema & models** (tasks 1.1–1.8) — migrations, new models, update existing models
2. **Infrastructure** (tasks 2.1–2.5) — middleware, CORS, routes, rate limiters, Sanctum install
3. **Data migration & upstream changes** (tasks 3.1–3.6) — cardio distance migration, strategy updates, verify tests
4. **Checkpoint** — run `php artisan test --parallel` to verify nothing broke
5. **Services** (tasks 5.1, 5.4) — SetFieldMapper and ExerciseResolverService
6. **Actions** (tasks 7.1–7.2) — StoreSyncLogAction and DeleteSyncLogAction
7. **Controllers** (tasks 8.1–8.5) — all 5 controllers
8. **Checkpoint** — verify routes respond with `php artisan route:list --path=api/sync`
9. **Error handling & logging** (tasks 10.1–10.5) — exception handler, request logging, CLI commands, operations doc
10. **Tests** (tasks 11.1–11.6) — unit tests, smoke tests, cardio migration regression tests
11. **Final checkpoint** — run full test suite: `php artisan test --parallel`

### HARD RULES — NEVER VIOLATE THESE:
- **NEVER commit.** Do not run `git commit`, `git add`, or any git command.
- **NEVER run Pint.** Do not run `vendor/bin/pint` in any form.
- **NEVER push.** Do not run `git push` under any circumstances.
- **NEVER run destructive database commands.** No `migrate:fresh`, `migrate:reset`, `db:wipe`.

### Implementation rules:
- **All new code goes in `app/Sync/`** — never put sync code in `app/Http/Controllers/`, `app/Services/`, etc.
- **Routes go in `routes/sync.php`** — register in bootstrap/app.php, not in routes/api.php
- **Always use `php artisan test --parallel` when running tests.**
- **The cardio migration (phase 3) is the riskiest part** — run full test suite after this phase.
- **Never use `DB::` facade** — use Eloquent models and relationships
- **Use PHP 8 constructor promotion** in all new classes
- **Add explicit return types** to all methods
