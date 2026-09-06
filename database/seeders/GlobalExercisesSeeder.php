<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Exercise;

class GlobalExercisesSeeder extends Seeder
{
    
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $exercises = $this->getHardcodedExercises();
        
        foreach ($exercises as $exerciseData) {
            Exercise::create($exerciseData);
        }
        
        $this->command->info("Successfully created " . count($exercises) . " exercises");

        $this->seedCarrySplitExercises();
    }

    /**
     * Seed the six global "carry split" exercise definitions.
     *
     * These rows were originally inserted by the migration
     * 2026_09_04_113405_retype_carry_exercises_create_splits_and_purge_history. They are
     * baseline reference data (new global exercises), so they were moved here to keep the
     * migration limited to transforming existing rows and to stop them from polluting the
     * RefreshDatabase test database (which replays migrations but never runs seeders).
     *
     * Uses firstOrCreate keyed on canonical_name so this is idempotent: environments that
     * already have these rows (e.g. production, where the original migration created them) are
     * left untouched, and running the seeder more than once will not create duplicates.
     *
     * @return void
     */
    private function seedCarrySplitExercises(): void
    {
        $carrySplitExercises = [
            [
                'title' => 'Mixed Rack Carry (KB)',
                'canonical_name' => 'mixed_rack_carry_kb',
                'exercise_type' => 'load_output',
                'log_type' => 'weighted-carry-2-kb',
                'show_in_feed' => true,
                'user_id' => null,
            ],
            [
                'title' => 'Mixed Rack Carry (DB)',
                'canonical_name' => 'mixed_rack_carry_db',
                'exercise_type' => 'load_output',
                'log_type' => 'weighted-carry-2-db',
                'show_in_feed' => true,
                'user_id' => null,
            ],
            [
                'title' => 'Single-Arm Overhead Carry (KB)',
                'canonical_name' => 'single_arm_oh_carry_kb',
                'exercise_type' => 'load_output',
                'log_type' => 'weighted-carry-1-kb',
                'show_in_feed' => true,
                'user_id' => null,
            ],
            [
                'title' => 'Single-Arm Overhead Carry (DB)',
                'canonical_name' => 'single_arm_oh_carry_db',
                'exercise_type' => 'load_output',
                'log_type' => 'weighted-carry-1-db',
                'show_in_feed' => true,
                'user_id' => null,
            ],
            [
                'title' => 'Suitcase March (KB)',
                'canonical_name' => 'suitcase_march_kb',
                'exercise_type' => 'load_output',
                'log_type' => 'weighted-carry-1-kb',
                'show_in_feed' => true,
                'user_id' => null,
            ],
            [
                'title' => 'Suitcase March (DB)',
                'canonical_name' => 'suitcase_march_db',
                'exercise_type' => 'load_output',
                'log_type' => 'weighted-carry-1-db',
                'show_in_feed' => true,
                'user_id' => null,
            ],
        ];

        foreach ($carrySplitExercises as $exerciseData) {
            Exercise::firstOrCreate(
                ['canonical_name' => $exerciseData['canonical_name']],
                $exerciseData
            );
        }

        $this->command->info('Seeded ' . count($carrySplitExercises) . ' carry-split exercises');
    }
    
    /**
     * Get hardcoded exercise dataset covering major muscle groups
     * 
     * @return array
     */
    private function getHardcodedExercises(): array
    {
        return [
            // Upper Body - Chest
            [
                'title' => 'Push-Up',
                'description' => 'A bodyweight exercise that targets the chest, shoulders, and triceps.',
                'exercise_type' => 'bodyweight',
                'canonical_name' => 'push_up',
                'user_id' => null,
            ],
            [
                'title' => 'Bench Press',
                'description' => 'A compound exercise that targets the muscles of the upper body, including the chest, shoulders, and triceps.',
                'exercise_type' => 'regular',
                'canonical_name' => 'bench_press',
                'user_id' => null,
            ],
            
            // Upper Body - Back
            [
                'title' => 'Pull-Up',
                'description' => 'A bodyweight pulling exercise with pronated grip that targets the back and biceps.',
                'exercise_type' => 'bodyweight',
                'canonical_name' => 'pull_ups',
                'user_id' => null,
            ],
            [
                'title' => 'Chin-Up',
                'description' => 'A bodyweight pulling exercise with supinated grip that targets the back and biceps.',
                'exercise_type' => 'bodyweight',
                'canonical_name' => 'chin_ups',
                'user_id' => null,
            ],
            [
                'title' => 'Bent-Over Row',
                'description' => 'A compound pulling exercise that targets the back muscles and rear deltoids.',
                'exercise_type' => 'regular',
                'canonical_name' => 'bent_over_row',
                'user_id' => null,
            ],
            [
                'title' => 'Lat Pull-Down',
                'description' => 'A machine exercise that targets the latissimus dorsi and other back muscles.',
                'exercise_type' => 'banded_resistance',
                'canonical_name' => 'lat_pull_down',
                'user_id' => null,
            ],
            
            // Upper Body - Shoulders
            [
                'title' => 'Strict Press',
                'description' => 'An overhead pressing movement that targets the shoulders and triceps.',
                'exercise_type' => 'regular',
                'canonical_name' => 'strict_press',
                'user_id' => null,
            ],
            [
                'title' => 'Pike Push-Up',
                'description' => 'A bodyweight exercise that targets the shoulders and upper chest.',
                'exercise_type' => 'bodyweight',
                'canonical_name' => 'pike_push_up',
                'user_id' => null,
            ],
            
            // Lower Body - Quads/Glutes
            [
                'title' => 'Back Squat',
                'description' => 'A compound exercise that targets the muscles of the legs and core.',
                'exercise_type' => 'regular',
                'canonical_name' => 'back_squat',
                'user_id' => null,
            ],
            [
                'title' => 'Front Squat',
                'description' => 'A squat variation with the barbell held in front that emphasizes the quads and core.',
                'exercise_type' => 'regular',
                'canonical_name' => 'front_squat',
                'user_id' => null,
            ],
            [
                'title' => 'Bodyweight Squat',
                'description' => 'A fundamental bodyweight exercise that targets the legs and glutes.',
                'exercise_type' => 'bodyweight',
                'canonical_name' => 'bodyweight_squat',
                'user_id' => null,
            ],
            [
                'title' => 'Lunge',
                'description' => 'A unilateral exercise that targets the legs and improves balance.',
                'exercise_type' => 'bodyweight',
                'canonical_name' => 'lunge',
                'user_id' => null,
            ],
            
            // Lower Body - Posterior Chain
            [
                'title' => 'Deadlift',
                'description' => 'A compound exercise that targets the muscles of the back, legs, and grip.',
                'exercise_type' => 'regular',
                'canonical_name' => 'deadlift',
                'user_id' => null,
            ],
            [
                'title' => 'Romanian Deadlift',
                'description' => 'A hip-hinge movement that targets the hamstrings and glutes.',
                'exercise_type' => 'regular',
                'canonical_name' => 'romanian_deadlift',
                'user_id' => null,
            ],
            [
                'title' => 'Hip Thrust',
                'description' => 'An exercise that specifically targets the glutes and posterior chain.',
                'exercise_type' => 'regular',
                'canonical_name' => 'hip_thrust',
                'user_id' => null,
            ],
            
            // Core
            [
                'title' => 'Plank',
                'description' => 'An isometric core exercise that builds stability and strength.',
                'exercise_type' => 'bodyweight',
                'canonical_name' => 'plank',
                'user_id' => null,
            ],
            [
                'title' => 'Mountain Climbers',
                'description' => 'A dynamic bodyweight exercise that targets the core and provides cardio.',
                'exercise_type' => 'bodyweight',
                'canonical_name' => 'mountain_climbers',
                'user_id' => null,
            ],
            [
                'title' => 'Dead Bug',
                'description' => 'A core stability exercise that teaches proper spinal alignment.',
                'exercise_type' => 'bodyweight',
                'canonical_name' => 'dead_bug',
                'user_id' => null,
            ],
            
            // Full Body/Olympic
            [
                'title' => 'Burpee',
                'description' => 'A full-body exercise that combines a squat, push-up, and jump.',
                'exercise_type' => 'bodyweight',
                'canonical_name' => 'burpee',
                'user_id' => null,
            ],
            [
                'title' => 'Power Clean',
                'description' => 'An explosive Olympic lift that targets the entire body.',
                'exercise_type' => 'regular',
                'canonical_name' => 'power_clean',
                'user_id' => null,
            ],
            [
                'title' => 'Thruster',
                'description' => 'A combination of front squat and overhead press in one fluid movement.',
                'exercise_type' => 'regular',
                'canonical_name' => 'thruster',
                'user_id' => null,
            ],
            
            // Isolation/Accessory
            [
                'title' => 'Bicep Curl',
                'description' => 'An isolation exercise that targets the biceps.',
                'exercise_type' => 'regular',
                'canonical_name' => 'bicep_curl',
                'user_id' => null,
            ],
            [
                'title' => 'Tricep Dip',
                'description' => 'A bodyweight exercise that targets the triceps and chest.',
                'exercise_type' => 'bodyweight',
                'canonical_name' => 'tricep_dip',
                'user_id' => null,
            ],
            [
                'title' => 'Calf Raise',
                'description' => 'An isolation exercise that targets the calf muscles.',
                'exercise_type' => 'bodyweight',
                'canonical_name' => 'calf_raise',
                'user_id' => null,
            ],
            
            // Cardio/Conditioning
            [
                'title' => 'Jumping Jacks',
                'description' => 'A cardiovascular exercise that involves jumping while moving arms and legs.',
                'exercise_type' => 'bodyweight',
                'canonical_name' => 'jumping_jacks',
                'user_id' => null,
            ],
        ];
    }
}