# PR Engine — `load` Role Token (Logger slice)

> **Scope:** Logger-only slice of a coordinated three-repo change. Reference architecture; execution
> steps live in `pr-load-role-token-prompt.md`. The shared cross-repo contract (the `load` token both
> apps mirror) is FROZEN at the root; this slice keeps `config/pr_families.php` byte-shape-aligned with
> Athlete's `prDescriptors.js` by renaming the mass-axis field to the role token `load`.
>
> **Directional isolation:** Stay entirely inside `logger/`. Do NOT read or reference `../athlete`,
> `../contracts`, `../docs`, or any sibling app. Duplicate any shared shape inline; do not reach up.

---

## What You're Building

Athlete and Logger deliberately share IDENTICAL PR-family descriptor shapes (Phase C1/C2 "structural
mirror"): `config/pr_families.php` (Logger) mirrors `prDescriptors.js` (Athlete), and the cross-app
equivalence contract trusts that alignment. The mass-axis field in both was named `weight`.

Athlete is introducing a role token `load` for the mass axis, because Athlete stores its load under
several DIALECT field names (`addedWeight`, `kbWeight`, `ballWeight`) depending on logType and needed a
role token to resolve the concrete field per logType. To keep the two configs shape-mirrored, Logger
renames the same mass-axis field from `weight` to the role token `load` in lockstep.

On the Logger side this is a **pure shape alignment with ZERO behavioral change**: Logger has no dialect —
every family's load lives in the single `lift_sets.weight` column. So the role `load` resolves to the
`weight` column everywhere. Descriptors say `load`; the reduction engine maps `load → weight` when reading
a set. No DB change, no migration, no output change — the same PRs are computed, byte-for-byte.

## Why (the shared contract)

The cross-app equivalence suite (authored at root, separately) asserts the two engines agree AND that the
two configs share the same descriptor shapes. If Athlete renames the mass field to `load` and Logger does
not, the shapes diverge and the mirror invariant breaks. This slice preserves the mirror.

## Architecture

### The one change of substance: resolve `load` → the `weight` column
`app/Services/PR/Reductions.php` reads set values via `extractValue($set, $field)` (and
`extractValueRaw`), where `$field` comes from the descriptor (`field`, each of `factors`, `keyFields`,
`valueField`, `unitField`). Those functions special-case `$field === 'weight'` (kg→lbs conversion) and
`$field === 'distance'` (integer-meter normalization). After the descriptors say `load` for the mass axis,
the reduction must resolve `load → 'weight'` BEFORE those field-name checks, so the mass conversion still
fires.

Add a single private resolver used at the top of `extractValue` (and anywhere else a descriptor field name
is used to read a set column):
```php
// The only role token is 'load' → the weight column (Logger has no dialect; mass lives in `weight`).
private static function resolveField(string $field): string
{
    return $field === 'load' ? 'weight' : $field;
}
```
`extractValue`/`extractValueRaw` resolve `$field` first, then proceed unchanged. `perKey`'s keyField
handling (the `$kf === 'weight'` whole-pound rounding for the speed bucket) must also resolve `load →
weight` before that check, so a `keyFields: ['load','distance']` speed record still rounds the mass
component identically.

### The config rename (`config/pr_families.php`)
Rename `weight` → `load` ONLY on the mass axis, everywhere it currently names the load:
- `weightlifting`: `one_rm.field`, `rep_specific.valueField`, `volume.factors[0]` + `volume.unitField`,
  `density.keyFields[0]`, `hypertrophy.keyFields[0]`
- `bodyweight`: `rep_specific.valueField`, `volume.factors[0]` + `volume.unitField`
- `load_output`: `load` record `field`, and `speed.keyFields[0]` (the `weight` in `['weight','distance']`)
Leave every non-mass field literal (`reps`, `distance`, `duration`, `time`, `rounds`). Do NOT rename the
`'weight'` COLUMN anywhere in code, models, or DB — only the descriptor's field NAME becomes the role
token, and the engine resolves it back to the `weight` column.

## Consumer Impact Trace (§13 — config-shape change)

The descriptor `field`/`factors`/`keyFields`/`valueField`/`unitField` VALUE changes from `'weight'` to
`'load'`. What reads those descriptor field names:

1. **`Reductions::reduce` → `maxOf`/`minOf`/`sumOf`/`sumProduct`/`estimated1RM`/`perKey`** — all read the
   descriptor's field name(s) and pass them to `extractValue`. Resolving inside `extractValue` (+ the
   `perKey` keyField check) covers all of them in one place. Updated in the same milestone.
2. **`Comparators::scalarBest`/`keyedBest`** — operate on reduced NUMBERS + descriptor `tolerance`/
   `direction`/`requirePrevious`, not field names. No change.
3. **`PrEngine::computeMetrics`/`detectPRs`** — iterate descriptors keyed by `type`, dispatch on
   `reduce`/`compare`. They pass the whole descriptor to `Reductions`/`Comparators`; they do not read
   `field` directly. No change.
4. **`PRDetectionService::buildHistoryFromPreviousLogs`** (or wherever Logger folds prior-log metrics into
   comparable history) — if it reads a descriptor `field`/`factors` to build the stored side, it must
   resolve `load → weight` the same way. Trace it; route through the same resolver or reuse
   `Reductions::extractValue`.
5. **Display/label assemblers** (`PRRecordsComponentAssembler`, `unitField` readers) — if any reads the
   descriptor `unitField`/`field` to pick a display unit, resolve `load → weight`. The `weight` column and
   `unit` stamp are unchanged, so display is identical.
6. **Tests** — any test asserting a descriptor's `field === 'weight'`, or building a descriptor literal
   with `field => 'weight'` for a direct `Reductions` call, still works (`resolveField('weight')` is
   `'weight'`). Tests that now read the config and expect `field => 'weight'` on the mass axis must expect
   `'load'`. List and update them.

## Files Changed (Logger)

```
config/pr_families.php                       [modified] mass-axis field 'weight' → 'load' (all families; non-mass fields literal)
app/Services/PR/Reductions.php               [modified] add resolveField(); resolve 'load' → 'weight' column at the top of extractValue/extractValueRaw + perKey keyField check
app/Services/PR/PRDetectionService.php       [verify/modify] if it reads descriptor field/factors to build history, resolve load→weight there too
tests/**                                     [modified] tests asserting mass-axis field name 'weight' now expect 'load'; direct-Reductions tests unchanged (weight role still valid)
```

## Success Criteria / Constraints

- `config/pr_families.php` names the mass axis `load` across all families; non-mass fields literal.
- `Reductions` resolves `load → weight` column via one `resolveField()` lookup; kg→lbs conversion and
  speed-bucket whole-pound rounding still fire (they key off the RESOLVED `weight`).
- ZERO behavioral change: `php artisan test --parallel` green with identical PR outputs; no DB change, no
  migration.
- Descriptor shapes stay mirrored with Athlete's `prDescriptors.js` (both name the mass axis `load`).
- No dual-read, no repurposed column: the `weight` COLUMN name is unchanged in code/DB; only the
  descriptor field NAME is the role token.

## Risks

- **kg→lbs conversion keyed on `$field === 'weight'`.** Must resolve `load → weight` BEFORE that check, or
  a kg set stops converting. This is the highest-risk spot — verify with a kg fixture.
- **Speed-bucket whole-pound rounding in `perKey`** (the `$kf === 'weight'` branch). Resolve the keyField
  first so `keyFields: ['load','distance']` still rounds the mass component; otherwise cross-app speed
  buckets diverge.
- **History-building path** (`PRDetectionService`) may independently read descriptor `field`/`factors`.
  If it bypasses `Reductions::extractValue`, it needs the same resolution — trace it explicitly.

## Changelog
- rev 1 (2026-09-01) — initial Logger slice; pure shape alignment to preserve the Athlete⇄Logger config
  mirror as Athlete introduces the `load` role token.
