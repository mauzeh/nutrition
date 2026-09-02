# PR Engine `load` Role Token — Prompt for Antigravity CLI

## Before You Start

Read these files in order.

### 1. Steering (project rules — always follow)
```
.kiro/steering/git-workflow.md          → commit freely, NEVER push, NEVER merge into main (delegated run: NEVER touch git — see antigravity-steering §3)
.kiro/steering/safe-operations.md       → files to never touch, bash safety, artisan safety, Pint ban
.kiro/steering/project-conventions.md   → architectural principles
.kiro/steering/laravel-boost.md         → Laravel/PHP conventions, PHPUnit-only testing
docs/antigravity-steering.md            → §4 milestone testing (--parallel), §13 consumer-impact trace, retro-to-file
```

### 2. Feature Spec (what to build)
```
docs/plans/pr-load-role-token.md        → architecture, the resolveField mapping, consumer trace, risks
```

### 3. Existing Code to Understand (read before modifying)
```
config/pr_families.php                              → the family descriptors (mass axis currently 'weight')
app/Services/PR/Reductions.php                      → extractValue/extractValueRaw ($field → set column; kg→lbs on 'weight', integer-meters on 'distance'); perKey keyField whole-pound rounding ($kf === 'weight')
app/Services/PR/PrEngine.php                         → computeMetrics/detectPRs read descriptors by type, pass whole descriptor to Reductions/Comparators (no direct field read)
app/Services/PRDetectionService.php                  → buildHistoryFromPreviousLogs reads config("pr_families.families.{family}") to fold prior-log metrics into comparable history
app/Services/PR/Comparators.php                      → operates on reduced numbers + tolerance/direction (no field-name read)
```

---

## What You're Building

A pure SHAPE ALIGNMENT with ZERO behavioral change. Athlete is introducing a role token `load` for the
mass axis of its PR family descriptors (because Athlete stores load under dialect field names —
`addedWeight`/`kbWeight`/`ballWeight` — and needs to resolve the concrete field per logType). Logger and
Athlete deliberately keep their PR-family descriptor shapes byte-mirrored (Phase C1/C2), so Logger renames
the same mass-axis field from `weight` to the role token `load` in lockstep.

Logger has NO dialect: every family's load lives in the single `lift_sets.weight` column. So the role
`load` resolves to the `weight` column everywhere. Descriptors say `load`; the reduction engine maps
`load → weight` when reading a set. No DB change, no migration, no output change — identical PRs computed.

This slice is Logger-only. Do NOT touch `../athlete`, `../contracts`, or `../docs`. The `weight` COLUMN
name (DB, models, mapping) does NOT change — only the descriptor's field NAME becomes the token `load`,
which the engine resolves back to the `weight` column.

---

## Execution Plan

### Phase order:
1. **Resolver in Reductions** (Milestone 1) — add `resolveField('load' → 'weight')`; resolve at the top of
   `extractValue`/`extractValueRaw` and before the `perKey` keyField `$kf === 'weight'` rounding check.
2. **Config rename** (Milestone 2) — mass-axis `'weight'` → `'load'` in `config/pr_families.php`.
3. **History-build path** (Milestone 2) — ensure `PRDetectionService::buildHistoryFromPreviousLogs`
   resolves `load → weight` (reuse `Reductions` or the same resolver) if it reads descriptor `field`/`factors`.
4. **Checkpoint** — `php artisan test --parallel`.
5. **Kg + speed-bucket verification + final checkpoint** — a kg fixture and a speed-bucket fixture prove
   the conversion/rounding still fire on the resolved `weight`.

---

## Milestone 1: `resolveField` in Reductions (behavior-preserving)

### Step 1 — add the resolver
In `app/Services/PR/Reductions.php`:
```php
// The only role token is 'load' → the weight column. Logger has no dialect: all mass lives in `weight`.
private static function resolveField(string $field): string
{
    return $field === 'load' ? 'weight' : $field;
}
```

### Step 2 — resolve at every point a descriptor field name reads a set column
- At the TOP of `extractValue(mixed $set, string $field, ...)`: `$field = self::resolveField($field);`
  BEFORE the existing `$field === 'weight'` (kg→lbs) and `$field === 'distance'` branches. This single
  change covers `maxOf`/`minOf`/`sumOf`/`estimated1RM`/`perKey`/`sumProduct`'s per-factor reads, because
  they all funnel through `extractValue`/`extractValueRaw`.
- In `perKey`, the keyField loop checks `if ($kf === 'weight')` to whole-pound-round the mass component
  of the speed bucket. Resolve there too: compute `$resolvedKf = self::resolveField($kf);` and gate the
  rounding on `$resolvedKf === 'weight'`, so `keyFields: ['load','distance']` still rounds the mass part.
- LEAVE the hardcoded `self::extractValue($set, 'weight')` in `sumProduct`'s `$allZeroWeight` pre-scan as
  literal `'weight'` — it reads the column directly, not a descriptor field. `resolveField('weight')` is
  `'weight'` anyway, so it is unaffected.

### Milestone 1 Checkpoint
```bash
php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt
```
Full suite green — descriptors still say `weight`, so `resolveField('weight')` is a no-op and nothing
changed yet. Read `.test-output.txt`; delete when green.

---

## Milestone 2: config rename + history-build path

### Step 3 — rename the mass axis to `load` in `config/pr_families.php`
Change `'weight'` → `'load'` ONLY on the mass axis:
- `weightlifting`: `one_rm.field`; `rep_specific.valueField`; `volume.factors` first element + `volume.unitField`;
  `density.keyFields` element; `hypertrophy.keyFields` element
- `bodyweight`: `rep_specific.valueField`; `volume.factors` first element + `volume.unitField`
- `load_output`: `load` record `.field`; `speed.keyFields` — the `'weight'` element becomes `'load'`
  (the `['weight','distance']` → `['load','distance']`)
Leave `reps`, `distance`, `duration`, `time`, `rounds` literal. Do NOT rename `logTypeToFamily` keys or
any label/format.

### Step 4 — history-build path
Read `PRDetectionService::buildHistoryFromPreviousLogs`. If it reads a descriptor's `field`/`factors`/
`keyFields`/`valueField`/`unitField` to fold prior-log metrics into the comparable history, it must
resolve `load → weight` the same way. Prefer reusing `Reductions` (route the reads through the same
`extractValue`), or apply the same `resolveField` mapping locally. If it only ever calls back into
`Reductions`/`PrEngine` (which already resolve), no change is needed — confirm by reading it.

### Milestone 2 Checkpoint
```bash
php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt
```
Full suite green with descriptors now saying `load`. Update any test that asserted a mass-axis
`field => 'weight'` on the config to expect `'load'`. Direct-`Reductions` tests that pass a literal
descriptor with `field => 'weight'` still pass (resolver no-op). Read `.test-output.txt`; delete when green.

---

## Milestone 3: kg + speed-bucket verification + final run

### Step 5 — targeted verification (prove the risky paths)
Add or confirm feature/unit coverage for:
- **kg conversion:** a bodyweight or weightlifting log stored in kg still converts to lbs in the PR (the
  kg→lbs branch fires on the RESOLVED `weight`). If an existing test covers kg PRs, confirm it still passes;
  else add one.
- **speed bucket:** a `load_output` `speed` record with `keyFields: ['load','distance']` still buckets by
  whole-pound load (the `$kf` rounding fires on resolved `weight`). Confirm the existing speed test passes.

### Step 6 — final run
```bash
php artisan test --parallel > .test-output.txt 2>&1; tail -40 .test-output.txt
```
All green, zero new failures (note any PRE-EXISTING unrelated failures but do not fix them). Delete
`.test-output.txt`.

### Post-Execution Retro
Write the retro into THIS file's `## Post-Execution Retro` section (str_replace the placeholders) per
`docs/antigravity-steering.md` §4, THEN print:
```
AGY_COMPLETE: All milestones passed.
```

---

## HARD RULES — NEVER VIOLATE THESE
- **NEVER commit / add / push / touch git** (delegated run — antigravity-steering §3).
- **NEVER run Pint.**
- **NEVER run destructive DB commands** (`migrate:fresh`/`reset`/`db:wipe`). This change needs NO migration.

## Implementation Rules
- Always test with `php artisan test --parallel`.
- Use PHP 8 constructor promotion, explicit return types, Eloquent over `DB::`.
- The `weight` COLUMN name is unchanged everywhere — only the descriptor field NAME becomes `load`.

## Success Criteria
- [ ] `config/pr_families.php` mass axis is `load` across all families; non-mass fields literal.
- [ ] `Reductions::resolveField` maps `load → weight`; resolution happens before the `weight`/`distance`
      branches in `extractValue` and before the `perKey` keyField rounding check.
- [ ] ZERO behavioral change: full suite green, identical PR outputs; no DB change, no migration.
- [ ] kg conversion + speed-bucket rounding still fire (verified).
- [ ] Descriptor shapes mirror Athlete's `prDescriptors.js` (both name the mass axis `load`).
- [ ] No git commits, no Pint, no new composer deps.

## Do Not
- Do NOT commit, push, or run Pint.
- Do NOT rename the `weight` DB column or the `weight` key in set/column mappers — only the descriptor field name.
- Do NOT touch `../athlete`, `../contracts`, `../docs`, or any sibling app.
- Do NOT tokenize non-mass fields (`reps`/`distance`/`duration`/`time`/`rounds`).
- Do NOT add a migration or change how sets are stored.

## Post-Execution Retro (added after completion)
- **Attempts:** 1 (clean)
- **Tests added:** 2
- **Prompt improvements for next time:** None — prompt plan was clear and exact.
- **Steering updates needed:** no
