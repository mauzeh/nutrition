# {Feature Name} — Prompt for Antigravity CLI

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
{spec or plan doc path}                 → requirements, architecture, implementation plan
```

### 3. Existing Code to Understand (read before modifying)
```
{list files with brief annotations}
```

### 4. Reference (already implemented — don't rebuild, just understand)
```
{optional: related existing implementations to study for patterns}
```

---

## What You're Building

{2-3 paragraph description: what the feature does, why, end state.}

---

## Execution Plan

Follow the tasks in `{spec or plan path}` in order. Each numbered section is a phase.

### Phase order:
1. **{Phase name}** (tasks N.N–N.N) — {brief description}
2. **{Phase name}** — {brief description}
3. **Checkpoint** — run `php artisan test --parallel`
4. **{Phase name}** — {brief description}
5. **Final checkpoint** — run `php artisan test --parallel`

---

## HARD RULES — NEVER VIOLATE THESE:

- **NEVER commit.** Do not run `git commit`, `git add`, or any git command.
- **NEVER run Pint.** Do not run `vendor/bin/pint` in any form.
- **NEVER push.** Do not run `git push` under any circumstances.
- **NEVER run destructive database commands.** No `migrate:fresh`, `migrate:reset`, `db:wipe`.

---

## Implementation Rules

- **All new code goes in `{target namespace}`** — e.g., `app/Sync/` for sync features.
- **Routes go in `{route file}`** — e.g., `routes/sync.php`.
- **Always use `php artisan test --parallel` when running tests.** This project has 2000+ tests; parallel execution takes ~11 seconds vs 100+ sequential.
- **To run a specific test file:** `php artisan test --parallel tests/Feature/Path/SomeTest.php`
- **To filter by name:** `php artisan test --parallel --filter=testName`
- **Never use `DB::` facade** — use Eloquent models and relationships.
- **Use PHP 8 constructor promotion** in all new classes.
- **Add explicit return types** to all methods.
- {Feature-specific rules}

---

## Success Criteria

- [ ] {Concrete, verifiable criterion}
- [ ] {Another criterion}
- [ ] All tests pass (`php artisan test --parallel`)
- [ ] No git commits made
- [ ] No new composer dependencies (unless specified)

---

## Do Not

- Do NOT commit or push.
- Do NOT run Pint.
- Do NOT run destructive database commands.
- Do NOT put code outside the designated namespace.
- {Feature-specific prohibitions}

---

## Post-Execution Retro (authored by the REVIEWER, not the executor)

> The executor must NOT fill this in — leave the `{placeholder}` values untouched. After the run, the human reviewer reconstructs this section from the commit trail at archive time (the `snapshot:` commit, the `cleanup:`/`fix:` follow-ups, any phased slices) and moves both plan + prompt to `completed/`. See `.kiro/steering/architect-workflow.md` → Post-Execution Review Protocol.

- **Attempts:** {1 (clean) / N (root cause of failures)}
- **Tests added:** {count}
- **Prompt improvements for next time:** {what to add/change}
- **Steering updates needed:** {yes/no, what}
