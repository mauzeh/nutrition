# Sync Auth Upgrade — Prompt for Antigravity CLI

> **Status: Completed.** Rewrote auth from username-based to email-based with Google OAuth via Socialite. Exercise resolver upgraded with canonical_name support.

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
.kiro/specs/sync-auth-upgrade/requirements.md → requirements (9 requirements with acceptance criteria)
.kiro/specs/sync-auth-upgrade/design.md       → architecture, method designs, JWT verifier services
.kiro/specs/sync-auth-upgrade/tasks.md        → ordered implementation plan with dependencies
```

### 3. Existing Code to Understand (read before modifying)
```
app/Sync/Controllers/AuthController.php → the controller you're rewriting (currently username-based)
app/Sync/Services/ExerciseResolverService.php → exercise resolution you're modifying
app/Services/ExerciseMergeService.php   → merge service you're modifying (add canonical_name alias)
app/Services/ExerciseAliasService.php   → alias creation service (used by merge)
app/Models/Exercise.php                 → exercise model (canonical_name, user_id, aliases relationship)
app/Models/ExerciseAlias.php            → alias model (alias_name, exercise_id, user_id)
app/Models/User.php                     → user model (email, name, password, google_id, HasApiTokens)
routes/sync.php                         → existing sync routes (add new auth routes here)
config/services.php                     → add Apple service config here
```

### 4. Reference (already implemented — don't rebuild, just understand)
```
.kiro/specs/squirby-sync-api/design.md  → original sync API design (set field mapping table, exercise type derivation)
app/Sync/Services/SetFieldMapper.php    → bidirectional set field mapping (already implemented)
app/Sync/Actions/StoreSyncLogAction.php → how logs are stored (passes exercise resolver result)
```

## Execution Plan

Follow the tasks in `.kiro/specs/sync-auth-upgrade/tasks.md` in order.

### Phase order:
1. **Configuration** — Apple service config
2. **JWT Verifiers** — GoogleJwtVerifier, AppleJwtVerifier
3. **AuthController rewrite** — authResponse helper, register, login, findOrCreateSocialUser, googleAuth, appleAuth
4. **Routes & email check** — register new routes, checkEmail endpoint
5. **Checkpoint** — run `php artisan test --parallel`
6. **Exercise resolver upgrade** — accept canonical_name, global-only scope, promote user-owned on conflict
7. **Merge service upgrade** — store canonical_name as global alias on merge
8. **Documentation** — update docs/sync-api-operations.md
9. **Tests** — feature tests for all auth endpoints
10. **Final checkpoint** — run `php artisan test --parallel`

### HARD RULES — NEVER VIOLATE THESE:
- **NEVER commit.** Do not run `git commit`, `git add`, or any git command.
- **NEVER run Pint.** Do not run `vendor/bin/pint` in any form.
- **NEVER push.** Do not run `git push` under any circumstances.
- **NEVER run destructive database commands.** No `migrate:fresh`, `migrate:reset`, `db:wipe`.

### Implementation rules:
- **All new sync code goes in `app/Sync/`** — JWT verifiers go in `app/Sync/Services/`.
- **Exception: ExerciseMergeService stays where it is** (`app/Services/ExerciseMergeService.php`).
- **Routes go in `routes/sync.php`** — add new routes alongside existing ones.
- **Always use `php artisan test --parallel` when running tests.**
- **Never use `DB::` facade** — use Eloquent models and relationships.
- **Use PHP 8 constructor promotion** in all new classes.
- **Add explicit return types** to all methods.
