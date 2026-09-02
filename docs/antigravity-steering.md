# Antigravity Steering

Standard rules and context for any external LLM implementing a plan from this project. Read this file first, then read the specific plan document.

This app is the durable sync backend and coaching web app for the Squirby platform (Laravel 11+, PHP 8.4, MySQL, Blade + Alpine.js + Tailwind, Breeze session auth for web, Sanctum token auth for the API).

---

## 1. Project Steering Files (Always Read First)

These contain project-wide rules. Read them before starting any work.

```
.kiro/steering/safe-operations.md        → files never to edit, bash safety, artisan safety, Pint ban
.kiro/steering/project-conventions.md    → data integrity, domain folders, event dispatch, naming
.kiro/steering/git-workflow.md           → branch assumptions, no push, no merge to main
.kiro/steering/laravel-boost.md          → Laravel 12 conventions (PHP style, Eloquent, testing)
```

When working on the Sync API specifically, also read (manual-inclusion) context:

```
.kiro/steering/sync-api-context.md       → sync API architecture, data model, cardio migration, app/Sync/ layout
```

> **Precedence note on Pint:** `laravel-boost.md` mentions running `vendor/bin/pint --dirty`. `safe-operations.md` bans Pint entirely. For delegated antigravity runs, **safe-operations wins — never run Pint.** The user runs formatting.

---

## 2. Tool & Bash Safety Rules

These rules prevent the most common failure modes when working in this codebase.

### File Operations

- **NEVER use bash commands (echo, cat, printf, heredocs, `>`, `>>`, tee) to create or write file content.** Always use fsWrite/fsAppend tools. Bash with quoted content causes hanging processes (shell waits for the closing quote).
- **NEVER use `sed`, `awk`, or `perl` one-liners to modify file content** — and never `sed -i` on config files. Use strReplace/edit tools instead.
- **NEVER use inline interpreter commands** (`php -r`, `node -e`). If you need a throwaway script, write a temporary `.php` file, run it with `php`, then delete it. For read-only DB inspection, prefer `php artisan tinker` with a read query.
- **NEVER use `grep` via bash.** Use the grep/search tool instead.
- **Always pass `--no-interaction`** to artisan commands so they never hang on a prompt.

### Protected Files (Never Edit)

- `.env`, `.env.backup`, `.env.local` — production credentials. Never read into output, never modify.
- `vendor/`, `node_modules/` — managed by Composer/npm.
- `composer.lock` — only changed by Composer.
- `*.sql` root backups — production database dumps. Never modify, delete, or echo contents.
- `storage/logs/` — read for debugging only; never write or delete manually.
- **Existing migrations that have run in production** — never modify. Create a new migration instead.
- **Existing tests** — never delete or significantly alter without approval. Add new tests freely.

### Files that need explicit approval before editing

- `composer.json`, `bootstrap/app.php`, `config/*.php` (structural changes), existing shared models (`LiftLog`, `LiftSet`, `User`, `Exercise`).

---

## 3. Git Rules

- **NEVER commit or push.** Do not run `git commit`, `git add`, `git push`, or any git command. The user handles all version control.
- No exceptions. Do not commit "for convenience" or "to save progress."

> This overrides `.kiro/steering/git-workflow.md` (which allows committing at your discretion) **for delegated antigravity runs only.** When a human is driving the session interactively, git-workflow.md applies. When antigravity is executing a prompt, this rule applies: never touch git.

---

## 4. Verification

### Milestone-Based Testing

Do NOT run tests after every individual step. Tests run only at designated **milestone checkpoints** defined in the prompt document.

### Test Commands

Always run the suite in parallel — this project has 2000+ tests and parallel (ParaTest) is ~10x faster:

```bash
php artisan test --parallel
```

Run a single file or filter while iterating on a fix:

```bash
php artisan test tests/Feature/Sync/SomeTest.php
php artisan test --filter=testMethodName
```

Pipe verbose output to a file inside the project so you can re-inspect without re-running:

```bash
php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt
```

- **NEVER re-run the full suite just to see output you missed.** The `.test-output.txt` file has it. Read the file.
- Never write output files to `/tmp/` or outside the project tree.
- Delete `.test-output.txt` at the end of your execution.

### Self-Correction Loop

When you reach a milestone test checkpoint:

1. Execute the test command.
2. If tests **pass** — proceed to the next milestone.
3. If tests **fail** — you MUST immediately:
   - Read the error output
   - Identify and fix the failing code
   - Re-run the affected test file, then the suite
   - Repeat until all tests pass
4. **Do NOT finish your execution turn or yield control back to the shell until the tests for the current milestone pass completely.**

This loop is non-negotiable. Never report a failure and stop. Never ask for help with a test failure. Fix it yourself.

### Scope of Fixes

- If a test failure is caused by YOUR changes — fix it immediately.
- If a test was already failing before your changes (pre-existing, unrelated) — note it and continue. Never silence or delete existing tests.

### Post-Execution Retro (Written to File)

After all milestones pass and before printing the completion signal, write the Post-Execution Retro into the prompt file itself. Find the `## Post-Execution Retro` section at the bottom of the prompt `.md` file and replace the `{placeholder}` values with actual data from your execution using `str_replace`. This is a file write, not a console print. Fill in ALL fields.

### End-of-Run Cleanup Sweep (MANDATORY — before AGY_COMPLETE)

The "No dead code" principle (§10) is a pass/fail GATE, not a hope. Before printing the completion signal,
you MUST sweep the files you touched — a green PHPUnit suite does NOT prove the absence of dead code (an
unused method, an orphaned class, or a compat shim all pass).

> **Tooling note:** Pint is BANNED for antigravity runs (`safe-operations.md`), and this project has no
> PHPStan/Larastan configured. So this sweep is done by INSPECTION + grep, NOT by running a linter. Do
> not run Pint or any formatter.

1. **No orphaned/removed-code residue.** If you removed the caller(s) of a method/class/route, remove the
   dead code too — never leave a no-op stub or empty "legacy" method "to be safe." Grep the removed symbol
   (method name, class, route name) across `app/` + `routes/` to confirm zero references remain.
2. **No unused imports (`use` statements).** Inspect the `use` block of every file you edited; remove any
   `use` you no longer reference.
3. **No fallback / backward-compat shims.** No `?? $oldColumn` dual-reads, no "just in case" branches, no
   commented-out old code, no TODO/FIXME. If a boundary/migration is done correctly the old shape is
   unreachable — a surviving fallback is dead code by construction (see §13 Consumer Impact Trace).
4. **No leftover artifacts.** Delete any temporary `.php` scripts you wrote; no stray `dd()`/`dump()`/
   `Log::` debug noise beyond intended logging; delete `.test-output.txt`.

If it finds nothing, say so. If it finds something, fix it and re-run the affected tests BEFORE AGY_COMPLETE.

### Completion Signal

After all milestones pass, the retro is written, AND the cleanup sweep is clean, print exactly this line as your final output:

```
AGY_COMPLETE: All milestones passed.
```

Do not print it prematurely. Do not print it if any milestone has not passed.

---

## 5. Dependency Rules

- Do NOT add new composer or npm dependencies unless the plan explicitly says so.
- Use only what's already in `composer.json` / `package.json`.

---

## 6. Database & Data Integrity Rules

- **Migrations are forward-only in production.** Never modify a migration that has already run. Create a new migration to alter the schema.
- **NEVER run destructive database commands** — no `migrate:fresh`, `migrate:reset`, `db:wipe`. Use `migrate` (forward-only). `migrate:rollback`, `db:seed`, and `tinker` writes require approval.
- **New columns on existing tables must be nullable**, added to the model's `$fillable`, and given casts in the `casts()` method where needed. If a column changes how existing data is interpreted, update all upstream readers in the same milestone.
- **Never repurpose a column** for something it wasn't designed for. New features get proper schema.
- **One source of truth per datum.** Don't store the same information in two shapes — derive it at read time.

---

## 7. Architecture Conventions

- **Domain folders for isolated features.** New feature modules that don't belong in the existing web UI flow get their own `app/X/` directory (controllers, actions, services, models, middleware, commands). Sync code lives in `app/Sync/`; its routes live in `routes/sync.php`. Don't scatter new feature code across `app/Http/Controllers/`, `app/Services/`, etc. unless it's genuinely shared.
- **Existing web UI behavior is sacred.** Changes to shared models (`LiftLog`, `LiftSet`, `User`, `Exercise`) must not break existing views, controllers, or tests. If you touch a shared model, run the full suite.
- **Dispatch events, don't inline side effects.** Downstream consequences (PR detection, notifications) are dispatched as events, not inlined into a controller or action. PR detection piggybacks on `LiftLogCompleted`.
- **Actions pattern.** Business logic lives in Action classes with a single `execute()` method, injected via constructor. See `app/Actions/LiftLogs/CreateLiftLogAction.php`.
- **Soft deletes everywhere.** `LiftLog`, `LiftSet`, `Exercise`, `ExerciseAlias` use SoftDeletes. Always scope queries to exclude trashed records.
- **Sanctum for API auth only.** The web UI keeps session auth (Breeze). Adding `HasApiTokens` to `User` is the only auth change for the sync API.

---

## 8. PHP / Laravel Style (from laravel-boost.md)

- **Use Eloquent, avoid the `DB::` facade.** Prefer `Model::query()`, relationship methods with return-type hints, and eager loading to prevent N+1.
- **PHP 8 constructor property promotion** in every `__construct()`. No empty zero-parameter constructors.
- **Explicit return types** on all methods and functions; type-hint all parameters.
- **Form Request classes for validation**, not inline validation in controllers. Match the sibling convention (array vs string rules).
- **`config()` not `env()`** outside config files.
- **Named routes + `route()`** for URL generation.
- **PHPDoc over inline comments.** Only comment genuinely complex logic.
- **No brand names in code.** Class names, URLs, and route prefixes describe function, not marketing. Use descriptive names (`StoreSyncLogAction`, not `StoreAction`).
- **`php artisan make:` for new files** (migrations, models, controllers, tests) with `--no-interaction`. Use `make:test --phpunit` — this project is PHPUnit only, never Pest.

---

## 9. Plan Document Format

Each plan doc in `docs/plans/` follows this structure. Read all sections before starting:

| Section | Purpose |
|---------|---------|
| **Before You Start** | Steering files and specs to read first. |
| **What You're Building** | Scope, why, end state. |
| **Existing Code to Understand** | Files to read before modifying. |
| **Execution Plan** | Numbered phases in execution order, with test checkpoints. |
| **Hard Rules** | Never commit, never Pint, never destructive DB. |
| **Implementation Rules** | Namespace, testing, style. |
| **Success Criteria** | Concrete exit conditions — verify all before declaring done. |
| **Do Not** | Feature-specific prohibitions. |
| **Post-Execution Retro** | Empty on handoff; filled in after completion. |

**IMPORTANT:** The plan/spec is reference architecture — NOT execution instructions. The `-prompt.md` file (from `docs/plans/template-prompt.md`) provides the execution steps. Read the plan for context, execute from the prompt.

---

## 10. General Implementation Principles

- **Read before writing.** Always read a file before modifying it. Never propose changes to code you haven't seen.
- **One logical change per phase.** Complete and verify each phase before moving to the next.
- **Match existing patterns.** Before writing new code, find the closest existing pattern (sibling controllers, actions, Form Requests, tests) and mirror its structure and naming. Don't introduce new conventions or libraries unless the plan calls for it.
- **Resolve upstream.** When adding a data transformation, do it at the earliest point in the pipeline where the data is produced — not by patching multiple downstream consumers.
- **Zero behavioral changes unless specified.** If the plan says "refactor," existing behavior and web UI must be identical afterward. If it says "change behavior to X," only X changes.
- **No dead code.** No unused imports, no commented-out code, no backward-compat shims, no leftover TODO/FIXME.
- **Test enforcement.** Every change is programmatically tested — write or update a test, then run it. Most tests are feature tests; use factories and factory states for test data.

---

## 11. Context Efficiency — Protect Your Token Budget

Your context window is finite and non-renewable within an execution. Every file read, every test output, every tool call consumes budget. Running out mid-task means half-finished migrations and human cleanup. Treat context like money.

### The Read Rule

**Only read what you need to make the edit.** The cost of reading is proportional to lines, not files.

| Situation | Approach |
|-----------|----------|
| Changing 1–3 lines in a known location | `str_replace` with exact match from grep context |
| Logic depends on surrounding code | Read the relevant section (offset + limit), not the whole file |
| Adding a `use` import or a `$fillable` entry | Read only the top of the class |
| Same mechanical edit across many files | Write a temporary script, run once, delete |

### The Script-First Rule

When a milestone applies the SAME mechanical transformation (string replacement, adding a trait/import, renaming a method call) to more than 5 files, write a temporary `.php` (or Node `.cjs` for frontend assets) script, run it once, run tests, then delete it.

**Scripts are PROHIBITED when** each file needs different structural reasoning (changing a method's internal logic, wiring new relationships, edits that depend on surrounding context). Those are individual edits.

### Budget Warning Signs

If you've read more than 15 full files in one milestone, or you're re-reading a file to find a line you already located via grep, or you're doing the same edit for the 6th time — stop and switch to a script.

---

## 12. Prototype-First Mandate for Shared UI

When a task unifies two or more existing Blade views/components into one shared implementation, or builds a component serving ≥2 consumers with different visual themes:

1. **Prototype before production.** Build a standalone HTML mockup demonstrating the shared structure before touching production Blade. The mockup is the visual contract.
2. **Human review gate.** The prompt must include an `AGY_PAUSE` signal after the prototype milestone. Do not proceed to production integration without explicit human permission.
3. **Visual parity = literal Tailwind classes, not prose.** "Match existing appearance" is not a spec — `border-2 border-white dark:border-neutral-700` is a spec. Provide a per-consumer, per-state class table.
4. **Per-consumer themes, not shared styles.** When two consumers have different visual vocabularies, the logic layer is shared; the CSS mapping is injected per consumer.
5. **Emit events, don't internalize logic.** A shared component emits raw interaction events; the parent decides what each means.

> *Rationale: this rule set was hardened in the athlete app after a shared-component refactor shipped without a prototype, guessed at styling, and had to be fully reverted. The rule ports here as a principle — logger has no such incident of its own.*

---

## 13. Consumer Impact Trace — Mandatory for Data Shape Changes

When a plan changes any data shape — adding/removing model columns, changing a cast, altering an API response body, moving a value between columns (like the cardio `reps` → `distance` migration), or changing an event payload — the prompt **must** include a consumer trace before the change is executed.

For each changed structure, answer:

1. **What reads this?** List every model accessor, controller, action, service (e.g., `ExerciseType` strategies), Blade view, API resource, and test that consumes the field.
2. **What interprets it at display time?** Strategy classes and formatters that decode a column (e.g., `CardioExerciseType` reading `reps` as meters) must be updated in the same milestone.
3. **What tests assert on the old shape?** List them and include update instructions.

> *Rationale: across the athlete app's history, a large share of post-implementation fixes were downstream consumers left unupdated after a shape change — the producer was correct but a formatter, serializer, or test still assumed the old shape. In this app the highest-risk version is a schema/column-semantics change with multiple readers (strategies, web UI, sync API). Trace all readers before you change the shape.*

---

## 14. Logic Belongs Where Its Dependencies Live

Place new behavior in the layer that already owns all the dependencies it needs (models, services, events, config). Never route a decision through an extra parameter or callback just to reach a dependency that lives elsewhere.

Before specifying where logic goes, answer: "What does this logic need to execute?" then "Which layer already has all of those?" That's where it goes. If no single layer owns everything, the prompt must explicitly instruct wiring the missing dependency into the target layer — not leave it implicit.

For the Actions pattern this usually means: business logic lives in the Action (which receives its dependencies via constructor injection), not in the controller that dispatches it.

---

## 15. Prompt Decomposition for Large Features

A single prompt should not introduce new behavior that requires modifying more than **3 existing consumer components** (controllers, shared models, strategies, views) in one shot. If a feature needs more integration points, decompose into sequential prompts:

- **Prompt 1 — Pure logic + unit tests.** Build the Action, service, migration, model changes with full test coverage. Zero web UI integration.
- **Prompt 2 — Single integration point + feature test.** Wire the logic into exactly one route/controller with one feature test proving the path works end to end.
- **Prompt 3 — Integration sweep.** Enable the feature across remaining consumers, update affected strategies/views, handle edge cases and defaults.

Apply decomposition when the feature adds a new stored entity or column consumed by 4+ places, or when at least one existing strategy/formatter must change. Skip it for pure refactors, single-consumer logic, or mechanical renames.

### Entity Defaults — Mandatory for New Stored Objects

When the feature creates new stored records (a new model, a default row, a generated payload), the prompt MUST specify the exact initial shape — every field, default value, nullability, and cast — as a code block. Never leave "create a blank X" to the executor's imagination.

> *Rationale: in the athlete app, large single-shot prompts that built new state and integrated it into many consumers at once concentrated the majority of follow-up fixes in the integration wiring, while the pure logic was consistently correct. Sequencing keeps each prompt's context focused. The same failure mode applies here to features that touch many controllers, strategies, and views at once.*
