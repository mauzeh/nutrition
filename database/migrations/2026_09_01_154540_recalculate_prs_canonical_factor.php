<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Canonical-factor PR recalculation.
 *
 * The full historical PR recalculation is INTENTIONALLY NOT run here. Personal records are a
 * derived cache (rebuildable from lift_logs at any time), and the recalc is a long-running,
 * synchronous sweep over every (user, exercise) pair — running it inside `php artisan migrate`
 * would block the deploy for its full (unbounded) duration on the Forge host.
 *
 * Instead, run it MANUALLY as a deliberate, observable step after migrations complete:
 *
 *     php artisan prs:calculate-historical --force
 *
 * (Add `--dry-run` first to see the combination count, or scope with --user / --exercise.)
 *
 * The preceding data migrations (retype_sled_and_carry_exercises_to_load_output, merge_pull_up_variants)
 * already recompute PRs for the specific (user, exercise) pairs they touch, so the schema is consistent
 * without this step; the manual sweep re-derives EVERY pair under the canonical arithmetic factor.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Intentionally empty — PR recalculation is run manually post-deploy.
        // See the class docblock for the command.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Derived cache rebuild; no-op.
    }
};
