---
inclusion: manual
---

# Architect Workflow

Rules for planning features, writing antigravity execution prompts, and reviewing results in this
Laravel app. This file governs Kiro's behavior when acting as **architect** — deciding what to build,
designing the architecture, writing prompts for delegation, and reviewing the output.

It is the counterpart to the *executor* contract in `docs/antigravity-steering.md`: this file is for the
architect (Kiro + the user) deciding and authoring; that file is for the antigravity executor running a
finished prompt. When authoring, read both — you are writing prompts that must satisfy the executor
contract.

---

## Role

In architect mode, Kiro and the user are architects. Implementation is delegated to an external LLM
(antigravity) running inside this repo. Our job:

1. Decide what to build and in what order.
2. Design architecture with enough precision for mechanical implementation.
3. Write execution prompts that eliminate ambiguity.
4. Review results holistically after implementation.
5. Learn from each execution and improve the process.

---

## The Three-Layer System

| Layer | Location | Purpose |
|-------|----------|---------|
| PLAN | `docs/plans/{feature}.md` | Architecture reference — explains why and what, not how to execute. Follows the Plan Document Format in `docs/antigravity-steering.md` §9. |
| PROMPT | `docs/plans/{feature}-prompt.md` | Mechanical execution steps antigravity follows. Authored from `docs/plans/template-prompt.md`. |
| STEERING | `docs/antigravity-steering.md` + `.kiro/steering/*` | Permanent rules the executor reads first (safety, git, DB, architecture, style). |

Template for prompts: `docs/plans/template-prompt.md`.
Both plan + prompt move to `docs/plans/completed/` after shipping (see the archival gate below).

> The plan/spec is **reference architecture, not execution instructions.** The `-prompt.md` provides the
> execution steps. This mirrors `docs/antigravity-steering.md` §9.

---

## Prompt Precision Rules

These prevent the failure mode where early phases execute well but later phases are vague and produce
incomplete results. They complement (do not replace) the executor rules in `docs/antigravity-steering.md`
— several of those (§13 Consumer Impact Trace, §14 Logic-Belongs-Where-Dependencies-Live, §15 Prompt
Decomposition) are authored INTO the prompt by the architect, so honor them here.

1. **Every target needs a named destination.** Never say "add a service" or "refactor X" without the
   exact path, the class/method signature (promoted constructor params, `execute()` shape, return type),
   and what moves there (named methods or structural description). Match Laravel conventions
   (`app/X/` domain folders, Action classes, Form Requests) from `project-conventions.md`.

2. **Test checkpoints are verification steps, not aspirational goals.** Each milestone ends with a
   concrete `php artisan test --parallel` checkpoint (never after every step — see the executor's
   milestone rule). If a data-shape milestone lands, its checkpoint must assert the new shape.

3. **Uniform depth across all phases.** If Phase 1 specifies "create class X with this signature and
   these dependencies," every later phase has the same density. Before handoff, scan all phases: any
   "determine whether…" / "if appropriate…" is underspecified. Antigravity treats ambiguity as "leave it
   alone."

4. **No decisions delegated to the executor.** Phrases like "decide if this stays or moves," "choose the
   best approach," or "if X then Y else Z" are prompt defects. The architect makes all architectural
   decisions; the prompt encodes them.

5. **Consumer Impact Trace is mandatory for data-shape changes (executor §13).** When the plan
   adds/removes a column, changes a cast, alters an API response body, moves a value between columns, or
   changes an event payload, the prompt MUST enumerate: what reads it (models, controllers, actions,
   `ExerciseType` strategies, Blade, API resources, tests), what interprets it at display time, and which
   tests assert the old shape. This app's highest-risk change is a schema / column-semantics change with
   multiple readers — trace all readers before the change is executed.

6. **Migrations are forward-only; specify them exactly.** Per `project-conventions.md` and executor §6:
   new columns nullable, added to `$fillable` and `casts()`, no repurposed columns, no modifying a
   migration that has run. If a migration re-shapes or re-types existing data, the prompt must state the
   exact selection query and the down-migration behavior. Never leave "migrate the old data" to the
   executor's imagination.

7. **Entity defaults for new stored objects (executor §15).** When the feature creates new stored records
   (a model, a default row, a generated payload), specify the exact initial shape — every field, default,
   nullability, cast — as a code block.

8. **Decompose large features (executor §15).** A single prompt should not modify more than 3 existing
   consumer components (controllers, shared models, strategies, views) in one shot. Split into: (1) pure
   logic + unit tests, (2) single integration point + feature test, (3) integration sweep. Apply when the
   feature adds a stored entity/column consumed by 4+ places or changes an existing strategy/formatter.

---

## Required Prompt Sections

Every prompt is authored from `docs/plans/template-prompt.md` and must contain, in order:

1. **Before You Start** — steering read-order (`docs/antigravity-steering.md` first), spec/plan path,
   existing code to understand, reference implementations.
2. **What You're Building** — scope, why, end state, zero-behavioral-change constraint if a refactor.
3. **Execution Plan** — numbered phases with `php artisan test --parallel` checkpoints.
4. **Hard Rules** — never commit/push (executor §3), never Pint (safe-operations wins), never destructive
   DB.
5. **Implementation Rules** — namespace, routes file, Eloquent-not-`DB::`, constructor promotion, return
   types, PHPUnit-only.
6. **Success Criteria** — each verifiable by a test command or grep.
7. **Do Not** — from history + feature-specific prohibitions.
8. **Post-Execution Retro** — placeholders on handoff; filled during review (see below).

If any section is missing, the prompt is incomplete. Do not hand off.

---

## Readiness Checklist

Run before declaring a prompt ready:

- [ ] Every new/modified file has a path, signature, and list of what it contains.
- [ ] Every phase has equal instruction density (no phase vaguer than others).
- [ ] No "determine," "decide," "choose," or "if appropriate" language — all decisions made.
- [ ] Test checkpoints appear WITHIN milestones as `php artisan test --parallel`, not only at the end.
- [ ] Data-shape changes carry a full Consumer Impact Trace (readers + display interpreters + tests).
- [ ] Migrations specified exactly: nullable columns, `$fillable`/`casts()`, selection query for any
      data re-shape, correct down-migration. No modified historical migrations.
- [ ] New stored records specify their exact initial shape as a code block.
- [ ] Feature touching >3 consumers is decomposed into sequential prompts.
- [ ] Success criteria are testable (command or grep).
- [ ] Dead-code removal is explicit in the final milestone.
- [ ] "Do Not" list encodes lessons from any resembling past failure.

---

## Post-Execution Review Protocol

When antigravity finishes and the user brings the code back:

### 1. Verify
- Run `php artisan test --parallel`.
- Confirm `AGY_COMPLETE` was printed (or identify why not).
- Confirm no git commits were made by the executor and no Pint was run.
- **If the change includes a migration: RUN it and confirm it took.** Run `php artisan migrate`, then
  `php artisan migrate:status` to confirm the new migration is **Ran**, and verify the intended data end
  state (e.g. row counts, re-typed columns). A green `RefreshDatabase` feature test proves the migration
  works on in-memory SQLite — it does NOT prove it ran, and it does NOT catch MySQL-only failures
  (self-referential FK integrity, SoftDeletes leaving rows behind). Data migrations must be exercised
  against the real DB before the feature is considered verified.

### 2. Review holistically
- Read the diff as a behavioral narrative (what changed, not file-by-file).
- Check for dead code, commented-out code, backward-compat shims, leftover TODO/FIXME.
- Verify each success criterion.
- Check convention compliance: domain-folder placement, Action pattern, Eloquent over `DB::`, explicit
  return types, forward-only migration, shared-model safety.

### 3. Write the retro (reviewer-owned)
The executor writes its retro into the prompt file before `AGY_COMPLETE` (executor §4). During review YOU
verify/expand it against the observed outcome — attempts, follow-up fixes surfaced in review, tests
added, prompt gaps, steering updates needed. Follow-up fixes only become known at review time, which is
why the reviewer owns the final retro.

### 4. Archive (gated)
Move both `{feature}.md` and `{feature}-prompt.md` to `docs/plans/completed/` — but ONLY once every retro
field has a concrete value (no `{placeholder}` text, no empty values). If any field is unfilled, STOP and
fill it first.

### 5. Update steering
If the execution revealed a new failure mode, add it to `docs/antigravity-steering.md` or the relevant
`.kiro/steering/*` file so the next run inherits the lesson.

---

## Cross-Repo Work

Some features are coordinated across sibling apps and the root workspace (for example, changes that must
stay consistent with a cross-app contract). When a feature's design is owned by a plan at the root
workspace level rather than this repo:

- Treat the root plan as the source of truth for shared decisions (names, shapes, sequencing); this
  repo's plan/prompt implement only this app's slice.
- Follow this repo's own workflow (this file + `docs/antigravity-steering.md`) for the local execution —
  do not invent a different process just because the effort spans repos.
- If the shared behavior is guarded by cross-app contract tests, land this repo's change in lockstep with
  those tests (see `context-routing.md` for the routing trigger), never ahead of them.

Keep this section generic. Do not name specific in-flight efforts here — those live in plan docs, which
are completed and archived; steering is permanent.

---

## References

- Executor contract (antigravity reads this): `docs/antigravity-steering.md`
- Prompt template: `docs/plans/template-prompt.md`
- Plan Document Format: `docs/antigravity-steering.md` §9
- Completed archive: `docs/plans/completed/`
- Project conventions: `.kiro/steering/project-conventions.md`
